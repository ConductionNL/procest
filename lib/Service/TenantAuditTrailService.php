<?php

/**
 * Dossiq Tenant Audit Trail Service
 *
 * Emits tenant-stamped audit-trail entries for every mutating action so
 * REQ-010 (compliance — tenant-stamped audit log on data access) is met.
 *
 * Entries carry: action, actor (NC user ID), role, resource, ts, ip, ua,
 * tenant_id, and (when available) enterprise BIO context (deviceId,
 * geoLocation, mfaVerified, sessionDuration).
 *
 * Persistence is OpenRegister's hash-chained, natively-immutable audit trail
 * (ADR-022 / consume-or-audit-trail-fleet-wide). Before 2026-07-16 `emit()` only wrote
 * an INFO log line, while `hardeningChecklist()` attested that every mandate,
 * status and provisioning mutation "emits an audit entry" (procest#223 finding
 * 1). A log line is not a durable, queryable, tamper-evident audit record, so
 * that attestation asserted a control the app did not implement. `emit()` now
 * writes a real audit row and reports whether it landed; the checklist derives
 * its status from the live sink and FAILS CLOSED to `unverified` when the sink
 * is unavailable, rather than claiming a control it cannot back.
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
 * @spec openspec/changes/tenant-zaaksysteem-saas-12-isolation-tests-compliance/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tenant-stamped audit-trail emitter.
 *
 * @spec openspec/specs/tenant-compliance/spec.md
 */
class TenantAuditTrailService {
	/**
	 * OpenRegister register + schema holding tenant objects. An audit row is
	 * anchored to the tenant ObjectEntity it concerns.
	 */
	// FROZEN: the OpenRegister register SLUG, not this app's id. OR resolves
	// registers by slug, so renaming it with the app id would address a
	// register that does not exist and every durable audit row would fail to
	// be written — a compliance surface going quiet, not erroring.
	private const REGISTER = 'dossiq';

	/**
	 * Schema slug for tenant objects.
	 */
	private const SCHEMA_TENANT = 'tenant';

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger (SIEM stream; NOT the audit sink of record).
	 * @param IAppManager $appManager App manager (OpenRegister availability check).
	 * @param ContainerInterface $container DI container (graceful OR resolution).
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Emit an audit-trail entry: write one hash-chained OpenRegister audit row
	 * anchored to the tenant ObjectEntity, and mirror it to the log for SIEM
	 * ingestion. Returns the normalised entry, including a `persisted` flag
	 * reporting whether the durable row actually landed.
	 *
	 * Audit-write failures are swallowed — a failed audit MUST NOT break the
	 * mutation the caller is performing — but they are reported truthfully via
	 * `persisted:false` and an error log, and they turn the hardening
	 * checklist's `audit_logged_mutations` claim to `unverified` (fail-closed).
	 *
	 * Payload keys: action (string), actor (string), role (?string),
	 * resource (?string), tenantId (string), ip (?string), ua (?string),
	 * bio (?array<string, mixed>).
	 *
	 * @param array<string, mixed> $payload Audit payload.
	 *
	 * @return array<string,mixed> Normalised entry (with `persisted`).
	 *
	 * @spec openspec/specs/tenant-compliance/spec.md
	 */
	public function emit(array $payload): array {
		$entry = [
			'ts' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
			'action' => (string)($payload['action'] ?? ''),
			'actor' => (string)($payload['actor'] ?? ''),
			'role' => (string)($payload['role'] ?? ''),
			'resource' => (string)($payload['resource'] ?? ''),
			'tenantId' => (string)($payload['tenantId'] ?? ''),
			'ip' => (string)($payload['ip'] ?? ''),
			'ua' => (string)($payload['ua'] ?? ''),
			'bio' => $this->sanitiseBio(bio: (array)($payload['bio'] ?? [])),
		];

		$entry['persisted'] = $this->persist(entry: $entry);

		$this->logger->info('Dossiq AUDIT', $entry);
		return $entry;
	}//end emit()

