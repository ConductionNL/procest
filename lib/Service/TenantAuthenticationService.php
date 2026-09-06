<?php

/**
 * Dossiq Tenant Authentication Service
 *
 * Validates that a user is authorised to perform an action on a tenant by
 * resolving the tenant's active TenantMandate and matching the user's role
 * + action against the mandate matrix.
 *
 * Fail-closed: any unresolved matrix, role, or service error returns
 * `{allowed: false}` so the caller cannot fall open.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-06-mandate-validation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mandate-matrix authorisation guard for tenant actions.
 *
 * @spec openspec/specs/multi-tenancy/spec.md#req-005-tenant-membership-and-status-helpers-for-middleware
 */
class TenantAuthenticationService {
	/**
	 * Default deny-everything matrix (fail-closed fallback).
	 *
	 * @var array<string, array<string, bool>>
	 */
	private const DEFAULT_DENY_MATRIX = [];

	/**
	 * Upper bound on memberships read for one user.
	 *
	 * A person belongs to a handful of tenants, not hundreds. The cap exists so
	 * a malformed filter that matches every row cannot turn a per-request
	 * lookup into a full table read.
	 *
	 * @var int
	 */
	private const MAX_MEMBERSHIPS = 100;

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager App manager (for OR availability check).
	 * @param ContainerInterface $container DI container.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Validate a tenant action against the active mandate matrix.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $userId NC user ID.
	 * @param string $action Requested action (create|edit|status_update|delete|...).
	 *
	 * @return array{allowed: bool, reason: string} Decision payload.
	 *
	 * @spec openspec/specs/tenant-mandate/spec.md#requirement-mandate-matrix-validation-per-action-req-002-d-req-006-d
	 */
	public function validateMandateMatrix(string $tenantId, string $userId, string $action): array {
		try {
			$matrix = $this->loadActiveMatrix(tenantId: $tenantId);
			if ($matrix === null) {
				return ['allowed' => false, 'reason' => 'No active mandate matrix for tenant'];
			}

			$role = $this->resolveUserRole(tenantId: $tenantId, userId: $userId);
			if ($role === null) {
				return ['allowed' => false, 'reason' => 'User has no role inside tenant'];
			}

			$allowed = $this->isAllowed(matrix: $matrix, role: $role, action: $action);
			if ($allowed === true) {
				return ['allowed' => true, 'reason' => 'Authorised by mandate matrix'];
			}

			return ['allowed' => false, 'reason' => 'Role ' . $role . ' is not authorised for action ' . $action];
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: mandate matrix validation failed (fail-closed)',
				['tenantId' => $tenantId, 'userId' => $userId, 'exception' => $e->getMessage()]
			);
			return ['allowed' => false, 'reason' => 'Mandate validation error'];
		}//end try
	}//end validateMandateMatrix()

	/**
	 * Check whether the matrix authorises (role, action).
	 *
	 * The matrix layout is `{role: {action: bool}}`. A wildcard role `*` or
	 * action `*` is honoured. Missing entries default to false (fail-closed).
	 *
	 * @param array<string, array<string, bool>> $matrix Active mandate matrix.
	 * @param string $role Resolved user role.
	 * @param string $action Requested action.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/tenant-mandate/spec.md#requirement-mandate-matrix-validation-per-action-req-002-d-req-006-d
	 */
	public function isAllowed(array $matrix, string $role, string $action): bool {
		$roleEntry = ($matrix[$role] ?? null);
		$wildcardEntry = ($matrix['*'] ?? null);

		$candidates = [];
		if (is_array($roleEntry) === true) {
			$candidates[] = $roleEntry;
		}

		if (is_array($wildcardEntry) === true) {
			$candidates[] = $wildcardEntry;
		}

		foreach ($candidates as $entry) {
			if (($entry[$action] ?? false) === true) {
				return true;
			}

			if (($entry['*'] ?? false) === true) {
				return true;
			}
		}

		return false;
	}//end isAllowed()

	/**
	 * Load the active mandate matrix for the tenant.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return array<string, array<string, bool>>|null Active matrix or null.
	 *
	 * @spec openspec/specs/tenant-mandate/spec.md#requirement-mandate-matrix-validation-per-action-req-002-d-req-006-d
	 */
	public function loadActiveMatrix(string $tenantId): ?array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			// ObjectService::findAll() takes a single $config array — the previous
			// named-argument form threw "Unknown named parameter $register" and
			// was swallowed by the catch below. Register/schema live inside
			// `filters`; limit/offset are top-level config keys.
			$rows = $objectService->findAll(
				[
					'filters' => [
						'register' => TenantSaasService::REGISTER,
						'schema' => 'tenantMandate',
						'tenantRef' => $tenantId,
					],
					'limit' => 50,
					'offset' => 0,
				]
			);
		} catch (Throwable $e) {
			return null;
		}

		if (is_array($rows) === false || count($rows) === 0) {
			return null;
		}

		$active = $this->findActiveMandateRow(rows: $rows);
		if ($active === null) {
			return null;
		}

		return $this->normaliseMatrix(matrixField: ($active['matrix'] ?? null));
	}//end loadActiveMatrix()

	/**
	 * Pick the mandate row whose effective window contains "now".
	 *
	 * @param array<int, mixed> $rows The tenantMandate rows.
	 *
	 * @return mixed The active row, or null when none applies.
	 */
	private function findActiveMandateRow(array $rows): mixed {
		$now = time();
		foreach ($rows as $row) {
			$from = strtotime((string)($row['effectiveFrom'] ?? '1970-01-01'));
			$to = strtotime((string)($row['effectiveTo'] ?? '2099-12-31'));
			if ($from !== false && $to !== false && $from <= $now && $now <= $to) {
				return $row;
			}
		}

		return null;
	}//end findActiveMandateRow()

	/**
	 * Normalise the `matrix` field of an active mandate row.
	 *
	 * @param mixed $matrixField The raw matrix value.
	 *
	 * @return array<string, array<string, bool>> The resolved matrix.
	 */
	private function normaliseMatrix(mixed $matrixField): array {
		if (is_array($matrixField) === true) {
			return $matrixField;
		}

		if (is_string($matrixField) === true) {
			$decoded = json_decode($matrixField, true);
			if (is_array($decoded) === true) {
				return $decoded;
			}

			return self::DEFAULT_DENY_MATRIX;
		}

		// Fallback: a default role-action matrix when the active mandate row
		// does not embed one. Mirrors the common municipal mandate template.
		return [
			'tenant_admin' => ['*' => true],
			'case_handler' => ['create' => true, 'edit' => true, 'status_update' => true],
			'viewer' => [],
		];
	}//end normaliseMatrix()

	/**
	 * Resolve the role for a user inside a tenant.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $userId NC user ID.
	 *
	 * @return string|null Role name or null when unresolved.
	 *
	 * @spec openspec/specs/security-hardening/spec.md
	 */
	public function resolveUserRole(string $tenantId, string $userId): ?string {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			// ObjectService::findAll() takes a single $config array — see the note
			// above; register/schema live inside `filters`.
			$rows = $objectService->findAll(
				[
					'filters' => [
						'register' => TenantSaasService::REGISTER,
						'schema' => 'tenantUser',
						'tenantRef' => $tenantId,
						'userRef' => $userId,
					],
					'limit' => 1,
					'offset' => 0,
				]
			);
			if (is_array($rows) === true && count($rows) > 0) {
				$row = $rows[0];
				$role = (string)($row['role'] ?? '');
				if ($role !== '') {
					return $role;
				}

				return null;
			}
		} catch (Throwable $e) {
			// Fail CLOSED: a backend error is NOT "no role". Surfacing it as a
			// null role would let the mandate-matrix caller treat the lookup as
			// simply absent and silently fall open. Log it and re-throw so the
			// single caller (validateMandateMatrix) denies the action.
			$this->logger->error(
				'Dossiq: resolveUserRole lookup failed (fail-closed)',
				['tenantId' => $tenantId, 'userId' => $userId, 'exception' => $e->getMessage()]
			);
			throw $e;
		}//end try

		return null;
	}//end resolveUserRole()

	/**
	 * The tenant ids the given user is a member of.
	 *
	 * WHY THIS EXISTS. The tenant a request acts as is decided by the session,
	 * not by an `X-Tenant-Id` header and not by a JWT claim. Both of those are
	 * supplied by the caller, and a caller is exactly who must not choose. This
	 * is the lookup that says which tenants a user may legitimately be on, so
	 * that a switch can be verified and a session can be resolved.
	 *
	 * Fails CLOSED for the same reason as `resolveUserRole()`: a backend error
	 * returned as an empty list is indistinguishable from "member of nothing",
	 * and the caller would read it as a clean answer.
	 *
	 * @param string $userId The Nextcloud uid.
	 *
	 * @return array<int, string> Tenant ids, possibly empty.
	 *
	 * @throws Throwable When the membership lookup fails.
	 *
	 * @spec openspec/specs/multi-tenancy/spec.md#req-005-tenant-membership-and-status-helpers-for-middleware
	 */
	public function listTenantsForUser(string $userId): array {
		if ($userId === '') {
			return [];
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		try {
			$rows = $objectService->findAll(
				[
					'filters' => [
						'register' => TenantSaasService::REGISTER,
						'schema' => 'tenantUser',
						'userRef' => $userId,
					],
					'limit' => self::MAX_MEMBERSHIPS,
					'offset' => 0,
				]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: listTenantsForUser lookup failed (fail-closed)',
				['userId' => $userId, 'exception' => $e->getMessage()]
			);
			throw $e;
		}

		if (is_array($rows) === false) {
			return [];
		}

		$tenantIds = [];
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$tenantId = trim((string)($row['tenantRef'] ?? ''));
			if ($tenantId === '' || in_array($tenantId, $tenantIds, true) === true) {
				continue;
			}

			$tenantIds[] = $tenantId;
		}

		return $tenantIds;
	}//end listTenantsForUser()

	/**
	 * Whether the user is a member of the tenant.
	 *
	 * Membership is "has a tenantUser row", which is what `resolveUserRole()`
	 * already answers — a row without a role is still a row, so this asks the
	 * membership question directly rather than inferring it from a role string
	 * that may legitimately be empty.
	 *
	 * @param string $tenantId The tenant id.
	 * @param string $userId   The Nextcloud uid.
	 *
	 * @return bool Whether the user may act as this tenant.
	 *
	 * @throws Throwable When the membership lookup fails.
	 *
	 * @spec openspec/specs/multi-tenancy/spec.md#req-005-tenant-membership-and-status-helpers-for-middleware
	 */
	public function isMemberOf(string $tenantId, string $userId): bool {
		if ($tenantId === '' || $userId === '') {
			return false;
		}

		return in_array($tenantId, $this->listTenantsForUser(userId: $userId), true);
	}//end isMemberOf()


	/**
	 * Resolve OR's ObjectService when installed.
	 *
	 * @return mixed|null
	 */
	private function getObjectService() {
		// IAppManager::getInstalledApps() declares its array return in PHPDoc
		// only, so normalise defensively before the membership test.
		$installed = (array)$this->appManager->getInstalledApps();
		if (in_array('openregister', $installed, true) === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			return null;
		}
	}//end getObjectService()
}//end class
