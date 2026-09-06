<?php

/**
 * Dossiq PublicationService
 *
 * Service for publishing besluitvorming case decisions. Publishing a besluit
 * means: (a) emitting a publication record with publishedAt + channel, and
 * (b) appending a publishedAt timestamp to the case.
 *
 * The publication record is appended to the case's `publications[]` array;
 * cross-app publication to Open Raadsinformatie / GemeenteBlad is handled
 * by openconnector wiring (out of scope for the host app build).
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/besluitvorming-workflow/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use InvalidArgumentException;
use OCA\Dossiq\AppInfo\Application;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Service for besluitvorming publication.
 *
 * @spec openspec/specs/besluitvorming-workflow/spec.md
 */
class PublicationService {
	/**
	 * Supported publication channels.
	 */
	public const CHANNELS = ['gemeenteblad', 'website', 'open_raadsinformatie', 'pdc'];

	/**
	 * Integriq's ADR-041 delivery-request event, by FQN string so dossiq
	 * carries no compile-time dependency on the optional integriq app. A
	 * cross-app event class name is a RUNTIME lookup — this app cannot move
	 * it, only follow it (see WorkflowListenerRegistrar for the rename
	 * incident that shaped this rule).
	 *
	 * @var string
	 */
	private const DELIVERY_EVENT = 'OCA\Integriq\Event\DeliveryRequestedEvent';

