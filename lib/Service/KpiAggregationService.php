<?php

/**
 * Dossiq KPI Aggregation Service
 *
 * Computes the dashboard KPI metrics through OpenRegister's ObjectService.
 *
 * 🔴 THIS USED TO BE HAND-WRITTEN SQL AGAINST OPENREGISTER'S OWN TABLES, AND IT
 * RETURNED ZERO FOR EVERYTHING. Three faults stacked, and each one alone was
 * enough. The predicates used MySQL's `JSON_EXTRACT`, which does not exist on
 * PostgreSQL, so every query threw. Each query caught the exception and returned
 * 0, so the throw was invisible. And the case rows were selected by
 * `schema.title LIKE '%zaak%'`, while the shipped schema is titled "Case", so
 * even on MySQL it would have matched nothing. Measured on a register holding 16
 * open cases and 32 tasks, the endpoint answered 0 for all of them.
 *
 * 🔴 A ZERO IS INDISTINGUISHABLE FROM "NO DATA", WHICH IS WHY IT SURVIVED. The
 * numbers are only wrong to someone who knows what they should be. Nothing
 * errored, nothing logged above debug, and the values were cached.
 *
 * Reading through ObjectService instead of the objects table is also what
 * ADR-022 asks of a leaf app: OpenRegister shards objects across per-register
 * tables, so a query naming `openregister_objects` is coupled to storage layout
 * this app does not own.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Dashboard KPI aggregation.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — needs OpenRegister service access
 *
 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-001
 */
class KpiAggregationService {
	/**
	 * Upper bound on rows pulled for the metrics that must fold rows rather than
	 * count them (the two breakdowns, and the processing-time average).
	 *
	 * Counts never fetch rows at all, so this only caps the three metrics that
	 * genuinely need values. A register larger than this reports on its most
	 * recent slice rather than timing out.
	 *
	 * @var integer
	 */
	private const FOLD_LIMIT = 2000;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration service.
	 * @param ContainerInterface $container The DI container.
	 * @param IAppManager $appManager Used to establish that OpenRegister is present.
	 * @param LoggerInterface $logger The logger interface.
	 *
	 * @return void
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private ContainerInterface $container,
		private IAppManager $appManager,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Compute every dashboard KPI for one user.
	 *
	 * The keys are the contract REQ-DASH-001 reads: a count per card, plus the
	 * secondary number each card's sub-label needs.
	 *
	 * @param string $userId The user whose task counts these are.
	 *
	 * @return array<string, mixed> The KPI payload.
	 *
	 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-001
	 */
	public function computeKpis(string $userId): array {
		$ids = $this->ids();
		if ($ids === null) {
			$this->logger->warning('[KpiAggregationService] Not configured against a register yet.');
			return $this->emptyKpis();
		}

		$now = new DateTimeImmutable('today');
		$today = $now->format('Y-m-d');
		$monthStart = $now->format('Y-m-01');
		$monthEnd = $now->format('Y-m-t');

		$closed = $this->closedThisMonth(ids: $ids, monthStart: $monthStart, monthEnd: $monthEnd);
		$openCases = $this->rows(
			ids: $ids,
			schema: $ids['case'],
			filters: ['isFinalStatus' => 0]
		);

		return [
			'openCount' => $this->countCases(ids: $ids, filters: ['isFinalStatus' => 0]),
			'newToday' => $this->countCases(ids: $ids, filters: ['isFinalStatus' => 0, 'startDate' => $today]),
			'overdueCount' => $this->countCases(
				ids: $ids,
				filters: ['isFinalStatus' => 0, 'deadline' => ['lt' => $today]]
			),
			'completedCount' => count($closed),
			'taskCount' => $this->countTasks(
				ids: $ids,
				filters: ['assignee' => $userId, 'isTerminalStatus' => 0]
			),
			'tasksDueToday' => $this->countTasks(
				ids: $ids,
				filters: [
					'assignee' => $userId,
					'isTerminalStatus' => 0,
					'dueDate' => ['gte' => ($today . 'T00:00:00+00:00'), 'lte' => ($today . 'T23:59:59+00:00')],
				]
			),
			'statusBreakdown' => $this->breakdown(rows: $openCases, field: 'status', label: 'status'),
			'typeBreakdown' => $this->breakdown(rows: $openCases, field: 'caseType', label: 'type'),
			'avgProcessingDays' => $this->averageProcessingDays(closed: $closed),
			'slaCompliance' => $this->slaCompliance(closed: $closed),
		];
	}//end computeKpis()

