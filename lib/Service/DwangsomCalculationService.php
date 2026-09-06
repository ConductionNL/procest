<?php

/**
 * Dossiq DwangsomCalculationService.
 *
 * Daily-accruing dwangsom calculator per AWB 4:17:
 *   - Tier 1: days 1-14 at EUR23/day  (2300 cents)
 *   - Tier 2: days 15-28 at EUR35/day (3500 cents)
 *   - Tier 3: day 29+ at EUR45/day    (4500 cents)
 *   - Plafond: EUR1442 (144200 cents)
 *
 * Custom regimes (e.g. Woo €15/day max €500) override via the
 * DwangsomBerekening.regime=afwijkend + the linked TermijnDefinitie
 * (resolved by {@see NoticeOfDefaultService::registerNoticeOfDefault}).
 *
 * Money values: integer EUR cents throughout (ADR-031).
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-06-dwangsom-calculation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Daily-accruing dwangsom calculator.
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-06-dwangsom-calculation/tasks.md
 */
class DwangsomCalculationService {
	use SearchesObjects;

	/**
	 * AWB-default tier 1 daily tariff in EUR cents (days 1-14).
	 */
	public const AWB_TIER_1_CENTS = 2300;

	/**
	 * AWB-default tier 2 daily tariff in EUR cents (days 15-28).
	 */
	public const AWB_TIER_2_CENTS = 3500;

	/**
	 * AWB-default tier 3 daily tariff in EUR cents (day 29+).
	 */
	public const AWB_TIER_3_CENTS = 4500;

	/**
	 * AWB-default plafond in EUR cents (EUR1442).
	 */
	public const AWB_PLAFOND_CENTS = 144200;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Compute today's dagtarief for a 1-indexed day count under AWB-default.
	 *
	 * Day 1-14 → tier 1, day 15-28 → tier 2, day 29+ → tier 3.
	 *
	 * @param int $dayNumber 1-indexed day.
	 *
	 * @return int Daily tariff in EUR cents.
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-06-dwangsom-calculation/tasks.md
	 */
	public function dailyTariffAwb(int $dayNumber): int {
		if ($dayNumber <= 14) {
			return self::AWB_TIER_1_CENTS;
		}

		if ($dayNumber <= 28) {
			return self::AWB_TIER_2_CENTS;
		}

		return self::AWB_TIER_3_CENTS;
	}//end dailyTariffAwb()

	/**
	 * Settle a DwangsomBerekening's accrual through a moment in time.
	 *
	 * Derives the day count from the berekening's `startDate` (receipt +
	 * grace, per AWB 4:17) to `$now` and applies the identical per-day
	 * tier step for every not-yet-accrued day, capped at the plafond.
	 * Idempotent per day and catch-up-safe: it replaces the retired
	 * cron-tick accrual, which advanced one day per scan run and so
	 * under-accrued on missed runs, over-accrued on doubled runs, and
	 * accrued during the grace window. The timers consult this
	 * calculation; nothing schedules it.
	 *
	 * @param string $calculationId Berekening id.
	 * @param DateTimeImmutable|null $now The clock (defaults to now).
	 *
	 * @return array<string, mixed>|null The settled row.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function accrueThrough(string $calculationId, ?DateTimeImmutable $now = null): ?array {
		$store = $this->calculationStore();
		if ($store === null) {
			return null;
		}

		$row = $this->fetchCalculationRow(
			objectService: $store['objects'],
			register: $store['register'],
			schema: $store['schema'],
			calculationId: $calculationId
		);
		if ($row === null) {
			return null;
		}

		if (($row['status'] ?? '') !== 'lopend' || ($row['plafondBereikt'] ?? false) === true) {
			return $row;
		}

		$settled = $this->settle(row: $row, now: ($now ?? new DateTimeImmutable()));
		if ($settled === null) {
			return $row;
		}

		return $this->persistCalculation(
			objectService: $store['objects'],
			register: $store['register'],
			schema: $store['schema'],
			row: $settled,
			calculationId: $calculationId
		);
	}//end accrueThrough()

	/**
	 * Apply the per-day tier step for every not-yet-accrued day up to the
	 * moment, or null when the row is already settled for that day.
	 *
	 * @param array<string, mixed> $row Berekening row (status lopend, below plafond).
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return array<string, mixed>|null The settled row, or null when nothing accrued.
	 */
	private function settle(array $row, DateTimeImmutable $now): ?array {
		$targetDay = $this->elapsedAccrualDays(row: $row, now: $now);
		if ((int)($row['currentDag'] ?? 0) >= $targetDay) {
			return null;
		}

		while ((int)($row['currentDag'] ?? 0) < $targetDay && ($row['plafondBereikt'] ?? false) === false) {
			$row = $this->applyDailyAccrual(row: $row);
		}

		return $row;
	}//end settle()