	/**
	 * Write the entry to OpenRegister's hash-chained audit trail, anchored to
	 * the tenant ObjectEntity named by the payload.
	 *
	 * @param array<string, mixed> $entry Normalised audit entry.
	 *
	 * @return bool True when a durable audit row was written.
	 */
	private function persist(array $entry): bool {
		$tenantId = (string)$entry['tenantId'];
		if ($tenantId === '') {
			$this->logger->error('Dossiq AUDIT: no tenantId — durable audit row NOT written', $entry);
			return false;
		}

		try {
			$mapper = $this->getAuditTrailMapper();
			$object = $this->resolveTenantEntity(tenantId: $tenantId);
			if ($mapper === null || $object === null) {
				$this->logger->error(
					'Dossiq AUDIT: OpenRegister audit sink unavailable — durable audit row NOT written',
					$entry
				);
				return false;
			}

			$mapper->createAuditTrailEntry(
				object: $object,
				// FROZEN PREFIX — still `procest.`, deliberately. Written into
				// OpenRegister's append-only audit trail; every existing tenant
				// audit row already carries it and readers filter on it, so a
				// rename splits the trail and shows a partial history as though
				// it were complete.
				action: 'procest.tenant.' . $entry['action'],
				context: $entry,
			);
			return true;
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq AUDIT: durable audit row failed',
				['exception' => $e->getMessage()] + $entry
			);
			return false;
		}//end try
	}//end persist()

	/**
	 * Report whether the durable audit sink is currently resolvable. Backs the
	 * honest `audit_logged_mutations` checklist status — this is a live probe,
	 * not a static claim.
	 *
	 * @return bool True when OpenRegister's audit trail can be written to.
	 *
	 * @spec openspec/specs/tenant-compliance/spec.md
	 */
	public function auditSinkAvailable(): bool {
		return $this->getAuditTrailMapper() !== null;
	}//end auditSinkAvailable()

	/**
	 * Resolve OpenRegister's AuditTrailMapper, or null when OR is unavailable.
	 *
	 * @return mixed The mapper, or null.
	 */
	private function getAuditTrailMapper(): mixed {
		// IAppManager::getInstalledApps() declares its array return in PHPDoc
		// only, so normalise defensively before the membership test.
		$installed = (array)$this->appManager->getInstalledApps();
		if (in_array('openregister', $installed, true) === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\\OpenRegister\\Db\\AuditTrailMapper');
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: could not resolve AuditTrailMapper', ['exception' => $e->getMessage()]);
			return null;
		}
	}//end getAuditTrailMapper()

	/**
	 * Resolve the tenant ObjectEntity an audit row anchors to.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return mixed The ObjectEntity, or null.
	 */
	private function resolveTenantEntity(string $tenantId): mixed {
		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			return $objectService->find($tenantId, register: self::REGISTER, schema: self::SCHEMA_TENANT);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq AUDIT: could not resolve tenant ObjectEntity',
				['tenantId' => $tenantId, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end resolveTenantEntity()

	/**
	 * Whitelist enterprise BIO context fields. Drops anything we don't
	 * recognise to keep the audit shape stable.
	 *
	 * @param array<string, mixed> $bio Raw BIO context.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/tenant-compliance/spec.md
	 */
	public function sanitiseBio(array $bio): array {
		$out = [];
		foreach (['deviceId', 'geoLocation', 'mfaVerified', 'sessionDuration'] as $field) {
			if (array_key_exists($field, $bio) === true) {
				$out[$field] = $bio[$field];
			}
		}

		return $out;
	}//end sanitiseBio()

	/**
	 * Compile the security-hardening checklist used by the chain-member-12
	 * compliance audit.
	 *
	 * HONESTY CONTRACT (procest#223 finding 1): this checklist is a compliance
	 * attestation for a government system, so it MUST NOT assert a control the
	 * app cannot back. Every entry therefore carries an explicit `status`:
	 *
	 * - `pass`       — the control is implemented AND verified here or by a named gate.
	 * - `unverified` — the control is claimed by design but not proven at runtime;
	 *                  it is NOT an assertion of compliance.
	 *
	 * `audit_logged_mutations` is probed LIVE against the durable audit sink and
	 * fails closed to `unverified` when OpenRegister's audit trail is
	 * unreachable — previously it hardcoded a pass while `emit()` wrote nothing
	 * but a log line.
	 *
	 * @return array<int, array{key:string, description:string, evidence:string, status:string}>
	 *
	 * @spec openspec/specs/tenant-compliance/spec.md
	 */
	public function hardeningChecklist(): array {
		// Live probe — never a hardcoded pass.
		$auditStatus = 'unverified';
		if ($this->auditSinkAvailable() === true) {
			$auditStatus = 'pass';
		}

		return [
			[
				'key' => 'tenant_scoped_queries',
				'description' => 'Every query carries the request-scoped tenant filter',
				'evidence' => 'TenantIsolationMiddleware sets the Postgres search_path; TenantContext carries the active tenant',
				'status' => 'pass',
			],
			[
				'key' => 'claim_validation',
				'description' => 'JWT tenant_id claim is cross-checked against the request tenant',
				'evidence' => 'TenantClaimValidationMiddleware',
				'status' => 'pass',
			],
			[
				'key' => 'audit_logged_mutations',
				'description' => 'Mandate decisions, tenant provisioning, and tenant status changes each write a hash-chained OpenRegister audit row',
				'evidence' => 'TenantAuditTrailService::emit -> AuditTrailMapper::createAuditTrailEntry '
					. '(probed live); MandateValidationMiddleware::logDecision; '
					. 'TenantSaasService::create/updateStatus',
				'status' => $auditStatus,
			],
			[
				'key' => 'no_hardcoded_secrets',
				'description' => 'JWT signing secret + Shillinq credentials resolved from app config',
				'evidence' => 'Application.php registerService factory for TenantJwtService + ShillinqIntegrationService',
				'status' => 'pass',
			],
			[
				'key' => 'no_tenant_info_leak',
				'description' => 'Cross-tenant queries return 404 (not 403) to prevent existence leak',
				'evidence' => 'TenantIsolationMiddleware search_path scoping + controller-level 404 responses',
				'status' => 'pass',
			],
			[
				'key' => 'composer_audit',
				'description' => 'composer audit passes with zero high-severity CVEs',
				'evidence' => 'hydra-gate-composer-audit (Hydra gate 4)',
				'status' => 'pass',
			],
			[
				'key' => 'isolation_pen_test',
				'description' => 'Cross-tenant pen-test asserts schema isolation under DDL + DQL',
				'evidence' => 'Deferred to a live-OR fixture; no automated pen-test executes today',
				'status' => 'unverified',
			],
		];
	}//end hardeningChecklist()
}//end class
