<?php

/**
 * Unit tests for TermijnTimerService — the arm mapping onto the engine.
 *
 * Uses the shared {@see FlowTimerEngineFake} fixture, which mirrors the
 * REAL FlowTimerService signatures (parameter names included, because
 * every call site uses named arguments): a fake that agreed with the
 * caller instead of with the engine could not fail.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnTimerService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\TermijnTimerService
 */
class TermijnTimerServiceTest extends TestCase {
	private FlowTimerEngineFake $engine;
	private TermijnTimerService $service;

	protected function setUp(): void {
		$this->engine = new FlowTimerEngineFake();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')
			->with(TermijnTimerService::ENGINE_CLASS)
			->willReturn($this->engine);
		$this->service = new TermijnTimerService($settings, $this->createMock(LoggerInterface::class));
	}

	/**
	 * A fresh 56-day instance.
	 *
	 * @return array<string, mixed>
	 */
	private function instance(): array {
		return [
			'id' => 'ti-1',
			'case' => 'Z/2026/1',
			'deadlineDefinition' => 'td-ov',
			'startDate' => '2026-06-01T10:00:00+00:00',
			'endDateCalculated' => '2026-07-27',
			'endDateCurrent' => '2026-07-27',
			'status' => 'lopend',
			'engineTimerId' => 'timer-1',
		];
	}

	/**
	 * The full arm mapping per design D-1.
	 *
	 * @return void
	 */
	public function testArmBeslistermijnMapsTheTermOntoTheEngine(): void {
		$uuid = $this->service->armBeslistermijn(
			instance: $this->instance(),
			definitie: ['standardDurationDays' => 56, 'countExtensions' => 1, 'legalBasis' => 'Wabo 3.9 lid 1']
		);

		self::assertSame('timer-1', $uuid);
		$config = $this->engine->calls['arm'][0]['config'];
		self::assertSame('object', $config['subjectType']);
		self::assertSame('ti-1', $config['subjectUuid']);
		self::assertSame('dossiq', $config['appId']);
		self::assertSame('due', $config['purpose']);
		self::assertSame('wettelijk', $config['legalEffect']);
		self::assertSame(['value' => 56, 'unit' => 'calendarDays'], $config['sla']);
		self::assertSame('nl-termijn-default', $config['ladder']);
		self::assertSame(1, $config['extensionMax']);
		self::assertSame('case_created', $config['anchorEvent']);
		self::assertSame('2026-06-01', $config['anchorEventAt']->format('Y-m-d'));
		self::assertArrayNotHasKey('onExpiry', $config);
		self::assertSame('dossiq-termijn', $config['metadata']['source']);
		self::assertSame('beslistermijn', $config['metadata']['kind']);
		self::assertSame('ti-1', $config['metadata']['termijnInstanceId']);
		self::assertSame('Wabo 3.9 lid 1', $config['metadata']['basis']);
	}

	/**
	 * An in-flight extended instance arms at its CURRENT deadline
	 * (REQ-TOT-006): 2026-06-01 to 2026-08-31 is 91 calendar days.
	 *
	 * @return void
	 */
	public function testArmSpansTheCurrentDeadlineForInFlightInstances(): void {
		$row = $this->instance();
		$row['endDateCurrent'] = '2026-08-31';

		$this->service->armBeslistermijn(instance: $row, definitie: ['standardDurationDays' => 56]);

		$config = $this->engine->calls['arm'][0]['config'];
		self::assertSame(['value' => 91, 'unit' => 'calendarDays'], $config['sla']);
	}

	/**
	 * The hersteltermijn helper: advisory, one explicit slaBreached rule,
	 * so no ladder rungs fire on a 14-day pause.
	 *
	 * @return void
	 */
	public function testArmHersteltermijnUsesOneExplicitRule(): void {
		$this->service->armHersteltermijn(instance: $this->instance(), durationDays: 14);

		$config = $this->engine->calls['arm'][0]['config'];
		self::assertSame('none', $config['legalEffect']);
		self::assertSame(['value' => 14, 'unit' => 'calendarDays'], $config['sla']);
		self::assertArrayNotHasKey('ladder', $config);
		self::assertCount(1, $config['escalationRules']);
		self::assertSame('slaBreached', $config['escalationRules'][0]['trigger']);
		self::assertSame(0, $config['escalationRules'][0]['offset']);
		self::assertSame('pauze-verlopen', $config['escalationRules'][0]['message']);
		self::assertSame('hersteltermijn', $config['metadata']['kind']);
	}

