<?php

/**
 * Dossiq Termijn Notification Dispatch Job.
 *
 * Asynchronous QueuedJob that delivers a templated AWB termijn
 * notification (ontvangstbevestiging / extension / ingebrekestelling-
 * receipt / dwangsom-payment) outside the request thread, so SMTP /
 * berichtenbox-router failures never block burger-facing flows. The
 * synchronous {@see TermijnNotificationService::sendTermijnNotification}
 * remains the canonical render path; this job re-enters it from the
 * NC background-job runner.
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

use OCA\Dossiq\Service\TermijnNotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Asynchronous queued notification dispatcher.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
 */
class DeadlineNotificationDispatchJob extends QueuedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param TermijnNotificationService $notificationService Notification service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly TermijnNotificationService $notificationService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Execute one queued notification.
	 *
	 * @param mixed $argument Job argument; expects keys
	 *                        `type`, `termijnInstanceId`, `recipientUserId`, `context`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/burger-notifications/spec.md
	 */
	protected function run($argument): void {
		if (is_array($argument) === false) {
			$this->logger->warning('DeadlineNotificationDispatchJob: invalid argument');
			return;
		}

		$type = (string)($argument['type'] ?? '');
		$termInstanceId = (string)($argument['termijnInstanceId'] ?? '');
		$recipientUserId = (string)($argument['recipientUserId'] ?? '');
		$context = (array)($argument['context'] ?? []);

		if ($type === '' || $termInstanceId === '' || $recipientUserId === '') {
			$this->logger->warning('DeadlineNotificationDispatchJob: missing required argument keys', $argument);
			return;
		}

		try {
			$this->notificationService->sendTermijnNotification(
				$type,
				$termInstanceId,
				$recipientUserId,
				$context,
			);
			$this->logger->info(
				'DeadlineNotificationDispatchJob: delivered',
				['type' => $type, 'recipient' => $recipientUserId, 'instance' => $termInstanceId]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'DeadlineNotificationDispatchJob: delivery failed',
				['error' => $e->getMessage(), 'type' => $type, 'recipient' => $recipientUserId]
			);
		}
	}//end run()
}//end class
