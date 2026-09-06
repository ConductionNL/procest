<?php

/**
 * Dossiq Termijn Case Created Listener.
 *
 * Observes OpenRegister ObjectCreatedEvent on the dossiq case schema and
 * binds an AWB termijn (TermijnInstance) to the case using the active
 * TermijnDefinitie for the case zaaktype. Defers all work to
 * {@see TermijnService} (ADR-022). A missing definition is logged at debug
 * level but never blocks case creation.
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Exception\NoTermijnDefinitieException;
use OCA\Dossiq\Service\CaseTypeSlugResolver;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\TermijnService;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Binds a TermijnInstance to a freshly-created dossiq case.
 *
 * @spec openspec/specs/termijnbewaking-schemas/spec.md
 *
 * @template-implements IEventListener<Event>
 */
class DeadlineCaseCreatedListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param TermijnService $termService TermijnService.
	 * @param ObjectSchemaSlugResolver $slugResolver Schema id-to-slug resolver.
	 * @param CaseTypeSlugResolver $caseTypeSlugs Case-type uuid-to-slug resolver.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly TermijnService $termService,
		private readonly ObjectSchemaSlugResolver $slugResolver,
		private readonly CaseTypeSlugResolver $caseTypeSlugs,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a case-created event.
	 *
	 * @param Event $event Event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		$payload = $this->extractObject(event: $event);
		if ($payload === null) {
			return;
		}

		if ($this->resolveSchemaSlug(payload: $payload) !== 'case') {
			return;
		}

		$caseId = (string)($payload['id'] ?? ($payload['uuid'] ?? ''));
		$caseTypeRef = (string)($payload['caseType'] ?? '');
		if ($caseId === '' || $caseTypeRef === '') {
			return;
		}

		// 🔴 A `case` carries its case type as a UUID; a deadlineDefinition
		// binds by SLUG. Handing the uuid straight to TermijnService matched
		// no shipped case type, so no term was ever bound and no FlowTimer
		// ever armed — and the refusal was invisible at the default loglevel.
		$caseType = $this->caseTypeSlugs->toSlug(reference: $caseTypeRef);
		if ($caseType === '') {
			$this->logger->warning(
				'Dossiq termijn: the case type behind a new case could not be resolved to a slug, '
				. 'so no statutory term was started',
				['case' => $caseId, 'caseType' => $caseTypeRef]
			);

			return;
		}

		try {
			$this->termService->createTermijnInstance($caseId, $caseType);
		} catch (NoTermijnDefinitieException $e) {
			// NOT debug. A case that matched no definition at all has no
			// statutory clock running, which is exactly the state that hid a
			// fleet-wide key mismatch behind a quiet log line. It is a valid
			// configuration for a case type with no beslistermijn, so it is a
			// warning rather than an error — but it is visible.
			$this->logger->warning(
				'Dossiq termijn: no active TermijnDefinitie for case type "' . $caseType . '", '
				. 'so case ' . $caseId . ' runs without a statutory term',
				['case' => $caseId, 'caseType' => $caseType, 'caseTypeId' => $caseTypeRef]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq termijn: could not bind a term to case ' . $caseId . ': ' . $e->getMessage(),
				['case' => $caseId, 'caseType' => $caseType, 'exception' => $e->getMessage()]
			);
		}//end try
	}//end handle()

	/**
	 * Extract OR object array from an event.
	 *
	 * @param Event $event Event.
	 *
	 * @return array<string, mixed>|null
	 */
	private function extractObject(Event $event): ?array {
		if (method_exists($event, 'getObject') === false) {
			return null;
		}

		$object = $event->getObject();
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return null;
	}//end extractObject()

	/**
	 * Resolve the schema slug.
	 *
	 * The payload carries the schema as an ID (`@self.schema` is
	 * `ObjectEntity::$schema`, written as `(string) $schemaId`), and `@self`
	 * has no `schemaSlug` key. Reading those keys directly — as this method
	 * used to — returned an id or an empty string, so the `!== 'case'` guard in
	 * {@see self::handle()} always short-circuited and no AWB TermijnInstance
	 * has ever been bound to a case. Resolution goes through the shared
	 * {@see ObjectSchemaSlugResolver}.
	 *
	 * @param array<string, mixed> $payload Payload.
	 *
	 * @return string
	 */
	private function resolveSchemaSlug(array $payload): string {
		return $this->slugResolver->resolveFromPayload(payload: $payload);
	}//end resolveSchemaSlug()
}//end class