	/**
	 * What this service delivers, as named on the integriq seam.
	 *
	 * @var string
	 */
	private const DELIVERY_KIND = 'besluit-publication';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service.
	 * @param IEventDispatcher $eventDispatcher Dispatches the integriq delivery request (ADR-041).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Publish a besluit on a case.
	 *
	 * Idempotent per (caseId, channel): re-publishing on the same channel
	 * updates the publishedAt timestamp rather than appending duplicates.
	 *
	 * NOTE: As of dossiq-delegate-contract-decision, this method publishes the
	 * already-recorded ZGW Besluit (fed by the decidesk Decision outcome via
	 * BesluitMaterialisationService) rather than authoring a new local besluit.
	 * The publication record is appended to the case's publications[] array;
	 * cross-app publication to Open Raadsinformatie / GemeenteBlad is handled
	 * by openconnector wiring (out of scope for the host app build).
	 *
	 * @param string $caseId The case id.
	 * @param array<string, mixed> $payload The publish payload: { channel, publishedAt?, notes? }.
	 *
	 * @return array<string, mixed> The publication record + updated case ref.
	 *
	 * @throws \InvalidArgumentException When the requested publication channel is not supported.
	 * @throws \RuntimeException When OR is unavailable or the case can't be loaded.
	 *
	 * @spec openspec/changes/besluitvorming-workflow/tasks.md#task-7
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	public function publish(string $caseId, array $payload): array {
		$channel = (string)($payload['channel'] ?? 'website');
		if (in_array($channel, self::CHANNELS, true) === false) {
			throw new InvalidArgumentException('Invalid publication channel: ' . $channel);
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');

		$case = $this->loadCase(
			objectService: $objectService,
			caseId: $caseId,
			register: $register,
			schema: $schema
		);

		$publications = $this->extractPublications(case: $case);

		$publishedAt = (string)($payload['publishedAt'] ?? date(format: 'c'));
		$notes = null;
		if (isset($payload['notes']) === true) {
			$notes = (string)$payload['notes'];
		}

		// Upsert by channel — same channel publishing twice updates the timestamp.
		$publications = $this->upsertPublication(
			publications: $publications,
			channel: $channel,
			publishedAt: $publishedAt,
			notes: $notes
		);

		// Hand the outbound leg to integriq (ADR-041 seam): dossiq composes
		// WHAT is published; integriq owns HOW it travels. The outcome —
		// including a refusal when integriq is absent or unrouted — is
		// recorded on the publication record so the case shows the delivery
		// status, and it never rolls back the publication itself.
		$delivery = $this->requestDelivery(
			caseId: $caseId,
			channel: $channel,
			publishedAt: $publishedAt,
			notes: $notes,
			case: $case,
			register: $register,
			schema: $schema
		);
		$publications = $this->attachDelivery(publications: $publications, channel: $channel, delivery: $delivery);

		$case['publications'] = $publications;
		$case['publishedAt'] = $publishedAt;

		$objectService->saveObject(
			object: $case,
			register: $register,
			schema: $schema,
		);

		return [
			'caseId' => $caseId,
			'channel' => $channel,
			'publishedAt' => $publishedAt,
			'publications' => $publications,
			'delivery' => $delivery,
		];
	}//end publish()

	/**
	 * Request cross-app delivery of the publication via integriq's ADR-041
	 * delivery seam.
	 *
	 * Fail-closed: when integriq is absent, does not handle the event, or has
	 * no delivery route configured, the returned record says so explicitly —
	 * a publication is never reported as travelling when nothing carries it.
	 * Terminal status later arrives through integriq's DeliveryConcludedEvent
	 * and is projected by {@see \OCA\Dossiq\Listener\DeliveryConcludedListener}.
	 *
	 * @param string $caseId The case id.
	 * @param string $channel The publication channel.
	 * @param string $publishedAt The publication timestamp.
	 * @param string|null $notes Optional publication notes.
	 * @param array<string, mixed> $case The loaded case data.
	 * @param mixed $register The configured register id.
	 * @param mixed $schema The configured case schema id.
	 *
	 * @return array<string, mixed> The delivery record for the publication entry.
	 *
	 * @spec openspec/changes/dossiq-delivers-nothing/specs/besluitvorming-delivery/spec.md
	 */
	private function requestDelivery(
		string $caseId,
		string $channel,
		string $publishedAt,
		?string $notes,
		array $case,
		mixed $register,
		mixed $schema,
	): array {
		$requestedAt = date(format: 'c');
		if (class_exists('\\' . self::DELIVERY_EVENT) === false) {
			$this->logger->warning(
				'Publication delivery refused: integriq is not installed',
				['app' => Application::APP_ID, 'caseId' => $caseId, 'channel' => $channel]
			);
			return [
				'status' => 'refused',
				'reason' => 'integriq_not_installed',
				'requestedAt' => $requestedAt,
			];
		}

		$correlationId = bin2hex(string: random_bytes(length: 16));
		$eventClass = '\\' . self::DELIVERY_EVENT;
		$event = new $eventClass(
			sourceApp: Application::APP_ID,
			subjectRegister: (string)$register,
			subjectSchema: (string)$schema,
			subjectId: $caseId,
			subjectLabel: (string)($case['title'] ?? $caseId),
			deliveryKind: self::DELIVERY_KIND,
			channel: $channel,
			payload: [
				'caseId' => $caseId,
				'channel' => $channel,
				'publishedAt' => $publishedAt,
				'notes' => $notes,
				'besluitDocument' => ($case['besluitDocument'] ?? null),
				'identifier' => ($case['identifier'] ?? null),
			],
			correlationId: $correlationId,
			externalReference: (string)($case['identifier'] ?? ''),
		);

		try {
			$this->eventDispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			$this->logger->error(
				'Publication delivery dispatch failed',
				['app' => Application::APP_ID, 'caseId' => $caseId, 'channel' => $channel, 'error' => $e->getMessage()]
			);
			return [
				'status' => 'refused',
				'reason' => 'dispatch_failed',
				'correlationId' => $correlationId,
				'requestedAt' => $requestedAt,
			];
		}

		if ($event->isHandled() === false) {
			return [
				'status' => 'refused',
				'reason' => 'not_handled',
				'correlationId' => $correlationId,
				'requestedAt' => $requestedAt,
			];
		}

		if ((int)$event->getMatchedSubscriptions() === 0) {
			// Accepted by integriq, but no delivery route is configured —
			// fail closed rather than implying the publication travelled.
			return [
				'status' => 'unrouted',
				'eventId' => (string)$event->getResultId(),
				'correlationId' => $correlationId,
				'requestedAt' => $requestedAt,
			];
		}

		return [
			'status' => 'requested',
			'eventId' => (string)$event->getResultId(),
			'correlationId' => $correlationId,
			'matchedSubscriptions' => (int)$event->getMatchedSubscriptions(),
			'requestedAt' => $requestedAt,
		];
	}//end requestDelivery()

	/**
	 * Attach a delivery record to the publication entry for a channel.
	 *
	 * @param array<int, array<string, mixed>> $publications The publications list.
	 * @param string $channel The channel whose entry carries the delivery.
	 * @param array<string, mixed> $delivery The delivery record.
	 *
	 * @return array<int, array<string, mixed>> The updated publications list.
	 */
	private function attachDelivery(array $publications, string $channel, array $delivery): array {
		foreach ($publications as $i => $pub) {
			if ((string)($pub['channel'] ?? '') === $channel) {
				$publications[$i]['delivery'] = $delivery;
				break;
			}
		}

		return $publications;
	}//end attachDelivery()

	/**
	 * Load a case from OpenRegister and normalise it to its array form.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $caseId The case id.
	 * @param mixed $register The configured register id.
	 * @param mixed $schema The configured case schema id.
	 *
	 * @return array<string, mixed> The case data.
	 *
	 * @throws \RuntimeException When the case cannot be loaded or does not exist.
	 */
	private function loadCase(object $objectService, string $caseId, mixed $register, mixed $schema): array {
		try {
			$obj = $objectService->find(id: $caseId, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->error(
				'PublicationService::publish find failed',
				['app' => Application::APP_ID, 'caseId' => $caseId, 'error' => $e->getMessage()]
			);
			throw new RuntimeException('Case not found: ' . $caseId);
		}

		if ($obj === null) {
			throw new RuntimeException('Case not found: ' . $caseId);
		}

		// An array casts to itself, so the cast doubles as the plain-object fallback.
		$case = (array)$obj;
		if (is_array($obj) === false && method_exists($obj, 'jsonSerialize') === true) {
			$case = $obj->jsonSerialize();
		}

		return $case;
	}//end loadCase()

	/**
	 * Upsert a publication record by channel — an existing record for the same
	 * channel has its timestamp and notes replaced rather than being duplicated.
	 *
	 * @param array<int, array<string, mixed>> $publications The existing publications list.
	 * @param string $channel The publication channel.
	 * @param string $publishedAt The publication timestamp.
	 * @param string|null $notes Optional publication notes.
	 *
	 * @return array<int, array<string, mixed>> The updated publications list.
	 */
	private function upsertPublication(array $publications, string $channel, string $publishedAt, ?string $notes): array {
		$upserted = false;
		foreach ($publications as $i => $pub) {
			if ((string)($pub['channel'] ?? '') === $channel) {
				$publications[$i] = [
					'channel' => $channel,
					'publishedAt' => $publishedAt,
					'notes' => $notes,
				];
				$upserted = true;
				break;
			}
		}

		if ($upserted === false) {
			$publications[] = [
				'channel' => $channel,
				'publishedAt' => $publishedAt,
				'notes' => $notes,
			];
		}

		return $publications;
	}//end upsertPublication()

	/**
	 * Pull the existing publications list from a case.
	 *
	 * @param array<string, mixed> $case The case object.
	 *
	 * @return array<int, array<string, mixed>> The publications list.
	 */
	private function extractPublications(array $case): array {
		$pubs = $case['publications'] ?? [];
		if (is_string($pubs) === true) {
			$decoded = json_decode((string)$pubs, associative: true);
			$pubs = [];
			if (is_array($decoded) === true) {
				$pubs = $decoded;
			}
		}

		if (is_array($pubs) === false) {
			return [];
		}

		$clean = [];
		foreach ($pubs as $pub) {
			if (is_array($pub) === true) {
				$clean[] = $pub;
			}
		}

		return $clean;
	}//end extractPublications()
}//end class
