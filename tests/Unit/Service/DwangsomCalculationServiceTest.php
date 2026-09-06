<?php

/**
 * Unit tests for DwangsomCalculationService.
 *
 * Exercises the AWB 4:17 tier arithmetic through the derived
 * `accrueThrough()` settlement (termijnbewaking-op-engine-timers): the
 * fixture pairs assert the derived value at day N equals N applications
 * of the legacy one-tick-per-cron-run step, so the tariff arithmetic is
 * proven unchanged while the trigger moves off the cron.
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
use OCA\Dossiq\Service\DwangsomCalculationService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\DwangsomCalculationService
 */
class DwangsomCalculationServiceTest extends TestCase {
	private FakeTermijnStore $objects;
	private DwangsomCalculationService $service;

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
					'dwangsom_berekening_schema' => 'penaltyPaymentCalculation',
					default => '',
				};
			},
		);

		$this->service = new DwangsomCalculationService($settings, $this->createMock(LoggerInterface::class));
	}

	/**
	 * Seed one lopend berekening starting 2026-03-29.
	 *
	 * @param string $id Berekening id.
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return void
	 */
	private function seedCalculation(string $id, array $overrides = []): void {
		$this->objects->seed('penaltyPaymentCalculation', array_merge([
			'id' => $id,
			'noticeOfDefault' => 'ig-1',
			'deadlineInstance' => 'ti-1',
			'startDate' => '2026-03-29',
			'currentDag' => 0,
			'cumulativeAmount' => 0,
			'plafondCalculated' => 144200,
			'plafondBereikt' => false,
			'status' => 'lopend',
			'regime' => 'awb-default',
		], $overrides));
	}

	/**
	 * @return void
	 */
	public function testAwbTierBoundaries(): void {
		self::assertSame(2300, $this->service->dailyTariffAwb(1));
		self::assertSame(2300, $this->service->dailyTariffAwb(14));
		self::assertSame(3500, $this->service->dailyTariffAwb(15));
		self::assertSame(3500, $this->service->dailyTariffAwb(28));
		self::assertSame(4500, $this->service->dailyTariffAwb(29));
		self::assertSame(4500, $this->service->dailyTariffAwb(60));
	}

	/**
	 * Day 1 at tier 1 — the same result one legacy daily tick produced.
	 *
	 * @return void
	 */
	public function testAccrueThroughDayOneAtTier1(): void {
		$this->seedCalculation('b1');

		$row = $this->service->accrueThrough('b1', new DateTimeImmutable('2026-03-30'));
		self::assertSame(1, $row['currentDag']);
		self::assertSame(2300, $row['dailyRate']);
		self::assertSame(2300, $row['cumulativeAmount']);
		self::assertFalse($row['plafondBereikt']);
	}

	/**
	 * Advancing from a persisted day 14 into day 15 crosses into tier 2 —
	 * the legacy tick's exact expectation.
	 *
	 * @return void
	 */
	public function testAccrueThroughTransitionsToTier2OnDay15(): void {
		$this->seedCalculation('b2', ['currentDag' => 14, 'cumulativeAmount' => 32200]);

		$row = $this->service->accrueThrough('b2', new DateTimeImmutable('2026-04-13'));
		self::assertSame(15, $row['currentDag']);
		self::assertSame(3500, $row['dailyRate']);
		self::assertSame(35700, $row['cumulativeAmount']);
	}

	/**
	 * Day 29 crosses into tier 3 — the legacy tick's exact expectation.
	 *
	 * @return void
	 */
	public function testAccrueThroughTransitionsToTier3OnDay29(): void {
		$this->seedCalculation('b3', ['currentDag' => 28, 'cumulativeAmount' => 81200]);

		$row = $this->service->accrueThrough('b3', new DateTimeImmutable('2026-04-27'));
		self::assertSame(29, $row['currentDag']);
		self::assertSame(4500, $row['dailyRate']);
		self::assertSame(85700, $row['cumulativeAmount']);
	}

	/**
	 * FIXTURE PAIR: one catch-up settlement at day N equals N legacy
	 * one-day ticks. Day 20 = 14 x 2300 + 6 x 3500 = 53200.
	 *
	 * @return void
	 */
	public function testCatchUpEqualsTheLegacyDailySeries(): void {
		$this->seedCalculation('b-pair');

		$row = $this->service->accrueThrough('b-pair', new DateTimeImmutable('2026-04-18'));
		self::assertSame(20, $row['currentDag']);
		self::assertSame((14 * 2300) + (6 * 3500), $row['cumulativeAmount']);

		// Idempotent per day: settling the same moment again changes nothing.
		$again = $this->service->accrueThrough('b-pair', new DateTimeImmutable('2026-04-18'));
		self::assertSame(20, $again['currentDag']);
		self::assertSame($row['cumulativeAmount'], $again['cumulativeAmount']);
	}

	/**
	 * Inside the grace window (now before startDate) nothing accrues —
	 * unlike the retired cron tick, which accrued on every run.
	 *
	 * @return void
	 */
	public function testNoAccrualBeforeStartDate(): void {
		$this->seedCalculation('b-grace');

		$row = $this->service->accrueThrough('b-grace', new DateTimeImmutable('2026-03-20'));
		self::assertSame(0, $row['currentDag']);
		self::assertSame(0, $row['cumulativeAmount']);
	}

	/**
	 * @return void
	 */
	public function testAccrueThroughCapsAtPlafond(): void {
		$this->seedCalculation('b4', ['currentDag' => 41, 'cumulativeAmount' => 142000]);

		$row = $this->service->accrueThrough('b4', new DateTimeImmutable('2026-05-10'));
		self::assertSame(144200, $row['cumulativeAmount']);
		self::assertTrue($row['plafondBereikt']);

		// A later settlement after the plafond changes nothing.
		$row2 = $this->service->accrueThrough('b4', new DateTimeImmutable('2026-06-10'));
		self::assertSame(144200, $row2['cumulativeAmount']);
	}

	/**
	 * The stop settles through the stop moment FIRST, so the legally
	 * binding amount reflects elapsed days rather than the last sync.
	 *
	 * @return void
	 */
	public function testStopForBeschikkingSettlesThenLocksDefinitievBedrag(): void {
		$this->seedCalculation('b5', ['currentDag' => 3, 'cumulativeAmount' => 6900]);

		$stopped = $this->service->stopForBeschikking('b5', new DateTimeImmutable('2026-04-03'));
		self::assertSame('gestopt-wegens-decision', $stopped['status']);
		self::assertSame(5, $stopped['currentDag']);
		self::assertSame(11500, $stopped['definitiveAmount']);

		// Further settlement is a no-op on stopped berekeningen.
		$row = $this->service->accrueThrough('b5', new DateTimeImmutable('2026-05-01'));
		self::assertSame(5, $row['currentDag']);
		self::assertSame(11500, $row['cumulativeAmount']);
	}

	/**
	 * @return void
	 */
	public function testCustomRegimeUsesDefinitionTariff(): void {
		// Seed Woo definition + instance.
		$this->objects->seed('deadlineDefinition', [
			'id' => 'td-woo',
			'caseType' => 'woo-verzoek',
			'deviatingPenaltyPaymentRegime' => ['dailyTariff' => 1500, 'plafond' => 50000, 'grace' => 14],
			'validFrom' => '2026-01-01',
		]);
		$this->objects->seed('deadlineInstance', [
			'id' => 'ti-woo',
			'deadlineDefinition' => 'td-woo',
		]);
		$this->seedCalculation('b-woo', [
			'noticeOfDefault' => 'ig-woo',
			'deadlineInstance' => 'ti-woo',
			'plafondCalculated' => 50000,
			'regime' => 'afwijkend',
		]);

		$row = $this->service->accrueThrough('b-woo', new DateTimeImmutable('2026-03-30'));
		self::assertSame(1500, $row['dailyRate']);
		self::assertSame(1500, $row['cumulativeAmount']);
	}
}
