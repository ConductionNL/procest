<?php

/**
 * Dossiq Tenant SaaS Service
 *
 * Service that owns the SaaS-shape Tenant CRUD + lifecycle state machine,
 * backed by the seven OpenRegister schemas declared in chain member 01.
 *
 * Separate from the older `TenantService` (which wraps OR's Organisation
 * entity for single-tenant deployments). This service operates on the new
 * `tenant` register schema and implements the slugify + state-machine
 * primitives required by chain members 02-11.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-02-tenant-crud-lifecycle/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\App\IAppManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Tenant SaaS CRUD + lifecycle state machine backed by OR `tenant` schema.
 *
 * All persistence goes through OpenRegister's ObjectService — no bespoke
 * Doctrine entity. The state machine validates that only the documented
 * transitions are written.
 *
 * @spec openspec/specs/tenant-crud-lifecycle/spec.md
 */
class TenantSaasService {
	use SearchesObjects;

	/**
	 * Dossiq register slug.
	 */
	// FROZEN: OpenRegister register SLUG, not this app's id, and unchanged by
	// the procest -> dossiq rename.
	public const REGISTER = 'dossiq';

	/**
	 * Tenant schema slug.
	 */
	public const SCHEMA_TENANT = 'tenant';

	/**
	 * Legal lifecycle transitions: from-status => allowed to-statuses.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const LIFECYCLE_TRANSITIONS = [
		'onboarding' => ['active'],
		'active' => ['suspended', 'terminated'],
		'suspended' => ['active', 'terminated'],
		'terminated' => [],
	];

	/**
	 * Valid tier values.
	 */
	private const TIERS = ['basic', 'standard', 'enterprise'];

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager App manager (for OR availability check).
	 * @param ContainerInterface $container DI container (graceful OR resolution).
	 * @param LoggerInterface $logger Logger.
	 * @param TenantAuditTrailService $audit Tenant-stamped audit-trail emitter.
	 * @param IUserSession $userSession Current user session (audit actor).
	 */
	public function __construct(
		private IAppManager $appManager,
		private ContainerInterface $container,
		private LoggerInterface $logger,
		private TenantAuditTrailService $audit,
		private IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Emit a tenant-stamped audit-trail entry for a provisioning/status
	 * mutation. Backs the `audit_logged_mutations` hardening-checklist claim —
	 * every create/updateStatus writes an audit row (procest#223 finding 2:
	 * this was a false compliance attestation before the wiring landed).
	 *
	 * @param string $action Audit action verb.
	 * @param string $tenantId Tenant UUID.
	 * @param string $resource Affected resource description.
	 *
	 * @return void
	 */
	private function auditMutation(string $action, string $tenantId, string $resource): void {
		$user = $this->userSession->getUser();
		$actor = 'system';
		if ($user !== null) {
			$actor = $user->getUID();
		}

		$this->audit->emit(
			[
				'action' => $action,
				'actor' => $actor,
				'role' => 'tenant-admin',
				'resource' => $resource,
				'tenantId' => $tenantId,
			]
		);
	}//end auditMutation()

	/**
	 * Create a new tenant in `onboarding` status.
	 *
	 * @param string $name Display name; also drives slug generation.
	 * @param string $kvkNumber KvK (Chamber of Commerce) number.
	 * @param string $tier Tier (basic|standard|enterprise).
	 *
	 * @return array<string,mixed> The persisted tenant row.
	 *
	 * @throws InvalidArgumentException On invalid tier or duplicate slug.
	 * @throws RuntimeException When OpenRegister is unavailable.
	 *
	 * @spec openspec/specs/tenant-crud-lifecycle/spec.md
	 */
	public function create(string $name, string $kvkNumber, string $tier): array {
		if (in_array($tier, self::TIERS, true) === false) {
			throw new InvalidArgumentException('Invalid tier: ' . $tier);
		}

		$slug = $this->slugify(name: $name);
		if ($this->slugExists(slug: $slug) === true) {
			throw new InvalidArgumentException('Slug already exists: ' . $slug);
		}

		$tenant = [
			'slug' => $slug,
			'displayName' => $name,
			'kvkNumber' => $kvkNumber,
			'status' => 'onboarding',
			'tier' => $tier,
			'createdAt' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
		];

		$saved = $this->saveTenant(tenant: $tenant, uuid: null);
		$tenantId = (string)($saved['id'] ?? $saved['uuid'] ?? $slug);
		$this->auditMutation(action: 'tenant.provisioned', tenantId: $tenantId, resource: 'tenant:' . $slug);
		return $saved;
	}//end create()

	/**
	 * Fetch a tenant by UUID.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return array<string,mixed>|null Persisted tenant or null when missing.
	 *
	 * @spec openspec/specs/tenant-crud-lifecycle/spec.md#requirement-tenant-crud-api-req-001-a-api
	 */
	public function getById(string $tenantId): ?array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$row = $this->findObjectAsArray(objectService: $objectService, register: self::REGISTER, schema: self::SCHEMA_TENANT, id: $tenantId);
			if (is_array($row) === true) {
				return $row;
			}

			return null;
		} catch (Throwable $e) {
			$this->logger->info('Dossiq: TenantSaasService::getById miss', ['tenantId' => $tenantId, 'exception' => $e->getMessage()]);
			return null;
		}
	}//end getById()

	/**
	 * List tenants, optionally filtered by status.
	 *
	 * @param string|null $statusFilter Optional status enum value.
	 * @param int $limit Page size (default 100).
	 * @param int $offset Page offset.
	 *
	 * @return array<int, array<string,mixed>> Tenant rows.
	 *
	 * @spec openspec/specs/tenant-crud-lifecycle/spec.md#requirement-tenant-crud-api-req-001-a-api
	 */
	public function listActive(?string $statusFilter = null, int $limit = 100, int $offset = 0): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$filters = [];
		if ($statusFilter !== null && $statusFilter !== '') {
			$filters['status'] = $statusFilter;
		}

		try {
			// ObjectService::findAll() takes a single $config array — the previous
			// named-argument form threw "Unknown named parameter $register" and
			// was swallowed by the catch below. Register/schema are read from
			// inside `filters`; limit/offset are top-level config keys.
			$rows = $objectService->findAll(
				[
					'filters' => array_merge(
						[
							'register' => self::REGISTER,
							'schema' => self::SCHEMA_TENANT,
						],
						$filters
					),
					'limit' => $limit,
					'offset' => $offset,
				]
			);
			if (is_array($rows) === true) {
				return array_values($rows);
			}

			return [];
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: TenantSaasService::listActive failed', ['exception' => $e->getMessage()]);
			return [];
		}//end try
	}//end listActive()

	/**
	 * Update a tenant's status, validating the transition against the state machine.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $newStatus Target status enum value.
	 *
	 * @return array<string,mixed> Persisted tenant row.
	 *
	 * @throws InvalidArgumentException On illegal transition or missing tenant.
	 * @throws RuntimeException When OpenRegister is unavailable.
	 *
	 * @spec openspec/specs/tenant-crud-lifecycle/spec.md
	 */
	public function updateStatus(string $tenantId, string $newStatus): array {
		$row = $this->getById(tenantId: $tenantId);
		if ($row === null) {
			throw new InvalidArgumentException('Tenant not found: ' . $tenantId);
		}

		$current = (string)($row['status'] ?? '');
		$this->assertLegalTransition(current: $current, target: $newStatus);

		$row['status'] = $newStatus;
		if ($newStatus === 'active' && empty($row['activatedAt']) === true) {
			$row['activatedAt'] = (new DateTimeImmutable('now'))->format(DATE_ATOM);
		}

		if ($newStatus === 'terminated' && empty($row['terminatedAt']) === true) {
			$row['terminatedAt'] = (new DateTimeImmutable('now'))->format(DATE_ATOM);
		}

		$saved = $this->saveTenant(tenant: $row, uuid: $tenantId);
		$this->auditMutation(
			action: 'tenant.status_changed',
			tenantId: $tenantId,
			resource: 'tenant:' . $tenantId . ' ' . $current . '->' . $newStatus
		);
		return $saved;
	}//end updateStatus()

	/**
	 * Delete a tenant (hard delete via OR's `deleteObject`).
	 *
	 * Caller must enforce the business rule that only `terminated` tenants can be
	 * physically deleted; the state machine prevents direct delete of active rows.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return bool True when deletion succeeded.
	 *
	 * @spec openspec/specs/tenant-crud-lifecycle/spec.md#requirement-tenant-lifecycle-state-machine-req-001-a-lifecycle
	 */
	public function delete(string $tenantId): bool {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return false;
		}

		try {
			$objectService->deleteObject(uuid: $tenantId, register: self::REGISTER, schema: self::SCHEMA_TENANT);
			return true;
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: TenantSaasService::delete failed', ['tenantId' => $tenantId, 'exception' => $e->getMessage()]);
			return false;
		}
	}//end delete()

	/**
	 * Generate a URL-safe tenant slug from a human-readable name.
	 *
	 * Lowercased, non-alphanumerics collapsed to single hyphens, trimmed,
	 * max 64 chars.
	 *
	 * @param string $name Display name.
	 *
	 * @return string Slug.
	 *
	 * @spec openspec/specs/tenant-crud-lifecycle/spec.md#requirement-tenant-lifecycle-state-machine-req-001-a-lifecycle
	 */
	public function slugify(string $name): string {
		$lower = mb_strtolower(trim($name), 'UTF-8');
		// Unicode-aware: replace any non-letter/non-digit run with a hyphen.
		$rep = preg_replace('/[^\p{L}\p{N}]+/u', '-', $lower);
		$rep = (string)$rep;
		$rep = trim($rep, '-');

		if (mb_strlen($rep, 'UTF-8') > 64) {
			$rep = mb_substr($rep, 0, 64, 'UTF-8');
			$rep = trim($rep, '-');
		}

		return $rep;
	}//end slugify()

	/**
	 * Validate a lifecycle transition.
	 *
	 * @param string $current Current status.
	 * @param string $target Target status.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the transition is illegal.
	 *
	 * @spec openspec/specs/tenant-crud-lifecycle/spec.md#requirement-tenant-lifecycle-state-machine-req-001-a-lifecycle
	 */
	public function assertLegalTransition(string $current, string $target): void {
		if (array_key_exists($current, self::LIFECYCLE_TRANSITIONS) === false) {
			throw new InvalidArgumentException('Unknown current status: ' . $current);
		}

		if ($current === $target) {
			throw new InvalidArgumentException('No-op transition: ' . $current);
		}

		if (in_array($target, self::LIFECYCLE_TRANSITIONS[$current], true) === false) {
			throw new InvalidArgumentException(
				'Illegal lifecycle transition: ' . $current . ' → ' . $target
			);
		}
	}//end assertLegalTransition()

	/**
	 * Return the full lifecycle transition graph (for tests / introspection).
	 *
	 * @return array<string, array<int, string>>
	 *
	 * @spec openspec/specs/tenant-crud-lifecycle/spec.md#requirement-tenant-lifecycle-state-machine-req-001-a-lifecycle
	 */
	public function getLifecycleGraph(): array {
		return self::LIFECYCLE_TRANSITIONS;
	}//end getLifecycleGraph()

	/**
	 * Check whether a slug is already taken.
	 *
	 * @param string $slug Candidate slug.
	 *
	 * @return bool True when an existing tenant uses the slug.
	 *
	 * @spec openspec/specs/tenant-crud-lifecycle/spec.md#requirement-tenant-creation-with-unique-slug-req-001-a
	 */
	public function slugExists(string $slug): bool {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return false;
		}

		try {
			// ObjectService::findAll() takes a single $config array — see the
			// note in listActive(); register/schema live inside `filters`.
			$rows = $objectService->findAll(
				[
					'filters' => [
						'register' => self::REGISTER,
						'schema' => self::SCHEMA_TENANT,
						'slug' => $slug,
					],
					'limit' => 1,
					'offset' => 0,
				]
			);
			return is_array($rows) && count($rows) > 0;
		} catch (Throwable $e) {
			$this->logger->info('Dossiq: slugExists lookup failed', ['slug' => $slug, 'exception' => $e->getMessage()]);
			return false;
		}
	}//end slugExists()

	/**
	 * Persist a tenant row via OR's ObjectService.
	 *
	 * @param array<string,mixed> $tenant Tenant payload.
	 * @param string|null $uuid Optional existing UUID (update path).
	 *
	 * @return array<string,mixed> Persisted tenant row.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 *
	 * @spec openspec/specs/tenant-crud-lifecycle/spec.md
	 */
	protected function saveTenant(array $tenant, ?string $uuid): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		try {
			$row = $objectService->saveObject(
				object: $tenant,
				register: self::REGISTER,
				schema: self::SCHEMA_TENANT,
				uuid: $uuid,
			);
			if (is_array($row) === true) {
				return $row;
			}

			return $tenant;
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: TenantSaasService::saveTenant failed',
				['exception' => $e->getMessage()]
			);
			throw new RuntimeException('Failed to persist tenant: ' . $e->getMessage(), 0, $e);
		}
	}//end saveTenant()

	/**
	 * Resolve OR's ObjectService when installed.
	 *
	 * @return mixed The ObjectService instance or null.
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
			$this->logger->error('Dossiq: Could not resolve ObjectService', ['exception' => $e->getMessage()]);
			return null;
		}
	}//end getObjectService()
}//end class
