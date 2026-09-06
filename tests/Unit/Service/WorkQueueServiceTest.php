<?php

/**
 * WorkQueueService Unit Tests
 *
 * Tests for the Dossiq WorkQueueService that computes the intelligent
 * work-queue urgency score (deadline math, priority, case age) and the
 * coordinator workload summary.
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
 * @spec openspec/changes/werkvoorraad-intelligent-queue/specs/werkvoorraad-intelligent-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WorkQueueService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\WorkQueueService
 */
class WorkQueueServiceTest extends TestCase {

	private FakeWorkQueueStore $objects;

	private WorkQueueService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new FakeWorkQueueStore();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'case_schema' => 'case',
					'task_schema' => 'caseTask',
					'termijn_instance_schema' => 'deadlineInstance',
					default => '',
				};
			}
		);

		$this->service = new WorkQueueService($settings, $this->createMock(LoggerInterface::class));
	}//end setUp()

	// ── Pure scoreItem() tests ──────────────────────────────────────────

	/**
	 * @return void
	 */
	public function testNoDeadlineScoresNormalTierWithNullDays(): void {
		$result = $this->service->scoreItem(null, 'normal', null, new DateTimeImmutable('2026-07-13'));

		self::assertSame('normal', $result['tier']);
		self::assertNull($result['daysUntilDeadline']);
	}//end testNoDeadlineScoresNormalTierWithNullDays()

	/**
	 * @return void
	 */
	public function testOverdueDeadlineScoresOverdueTier(): void {
		// 2026-07-13 is a Monday; 2 business days before is 2026-07-09 (Thu).
		$result = $this->service->scoreItem('2026-07-09', 'normal', null, new DateTimeImmutable('2026-07-13'));

		self::assertSame('overdue', $result['tier']);
		self::assertLessThan(0, $result['daysUntilDeadline']);
	}//end testOverdueDeadlineScoresOverdueTier()

	/**
	 * @return void
	 */
	public function testCriticalTierAtZeroBusinessDays(): void {
		// Same calendar day → 0 business days.
		$result = $this->service->scoreItem('2026-07-13', 'normal', null, new DateTimeImmutable('2026-07-13'));

		self::assertSame(0, $result['daysUntilDeadline']);
		self::assertSame('critical', $result['tier']);
	}//end testCriticalTierAtZeroBusinessDays()

	/**
	 * @return void
	 */
	public function testCriticalTierAtThreeBusinessDaysBoundary(): void {
		// 2026-07-13 (Mon) + 3 business days = 2026-07-16 (Thu).
		$result = $this->service->scoreItem('2026-07-16', 'normal', null, new DateTimeImmutable('2026-07-13'));

		self::assertSame(3, $result['daysUntilDeadline']);
		self::assertSame('critical', $result['tier']);
	}//end testCriticalTierAtThreeBusinessDaysBoundary()

	/**
	 * @return void
	 */
	public function testWarningTierAtFourBusinessDaysBoundary(): void {
		// 2026-07-13 (Mon) + 4 business days = 2026-07-17 (Fri).
		$result = $this->service->scoreItem('2026-07-17', 'normal', null, new DateTimeImmutable('2026-07-13'));

		self::assertSame(4, $result['daysUntilDeadline']);
		self::assertSame('warning', $result['tier']);
	}//end testWarningTierAtFourBusinessDaysBoundary()

	/**
	 * @return void
	 */
	public function testWarningTierAtSevenBusinessDaysBoundary(): void {
		// 2026-07-13 (Mon) + 7 business days = 2026-07-22 (Wed), skipping the weekend.
		$result = $this->service->scoreItem('2026-07-22', 'normal', null, new DateTimeImmutable('2026-07-13'));

		self::assertSame(7, $result['daysUntilDeadline']);
		self::assertSame('warning', $result['tier']);
	}//end testWarningTierAtSevenBusinessDaysBoundary()

	/**
	 * @return void
	 */
	public function testNormalTierAtEightBusinessDaysBoundary(): void {
		// 2026-07-13 (Mon) + 8 business days = 2026-07-23 (Thu).
		$result = $this->service->scoreItem('2026-07-23', 'normal', null, new DateTimeImmutable('2026-07-13'));

		self::assertSame(8, $result['daysUntilDeadline']);
		self::assertSame('normal', $result['tier']);
	}//end testNormalTierAtEightBusinessDaysBoundary()

	/**
	 * @return void
	 */
	public function testHigherPriorityScoresHigherWithinSameTier(): void {
		$now = new DateTimeImmutable('2026-07-13');

		$urgent = $this->service->scoreItem('2026-07-22', 'urgent', null, $now);
		$low = $this->service->scoreItem('2026-07-22', 'low', null, $now);

		self::assertSame($urgent['tier'], $low['tier']);
		self::assertGreaterThan($low['score'], $urgent['score']);
	}//end testHigherPriorityScoresHigherWithinSameTier()

	/**
	 * @return void
	 */
	public function testUnknownPriorityFallsBackToNormalWeight(): void {
		$now = new DateTimeImmutable('2026-07-13');

		$unknown = $this->service->scoreItem(null, 'mystery', null, $now);
		$normal = $this->service->scoreItem(null, 'normal', null, $now);

		self::assertSame($normal['scoreBreakdown']['priority'], $unknown['scoreBreakdown']['priority']);
	}//end testUnknownPriorityFallsBackToNormalWeight()

	/**
	 * @return void
	 */
	public function testOlderReferenceDateIncreasesAgeComponent(): void {
		$now = new DateTimeImmutable('2026-07-13');

		$old = $this->service->scoreItem(null, 'normal', '2026-05-01', $now);
		$fresh = $this->service->scoreItem(null, 'normal', '2026-07-12', $now);

		self::assertGreaterThan($fresh['scoreBreakdown']['age'], $old['scoreBreakdown']['age']);
	}//end testOlderReferenceDateIncreasesAgeComponent()

	/**
	 * @return void
	 */
	public function testFutureReferenceDateDoesNotProduceNegativeAge(): void {
		$now = new DateTimeImmutable('2026-07-13');

		$result = $this->service->scoreItem(null, 'normal', '2026-12-01', $now);

		self::assertSame(0.0, $result['scoreBreakdown']['age']);
	}//end testFutureReferenceDateDoesNotProduceNegativeAge()

	// ── computeQueue() ───────────────────────────────────────────────────

	/**
	 * @return void
	 */
	public function testComputeQueueOnlyReturnsCallersOpenItems(): void {
		$this->objects->saveObject('case', [
			'id' => 'case-1',
			'title' => 'Jan open case',
			'assignee' => 'jan',
			'endDate' => '',
			'deadline' => '2026-07-16',
			'priority' => 'normal',
		]);
		$this->objects->saveObject('case', [
			'id' => 'case-2',
			'title' => 'Jan closed case',
			'assignee' => 'jan',
			'endDate' => '2026-06-01',
			'deadline' => '2026-06-05',
			'priority' => 'normal',
		]);
		$this->objects->saveObject('case', [
			'id' => 'case-3',
			'title' => 'Marie open case',
			'assignee' => 'marie',
			'endDate' => '',
			'deadline' => '2026-07-14',
			'priority' => 'normal',
		]);

		$items = $this->service->computeQueue('jan', new DateTimeImmutable('2026-07-13'));

		self::assertCount(1, $items);
		self::assertSame('case-1', $items[0]['id']);
		self::assertSame('case', $items[0]['itemType']);
	}//end testComputeQueueOnlyReturnsCallersOpenItems()

	/**
	 * @return void
	 */
	public function testComputeQueuePrefersActiveTermijnDeadlineOverCaseField(): void {
		$this->objects->saveObject('case', [
			'id' => 'case-1',
			'title' => 'Case with termijn',
			'assignee' => 'jan',
			'endDate' => '',
			'deadline' => '2026-08-01',
			'priority' => 'normal',
		]);
		$this->objects->saveObject('deadlineInstance', [
			'id' => 'ti-1',
			'case' => 'case-1',
			'status' => 'lopend',
			'endDateCurrent' => '2026-07-14',
		]);

		$items = $this->service->computeQueue('jan', new DateTimeImmutable('2026-07-13'));

		self::assertSame('2026-07-14', $items[0]['deadline']);
	}//end testComputeQueuePrefersActiveTermijnDeadlineOverCaseField()

	/**
	 * @return void
	 */
	public function testComputeQueueExcludesTerminalTasksAndSortsByScore(): void {
		$this->objects->saveObject('case', [
			'id' => 'case-1',
			'title' => 'Distant case',
			'assignee' => 'jan',
			'endDate' => '',
			'deadline' => '2026-08-01',
			'priority' => 'normal',
		]);
		$this->objects->saveObject('caseTask', [
			'id' => 'task-1',
			'title' => 'Overdue task',
			'assignee' => 'jan',
			'status' => 'active',
			'dueDate' => '2026-07-01',
			'priority' => 'normal',
		]);
		$this->objects->saveObject('caseTask', [
			'id' => 'task-2',
			'title' => 'Completed task',
			'assignee' => 'jan',
			'status' => 'completed',
			'dueDate' => '2026-07-01',
			'priority' => 'normal',
		]);

		$items = $this->service->computeQueue('jan', new DateTimeImmutable('2026-07-13'));

		self::assertCount(2, $items);
		// Overdue task must sort first (highest score).
		self::assertSame('task-1', $items[0]['id']);
		self::assertSame('overdue', $items[0]['tier']);
		self::assertSame('case-1', $items[1]['id']);
	}//end testComputeQueueExcludesTerminalTasksAndSortsByScore()

	/**
	 * @return void
	 */
	public function testComputeQueueReturnsEmptyForUnknownUser(): void {
		$this->objects->saveObject('case', [
			'id' => 'case-1',
			'assignee' => 'jan',
			'endDate' => '',
			'priority' => 'normal',
		]);

		$items = $this->service->computeQueue('nobody', new DateTimeImmutable('2026-07-13'));

		self::assertSame([], $items);
	}//end testComputeQueueReturnsEmptyForUnknownUser()

	// ── computeWorkload() ────────────────────────────────────────────────

	/**
	 * @return void
	 */
	public function testComputeWorkloadCountsOpenCasesPerHandler(): void {
		$this->objects->saveObject('case', ['id' => 'c1', 'assignee' => 'jan', 'endDate' => '']);
		$this->objects->saveObject('case', ['id' => 'c2', 'assignee' => 'jan', 'endDate' => '']);
		$this->objects->saveObject('case', ['id' => 'c3', 'assignee' => 'marie', 'endDate' => '']);
		$this->objects->saveObject('case', ['id' => 'c4', 'assignee' => 'jan', 'endDate' => '2026-06-01']);

		$workload = $this->service->computeWorkload();

		self::assertSame(
			[
				['handler' => 'jan', 'openCaseCount' => 2],
				['handler' => 'marie', 'openCaseCount' => 1],
			],
			$workload
		);
	}//end testComputeWorkloadCountsOpenCasesPerHandler()
}//end class

