<?php

/**
 * Unit tests for the ArmTermijnEngineTimers repair step.
 *
 * Migration onto engine timers (REQ-TOT-006): in-flight instances are
 * armed at their CURRENT deadline, paused rows are suspended right after
 * arming, terminal rows and rows already carrying a timer are skipped,
 * and a second run arms nothing.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
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

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\ArmTermijnEngineTimers;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use OCA\Dossiq\Tests\Unit\Service\FlowTimerEngineFake;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Repair\ArmTermijnEngineTimers
 *
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\TermijnService
 * @uses \OCA\Dossiq\Service\TermijnTimerService
 */
class ArmTermijnEngineTimersTest extends TestCase {
	private FakeTermijnStore $objects;
	private FlowTimerEngineFake $engine;
	private ArmTermijnEngineTimers $step;

	protected function setUp(): void {
		$this->objects = new FakeTermijnStore();
		$this->engine = new FlowTimerEngineFake();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getOpenRegisterClass')
			->with(TermijnTimerService::ENGINE_CLASS)
			->willReturn($this->engine);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'termijn_definitie_schema' => 'deadlineDefinition',
					'termijn_instance_schema' => 'deadlineInstance',
					'termijn_gebeurtenis_schema' => 'termijnGebeurtenis',
					default => '',
				};
			},
		);

		$logger = $this->createMock(LoggerInterface::class);
		$termService = new TermijnService($settings, $logger);
		$this->step = new ArmTermijnEngineTimers(
			$settings,
			$termService,
			new TermijnTimerService($settings, $logger),
			$logger
		);

		$this->objects->seed('deadlineDefinition', [
			'id' => 'td-ov',
			'caseType' => 'omgevingsvergunning-regulier',
			'standardDurationDays' => 56,
			'countExtensions' => 1,
			'validFrom' => '2026-01-01',
		]);
	}

	/**
	 * Seed one instance.
	 *
	 * @param string $id Instance id.
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return void
	 */
	private function seedInstance(string $id, array $overrides = []): void {
		$this->objects->seed('deadlineInstance', array_merge([
			'id' => $id,
			'case' => 'Z/2026/' . $id,
			'deadlineDefinition' => 'td-ov',
			'startDate' => '2026-06-01T10:00:00+00:00',
			'endDateCalculated' => '2026-07-27',
			'endDateCurrent' => '2026-07-27',
			'status' => 'lopend',
			'notificatiesVerstuurd' => [],
		], $overrides));
	}

	/**
	 * In-flight rows arm (verlengd at the CURRENT deadline), paused rows
	 * suspend, terminal and already-armed rows are skipped.
	 *
	 * @return void
	 */
	public function testMigratesOpenInstancesOnly(): void {
		$this->seedInstance('ti-lopend');
		$this->seedInstance('ti-verlengd', ['status' => 'verlengd', 'endDateCurrent' => '2026-08-31']);
		$this->seedInstance('ti-paused', ['status' => 'paused', 'pauseDeadline' => '2026-06-20']);
		$this->seedInstance('ti-done', ['status' => 'completed']);
		$this->seedInstance('ti-armed', ['engineTimerId' => 'timer-existing']);

		$this->step->run($this->createMock(IOutput::class));

		self::assertCount(3, $this->engine->calls['arm']);
		self::assertCount(1, $this->engine->calls['suspend']);

		// The verlengd row arms at its CURRENT deadline: 2026-06-01 ->
		// 2026-08-31 is 91 calendar days, not the definition's 56.
		$slaBySubject = [];
		foreach ($this->engine->calls['arm'] as $call) {
			$slaBySubject[(string)$call['config']['subjectUuid']] = $call['config']['sla']['value'];
		}

		self::assertSame(56, $slaBySubject['ti-lopend']);
		self::assertSame(91, $slaBySubject['ti-verlengd']);
		self::assertArrayNotHasKey('ti-done', $slaBySubject);
		self::assertArrayNotHasKey('ti-armed', $slaBySubject);

		// Every migrated row now carries its timer uuid.
		foreach (['ti-lopend', 'ti-verlengd', 'ti-paused'] as $id) {
			self::assertNotSame('', (string)($this->objects->store['deadlineInstance'][$id]['engineTimerId'] ?? ''));
		}
	}

	/**
	 * Idempotence: a second run arms nothing new (REQ-TOT-006).
	 *
	 * @return void
	 */
	public function testSecondRunArmsNothing(): void {
		$this->seedInstance('ti-1');

		$this->step->run($this->createMock(IOutput::class));
		$armedAfterFirst = count($this->engine->calls['arm']);
		$this->step->run($this->createMock(IOutput::class));

		self::assertSame($armedAfterFirst, count($this->engine->calls['arm']));
	}

	/**
	 * An unreachable engine is a warning and a failed count, never a
	 * broken upgrade.
	 *
	 * @return void
	 */
	public function testRefusingEngineDoesNotBreakTheUpgrade(): void {
		$this->seedInstance('ti-1');
		$this->engine->refuse = true;

		$this->step->run($this->createMock(IOutput::class));

		self::assertSame('', (string)($this->objects->store['deadlineInstance']['ti-1']['engineTimerId'] ?? ''));
	}
}
