<?php

/**
 * Dossiq Termijn Timer Fired Listener.
 *
 * The domain side of the engine's clock: consumes OpenRegister
 * FlowTimerFiredEvent for dossiq termijn timers. PreBreach rungs route
 * through {@see DeadlineEscalationService::notifyThreshold()} so the
 * instance's `notificatiesVerstuurd` list stays the ONE dedup source
 * (which also absorbs catch-up fires after the migration repair step);
 * the breach rung flips the instance to `exceeded` and records the
 * event; the hersteltermijn helper's rung records `pause-expired` only
 * while the instance is still paused; and every handled fire syncs the
 * dwangsom accrual derivation.
 *
 * The listener never throws out of handle(): a domain failure is logged
 * and must not poison the engine sweep.
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
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\DeadlineEscalationService;
use OCA\Dossiq\Service\DwangsomCalculationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Maps engine rung fires onto the AWB termijn domain actions.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */
class TermijnTimerFiredListener implements IEventListener {
	use SearchesObjects;

	/**
	 * Instance statuses on which no escalation or breach applies any more.
	 *
	 * @var array<int, string>
	 */
	private const TERMINAL_STATUSES = ['completed', 'withdrawn'];

	/**
	 * Constructor.
	 *
	 * @param TermijnService $termService Instance lookup, updates and events.
	 * @param DeadlineEscalationService $escalationService Threshold bookkeeping and dispatch.
	 * @param DwangsomCalculationService $penaltyService Dwangsom accrual derivation.
	 * @param SettingsService $settingsService Settings + ObjectService access.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly TermijnService $termService,
		private readonly DeadlineEscalationService $escalationService,
		private readonly DwangsomCalculationService $penaltyService,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a timer fire.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof FlowTimerFiredEvent) === false) {
			return;
		}

		try {
			$this->dispatch(event: $event);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq termijn: timer fire handling failed',
				['error' => $e->getMessage(), 'rung' => (string)$event->getRungKey()]
			);
		}
	}//end handle()

	/**
	 * Route one fire to its domain action.
	 *
	 * @param FlowTimerFiredEvent $event The fire.
	 *
	 * @return void
	 */
	private function dispatch(FlowTimerFiredEvent $event): void {
		$timer = $event->getTimer();
		$metadata = ($timer->getMetadata() ?? []);
		if ((string)$timer->getAppId() !== 'dossiq'
			|| (string)($metadata['source'] ?? '') !== TermijnTimerService::METADATA_SOURCE
		) {
			return;
		}

		$instanceId = (string)($metadata['termijnInstanceId'] ?? '');
		if ($instanceId === '' || $event->getKind() !== 'rung') {
			return;
		}

		$instance = $this->termService->getTermijnInstance($instanceId);
		if ($instance === null) {
			$this->logger->warning(
				'Dossiq termijn: timer fired for an unknown instance',
				['instance' => $instanceId, 'rung' => (string)$event->getRungKey()]
			);
			return;
		}

		$kind = (string)($metadata['kind'] ?? '');
		if ($kind === TermijnTimerService::KIND_HERSTELTERMIJN) {
			$this->handlePauseExpiry(instance: $instance, instanceId: $instanceId);
			return;
		}

		$this->handleBeslistermijnRung(event: $event, instance: $instance, instanceId: $instanceId);
	}//end dispatch()

	/**
	 * A beslistermijn rung: threshold bookkeeping, breach flip, accrual sync.
	 *
	 * @param FlowTimerFiredEvent $event The fire.
	 * @param array<string, mixed> $instance The TermijnInstance row.
	 * @param string $instanceId Its id.
	 *
	 * @return void
	 */
	private function handleBeslistermijnRung(FlowTimerFiredEvent $event, array $instance, string $instanceId): void {
		if (in_array((string)($instance['status'] ?? ''), self::TERMINAL_STATUSES, true) === true) {
			return;
		}

		$bucket = $this->bucketFromRungKey(rungKey: (string)$event->getRungKey());
		if ($bucket === null) {
			return;
		}

		if ($bucket === 0 && (string)($instance['status'] ?? '') !== 'exceeded') {
			$this->termService->updateTermijnInstance($instanceId, ['status' => 'exceeded']);
			$this->termService->recordEvent(
				termInstanceId: $instanceId,
				type: 'exceeded',
				basis: 'AWB 4:13',
				rationale: 'Termijn overschreden zonder beschikking',
				daysImpact: 0,
			);
			$instance['status'] = 'exceeded';
		}

		// Re-read so notifyThreshold sees the freshest notificatiesVerstuurd.
		$latest = ($this->termService->getTermijnInstance($instanceId) ?? $instance);
		$this->escalationService->notifyThreshold($latest, $bucket);
		$this->syncPenaltyAccrual(instanceId: $instanceId);
	}//end handleBeslistermijnRung()

	/**
	 * The hersteltermijn helper fired: the pause ran out without an
	 * aanvulling. The engine cannot cancel a single timer, so a helper
	 * outliving an on-time aanvulling fires anyway — the still-paused
	 * guard here is what drops it.
	 *
	 * @param array<string, mixed> $instance The TermijnInstance row.
	 * @param string $instanceId Its id.
	 *
	 * @return void
	 */
	private function handlePauseExpiry(array $instance, string $instanceId): void {
		if ((string)($instance['status'] ?? '') !== 'paused') {
			return;
		}

		$this->termService->recordEvent(
			termInstanceId: $instanceId,
			type: 'pause-expired',
			basis: 'AWB 4:5',
			rationale: 'Pauzetermijn verlopen zonder aanvulling',
			daysImpact: 0,
		);
	}//end handlePauseExpiry()

	/**
	 * Map an engine rung key onto the domain threshold bucket.
	 *
	 * Keys follow `trigger:offset:unit` (`preBreach:14:calendarDays`) or
	 * `slaBreached:0`; the offset IS the bucket. Unknown shapes map to
	 * null and are ignored rather than guessed.
	 *
	 * @param string $rungKey The rung's stable key.
	 *
	 * @return int|null The bucket (14/7/2/0), or null.
	 */
	private function bucketFromRungKey(string $rungKey): ?int {
		$parts = explode(':', $rungKey);
		$trigger = (string)($parts[0] ?? '');
		if (isset($parts[1]) === false || is_numeric($parts[1]) === false) {
			return null;
		}

		if ($trigger === 'slaBreached') {
			return 0;
		}

		if ($trigger === 'preBreach') {
			return (int)$parts[1];
		}

		return null;
	}//end bucketFromRungKey()

	/**
	 * Sync the dwangsom accrual derivation for the instance's running
	 * berekeningen. Idempotent per day; a failure here never blocks the
	 * escalation that triggered it.
	 *
	 * @param string $instanceId The TermijnInstance id.
	 *
	 * @return void
	 */
	private function syncPenaltyAccrual(string $instanceId): void {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('dwangsom_berekening_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return;
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: [
					'deadlineInstance' => $instanceId,
					'status' => 'lopend',
				]
			);
		} catch (\Throwable $e) {
			return;
		}

		foreach ($rows as $row) {
			$rowId = (string)($row['id'] ?? '');
			if ($rowId === '') {
				continue;
			}

			try {
				$this->penaltyService->accrueThrough($rowId);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Dossiq termijn: dwangsom accrual sync failed',
					['id' => $rowId, 'error' => $e->getMessage()]
				);
			}
		}//end foreach
	}//end syncPenaltyAccrual()
}//end class
