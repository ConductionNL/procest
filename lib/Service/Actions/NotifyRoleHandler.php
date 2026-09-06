<?php

/**
 * Dossiq NotifyRoleHandler
 *
 * Resolves a role slug to its members and emits an in-app Nextcloud
 * notification to each. In dry-run mode it returns the resolved recipient
 * list and rendered message without queuing any notifications.
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
 * Handler for `notifyRole` automatic actions.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class NotifyRoleHandler implements ActionHandlerInterface {
	use HandlesTemplates;

	/**
	 * Constructor for NotifyRoleHandler.
	 *
	 * @param ContainerInterface $container DI container — used to resolve
	 *                                      NotificatieService lazily.
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
		return 'notifyRole';
	}//end type()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $actionConfig Resolved action config array.
	 * @param array $case The full case object.
	 * @param array $transitionContext Transition context (carries dryRun).
	 *
	 * @return ActionResult The outcome of the role notification.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult {
		try {
			$roleSlug = (string)($actionConfig['roleSlug'] ?? '');
			$message = $this->renderTemplate(
				template: (string)($actionConfig['messageTemplate'] ?? ''),
				case: $case
			);

			$recipients = $this->resolveRoleMembers(roleSlug: $roleSlug, case: $case);
			$preview = [
				'roleSlug' => $roleSlug,
				'recipients' => $recipients,
				'message' => $message,
			];

			if (($transitionContext['dryRun'] ?? false) === true) {
				return new ActionResult(succeeded: true, data: $preview);
			}

			if ($roleSlug === '' || $recipients === []) {
				return new ActionResult(succeeded: false, error: 'no_recipients', data: $preview);
			}

			$notification = $this->resolveNotificationService();
			if ($notification === null) {
				return new ActionResult(succeeded: false, error: 'notificatie_unavailable', data: $preview);
			}

			foreach ($recipients as $userId) {
				if (method_exists($notification, 'notifyUser') === true) {
					// @phpstan-ignore-next-line — signature owned by service.
					$notification->notifyUser($userId, $message);
				}
			}

			return new ActionResult(succeeded: true, data: $preview);
		} catch (\Throwable $e) {
			$this->logger->error(
				'NotifyRoleHandler: failed to dispatch notification',
				[
					'app' => Application::APP_ID,
					'slug' => (string)($actionConfig['slug'] ?? ''),
					'exception' => $e->getMessage(),
				]
			);
			return new ActionResult(succeeded: false, error: 'notify_role_failed');
		}//end try
	}//end handle()

	/**
	 * Resolve a role slug to a list of user identifiers on the case.
	 *
	 * V1 strategy: look up `case.<roleSlug>` for a single user, or
	 * `case.<roleSlug>Members[]` for a collection. RoleResolverService will
	 * supersede this lookup once role-based-step-routing lands.
	 *
	 * @param string $roleSlug Role slug.
	 * @param array $case Case object.
	 *
	 * @return array<int, string>
	 */
	private function resolveRoleMembers(string $roleSlug, array $case): array {
		if ($roleSlug === '') {
			return [];
		}

		$singleId = $this->memberId(member: ($case[$roleSlug] ?? null));
		if ($singleId !== '') {
			return [$singleId];
		}

		$multi = ($case[$roleSlug . 'Members'] ?? null);
		if (is_array($multi) === false) {
			return [];
		}

		$out = [];
		foreach ($multi as $member) {
			$memberId = $this->memberId(member: $member);
			if ($memberId !== '') {
				$out[] = $memberId;
			}
		}

		return $out;
	}//end resolveRoleMembers()

	/**
	 * Read a user identifier off a role member, which may be a bare uid
	 * string or an object with an `id` / `userId` key.
	 *
	 * @param mixed $member A single role member entry.
	 *
	 * @return string The user identifier, or empty string when unreadable.
	 */
	private function memberId(mixed $member): string {
		if (is_string($member) === true) {
			return $member;
		}

		if (is_array($member) === false) {
			return '';
		}

		return (string)($member['id'] ?? ($member['userId'] ?? ''));
	}//end memberId()

	/**
	 * Resolve NotificatieService lazily.
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