	/**
	 * Opschorting carries the Awb 4:5 basis and the pause end.
	 *
	 * @return void
	 */
	public function testSuspendCarriesBasisReasonAndUntil(): void {
		$until = new DateTimeImmutable('2026-06-15');
		$done = $this->service->suspendBeslistermijn(
			instance: $this->instance(),
			reason: 'Aanvrager moet aanvulling indienen',
			until: $until
		);

		self::assertTrue($done);
		$call = $this->engine->calls['suspend'][0];
		self::assertSame('timer-1', $call['uuid']);
		self::assertSame('Aanvrager moet aanvulling indienen', $call['reason']);
		self::assertSame('Awb 4:5', $call['basis']);
		self::assertSame($until, $call['until']);
	}

	/**
	 * @return void
	 */
	public function testResumeTargetsTheStoredTimer(): void {
		$done = $this->service->resumeBeslistermijn(instance: $this->instance(), reason: 'Aanvulling ontvangen; termijn hervat');

		self::assertTrue($done);
		self::assertSame('timer-1', $this->engine->calls['resume'][0]['uuid']);
	}

	/**
	 * Standard verdaging uses the bounded extend; the supervisor path uses
	 * the separately authorized override.
	 *
	 * @return void
	 */
	public function testExtendChoosesTheAuthorizedPathPerMode(): void {
		$this->service->extendBeslistermijn(instance: $this->instance(), days: 35, rationale: 'meer onderzoek', supervisor: false);
		$this->service->extendBeslistermijn(instance: $this->instance(), days: 30, rationale: 'supervisor akkoord', supervisor: true);

		self::assertSame(35, $this->engine->calls['extend'][0]['amount']);
		self::assertSame('calendarDays', $this->engine->calls['extend'][0]['unit']);
		self::assertSame(30, $this->engine->calls['extendWithOverride'][0]['amount']);
		self::assertSame('supervisor', $this->engine->calls['extendWithOverride'][0]['actor']);
	}

	/**
	 * @return void
	 */
	public function testCancelForInstanceCancelsBySubject(): void {
		$count = $this->service->cancelForInstance(instanceId: 'ti-1', reason: 'Termijn voltooid door beschikking');

		self::assertSame(2, $count);
		$call = $this->engine->calls['cancelForSubject'][0];
		self::assertSame('object', $call['subjectType']);
		self::assertSame('ti-1', $call['subjectUuid']);
	}

	/**
	 * Without a stored engineTimerId there is nothing to address.
	 *
	 * @return void
	 */
	public function testLifecycleCallsWithoutTimerIdAreNoOps(): void {
		$row = $this->instance();
		unset($row['engineTimerId']);

		self::assertFalse($this->service->suspendBeslistermijn(instance: $row, reason: 'x', until: null));
		self::assertFalse($this->service->resumeBeslistermijn(instance: $row, reason: 'x'));
		self::assertFalse($this->service->extendBeslistermijn(instance: $row, days: 7, rationale: 'x', supervisor: false));
		self::assertSame([], $this->engine->calls);
	}

	/**
	 * An absent engine degrades to null/false, never a throw (D-7).
	 *
	 * @return void
	 */
	public function testAbsentEngineDegradesToNoOp(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturn(null);
		$service = new TermijnTimerService($settings, $this->createMock(LoggerInterface::class));

		self::assertNull($service->armBeslistermijn(instance: $this->instance(), definitie: []));
		self::assertFalse($service->suspendBeslistermijn(instance: $this->instance(), reason: 'x', until: null));
		self::assertSame(0, $service->cancelForInstance(instanceId: 'ti-1', reason: 'x'));
	}

	/**
	 * A refusing engine is logged and degraded, never rethrown: the
	 * domain flow must proceed on case data.
	 *
	 * @return void
	 */
	public function testRefusingEngineDegradesToNoOp(): void {
		$this->engine->refuse = true;

		self::assertNull($this->service->armBeslistermijn(instance: $this->instance(), definitie: ['standardDurationDays' => 56]));
		self::assertFalse($this->service->suspendBeslistermijn(instance: $this->instance(), reason: 'x', until: null));
		self::assertFalse($this->service->extendBeslistermijn(instance: $this->instance(), days: 7, rationale: 'x', supervisor: false));
		self::assertSame(0, $this->service->cancelForInstance(instanceId: 'ti-1', reason: 'x'));
	}
}
