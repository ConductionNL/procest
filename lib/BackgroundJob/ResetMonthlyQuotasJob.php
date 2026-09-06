<?php

/**
 * Dossiq Reset Monthly Quotas Job.
 *
 * Daily background job that scans tenant quotas and resets those whose
 * `resetAt` window has elapsed.
 *
 * @category BackgroundJob
 * @package  OCA\Dossiq\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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

namespace OCA\Dossiq\BackgroundJob;

use OCA\Dossiq\Service\TenantQuotaService;
use OCA\Dossiq\Service\TenantSaasService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resets monthly + hourly quotas after their window elapses.
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-09-quotas-enforcement/tasks.md
 */
class ResetMonthlyQuotasJob extends TimedJob {
	/**
	 * Interval — once per day.
	 */
	private const INTERVAL_SECONDS = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param TenantQuotaService $quotaService Tenant quota service.
	 * @param IAppManager $appManager App manager.
	 * @param ContainerInterface $container Service container.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly TenantQuotaService $quotaService,
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Reset monthly quotas for all tenants when their period is due.
	 *
	 * @param mixed $argument Job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is fixed by
	 * OCP\BackgroundJob\TimedJob::run(); this job takes no arguments.
	 *
	 * @spec exclude phpstan dead-code cleanup only — normalised the IAppManager return and
	 *       dropped the resulting always-false `is_array()` guard; no behavioural change.
	 */
	protected function run($argument): void {
		// IAppManager::getInstalledApps() declares its array return in PHPDoc
		// only, so normalise defensively before the membership test.
		$installed = (array)$this->appManager->getInstalledApps();
		if (in_array('openregister', $installed, true) === false) {
			return;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->info('Dossiq: ResetMonthlyQuotasJob — OR ObjectService unavailable');
			return;
		}

		try {
			// ObjectService::findAll() takes a single $config array — the previous
			// named-argument form (register:/schema:/limit:/offset:) threw
			// "Unknown named parameter $register" and was swallowed by the catch
			// below, so this job never reset a single quota. Register/schema are
			// read from inside `filters`; limit/offset are top-level config keys.
			$rows = $objectService->findAll(
				[
					'filters' => [
						'register' => TenantSaasService::REGISTER,
						'schema' => 'tenantQuota',
					],
					'limit' => 1000,
					'offset' => 0,
				]
			);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: ResetMonthlyQuotasJob fetch failed', ['exception' => $e->getMessage()]);
			return;
		}

		if (is_array($rows) === false) {
			return;
		}

		$resetCount = 0;
		foreach ($rows as $row) {
			$before = (int)($row['currentUsage'] ?? 0);
			$after = $this->quotaService->resetIfDue($row);
			if ((int)($after['currentUsage'] ?? 0) === 0 && $before > 0) {
				$resetCount++;
			}
		}

		if ($resetCount > 0) {
			$this->logger->info('Dossiq: ResetMonthlyQuotasJob reset ' . $resetCount . ' quotas');
		}
	}//end run()
}//end class
