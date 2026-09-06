<?php

/**
 * ProcessMiningService Unit Tests
 *
 * Covers the pure computation methods (dwell-time intervals/aggregation,
 * bottleneck ranking, transition-matrix + rework detection, throughput
 * trend) plus the orchestration entry point `getReport()` against the
 * shared `FakeTermijnStore` in-memory ObjectService fake.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T04
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\ProcessMining\DwellTimeAnalyzer;
use OCA\Dossiq\Service\ProcessMining\ProcessMiningDataLoader;
use OCA\Dossiq\Service\ProcessMining\ThroughputTrendCalculator;
use OCA\Dossiq\Service\ProcessMining\TransitionMatrixBuilder;
use OCA\Dossiq\Service\ProcessMiningService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\ProcessMiningService
 *
 * @uses \OCA\Dossiq\Service\ProcessMining\DwellTimeAnalyzer
 * @uses \OCA\Dossiq\Service\ProcessMining\ProcessMiningDataLoader
 * @uses \OCA\Dossiq\Service\ProcessMining\ThroughputTrendCalculator
 * @uses \OCA\Dossiq\Service\ProcessMining\TransitionMatrixBuilder
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class ProcessMiningServiceTest extends TestCase {
	private FakeTermijnStore $objects;
	private ProcessMiningService $service;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new FakeTermijnStore();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'case_schema' => 'case',
					'case_type_schema' => 'caseType',
					'status_type_schema' => 'statusType',
					'status_record_schema' => 'statusRecord',
					default => '',
				};
			},
		);

		$this->service = new ProcessMiningService(
			new ProcessMiningDataLoader($settings),
			new DwellTimeAnalyzer(),
			new TransitionMatrixBuilder(),
			new ThroughputTrendCalculator(),
		);
	}//end setUp()

	/**
	 * @return void
	 */
	public function testDwellIntervalsHandleClosedCase(): void {
		$now = new DateTimeImmutable('2026-07-14T00:00:00+00:00');
		$from = new DateTimeImmutable('2026-01-01');
		$to = new DateTimeImmutable('2026-12-31');

		$records = [
			'case-1' => [
				['statusType' => 'intake', 'createdAt' => '2026-06-01T09:00:00+00:00'],
				['statusType' => 'review', 'createdAt' => '2026-06-02T09:00:00+00:00'],
			],
		];
		$cases = ['case-1' => ['endDate' => '2026-06-05T09:00:00+00:00']];

		$intervals = $this->service->computeDwellIntervals($records, $cases, $now, $from, $to);

		self::assertCount(2, $intervals);
		// intake: 2026-06-01 09:00 -> 2026-06-02 09:00 = 24h.
		self::assertSame('intake', $intervals[0]['statusId']);
		self::assertEqualsWithDelta(24.0, $intervals[0]['hours'], 0.01);
		// review (last record, case closed): 2026-06-02 09:00 -> endDate 2026-06-05 09:00 = 72h.
		self::assertSame('review', $intervals[1]['statusId']);
		self::assertEqualsWithDelta(72.0, $intervals[1]['hours'], 0.01);
	}//end testDwellIntervalsHandleClosedCase()

	/**
	 * @return void
	 */
	public function testDwellIntervalsUseNowForOpenCase(): void {
		$now = new DateTimeImmutable('2026-06-03T09:00:00+00:00');
		$from = new DateTimeImmutable('2026-01-01');
		$to = new DateTimeImmutable('2026-12-31');

		$records = [
			'case-1' => [
				['statusType' => 'intake', 'createdAt' => '2026-06-01T09:00:00+00:00'],
			],
		];
		$cases = ['case-1' => ['endDate' => null]];

		$intervals = $this->service->computeDwellIntervals($records, $cases, $now, $from, $to);

		self::assertCount(1, $intervals);
		self::assertEqualsWithDelta(48.0, $intervals[0]['hours'], 0.01);
	}//end testDwellIntervalsUseNowForOpenCase()

	/**
	 * @return void
	 */
	public function testDwellIntervalsZeroDurationWhenTimestampsMatch(): void {
		$now = new DateTimeImmutable('2026-07-01');
		$from = new DateTimeImmutable('2026-01-01');
		$to = new DateTimeImmutable('2026-12-31');

		$records = [
			'case-1' => [
				['statusType' => 'intake', 'createdAt' => '2026-06-01T09:00:00+00:00'],
				['statusType' => 'review', 'createdAt' => '2026-06-01T09:00:00+00:00'],
			],
		];
		$cases = ['case-1' => ['endDate' => '2026-06-02T09:00:00+00:00']];

		$intervals = $this->service->computeDwellIntervals($records, $cases, $now, $from, $to);

		self::assertEqualsWithDelta(0.0, $intervals[0]['hours'], 0.01);
	}//end testDwellIntervalsZeroDurationWhenTimestampsMatch()

	/**
	 * A case with no statusRecords at all must not crash and contributes
	 * no intervals.
	 *
	 * @return void
	 */
	public function testDwellIntervalsSkipCaseWithMissingHistory(): void {
		$now = new DateTimeImmutable('2026-07-01');
		$from = new DateTimeImmutable('2026-01-01');
		$to = new DateTimeImmutable('2026-12-31');

		$intervals = $this->service->computeDwellIntervals(['case-1' => []], ['case-1' => ['endDate' => null]], $now, $from, $to);

		self::assertSame([], $intervals);
	}//end testDwellIntervalsSkipCaseWithMissingHistory()

	/**
	 * Intervals whose entry timestamp falls outside the requested period
	 * are excluded even though the case itself is in scope.
	 *
	 * @return void
	 */
	public function testDwellIntervalsRespectPeriodWindow(): void {
		$now = new DateTimeImmutable('2026-07-01');
		$from = new DateTimeImmutable('2026-06-01');
		$to = new DateTimeImmutable('2026-06-30');

		$records = [
			'case-1' => [
				['statusType' => 'intake', 'createdAt' => '2026-05-01T09:00:00+00:00'],
				['statusType' => 'review', 'createdAt' => '2026-06-15T09:00:00+00:00'],
			],
		];
		$cases = ['case-1' => ['endDate' => null]];

		$intervals = $this->service->computeDwellIntervals($records, $cases, $now, $from, $to);

		self::assertCount(1, $intervals);
		self::assertSame('review', $intervals[0]['statusId']);
	}//end testDwellIntervalsRespectPeriodWindow()

	/**
	 * @return void
	 */
	public function testAggregateDwellStatsComputesMedianP90Mean(): void {
		$intervals = [
			['caseId' => 'c1', 'statusId' => 'review', 'hours' => 10.0],
			['caseId' => 'c2', 'statusId' => 'review', 'hours' => 20.0],
			['caseId' => 'c3', 'statusId' => 'review', 'hours' => 30.0],
			['caseId' => 'c4', 'statusId' => 'review', 'hours' => 100.0],
		];

		$stats = $this->service->aggregateDwellStats($intervals, ['review' => ['name' => 'In Review']]);

		self::assertCount(1, $stats);
		self::assertSame('review', $stats[0]['statusId']);
		self::assertSame('In Review', $stats[0]['statusName']);
		self::assertSame(4, $stats[0]['visitCount']);
		// Nearest-rank percentile (rank = ceil(p/100 * n)): median of [10,20,30,100] is the 2nd value, 20.
		self::assertSame(20.0, $stats[0]['medianHours']);
		self::assertSame(40.0, $stats[0]['meanHours']);
	}//end testAggregateDwellStatsComputesMedianP90Mean()

	/**
	 * @return void
	 */
	public function testRankBottlenecksSortsByScoreDescending(): void {
		$dwellStats = [
			['statusId' => 'low-impact', 'statusName' => 'Low', 'visitCount' => 1, 'medianHours' => 5.0, 'p90Hours' => 5.0, 'meanHours' => 5.0],
			['statusId' => 'bottleneck', 'statusName' => 'Bottleneck', 'visitCount' => 10, 'medianHours' => 50.0, 'p90Hours' => 60.0, 'meanHours' => 52.0],
		];

		$ranked = $this->service->rankBottlenecks($dwellStats);

		self::assertSame('bottleneck', $ranked[0]['statusId']);
		self::assertSame(500.0, $ranked[0]['score']);
		self::assertSame('low-impact', $ranked[1]['statusId']);
	}//end testRankBottlenecksSortsByScoreDescending()

	/**
	 * A straight-line progression (A -> B -> C, never revisited) has zero
	 * rework transitions.
	 *
	 * @return void
	 */
	public function testTransitionsNoReworkOnLinearProgression(): void {
		$records = [
			['statusType' => 'A'],
			['statusType' => 'B'],
			['statusType' => 'C'],
		];

		$transitions = $this->service->computeCaseTransitions($records);

		self::assertCount(2, $transitions);
		self::assertFalse($transitions[0]['isRework']);
		self::assertFalse($transitions[1]['isRework']);
	}//end testTransitionsNoReworkOnLinearProgression()

	/**
	 * A -> B -> A is a rework loop: the case revisits status A after
	 * already having left it.
	 *
	 * @return void
	 */
	public function testTransitionsFlagReworkOnRevisit(): void {
		$records = [
			['statusType' => 'A'],
			['statusType' => 'B'],
			['statusType' => 'A'],
		];

		$transitions = $this->service->computeCaseTransitions($records);

		self::assertCount(2, $transitions);
		self::assertFalse($transitions[0]['isRework']);
		self::assertTrue($transitions[1]['isRework']);
	}//end testTransitionsFlagReworkOnRevisit()

	/**
	 * A single-record case (no transitions yet) yields an empty transition
	 * list rather than an error.
	 *
	 * @return void
	 */
	public function testTransitionsEmptyForSingleRecord(): void {
		self::assertSame([], $this->service->computeCaseTransitions([['statusType' => 'A']]));
		self::assertSame([], $this->service->computeCaseTransitions([]));
	}//end testTransitionsEmptyForSingleRecord()

	/**
	 * @return void
	 */
	public function testTransitionMatrixAggregatesReworkPercent(): void {
		$recordsByCase = [
			'c1' => [['statusType' => 'A'], ['statusType' => 'B']],
			'c2' => [['statusType' => 'A'], ['statusType' => 'B'], ['statusType' => 'A']],
		];

		$result = $this->service->computeTransitionMatrix($recordsByCase, ['A' => ['name' => 'Intake'], 'B' => ['name' => 'Review']]);

		self::assertSame(3, $result['totalCount']);
		self::assertSame(33.3, $result['reworkPercent']);

		$abRow = array_values(array_filter($result['matrix'], static fn (array $row): bool => $row['from'] === 'A' && $row['to'] === 'B'));
		self::assertSame(2, $abRow[0]['count']);
		self::assertSame(0, $abRow[0]['reworkCount']);

		$baRow = array_values(array_filter($result['matrix'], static fn (array $row): bool => $row['from'] === 'B' && $row['to'] === 'A'));
		self::assertSame(1, $baRow[0]['count']);
		self::assertSame(1, $baRow[0]['reworkCount']);
	}//end testTransitionMatrixAggregatesReworkPercent()

	/**
	 * @return void
	 */
	public function testThroughputTrendBucketsByIsoWeek(): void {
		$from = new DateTimeImmutable('2026-06-01');
		$to = new DateTimeImmutable('2026-06-14');

		$cases = [
			'c1' => ['endDate' => '2026-06-02'],
			'c2' => ['endDate' => '2026-06-03'],
			'c3' => ['endDate' => '2026-06-10'],
			'c4' => ['endDate' => null],
		];

		$trend = $this->service->computeThroughputTrend($cases, $from, $to);

		$counts = array_column($trend, 'count', 'week');
		// c1 (Jun 2) + c2 (Jun 3) fall in the same ISO week; c4 (null endDate) is excluded.
		self::assertSame(3, array_sum($counts));
		self::assertNotEmpty($trend);
	}//end testThroughputTrendBucketsByIsoWeek()

	/**
	 * Full orchestration: seeds cases/caseType/statusType/statusRecord rows
	 * into the fake store and asserts `getReport()` shapes a per-case-type
	 * breakdown plus a global throughput trend.
	 *
	 * @return void
	 */
	public function testGetReportOrchestratesFullPayload(): void {
		$this->objects->seed('caseType', ['id' => 'ct-1', 'title' => 'Omgevingsvergunning']);
		$this->objects->seed('statusType', ['id' => 'st-intake', 'name' => 'Intake']);
		$this->objects->seed('statusType', ['id' => 'st-review', 'name' => 'Review']);

		$this->objects->seed('case', [
			'id' => 'case-1',
			'caseType' => 'ct-1',
			'startDate' => '2026-06-01',
			'endDate' => '2026-06-05',
		]);

		$this->objects->seed('statusRecord', [
			'id' => 'sr-1', 'case' => 'case-1', 'statusType' => 'st-intake', 'createdAt' => '2026-06-01T09:00:00+00:00',
		]);
		$this->objects->seed('statusRecord', [
			'id' => 'sr-2', 'case' => 'case-1', 'statusType' => 'st-review', 'createdAt' => '2026-06-02T09:00:00+00:00',
		]);

		$report = $this->service->getReport(['from' => '2026-01-01', 'to' => '2026-12-31']);

		self::assertSame('2026-01-01', $report['period']['from']);
		self::assertCount(1, $report['caseTypes']);
		self::assertSame('Omgevingsvergunning', $report['caseTypes'][0]['title']);
		self::assertSame(1, $report['caseTypes'][0]['caseVolume']);
		self::assertNotEmpty($report['caseTypes'][0]['dwellTime']);
		self::assertArrayHasKey('throughputTrend', $report);
	}//end testGetReportOrchestratesFullPayload()

	/**
	 * `caseType` filter scopes the loaded case set — an unrelated case type
	 * must not appear in the response.
	 *
	 * @return void
	 */
	public function testGetReportFiltersByCaseType(): void {
		$this->objects->seed('caseType', ['id' => 'ct-1', 'title' => 'Type A']);
		$this->objects->seed('caseType', ['id' => 'ct-2', 'title' => 'Type B']);
		$this->objects->seed('case', ['id' => 'case-1', 'caseType' => 'ct-1', 'endDate' => null]);
		$this->objects->seed('case', ['id' => 'case-2', 'caseType' => 'ct-2', 'endDate' => null]);

		$report = $this->service->getReport(['caseType' => 'ct-1']);

		self::assertSame('ct-1', $report['caseTypeFilter']);
		self::assertCount(1, $report['caseTypes']);
		self::assertSame('ct-1', $report['caseTypes'][0]['id']);
	}//end testGetReportFiltersByCaseType()
}//end class