	/**
	 * The shape returned when the app is not configured against a register.
	 *
	 * Zeroes here are honest: there is genuinely nothing to count. The old
	 * implementation returned the same shape when its queries THREW, which is
	 * what made a broken endpoint look like an empty one.
	 *
	 * @return array<string, mixed> The empty payload.
	 */
	private function emptyKpis(): array {
		return [
			'openCount' => 0,
			'newToday' => 0,
			'overdueCount' => 0,
			'completedCount' => 0,
			'taskCount' => 0,
			'tasksDueToday' => 0,
			'statusBreakdown' => [],
			'typeBreakdown' => [],
			'avgProcessingDays' => null,
			'slaCompliance' => null,
		];
	}//end emptyKpis()

	/**
	 * Count cases matching a filter.
	 *
	 * @param array<string, string> $ids The register and schema ids.
	 * @param array $filters The filter criteria.
	 *
	 * @return int The count.
	 */
	private function countCases(array $ids, array $filters): int {
		return $this->countObjects(ids: $ids, schema: $ids['case'], filters: $filters);
	}//end countCases()

	/**
	 * Count tasks matching a filter.
	 *
	 * @param array<string, string> $ids The register and schema ids.
	 * @param array $filters The filter criteria.
	 *
	 * @return int The count.
	 */
	private function countTasks(array $ids, array $filters): int {
		return $this->countObjects(ids: $ids, schema: $ids['caseTask'], filters: $filters);
	}//end countTasks()

	/**
	 * Count objects of one schema, server-side.
	 *
	 * 🔴 THE REGISTER AND SCHEMA GO INSIDE `filters`, NOT VIA setRegister(). With
	 * only setRegister()/setSchema() the count IGNORES the filters entirely, and
	 * with neither it sums every register on the instance: measured 7531 against
	 * a register holding 37 cases. Passing them as filters alongside the field
	 * predicates is the form that answers correctly.
	 *
	 * 🔴 BOOLEANS MUST BE 0/1, NOT true/false. A PHP bool reaches PostgreSQL as a
	 * type it will not compare against the stored JSON and the query throws.
	 *
	 * @param array<string, string> $ids The register and schema ids.
	 * @param string $schema The schema id to count within.
	 * @param array $filters The filter criteria.
	 *
	 * @return int The count, or 0 when OpenRegister cannot answer.
	 */
	private function countObjects(array $ids, string $schema, array $filters): int {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return 0;
		}