	/**
	 * The berekening store coordinates, or null when unconfigured.
	 *
	 * @return array{objects: object, register: string, schema: string}|null The store.
	 */
	private function calculationStore(): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('dwangsom_berekening_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		return ['objects' => $objectService, 'register' => $register, 'schema' => $schema];
	}//end calculationStore()

	/**
	 * The number of accrual days elapsed between the berekening's start
	 * and a moment, at day granularity, never negative.
	 *
	 * @param array<string, mixed> $row Berekening row.
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return int Whole accrual days elapsed (0 while inside the grace window).
	 */
	private function elapsedAccrualDays(array $row, DateTimeImmutable $now): int {
		$start = (string)($row['startDate'] ?? '');
		if ($start === '') {
			return 0;
		}

		try {
			$startDay = new DateTimeImmutable((new DateTimeImmutable($start))->format('Y-m-d'));
			$today = new DateTimeImmutable($now->format('Y-m-d'));
		} catch (\Throwable $e) {
			return 0;
		}

		if ($today <= $startDay) {
			return 0;
		}

		return (int)$startDay->diff($today)->days;
	}//end elapsedAccrualDays()

	/**
	 * Fetch a DwangsomBerekening row, logging and swallowing lookup failures.
	 *
	 * @param object $objectService OpenRegister object service.
	 * @param string $register Register identifier.
	 * @param string $schema Schema identifier.
	 * @param string $calculationId Berekening id.
	 *
	 * @return array<string, mixed>|null The row, or null when unavailable.
	 */
	private function fetchCalculationRow(
		object $objectService,
		string $register,
		string $schema,
		string $calculationId,
	): ?array {
		try {
			return $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $calculationId);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'DwangsomCalculation lookup failed',
				['id' => $calculationId, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end fetchBerekeningRow()

	/**
	 * Accrue one calculation day onto a berekening row (capped at the plafond).
	 *
	 * @param array<string, mixed> $row Berekening row.
	 *
	 * @return array<string, mixed> The row with the new day, tariff and cumulative amount.
	 */
	private function applyDailyAccrual(array $row): array {
		$currentDay = (int)($row['currentDag'] ?? 0);
		$cumulative = (int)($row['cumulativeAmount'] ?? 0);
		$plafond = (int)($row['plafondCalculated'] ?? self::AWB_PLAFOND_CENTS);
		$regime = (string)($row['regime'] ?? 'awb-default');

		$nextDay = ($currentDay + 1);
		$tariff = $this->dailyTariffAwb(dayNumber: $nextDay);
		if ($regime === 'afwijkend') {
			$tariff = $this->resolveCustomDailyTariff(calculation: $row);
		}

		$newCumul = ($cumulative + $tariff);
		$plafondHit = false;
		if ($newCumul >= $plafond) {
			$newCumul = $plafond;
			$plafondHit = true;
		}

		$row['currentDag'] = $nextDay;
		$row['dailyRate'] = $tariff;
		$row['cumulativeAmount'] = $newCumul;
		$row['plafondBereikt'] = $plafondHit;

		return $row;
	}//end applyDailyAccrual()

	/**
	 * Persist a berekening row, falling back to the in-memory row on failure.
	 *
	 * @param object $objectService OpenRegister object service.
	 * @param string $register Register identifier.
	 * @param string $schema Schema identifier.
	 * @param array<string, mixed> $row Berekening row to persist.
	 * @param string $calculationId Berekening id (for logging).
	 *
	 * @return array<string, mixed> The saved row, or the supplied row.
	 */
	private function persistCalculation(
		object $objectService,
		string $register,
		string $schema,
		array $row,
		string $calculationId,
	): array {
		try {
			$saved = $this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				object: $row
			);
			return ($saved ?? $row);
		} catch (\Throwable $e) {
			$this->logger->error(
				'DwangsomCalculation persist failed',
				['id' => $calculationId, 'error' => $e->getMessage()]
			);
			return $row;
		}
	}//end persistBerekening()

	/**
	 * Stop a DwangsomBerekening because the beschikking was filed.
	 *
	 * Settles the accrual through the stop moment FIRST — the legally
	 * binding `definitiveAmount` reflects the elapsed days, not the last
	 * time anything happened to sync — then sets
	 * status=gestopt-wegens-beschikking and locks definitievBedrag.
	 *
	 * @param string $calculationId Berekening id.
	 * @param DateTimeImmutable|null $now The stop moment (defaults to now).
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function stopForBeschikking(string $calculationId, ?DateTimeImmutable $now = null): ?array {
		$this->accrueThrough(calculationId: $calculationId, now: $now);
		$store = $this->calculationStore();
		if ($store === null) {
			return null;
		}

		$row = $this->fetchCalculationRow(
			objectService: $store['objects'],
			register: $store['register'],
			schema: $store['schema'],
			calculationId: $calculationId
		);
		if ($row === null) {
			return null;
		}

		$row['status'] = 'gestopt-wegens-decision';
		$row['definitiveAmount'] = (int)($row['cumulativeAmount'] ?? 0);

		return $this->persistCalculation(
			objectService: $store['objects'],
			register: $store['register'],
			schema: $store['schema'],
			row: $row,
			calculationId: $calculationId
		);
	}//end stopForBeschikking()

	/**
	 * Resolve the custom daily tariff from the linked TermijnDefinitie.
	 *
	 * @param array<string, mixed> $calculation Berekening row.
	 *
	 * @return int Cents.
	 */
	private function resolveCustomDailyTariff(array $calculation): int {
		$instanceId = (string)($calculation['deadlineInstance'] ?? '');
		if ($instanceId === '') {
			return self::AWB_TIER_1_CENTS;
		}

		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$instSchema = (string)$this->settingsService->getConfigValue('termijn_instance_schema');
		$defSchema = (string)$this->settingsService->getConfigValue('termijn_definitie_schema');
		if ($objectService === null || $register === '' || $instSchema === '' || $defSchema === '') {
			return self::AWB_TIER_1_CENTS;
		}

		$defId = $this->resolveTermijnDefinitieId(
			objectService: $objectService,
			register: $register,
			schema: $instSchema,
			instanceId: $instanceId
		);
		if ($defId === '') {
			return self::AWB_TIER_1_CENTS;
		}

		return $this->resolveRegimeDailyTariff(
			objectService: $objectService,
			register: $register,
			schema: $defSchema,
			definitieId: $defId
		);
	}//end resolveCustomDailyTariff()

	/**
	 * Resolve the TermijnDefinitie id linked to a TermijnInstance.
	 *
	 * @param object $objectService OpenRegister object service.
	 * @param string $register Register identifier.
	 * @param string $schema TermijnInstance schema identifier.
	 * @param string $instanceId TermijnInstance id.
	 *
	 * @return string The definitie id, or an empty string when unresolvable.
	 */
	private function resolveTermijnDefinitieId(
		object $objectService,
		string $register,
		string $schema,
		string $instanceId,
	): string {
		try {
			$instance = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $instanceId);
		} catch (\Throwable $e) {
			return '';
		}

		if ($instance === null) {
			return '';
		}

		return (string)($instance['deadlineDefinition'] ?? '');
	}//end resolveTermijnDefinitieId()

	/**
	 * Read the afwijkend regime daily tariff from a TermijnDefinitie.
	 *
	 * @param object $objectService OpenRegister object service.
	 * @param string $register Register identifier.
	 * @param string $schema TermijnDefinitie schema identifier.
	 * @param string $definitieId TermijnDefinitie id.
	 *
	 * @return int Cents, falling back to the AWB tier 1 tariff.
	 */
	private function resolveRegimeDailyTariff(
		object $objectService,
		string $register,
		string $schema,
		string $definitieId,
	): int {
		try {
			$def = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $definitieId);
		} catch (\Throwable $e) {
			return self::AWB_TIER_1_CENTS;
		}

		if ($def === null) {
			return self::AWB_TIER_1_CENTS;
		}

		$regime = $def['deviatingPenaltyPaymentRegime'] ?? null;
		if (is_array($regime) === true && isset($regime['dailyTariff']) === true) {
			return (int)$regime['dailyTariff'];
		}

		return self::AWB_TIER_1_CENTS;
	}//end resolveRegimeDailyTariff()
}//end class
