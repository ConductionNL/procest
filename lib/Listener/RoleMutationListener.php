<?php

/**
 * Dossiq Role Mutation Listener
 *
 * Listens to OpenRegister object lifecycle events on the `role` schema and
 * invalidates the RoleResolverService cache for the affected case. Role
 * mutation drives the assignee recomputation requirement of the
 * role-based-step-routing spec.
 *
 * Eager recomputation of `case.assignedTo` is intentionally NOT performed
 * here: callers (task list, transition engine) hit the resolver lazily and
 * benefit from the freshly invalidated cache.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\RoleResolverService;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Invalidates resolver cache when role records mutate.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/role-based-step-routing/tasks.md#T04
 */
class RoleMutationListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param RoleResolverService $resolver Resolver to invalidate
	 * @param SettingsService $settingsService Schema slug bridge
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly RoleResolverService $resolver,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an OpenRegister object lifecycle event.
	 *
	 * Only role-schema events are honoured. The case id is extracted from
	 * the object payload and the resolver cache is flushed.
	 *
	 * @param Event $event The dispatched event
	 *
	 * @return void
	 *
	 * @spec openspec/specs/role-based-step-routing/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatedEvent === false
			&& $event instanceof ObjectUpdatedEvent === false
			&& $event instanceof ObjectDeletedEvent === false
		) {
			return;
		}

		try {
			$object = $this->extractObject(event: $event);
			if ($object === null) {
				return;
			}

			if ($this->isRoleSchema(object: $object) === false) {
				return;
			}

			$caseId = (string)($object['case'] ?? '');
			if ($caseId === '') {
				return;
			}

			$this->resolver->invalidateCache($caseId);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq: role mutation listener swallowed exception: ' . $e->getMessage(),
			);
		}//end try
	}//end handle()

	/**
	 * Whether the given object belongs to the `role` schema.
	 *
	 * @param array<string, mixed> $object The object payload
	 *
	 * @return bool
	 */
	private function isRoleSchema(array $object): bool {
		$schemaSlug = $this->settingsService->getConfigValue('role_schema');
		if ($schemaSlug === '') {
			return false;
		}

		$candidate = (string)(
			$object['@self']['schema'] ?? ($object['schema'] ?? '')
		);

		return $candidate !== '' && (
			$candidate === $schemaSlug
			|| str_ends_with($candidate, '/' . $schemaSlug)
		);
	}//end isRoleSchema()

	/**
	 * Extract the object payload from an event.
	 *
	 * @param Event $event The event
	 *
	 * @return array<string, mixed>|null
	 */
	private function extractObject(Event $event): ?array {
		foreach (['getObject', 'getNewObject', 'getOldObject'] as $method) {
			if (method_exists($event, $method) === false) {
				continue;
			}

			$value = $event->{$method}();
			if (is_array($value) === true) {
				return $value;
			}

			if (is_object($value) === true) {
				if (method_exists($value, 'jsonSerialize') === true) {
					$serialised = $value->jsonSerialize();
					if (is_array($serialised) === true) {
						return $serialised;
					}
				}

				if (method_exists($value, 'toArray') === true) {
					$arr = $value->toArray();
					if (is_array($arr) === true) {
						return $arr;
					}
				}
			}
		}//end foreach

		return null;
	}//end extractObject()
}//end class