		try {
			// 🔴 SET THE CONTEXT EVERY TIME. `count()` reads the register/schema
			// CONTEXT held on the shared ObjectService, not the ids in its own
			// filters, and `findAll()` overwrites that context as a side effect.
			// So a count issued after any read of another schema silently counts
			// in the WRONG schema and answers 0. Measured: a task count returns 23
			// on its own and 0 immediately after a findAll over cases.
			$objectService->setRegister($ids['register']);
			$objectService->setSchema($schema);

			return (int)$objectService->count(
				['filters' => (['register' => $ids['register'], 'schema' => $schema] + $filters)]
			);
		} catch (\Throwable $e) {
			// Logged at WARNING, not debug: a KPI that silently reads zero is the
			// defect this class was rewritten to remove.
			$this->logger->warning(
				'[KpiAggregationService] Count failed, reporting 0 for this metric',
				['schema' => $schema, 'filters' => array_keys($filters), 'exception' => $e->getMessage()]
			);
			return 0;
		}
	}//end countObjects()

	/**
	 * Fetch rows of one schema, bounded.
	 *
	 * @param array<string, string> $ids The register and schema ids.
	 * @param string $schema The schema id to read.
	 * @param array $filters The filter criteria.
	 *
	 * @return array<int, array> The rows, as plain arrays.
	 */
	private function rows(array $ids, string $schema, array $filters): array {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return [];
		}

		try {
			$results = $objectService->findAll(
				[
					'filters' => (['register' => $ids['register'], 'schema' => $schema] + $filters),
					'limit' => self::FOLD_LIMIT,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[KpiAggregationService] Read failed, reporting an empty set for this metric',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$found = ($results['results'] ?? $results);
		if (is_array($found) === false) {
			return [];
		}

		$rows = [];
		foreach ($found as $object) {
			$rows[] = $this->toArray(object: $object);
		}

		return $rows;
	}//end rows()

	/**
	 * The cases that reached a final status during the current calendar month.
	 *
	 * @param array<string, string> $ids The register and schema ids.
	 * @param string $monthStart First day of this month, as Y-m-d.
	 * @param string $monthEnd Last day of this month, as Y-m-d.
	 *
	 * @return array<int, array> The closed cases.
	 */
	private function closedThisMonth(array $ids, string $monthStart, string $monthEnd): array {
		return $this->rows(
			ids: $ids,
			schema: $ids['case'],
			filters: ['endDate' => ['gte' => $monthStart, 'lte' => $monthEnd]]
		);
	}//end closedThisMonth()

	/**
	 * Group rows by one field, most frequent first.
	 *
	 * @param array<int, array> $rows The rows to fold.
	 * @param string $field The field to group on.
	 * @param string $label The key the caller expects the value under.
	 *
	 * @return array<int, array{count: integer}> The breakdown.
	 */
	private function breakdown(array $rows, string $field, string $label): array {
		$counts = [];
		foreach ($rows as $row) {
			$value = (string)($row[$field] ?? '');
			if ($value === '') {
				continue;
			}

			$counts[$value] = (($counts[$value] ?? 0) + 1);
		}

		arsort($counts);

		$out = [];
		foreach ($counts as $value => $count) {
			$out[] = [$label => (string)$value, 'count' => $count];
		}

		return $out;
	}//end breakdown()

	/**
	 * Mean days from start to end across the closed cases.
	 *
	 * @param array<int, array> $closed The cases closed this month.
	 *
	 * @return float|null The average, or null when nothing closed.
	 */
	private function averageProcessingDays(array $closed): ?float {
		$total = 0;
		$counted = 0;

		foreach ($closed as $row) {
			$days = $this->daysBetween(from: ($row['startDate'] ?? null), to: ($row['endDate'] ?? null));
			if ($days === null) {
				continue;
			}

			$total += $days;
			$counted++;
		}

		if ($counted === 0) {
			return null;
		}

		return round(($total / $counted), 1);
	}//end averageProcessingDays()

	/**
	 * Share of the closed cases that met their deadline, as a percentage.
	 *
	 * A case with no deadline is not counted either way: it had no target to
	 * meet, so including it would flatter or punish the figure arbitrarily.
	 *
	 * @param array<int, array> $closed The cases closed this month.
	 *
	 * @return float|null The percentage, or null when nothing measurable closed.
	 */
	private function slaCompliance(array $closed): ?float {
		$within = 0;
		$measurable = 0;

		foreach ($closed as $row) {
			$deadline = substr((string)($row['deadline'] ?? ''), 0, 10);
			$end = substr((string)($row['endDate'] ?? ''), 0, 10);
			if ($deadline === '' || $end === '') {
				continue;
			}

			$measurable++;
			if ($end <= $deadline) {
				$within++;
			}
		}

		if ($measurable === 0) {
			return null;
		}

		return round((($within / $measurable) * 100), 0);
	}//end slaCompliance()

	/**
	 * Whole days between two dates.
	 *
	 * @param mixed $from The earlier date.
	 * @param mixed $to The later date.
	 *
	 * @return int|null The difference in days, or null when either is unusable.
	 */
	private function daysBetween(mixed $from, mixed $to): ?int {
		$fromDate = substr((string)$from, 0, 10);
		$toDate = substr((string)$to, 0, 10);
		if ($fromDate === '' || $toDate === '') {
			return null;
		}

		try {
			$start = new DateTimeImmutable($fromDate);
			$end = new DateTimeImmutable($toDate);
		} catch (\Exception $e) {
			return null;
		}

		return (int)$start->diff($end)->format('%r%a');
	}//end daysBetween()

	/**
	 * Normalise an OpenRegister object to a plain array.
	 *
	 * @param mixed $object The object entity or array.
	 *
	 * @return array The object's data.
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === false) {
			return [];
		}

		if (method_exists($object, 'getObject') === true) {
			return (array)$object->getObject();
		}

		if (method_exists($object, 'jsonSerialize') === true) {
			return (array)$object->jsonSerialize();
		}

		return [];
	}//end toArray()

	/**
	 * The register and schema ids these metrics read.
	 *
	 * @return array{register: string, case: string, caseTask: string}|null The ids, or null when unconfigured.
	 */
	private function ids(): ?array {
		$ids = [
			'register' => $this->appConfig->getValueString(Application::APP_ID, 'register', ''),
			'case' => $this->appConfig->getValueString(Application::APP_ID, 'case_schema', ''),
			'caseTask' => $this->appConfig->getValueString(Application::APP_ID, 'task_schema', ''),
		];

		if (in_array('', $ids, true) === true) {
			return null;
		}

		return $ids;
	}//end ids()

	/**
	 * OpenRegister's ObjectService, or null when the app is absent.
	 *
	 * A runtime lookup guarded by isInstalled(), per ADR-083: naming the class in
	 * a constructor property would make PHP resolve it on every construction, so
	 * an instance without OpenRegister would fail with an error about a class
	 * nobody mentioned.
	 *
	 * @return object|null The ObjectService.
	 *
	 * @psalm-return \OCA\OpenRegister\Service\ObjectService|null
	 */
	private function objectService(): ?object {
		if ($this->appManager->isInstalled('openregister') === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[KpiAggregationService] OpenRegister is installed but its ObjectService did not resolve',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end objectService()
}//end class
