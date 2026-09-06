<?php

/**
 * Dossiq DeliveryConcluded listener.
 *
 * Consumes integriq's terminal `DeliveryConcludedEvent` (ADR-041 delivery
 * seam) and projects the outcome onto the case's publication record, so the
 * delivery status of a besluit publication is visible as case data. The
 * projection is local and idempotent, filtered to events this app raised
 * (`getSourceApp() === 'dossiq'`), and never advances anything on a
 * non-terminal outcome.
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
 * @spec openspec/changes/dossiq-delivers-nothing/specs/besluitvorming-delivery/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Projects integriq's terminal delivery outcome onto the case publication
 * record.
 *
 * @psalm-suppress UnusedClass -- registered via ListenerRegistrar by FQN string.
 *
 * @spec openspec/changes/dossiq-delivers-nothing/specs/besluitvorming-delivery/spec.md
 */
class DeliveryConcludedListener implements IEventListener {
	/**
	 * Terminal statuses the seam can conclude with.
	 *
	 * @var array<int, string>
	 */
	private const TERMINAL_STATUSES = ['delivered', 'abandoned'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Resolves the ObjectService + register config.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an integriq `DeliveryConcludedEvent`.
	 *
	 * @param Event $event The dispatched event (integriq DeliveryConcludedEvent).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dossiq-delivers-nothing/specs/besluitvorming-delivery/spec.md
	 */
	public function handle(Event $event): void {
		// The event class is integriq's and optional at runtime; instanceof on
		// an absent class is simply false (no autoload error), and this
		// listener is only registered when the class exists. The declaration
		// stubs under tests/Stubs/Integriq keep the analyzers informed.
		if (($event instanceof \OCA\Integriq\Event\DeliveryConcludedEvent) === false) {
			return;
		}

		try {
			if ((string)$event->getSourceApp() !== Application::APP_ID) {
				return;
			}

			$status = strtolower((string)$event->getStatus());
			if (in_array($status, self::TERMINAL_STATUSES, true) === false) {
				return;
			}

			$this->projectOntoCase(
				caseId: (string)$event->getSubjectId(),
				correlationId: (string)$event->getCorrelationId(),
				channel: (string)$event->getChannel(),
				status: $status,
				attempts: (int)$event->getAttempts(),
				error: $event->getError(),
				concludedAt: (string)$event->getConcludedAt()
			);
		} catch (\Throwable $e) {
			// A projection failure must never bubble into integriq's delivery
			// bookkeeping — its message record stays the source of truth.
			$this->logger->error(
				'Dossiq DeliveryConcludedListener failed to project delivery outcome',
				['app' => Application::APP_ID, 'error' => $e->getMessage()]
			);
		}//end try
	}//end handle()

	/**
	 * Write the terminal delivery status onto the case's publication record.
	 *
	 * Matches the publication by the delivery correlation id — every
	 * conclusion originates from a dispatched request, which always carried
	 * one. Idempotent: re-delivering the same terminal state is a no-op
	 * write.
	 *
	 * @param string $caseId The case id.
	 * @param string $correlationId The delivery correlation id.
	 * @param string $channel The publication channel.
	 * @param string $status Terminal status: `delivered` or `abandoned`.
	 * @param int $attempts Delivery attempts made.
	 * @param string|null $error The last delivery error, or null.
	 * @param string $concludedAt ISO 8601 terminal timestamp.
	 *
	 * @return void
	 */
	private function projectOntoCase(
		string $caseId,
		string $correlationId,
		string $channel,
		string $status,
		int $attempts,
		?string $error,
		string $concludedAt,
	): void {
		if ($caseId === '' || $correlationId === '') {
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');

		$case = $this->loadCase(objectService: $objectService, caseId: $caseId, register: $register, schema: $schema);
		if ($case === null) {
			$this->logger->warning(
				'Dossiq DeliveryConcludedListener: case not found for concluded delivery',
				['caseId' => $caseId, 'correlationId' => $correlationId]
			);
			return;
		}

		$publications = $case['publications'] ?? [];
		if (is_array($publications) === false) {
			return;
		}

		$outcome = [
			'status' => $status,
			'attempts' => $attempts,
			'error' => $error,
			'concludedAt' => $concludedAt,
		];
		$applied = $this->applyOutcome(publications: $publications, correlationId: $correlationId, outcome: $outcome);
		if ($applied === null) {
			$this->logger->warning(
				'Dossiq DeliveryConcludedListener: no publication matches the concluded delivery',
				['caseId' => $caseId, 'correlationId' => $correlationId, 'channel' => $channel]
			);
			return;
		}

		if ($applied === []) {
			// Idempotent: this terminal state is already projected.
			return;
		}

		$case['publications'] = $applied;
		$objectService->saveObject(
			object: $case,
			register: $register,
			schema: $schema,
		);
	}//end projectOntoCase()

	/**
	 * Load a case from OpenRegister and normalise it to its array form.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $caseId The case id.
	 * @param mixed $register The configured register id.
	 * @param mixed $schema The configured case schema id.
	 *
	 * @return array<string, mixed>|null The case data, or null when not found.
	 */
	private function loadCase(object $objectService, string $caseId, mixed $register, mixed $schema): ?array {
		$obj = $objectService->find(id: $caseId, register: $register, schema: $schema);
		if ($obj === null) {
			return null;
		}

		$case = (array)$obj;
		if (is_array($obj) === false && method_exists($obj, 'jsonSerialize') === true) {
			$case = $obj->jsonSerialize();
		}

		return $case;
	}//end loadCase()

	/**
	 * Apply a terminal delivery outcome to the matching publication entry.
	 *
	 * @param array<int, mixed> $publications The case's publications list.
	 * @param string $correlationId The delivery correlation id to match.
	 * @param array<string, mixed> $outcome The terminal outcome fields (status, attempts, error, concludedAt).
	 *
	 * @return array<int, mixed>|null The updated list; an empty array when the
	 *                                terminal state is already projected
	 *                                (idempotent no-op); null when nothing
	 *                                matches.
	 */
	private function applyOutcome(array $publications, string $correlationId, array $outcome): ?array {
		foreach ($publications as $i => $pub) {
			if (is_array($pub) === false) {
				continue;
			}

			$delivery = (array)($pub['delivery'] ?? []);
			if ((string)($delivery['correlationId'] ?? '') !== $correlationId) {
				continue;
			}

			if ((string)($delivery['status'] ?? '') === (string)$outcome['status']) {
				return [];
			}

			$publications[$i]['delivery'] = array_merge($delivery, $outcome);
			return $publications;
		}//end foreach

		return null;
	}//end applyOutcome()
}//end class
