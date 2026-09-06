<?php

/**
 * Dossiq NeedsInputDispatcher (StUF).
 *
 * Centralises the "needs input from beheerder" signal raised by the adapter
 * when it cannot proceed (circuit open, permanent error, timeout). The
 * dispatcher emits a structured log entry (the canonical machine surface) and
 * a Nextcloud notification to admins so the operator hears about it.
 *
 * Decoupling notes:
 *  - The notification side is best-effort. The log line is mandatory and
 *    drives ADR-031 crashes-to-needs-input.
 *  - We never throw from `dispatch`: a failing notification must not crash
 *    the originating workflow.
 *
 * This is the StUF-adapter admin-alert surface. It deliberately uses NC's
 * native INotificationManager (admin group) rather than dossiq's
 * NotificatieService, because NotificatieService is the ZGW NRC callback
 * publisher (kanaal/hoofdObject/resourceUrl semantics) — a different concern
 * from "alert the operator that an outbound StUF endpoint degraded".
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Stuf
 *
 * @author    Conduction <info@conduction.nl>
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
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-needs-input-escalation
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Stuf;

use DateTime;
use OCA\Dossiq\AppInfo\Application;
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Dispatches needs-input events for the StUF adapter.
 *
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-needs-input-escalation
 */
class NeedsInputDispatcher {
	/**
	 * Constructor.
	 *
	 * @param INotificationManager $notificationManager The notification manager.
	 * @param IGroupManager $groupManager The group manager (admin lookup).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private INotificationManager $notificationManager,
		private IGroupManager $groupManager,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Dispatch a needs-input event.
	 *
	 * @param string $type The event type slug (e.g. stuf_circuit_open, stuf_permanent_error, stuf_timeout).
	 * @param array<string,mixed> $context Structured context (endpointId, fout, etc.).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-needs-input-escalation
	 */
	public function dispatch(string $type, array $context = []): void {
		$this->logger->warning(
			message: 'StUF needs-input event: {type}',
			context: array_merge(['type' => $type, 'event' => 'stuf_needs_input'], $context)
		);

		try {
			$this->notifyAdmins(type: $type, context: $context);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: 'StUF needs-input notification failed: {error}',
				context: ['error' => $e->getMessage()]
			);
		}
	}//end dispatch()

	/**
	 * Push a notification to every member of the `admin` group.
	 *
	 * @param string $type The event type.
	 * @param array<string,mixed> $context The event context.
	 *
	 * @return void
	 */
	private function notifyAdmins(string $type, array $context): void {
		$admin = $this->groupManager->get(gid: 'admin');
		if ($admin === null) {
			return;
		}

		foreach ($admin->getUsers() as $user) {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(app: Application::APP_ID)
				->setUser(user: $user->getUID())
				->setDateTime(dateTime: new DateTime())
				->setObject(type: 'stuf_needs_input', id: ($context['endpointId'] ?? $type))
				->setSubject(subject: $type, parameters: ['endpointId' => (string)($context['endpointId'] ?? '')])
				->setMessage(message: 'dossiq_stuf_needs_input', parameters: $context);
			$this->notificationManager->notify(notification: $notification);
		}
	}//end notifyAdmins()
}//end class
