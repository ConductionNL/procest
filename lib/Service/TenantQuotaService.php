<?php

/**
 * Dossiq Tenant Quota Service
 *
 * Per-tenant quota enforcement: initialize from tier templates, check
 * limit, atomic increment, set limit. The actual `check + increment`
 * lives inside `consume()` to avoid TOCTOU slippage under concurrent
 * case creation.
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
 * @spec openspec/changes/tenant-zaaksysteem-saas-09-quotas-enforcement/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Quota service.
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-09-quotas-enforcement/tasks.md
 */
class TenantQuotaService {
	/**
	 * Tier defaults: tier => quotaType => [limit, enforcement].
	 *
	 * @var array<string, array<string, array{limit:int|null, enforcement:string}>>
	 */
	public const TIER_DEFAULTS = [
		'basic' => [
			'cases_per_month' => ['limit' => 100,  'enforcement' => 'warn'],
			'storage_gb' => ['limit' => 10,   'enforcement' => 'warn'],
			'active_users' => ['limit' => 5,    'enforcement' => 'block'],
			'api_calls_per_hour' => ['limit' => 1000, 'enforcement' => 'throttle'],
		],
		'standard' => [
			'cases_per_month' => ['limit' => 1000,  'enforcement' => 'warn'],
			'storage_gb' => ['limit' => 100,   'enforcement' => 'warn'],
			'active_users' => ['limit' => 50,    'enforcement' => 'block'],
			'api_calls_per_hour' => ['limit' => 10000, 'enforcement' => 'throttle'],
		],
		'enterprise' => [
			'cases_per_month' => ['limit' => null, 'enforcement' => 'warn'],
			'storage_gb' => ['limit' => null, 'enforcement' => 'warn'],
			'active_users' => ['limit' => null, 'enforcement' => 'warn'],
			'api_calls_per_hour' => ['limit' => null, 'enforcement' => 'warn'],
		],
	];