/**
 * Minimal in-memory ObjectService fake for WorkQueueServiceTest, mirroring
 * the real OpenRegister ObjectService's `searchObjectsBySlug()` contract.
 * Ignores underscore-prefixed keys (`_limit`, `_offset`) during equality
 * filtering, matching the real API's pagination-vs-field-filter split
 * (unlike the shared FakeTermijnStore fixture, which treats every filter
 * key as a literal field match and is unsuitable for callers that pass
 * `_limit`).
 */
class FakeWorkQueueStore {

	/**
	 * Object store, keyed by schema slug then id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $store = [];

	/**
	 * Persist (insert or update) an object by id.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string, mixed> $object Object.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(string $schema, array $object): array {
		$this->store[$schema][(string)$object['id']] = $object;
		return $object;
	}//end saveObject()

	/**
	 * Slug-aware search bridge mirroring OpenRegister
	 * ObjectService::searchObjectsBySlug().
	 *
	 * @param string $registerSlug Register slug (unused by this fake).
	 * @param string $schemaSlug Schema slug.
	 * @param array<string, mixed> $filters Object-field filters (underscore keys ignored).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = []): array {
		$rows = array_values($this->store[$schemaSlug] ?? []);

		$fieldFilters = array_filter(
			$filters,
			static fn ($value, $key): bool => (is_string($key) === true && str_starts_with($key, '_') === false),
			ARRAY_FILTER_USE_BOTH
		);

		if (count($fieldFilters) === 0) {
			return $rows;
		}

		return array_values(
			array_filter(
				$rows,
				static function (array $row) use ($fieldFilters): bool {
					foreach ($fieldFilters as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}//end searchObjectsBySlug()
}//end class
