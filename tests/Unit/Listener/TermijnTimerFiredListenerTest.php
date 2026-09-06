<?php

/**
 * Unit tests for TermijnTimerFiredListener.
 *
 * Drives real engine-shaped FlowTimerFiredEvent objects (via the stubs
 * that mirror the engine's constructor) through the listener against the
 * in-memory store, asserting the domain side: threshold bookkeeping with
 * `notificatiesVerstuurd` dedup, the breach flip to `exceeded`, the
 * still-paused guard on the hersteltermijn helper, the dwangsom accrual
 * sync, and the ownership filters.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\TermijnTimerFiredListener;
use OCA\Dossiq\Service\DeadlineEscalationService;
use OCA\Dossiq\Service\DwangsomCalculationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Listener\TermijnTimerFiredListener
 *
 * @uses \OCA\Dossiq\Service\DeadlineEscalationService
 * @uses \OCA\Dossiq\Service\DwangsomCalculationService
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\TermijnService
 */
class TermijnTimerFiredListenerTest extends TestCase {
	private FakeTermijnStore $objects;
	private TermijnService $termService;
	private TermijnTimerFiredListener $listener;

	protected function setUp(): void {
		$this->objects = new FakeTermijnStore();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'termijn_definitie_schema' => 'deadlineDefinition',
					'termijn_instance_schema' => 'deadlineInstance',
					'termijn_gebeurtenis_schema' => 'termijnGebeurtenis',
					'dwangsom_berekening_schema' => 'penaltyPaymentCalculation',
					default => '',
				};
			},
		);

		$logger = $this->createMock(LoggerInterface::class);
		$this->termService = new TermijnService($settings, $logger);
		$this->listener = new TermijnTimerFiredListener(
			$this->termService,
			new DeadlineEscalationService($this->termService, $logger),
			new DwangsomCalculationService($settings, $logger),
			$settings,
			$logger
		);
	}

	/**
	 * Seed one instance, returning its id.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return string
	 */
	private function seedInstance(array $overrides = []): string {
		$row = $this->objects->seed('deadlineInstance', array_merge([
			'case' => 'Z/2026/L1',
			'deadlineDefinition' => 'td-ov',
			'startDate' => '2026-06-01T10:00:00+00:00',
			'endDateCalculated' => '2026-07-27',
			'endDateCurrent' => '2026-07-27',
			'status' => 'lopend',
			'notificatiesVerstuurd' => [],
			'engineTimerId' => 'timer-1',
		], $overrides));

		return (string)$row['id'];
	}

	/**
	 * Build an engine-shaped rung fire for a dossiq termijn timer.
	 *
	 * @param string $instanceId The bound instance.
	 * @param string $rungKey The rung's stable key.
	 * @param string $kind The metadata kind.
	 * @param string $appId The arming app.
	 *
	 * @return FlowTimerFiredEvent
	 */
	private function rungFire(
		string $instanceId,
		string $rungKey,
		string $kind = 'beslistermijn',
		string $appId = 'dossiq',
	): FlowTimerFiredEvent {
		$timer = new FlowTimer();
		$timer->setUuid('timer-1');
		$timer->setAppId($appId);
		$timer->setMetadata([
			'source' => 'dossiq-termijn',
			'kind' => $kind,
			'termijnInstanceId' => $instanceId,
			'caseId' => 'Z/2026/L1',
			'basis' => 'AWB 4:13',
		]);

		return new FlowTimerFiredEvent(
			timer: $timer,
			kind: FlowTimerFiredEvent::KIND_RUNG,
			transition: 'escalation:' . $rungKey,
			rungKey: $rungKey,
			recipients: [['type' => 'user', 'id' => 'handler-1', 'role' => 'handler']],
			priority: 'medium',
			message: null
		);
	}

	/**
	 * The recorded gebeurtenis types for an instance.
	 *
	 * @param string $instanceId The instance.
	 *
	 * @return array<int, string>
	 */
	private function eventTypes(string $instanceId): array {
		$types = [];
		foreach (($this->objects->store['termijnGebeurtenis'] ?? []) as $row) {
			if ((string)($row['deadlineInstance'] ?? '') === $instanceId) {
				$types[] = (string)($row['type'] ?? '');
			}
		}

		return $types;
	}

	/**
	 * FIXTURE PAIR (thresholds == ladder rungs): the engine's
	 * `preBreach:7:calendarDays` rung lands in the same bucket 7 the
	 * retired scan computed, and the notificatiesVerstuurd bookkeeping is
	 * byte-identical to the scan era.
	 *
	 * @return void
	 */
	public function testPreBreachRungDispatchesItsThresholdOnce(): void {
		$id = $this->seedInstance();

		$this->listener->handle($this->rungFire($id, 'preBreach:7:calendarDays'));
		$after = $this->termService->getTermijnInstance($id);
		self::assertSame([7], $after['notificatiesVerstuurd']);

		// The same rung again (or a second sweep pass): no duplicate.
		$this->listener->handle($this->rungFire($id, 'preBreach:7:calendarDays'));
		$again = $this->termService->getTermijnInstance($id);
		self::assertSame([7], $again['notificatiesVerstuurd']);
	}

	/**
	 * The breach rung flips the instance and records the exceeded event —
	 * the exact writes the retired scan performed.
	 *
	 * @return void
	 */
	public function testBreachRungFlipsInstanceToExceeded(): void {
		$id = $this->seedInstance();

		$this->listener->handle($this->rungFire($id, 'slaBreached:0'));

		$after = $this->termService->getTermijnInstance($id);
		self::assertSame('exceeded', $after['status']);
		self::assertContains(0, $after['notificatiesVerstuurd']);
		self::assertContains('exceeded', $this->eventTypes($id));
	}

	/**
	 * A breach rung on an already-exceeded instance records nothing twice.
	 *
	 * @return void
	 */
	public function testBreachRungIsIdempotent(): void {
		$id = $this->seedInstance();

		$this->listener->handle($this->rungFire($id, 'slaBreached:0'));
		$this->listener->handle($this->rungFire($id, 'slaBreached:0'));

		$types = array_count_values($this->eventTypes($id));
		self::assertSame(1, $types['exceeded']);
	}

	/**
	 * Every handled fire syncs the dwangsom accrual derivation for the
	 * instance's running berekeningen.
	 *
	 * @return void
	 */
	public function testFireSyncsRunningPenaltyCalculations(): void {
		$id = $this->seedInstance(['status' => 'exceeded']);
		$start = (new \DateTimeImmutable('today'))->modify('-5 days');
		$this->objects->seed('penaltyPaymentCalculation', [
			'id' => 'b-l1',
			'deadlineInstance' => $id,
			'startDate' => $start->format('Y-m-d'),
			'currentDag' => 0,
			'cumulativeAmount' => 0,
			'plafondCalculated' => 144200,
			'plafondBereikt' => false,
			'status' => 'lopend',
			'regime' => 'awb-default',
		]);

		$this->listener->handle($this->rungFire($id, 'slaBreached:0'));

		$calc = $this->objects->store['penaltyPaymentCalculation']['b-l1'];
		self::assertSame(5, $calc['currentDag']);
		self::assertSame(5 * 2300, $calc['cumulativeAmount']);
	}

	/**
	 * The hersteltermijn helper records pause-expired only while the
	 * instance is still paused (REQ-TOT-002).
	 *
	 * @return void
	 */
	public function testPauseExpiryFiresOnlyWhileStillPaused(): void {
		$paused = $this->seedInstance(['status' => 'paused', 'pauseDeadline' => '2026-06-15']);
		$this->listener->handle($this->rungFire($paused, 'slaBreached:0', kind: 'hersteltermijn'));
		self::assertContains('pause-expired', $this->eventTypes($paused));

		// A helper outliving an on-time aanvulling fires late; the guard drops it.
		$resumed = $this->seedInstance(['status' => 'lopend']);
		$this->listener->handle($this->rungFire($resumed, 'slaBreached:0', kind: 'hersteltermijn'));
		self::assertNotContains('pause-expired', $this->eventTypes($resumed));
	}

	/**
	 * Fires from other apps or foreign metadata are not ours.
	 *
	 * @return void
	 */
	public function testForeignTimersAreIgnored(): void {
		$id = $this->seedInstance();

		$this->listener->handle($this->rungFire($id, 'preBreach:7:calendarDays', appId: 'someotherapp'));

		$after = $this->termService->getTermijnInstance($id);
		self::assertSame([], $after['notificatiesVerstuurd']);
	}

	/**
	 * A rung reaching a completed term does nothing: the subject is
	 * terminal and its timers should already be cancelled.
	 *
	 * @return void
	 */
	public function testTerminalInstancesAreLeftAlone(): void {
		$id = $this->seedInstance(['status' => 'completed']);

		$this->listener->handle($this->rungFire($id, 'slaBreached:0'));

		$after = $this->termService->getTermijnInstance($id);
		self::assertSame('completed', $after['status']);
		self::assertSame([], $this->eventTypes($id));
	}
}