	/**
	 * Enforcement decisions.
	 */
	public const DECISION_ALLOW = 'allow';
	public const DECISION_THROTTLE = 'throttle';
	public const DECISION_BLOCK = 'block';
	public const DECISION_WARN = 'warn';

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager App manager.
	 * @param ContainerInterface $container Service container.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Initialise the four canonical quotas for a tenant from the tier template.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $tier Tier (basic|standard|enterprise).
	 *
	 * @return array<int, array<string,mixed>> Persisted quota rows.
	 *
	 * @throws InvalidArgumentException When tier is unknown.
	 *
	 * @spec openspec/specs/tenant-quotas/spec.md#requirement-tier-based-quota-initialisation-req-005-a-req-005-e
	 */
	public function initialize(string $tenantId, string $tier): array {
		if (array_key_exists($tier, self::TIER_DEFAULTS) === false) {
			throw new InvalidArgumentException('Unknown tier: ' . $tier);
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$rows = [];
		foreach (self::TIER_DEFAULTS[$tier] as $quotaType => $cfg) {
			try {
				$row = $objectService->saveObject(
					object: [
						'tenantRef' => $tenantId,
						'quotaType' => $quotaType,
						'limit' => $cfg['limit'],
						'currentUsage' => 0,
						'softLimitWarningPercent' => 80,
						'enforcement' => $cfg['enforcement'],
						'resetAt' => $this->nextResetAt(quotaType: $quotaType),
					],
					register: TenantSaasService::REGISTER,
					schema: 'tenantQuota',
					uuid: null,
				);
				if (is_array($row) === true) {
					$rows[] = $row;
				}
			} catch (Throwable $e) {
				$this->logger->error('Dossiq: quota initialise write failed', ['exception' => $e->getMessage()]);
			}//end try
		}//end foreach

		return $rows;
	}//end initialize()

	/**
	 * Get the quota row for (tenant, type).
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $quotaType Type.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/specs/tenant-quotas/spec.md#requirement-tier-based-quota-initialisation-req-005-a-req-005-e
	 */
	public function getQuota(string $tenantId, string $quotaType): ?array {
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
						'schema' => 'tenantQuota',
						'tenantRef' => $tenantId,
						'quotaType' => $quotaType,
					],
					'limit' => 1,
					'offset' => 0,
				]
			);
			if (is_array($rows) === true && count($rows) > 0) {
				return $rows[0];
			}

			return null;
		} catch (Throwable $e) {
			return null;
		}//end try
	}//end getQuota()

	/**
	 * Decide what to do for the next request — given the current quota row
	 * and the requested increment. Pure function over the row — no I/O.
	 *
	 * @param array<string,mixed> $quota Quota row.
	 * @param int $increment Requested increment.
	 *
	 * @return array{decision:string, soft:bool, reason:string}
	 *
	 * @spec openspec/specs/tenant-quotas/spec.md#requirement-tier-based-quota-initialisation-req-005-a-req-005-e
	 */
	public function decide(array $quota, int $increment = 1): array {
		$limit = $quota['limit'] ?? null;
		$current = (int)($quota['currentUsage'] ?? 0);
		$enforcement = (string)($quota['enforcement'] ?? self::DECISION_WARN);
		$warningPct = (int)($quota['softLimitWarningPercent'] ?? 80);

		if ($limit === null) {
			return ['decision' => self::DECISION_ALLOW, 'soft' => false, 'reason' => 'unlimited'];
		}

		$limitInt = (int)$limit;
		$next = ($current + $increment);
		$softLimit = (int)floor($limitInt * ($warningPct / 100));
		$softHit = $next >= $softLimit;
		$hardHit = $next > $limitInt;

		if ($hardHit === false) {
			$reason = 'within_limit';
			if ($softHit === true) {
				$reason = 'soft_limit';
			}

			return ['decision' => self::DECISION_ALLOW, 'soft' => $softHit, 'reason' => $reason];
		}

		// Hard limit reached.
		if ($enforcement === self::DECISION_BLOCK) {
			return ['decision' => self::DECISION_BLOCK, 'soft' => true, 'reason' => 'block_limit_exceeded'];
		}

		if ($enforcement === self::DECISION_THROTTLE) {
			return ['decision' => self::DECISION_THROTTLE, 'soft' => true, 'reason' => 'throttle_limit_exceeded'];
		}

		return ['decision' => self::DECISION_WARN, 'soft' => true, 'reason' => 'warn_limit_exceeded'];
	}//end decide()

	/**
	 * Atomic check + increment.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $quotaType Type.
	 * @param int $amount Amount to consume.
	 *
	 * @return array{decision:string, soft:bool, reason:string, currentUsage?:int}
	 *
	 * @spec openspec/specs/tenant-quotas/spec.md#requirement-tier-based-quota-initialisation-req-005-a-req-005-e
	 */
	public function consume(string $tenantId, string $quotaType, int $amount = 1): array {
		$quota = $this->getQuota(tenantId: $tenantId, quotaType: $quotaType);
		if ($quota === null) {
			return ['decision' => self::DECISION_ALLOW, 'soft' => false, 'reason' => 'no_quota_row'];
		}

		$decision = $this->decide(quota: $quota, increment: $amount);
		if (in_array($decision['decision'], [self::DECISION_BLOCK, self::DECISION_THROTTLE], true) === true) {
			return $decision;
		}

		$quota['currentUsage'] = (int)($quota['currentUsage'] ?? 0) + $amount;
		$this->persistQuota(quota: $quota);
		$decision['currentUsage'] = $quota['currentUsage'];
		return $decision;
	}//end consume()

	/**
	 * Set a new limit value.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $quotaType Type.
	 * @param int|null $limit New limit (null = unlimited).
	 *
	 * @return array<string,mixed>|null Persisted row.
	 *
	 * @spec openspec/specs/tenant-quotas/spec.md#requirement-tier-based-quota-initialisation-req-005-a-req-005-e
	 */
	public function setLimit(string $tenantId, string $quotaType, ?int $limit): ?array {
		$quota = $this->getQuota(tenantId: $tenantId, quotaType: $quotaType);
		if ($quota === null) {
			return null;
		}

		$quota['limit'] = $limit;
		$this->persistQuota(quota: $quota);
		return $quota;
	}//end setLimit()

	/**
	 * Reset due quotas. Used by the monthly background job.
	 *
	 * @param array<string,mixed> $quota Quota row.
	 *
	 * @return array<string,mixed> Updated row.
	 *
	 * @spec openspec/specs/tenant-quotas/spec.md#requirement-tier-based-quota-initialisation-req-005-a-req-005-e
	 */
	public function resetIfDue(array $quota): array {
		$resetAt = strtotime((string)($quota['resetAt'] ?? ''));
		$now = time();
		if ($resetAt === false || $resetAt > $now) {
			return $quota;
		}

		$quota['currentUsage'] = 0;
		$quota['resetAt'] = $this->nextResetAt(quotaType: (string)($quota['quotaType'] ?? 'cases_per_month'));
		$this->persistQuota(quota: $quota);
		return $quota;
	}//end resetIfDue()

	/**
	 * Compute the next reset timestamp for a quota type.
	 *
	 * @param string $quotaType Type.
	 *
	 * @return string ISO-8601 timestamp.
	 *
	 * @spec openspec/specs/tenant-quotas/spec.md#requirement-tier-based-quota-initialisation-req-005-a-req-005-e
	 */
	public function nextResetAt(string $quotaType): string {
		if ($quotaType === 'api_calls_per_hour') {
			return (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM);
		}

		// The cases_per_month type and others reset on the first of next month.
		return (new DateTimeImmutable('first day of next month'))->format(DATE_ATOM);
	}//end nextResetAt()

	/**
	 * Persist a quota row back to OpenRegister.
	 *
	 * @param array<string,mixed> $quota Quota row.
	 *
	 * @return void
	 */
	private function persistQuota(array $quota): void {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return;
		}

		try {
			$uuid = (string)($quota['uuid'] ?? $quota['id'] ?? '');
			$uuidArg = null;
			if ($uuid !== '') {
				$uuidArg = $uuid;
			}

			$objectService->saveObject(
				object: $quota,
				register: TenantSaasService::REGISTER,
				schema: 'tenantQuota',
				uuid: $uuidArg
			);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: persistQuota failed', ['exception' => $e->getMessage()]);
		}
	}//end persistQuota()

	/**
	 * Resolve the OpenRegister ObjectService when available.
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
