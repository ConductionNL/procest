<?php

/**
 * Dossiq SendEmailHandler
 *
 * Renders subject + body templates against the case and (in live mode)
 * dispatches the email via NotificatieService. In dry-run mode it returns
 * the rendered preview without contacting the mail subsystem.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Actions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Actions;

use OCA\Dossiq\AppInfo\Application;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Handler for `sendEmail` automatic actions.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class SendEmailHandler implements ActionHandlerInterface {
	use HandlesTemplates;

	/**
	 * Constructor for SendEmailHandler.
	 *
	 * @param ContainerInterface $container DI container — used to resolve
	 *                                      NotificatieService lazily (it is
	 *                                      not always available, e.g. during
	 *                                      dry-run unit tests).
	 * @param LoggerInterface $logger PSR-3 logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The action type slug handled by this handler.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function type(): string {
		return 'sendEmail';
	}//end type()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $actionConfig Resolved action config array.
	 * @param array $case The full case object.
	 * @param array $transitionContext Transition context (carries dryRun).
	 *
	 * @return ActionResult The outcome of sending the email.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult {
		try {
			$subject = $this->renderTemplate(
				template: (string)($actionConfig['subjectTemplate'] ?? ''),
				case: $case
			);
			$body = $this->renderTemplate(
				template: (string)($actionConfig['bodyTemplate'] ?? ''),
				case: $case
			);
			$recipient = $this->resolveRecipient(
				recipientRef: (string)($actionConfig['recipientRef'] ?? ''),
				case: $case
			);

			$preview = [
				'recipient' => $recipient,
				'subject' => $subject,
				'body' => $body,
			];

			if (($transitionContext['dryRun'] ?? false) === true) {
				return new ActionResult(succeeded: true, data: $preview);
			}

			if ($recipient === '') {
				return new ActionResult(succeeded: false, error: 'missing_recipient', data: $preview);
			}

			$notification = $this->resolveNotificationService();
			if ($notification === null) {
				return new ActionResult(succeeded: false, error: 'notificatie_unavailable', data: $preview);
			}

			// @phpstan-ignore-next-line — NotificatieService::sendEmail is
			// resolved lazily; signature is owned by the service itself.
			$notification->sendEmail($recipient, $subject, $body);

			return new ActionResult(succeeded: true, data: $preview);
		} catch (\Throwable $e) {
			$this->logger->error(
				'SendEmailHandler: failed to dispatch email',
				[
					'app' => Application::APP_ID,
					'slug' => (string)($actionConfig['slug'] ?? ''),
					'exception' => $e->getMessage(),
				]
			);
			return new ActionResult(succeeded: false, error: 'email_dispatch_failed');
		}//end try
	}//end handle()

	/**
	 * Resolve NotificatieService from the container without a hard dep.
	 *
	 * @return object|null
	 */
	private function resolveNotificationService(): ?object {
		try {
			return $this->container->get('OCA\Dossiq\Service\NotificatieService');
		} catch (\Throwable $e) {
			return null;
		}
	}//end resolveNotificatieService()
}//end class
