<?php

/**
 * Dossiq Bottleneck Detection Job.
 *
 * Daily timed job that scans active cases for ones that have stalled past an
 * expected milestone deadline and notifies the assigned case worker so the
 * coordinator can intervene. Implements the milestone-tracking "bottleneck
 * detection" requirement: it flags cases lingering longer than expected on
 * their earliest unreached milestone.
 *
 * @category BackgroundJob
 * @package  OCA\Dossiq\BackgroundJob
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
 * @spec openspec/specs/milestone-tracking/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

use DateTime;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\MilestoneService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Daily timed job that detects milestone bottlenecks and notifies case workers.
 *
 * @spec openspec/specs/milestone-tracking/spec.md
 */
class BottleneckDetectionJob extends TimedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param MilestoneService $milestoneService The milestone service.
	 * @param IAppManager $appManager The app manager.
	 * @param INotificationManager $notificationManager The notification manager.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly MilestoneService $milestoneService,
		private readonly IAppManager $appManager,
		private readonly INotificationManager $notificationManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		// Daily; the underlying scan is idempotent (notifications are
		// de-duplicated per case+milestone by the notification framework).
		$this->setInterval(seconds: 86400);
	}//end __construct()

	/**
	 * Run the daily bottleneck detection sweep.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/milestone-tracking/spec.md
	 */
	protected function run($argument): void {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			return;
		}

		try {
			$stalled = $this->milestoneService->findStalledCases(thresholdDays: 0);
		} catch (\Throwable $e) {
			$this->logger->error(
				'BottleneckDetectionJob: scan failed: ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			return;
		}

		foreach ($stalled as $row) {
			$this->notifyStall(row: $row);
		}

		$this->logger->info(
			'BottleneckDetectionJob: ' . count($stalled) . ' stalled case(s) detected',
			['app' => Application::APP_ID],
		);
	}//end run()

	/**
	 * Send a bottleneck notification to the assigned case worker.
	 *
	 * @param array<string, mixed> $row One stalled-case row from the service.
	 *
	 * @return void
	 */
	private function notifyStall(array $row): void {
		$assignee = (string)($row['assignee'] ?? '');
		$caseId = (string)($row['caseId'] ?? '');
		if ($assignee === '' || $caseId === '') {
			return;
		}

		$label = (string)($row['milestoneLabel'] ?? '');
		$daysOverdue = (int)($row['daysOverdue'] ?? 0);

		try {
			$notification = $this->notificationManager->createNotification();
			$notification
				->setApp(Application::APP_ID)
				->setUser($assignee)
				->setDateTime(new DateTime())
				->setObject('case', $caseId)
				->setSubject(
					'milestone_bottleneck',
					[
						'milestone' => $label,
						'daysOverdue' => $daysOverdue,
					]
				)
				->setMessage(
					'plain',
					[
						'message' => 'Zaak wacht ' . $daysOverdue . ' dag(en) langer dan verwacht op mijlpaal "' . $label . '".',
					]
				);

			$this->notificationManager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->error(
				'BottleneckDetectionJob: failed to notify ' . $assignee . ': ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
		}//end try
	}//end notifyStall()
}//end class
