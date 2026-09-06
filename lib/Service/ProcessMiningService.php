<?php

/**
 * Dossiq Process Mining Service
 *
 * Computes bottleneck-analysis metrics from the `statusRecord` chain that
 * {@see StatusTransitionService} already writes on every case transition
 * (ADR-Leaf-First: dossiq ships the data provider, nc-vue leaves render
 * it — no bespoke chart components here). Four metric families, all
 * derived from the same single read path via OpenRegister's ObjectService:
 *
 *  - per-status dwell-time stats (median/p90/mean hours), per case type
 *  - bottleneck ranking (dwell time x case volume)
 *  - transition frequency matrix, incl. rework-loop detection (a
 *    transition that revisits a status the case already left earlier)
 *  - weekly throughput trend (cases closed per week)
 *
 * This class is the orchestrator only. The read path lives in
 * {@see ProcessMiningDataLoader}; each metric family lives in its own
 * calculator ({@see DwellTimeAnalyzer}, {@see TransitionMatrixBuilder},
 * {@see ThroughputTrendCalculator}). What remains here is the report
 * assembly: resolving the reporting period, grouping the loaded cases per
 * case type, and handing each group to the calculators.
 *
 * See openspec/changes/process-mining-bottlenecks/design.md for why the
 * `statusRecord` register (rather than a new event log) is the source of
 * truth.
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
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateInterval;
use DateTimeImmutable;
use OCA\Dossiq\Service\ProcessMining\DwellTimeAnalyzer;
use OCA\Dossiq\Service\ProcessMining\ProcessMiningDataLoader;
use OCA\Dossiq\Service\ProcessMining\ThroughputTrendCalculator;
use OCA\Dossiq\Service\ProcessMining\TransitionMatrixBuilder;

/**
 * Computes process-mining bottleneck metrics from recorded status history.
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */
class ProcessMiningService {
	/**
	 * Constructor.
	 *
	 * @param ProcessMiningDataLoader $dataLoader The OpenRegister read path + lookup indexes.
	 * @param DwellTimeAnalyzer $dwellTimeAnalyzer Dwell-interval reconstruction + bottleneck ranking.
	 * @param TransitionMatrixBuilder $transitionBuilder Transition matrix + rework detection.
	 * @param ThroughputTrendCalculator $throughputCalculator Weekly closed-case throughput trend.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ProcessMiningDataLoader $dataLoader,
		private readonly DwellTimeAnalyzer $dwellTimeAnalyzer,
		private readonly TransitionMatrixBuilder $transitionBuilder,
		private readonly ThroughputTrendCalculator $throughputCalculator,
	) {
	}//end __construct()

	/**
	 * Compute the full process-mining report for the given parameters.
	 *
	 * @param array<string, mixed> $params Query parameters from the controller
	 *                                     (`from`, `to`, `caseType` — all optional strings).
	 *
	 * @return array<string, mixed> The structured response body.
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	public function getReport(array $params): array {
		$to = $this->parseDate(value: ($params['to'] ?? null), fallback: new DateTimeImmutable('today'));
		$from = $to->sub(new DateInterval('P12M'));
		$fromParam = $this->nonEmptyStringParam(params: $params, key: 'from');
		if ($fromParam !== null) {
			$from = $this->parseDate(value: $fromParam, fallback: $to->sub(new DateInterval('P12M')));
		}

		$caseTypeFilter = $this->nonEmptyStringParam(params: $params, key: 'caseType');

		$cases = $this->dataLoader->loadCases(caseTypeFilter: $caseTypeFilter);
		$caseTypes = $this->dataLoader->loadCaseTypes();
		$statusTypes = $this->dataLoader->loadStatusTypes();
		$records = $this->dataLoader->loadStatusRecords();

		$casesById = [];
		foreach ($cases as $caseData) {
			$id = (string)($caseData['id'] ?? '');
			if ($id !== '') {
				$casesById[$id] = $caseData;
			}
		}

		$recordsByCase = $this->groupRecordsByCase(records: $records, caseIds: array_keys($casesById));

		$statusTypeIndex = $this->dataLoader->indexById(rows: $statusTypes);
		$caseTypeIndex = $this->dataLoader->indexByIdAndSlug(rows: $caseTypes);

		$now = new DateTimeImmutable('now');

		$caseTypeGroups = $this->groupCasesByType(cases: $casesById, caseTypeIndex: $caseTypeIndex);

		$caseTypeReports = [];
		foreach ($caseTypeGroups as $caseTypeId => $group) {
			$caseIdsInGroup = array_keys($group['cases']);
			$recordsForThisGroup = array_intersect_key($recordsByCase, array_flip($caseIdsInGroup));

			$intervals = $this->dwellTimeAnalyzer->computeDwellIntervals(
				recordsByCase: $recordsForThisGroup,
				casesById: $group['cases'],
				now: $now,
				periodFrom: $from,
				periodTo: $to,
			);

			$dwellStats = $this->dwellTimeAnalyzer->aggregateDwellStats(intervals: $intervals, statusTypeIndex: $statusTypeIndex);
			$bottlenecks = $this->dwellTimeAnalyzer->rankBottlenecks(dwellStats: $dwellStats);
			$transitions = $this->transitionBuilder->computeTransitionMatrix(
				recordsByCase: $recordsForThisGroup,
				statusTypeIndex: $statusTypeIndex
			);

			$caseTypeReports[] = [
				'id' => $caseTypeId,
				'title' => $group['title'],
				'caseVolume' => count($group['cases']),
				'dwellTime' => $dwellStats,
				'bottlenecks' => $bottlenecks,
				'transitionMatrix' => $transitions['matrix'],
				'reworkPercent' => $transitions['reworkPercent'],
				'transitionCount' => $transitions['totalCount'],
			];
		}//end foreach

		usort(
			$caseTypeReports,
			static fn (array $left, array $right): int => ($right['caseVolume'] <=> $left['caseVolume'])
		);

		return [
			'period' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
			'caseTypeFilter' => $caseTypeFilter,
			'caseTypes' => $caseTypeReports,
			'throughputTrend' => $this->throughputCalculator->computeThroughputTrend(cases: $casesById, from: $from, to: $to),
		];
	}//end getReport()

	/**
	 * Build dwell-time intervals: one entry per (case, status-visit).
	 *
	 * Delegates to {@see DwellTimeAnalyzer::computeDwellIntervals()}.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $recordsByCase Chronologically sorted statusRecords, keyed by case id.
	 * @param array<string, array<string, mixed>> $casesById Case rows, keyed by id.
	 * @param DateTimeImmutable $now "Now", for open cases' current status.
	 * @param DateTimeImmutable $periodFrom Inclusive period start.
	 * @param DateTimeImmutable $periodTo Inclusive period end.
	 *
	 * @return array<int, array{caseId: string, statusId: string, hours: float}>
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	public function computeDwellIntervals(
		array $recordsByCase,
		array $casesById,
		DateTimeImmutable $now,
		DateTimeImmutable $periodFrom,
		DateTimeImmutable $periodTo,
	): array {
		return $this->dwellTimeAnalyzer->computeDwellIntervals(
			recordsByCase: $recordsByCase,
			casesById: $casesById,
			now: $now,
			periodFrom: $periodFrom,
			periodTo: $periodTo,
		);
	}//end computeDwellIntervals()

	/**
	 * Aggregate dwell-time intervals per status into median/p90/mean stats.
	 *
	 * Delegates to {@see DwellTimeAnalyzer::aggregateDwellStats()}.
	 *
	 * @param array<int, array{caseId: string, statusId: string, hours: float}> $intervals Dwell intervals.
	 * @param array<string, array<string, mixed>> $statusTypeIndex StatusType rows, keyed by id.
	 *
	 * @return array<int, array{statusId: string, statusName: string, visitCount: int, medianHours: float, p90Hours: float, meanHours: float}>
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	public function aggregateDwellStats(array $intervals, array $statusTypeIndex): array {
		return $this->dwellTimeAnalyzer->aggregateDwellStats(intervals: $intervals, statusTypeIndex: $statusTypeIndex);
	}//end aggregateDwellStats()

	/**
	 * Rank statuses by bottleneck severity: median dwell time x visit volume.
	 *
	 * Delegates to {@see DwellTimeAnalyzer::rankBottlenecks()}.
	 *
	 * @param array<int, array<string, mixed>> $dwellStats Per-status dwell stats.
	 *
	 * @return array<int, array{statusId: string, statusName: string, visitCount: int, medianHours: float, score: float}>
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	public function rankBottlenecks(array $dwellStats): array {
		return $this->dwellTimeAnalyzer->rankBottlenecks(dwellStats: $dwellStats);
	}//end rankBottlenecks()

	/**
	 * Build the from→to transition frequency matrix and detect rework loops.
	 *
	 * Delegates to {@see TransitionMatrixBuilder::computeTransitionMatrix()}.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $recordsByCase Chronologically sorted statusRecords, keyed by case id.
	 * @param array<string, array<string, mixed>> $statusTypeIndex StatusType rows, keyed by id.
	 *
	 * @return array{matrix: array<int, array{from: string, fromName: string, to: string,
	 *                toName: string, count: int, reworkCount: int}>, reworkPercent: float, totalCount: int}
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	public function computeTransitionMatrix(array $recordsByCase, array $statusTypeIndex): array {
		return $this->transitionBuilder->computeTransitionMatrix(
			recordsByCase: $recordsByCase,
			statusTypeIndex: $statusTypeIndex
		);
	}//end computeTransitionMatrix()

	/**
	 * Walk one case's chronologically sorted statusRecords into from→to pairs.
	 *
	 * Delegates to {@see TransitionMatrixBuilder::computeCaseTransitions()}.
	 *
	 * @param array<int, array<string, mixed>> $sortedRecords Chronologically sorted statusRecords for one case.
	 *
	 * @return array<int, array{from: string, to: string, isRework: bool}>
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	public function computeCaseTransitions(array $sortedRecords): array {
		return $this->transitionBuilder->computeCaseTransitions(sortedRecords: $sortedRecords);
	}//end computeCaseTransitions()

	/**
	 * Weekly throughput trend: cases closed (by `endDate`) per ISO week.
	 *
	 * Delegates to {@see ThroughputTrendCalculator::computeThroughputTrend()}.
	 *
	 * @param array<string, array<string, mixed>> $cases Case rows, keyed by id.
	 * @param DateTimeImmutable $from Inclusive period start.
	 * @param DateTimeImmutable $to Inclusive period end.
	 *
	 * @return array<int, array{week: string, count: int}>
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	public function computeThroughputTrend(array $cases, DateTimeImmutable $from, DateTimeImmutable $to): array {
		return $this->throughputCalculator->computeThroughputTrend(cases: $cases, from: $from, to: $to);
	}//end computeThroughputTrend()

	/**
	 * Read a query parameter that MUST be a non-empty string, or null when it is absent, not a
	 * string, or empty.
	 *
	 * @param array<string, mixed> $params The query parameters.
	 * @param string $key The parameter name.
	 *
	 * @return string|null The parameter value, or null.
	 */
	private function nonEmptyStringParam(array $params, string $key): ?string {
		$value = ($params[$key] ?? null);
		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end nonEmptyStringParam()

	/**
	 * Group cases by their caseType, resolving the display title.
	 *
	 * @param array<string, array<string, mixed>> $cases Case rows, keyed by id.
	 * @param array<string, array<string, mixed>> $caseTypeIndex CaseType rows, keyed by id and slug.
	 *
	 * @return array<string, array{title: string, cases: array<string, array<string, mixed>>}>
	 */
	private function groupCasesByType(array $cases, array $caseTypeIndex): array {
		$groups = [];
		foreach ($cases as $caseId => $caseData) {
			$caseTypeKey = (string)($caseData['caseType'] ?? '');
			if ($caseTypeKey === '') {
				continue;
			}

			if (isset($groups[$caseTypeKey]) === false) {
				$title = $caseTypeKey;
				if (isset($caseTypeIndex[$caseTypeKey]) === true) {
					$entry = $caseTypeIndex[$caseTypeKey];
					$title = (string)($entry['title'] ?? $caseTypeKey);
				}

				$groups[$caseTypeKey] = ['title' => $title, 'cases' => []];
			}

			$groups[$caseTypeKey]['cases'][$caseId] = $caseData;
		}//end foreach

		return $groups;
	}//end groupCasesByType()

	/**
	 * Group and chronologically sort statusRecords by their `case` field,
	 * restricted to the given set of case ids.
	 *
	 * @param array<int, array<string, mixed>> $records Raw statusRecord rows.
	 * @param array<int, string> $caseIds Case ids in scope.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function groupRecordsByCase(array $records, array $caseIds): array {
		$allowed = array_flip($caseIds);
		$grouped = [];
		foreach ($records as $record) {
			$caseId = (string)($record['case'] ?? '');
			if ($caseId === '' || isset($allowed[$caseId]) === false) {
				continue;
			}

			$grouped[$caseId][] = $record;
		}

		foreach ($grouped as $caseId => $rows) {
			usort(
				$rows,
				function (array $left, array $right): int {
					$leftAt = $this->extractTimestamp(record: $left);
					$rightAt = $this->extractTimestamp(record: $right);
					if ($leftAt === null || $rightAt === null) {
						return 0;
					}

					return ($leftAt <=> $rightAt);
				}
			);
			$grouped[$caseId] = $rows;
		}

		return $grouped;
	}//end groupRecordsByCase()

	/**
	 * Extract a record's creation timestamp — either the flattened
	 * `createdAt` key or OpenRegister's `@self.created` metadata block.
	 *
	 * @param array<string, mixed> $record A statusRecord row.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function extractTimestamp(array $record): ?DateTimeImmutable {
		$raw = ($record['createdAt'] ?? ($record['@self']['created'] ?? ($record['@self']['createdAt'] ?? null)));
		if (is_string($raw) === false || $raw === '') {
			return null;
		}

		return $this->parseDate(value: $raw, fallback: null);
	}//end extractTimestamp()

	/**
	 * Parse a date/datetime string; return `$fallback` on empty/invalid input.
	 *
	 * @param mixed $value Raw date value.
	 * @param DateTimeImmutable|null $fallback Value to return when parsing fails.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function parseDate(mixed $value, ?DateTimeImmutable $fallback): ?DateTimeImmutable {
		if (is_string($value) === false || $value === '') {
			return $fallback;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Throwable $e) {
			return $fallback;
		}
	}//end parseDate()
}//end class
