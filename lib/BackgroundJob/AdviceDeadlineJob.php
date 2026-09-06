<?php

/**
 * Dossiq Advice Deadline Job.
 *
 * Daily background job that processes advice request deadlines:
 * sends reminders 3 days before deadline and transitions overdue
 * advice requests to status `verlopen`.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/advice-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

use DateTimeImmutable;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\AdviceService;
use OCA\Dossiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily timed job that processes advice request deadlines and reminders.
 *
 * @spec openspec/specs/advice-management/spec.md
 */
class AdviceDeadlineJob extends TimedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory
	 * @param AdviceService $adviceService The advice service
	 * @param SettingsService $settingsService The settings service
	 * @param IAppManager $appManager The app manager
	 * @param LoggerInterface $logger The logger
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly AdviceService $adviceService,
		private readonly SettingsService $settingsService,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		// Daily.
		$this->setInterval(seconds: 86400);
	}//end __construct()

	/**
	 * Run the advice deadline processing.
	 *
	 * @param mixed $argument The job argument
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	protected function run($argument): void {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			return;
		}

		$reminderDays = (int)($this->settingsService->getConfigValue('advice_reminder_days', '3'));
		if ($reminderDays <= 0) {
			$reminderDays = 3;
		}

		$today = new DateTimeImmutable('today');
		$reminderOn = $today->modify('+' . $reminderDays . ' days')->format('Y-m-d');
		$todayStr = $today->format('Y-m-d');

		$this->logger->info(
			'Dossiq: running advice deadline job (today=' . $todayStr . ', reminderOn=' . $reminderOn . ')',
			['app' => Application::APP_ID],
		);

		$open = $this->adviceService->getOpenAdvice();

		foreach ($open as $advice) {
			$deadline = (string)($advice['deadline'] ?? '');
			if ($deadline === '') {
				continue;
			}

			$deadlineDate = substr($deadline, 0, 10);
			$adviceId = (string)($advice['uuid'] ?? ($advice['id'] ?? ''));
			if ($adviceId === '') {
				continue;
			}

			if ($deadlineDate < $todayStr) {
				$this->adviceService->expireAdvice($adviceId);
				$this->logger->info(
					'Dossiq: advice request expired',
					[
						'app' => Application::APP_ID,
						'adviceId' => $adviceId,
					],
				);
				continue;
			}

			if ($deadlineDate === $reminderOn) {
				$this->adviceService->dispatchReminder($adviceId);
				$this->logger->info(
					'Dossiq: advice reminder dispatched',
					[
						'app' => Application::APP_ID,
						'adviceId' => $adviceId,
					],
				);
			}
		}//end foreach
	}//end run()
}//end class
