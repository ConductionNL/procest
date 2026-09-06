<?php

/**
 * Dossiq Bezwaar Lifecycle Listener
 *
 * Observes OR object events on the bezwaar/hearingSession/advisoryReport/decision
 * schemas and mirrors lifecycle-relevant signals onto the linked dossiq case
 * via the status-transition-engine. Pure observer — does NOT bypass the engine
 * with bespoke case-mutation logic. All side effects route through:
 *   - OCA\Dossiq\Service\StatusTransitionService (transitions)
 *   - OpenRegister object events (sister-capability hooks per REQ-BL-8)
 *
 * Guards on legal-posture transitions are declared on the seeded
 * workflowTemplate; deadline maths is declared on the bezwaar schema
 * via x-openregister-calculations (ADR-022). This listener owns
 * neither.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
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
 * @spec openspec/specs/bezwaar-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Routes bezwaar/objection/hearing/advisory/decision events onto the
 * status-transition-engine without owning any transition logic itself.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/bezwaar-lifecycle/spec.md
 */
class BezwaarLifecycleListener implements IEventListener {

	/**
	 * Schemas this listener cares about (slugs).
	 *
	 * @var array<int, string>
	 */
	private const RELEVANT_SCHEMAS = [
		'objectionProceeding',
		'objection',
		'hearingSession',
		'advisoryReport',
		'decision',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectSchemaSlugResolver $slugResolver Schema id-to-slug resolver
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private ObjectSchemaSlugResolver $slugResolver,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an OR object event.
	 *
	 * @param Event $event The dispatched event
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false
			&& ($event instanceof ObjectUpdatedEvent) === false
		) {
			return;
		}

		$payload = $this->extractObject(event: $event);
		if ($payload === null) {
			return;
		}

		$schemaSlug = $this->resolveSchemaSlug(payload: $payload);
		if (in_array($schemaSlug, self::RELEVANT_SCHEMAS, true) === false) {
			return;
		}

		// Log only — actual state machine work happens inside
		// StatusTransitionService.execute(), driven by user-initiated
		// transitions via the controller layer. This listener exists
		// so the wiring is observable; sister capabilities trigger
		// their own engine calls via their own listeners. Per ADR
		// and REQ-BL-8 there is intentionally no bespoke transition
		// logic here.
		$this->logger->debug(
			'Dossiq bezwaar-lifecycle: observed ' . $schemaSlug . ' ' . $event::class,
			[
				'app' => Application::APP_ID,
				'schema' => $schemaSlug,
				'objectId' => (string)($payload['id'] ?? ''),
				'caseId' => (string)($payload['case'] ?? ''),
				'status' => (string)($payload['status'] ?? ''),
			]
		);
	}//end handle()

	/**
	 * Extract the OR object array from an event.
	 *
	 * @param Event $event Event instance
	 *
	 * @return array<string, mixed>|null
	 */
	private function extractObject(Event $event): ?array {
		$object = null;

		if (method_exists($event, 'getObject') === true) {
			$object = $event->getObject();
		}

		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}

			return null;
		}

		return null;
	}//end extractObject()

	/**
	 * Resolve the schema slug for an OR object payload.
	 *
	 * The payload carries the schema as an ID (`@self.schema` is
	 * `ObjectEntity::$schema`, written as `(string) $schemaId`), and no
	 * `schemaSlug` key exists on `@self`. Reading those keys directly — as this
	 * method used to — yielded an id or an empty string, so the strict
	 * `in_array()` against {@see self::RELEVANT_SCHEMAS} never matched and this
	 * listener's body had never run. Resolution goes through the shared
	 * {@see ObjectSchemaSlugResolver} so every listener uses one lookup.
	 *
	 * @param array<string, mixed> $payload Object payload
	 *
	 * @return string
	 */
	private function resolveSchemaSlug(array $payload): string {
		return $this->slugResolver->resolveFromPayload(payload: $payload);
	}//end resolveSchemaSlug()
}//end class
