<?php

/**
 * Dossiq Demo Caseload Seed Data Service Test
 *
 * Two jobs.
 *
 * First, it pins the date arithmetic. A case's `deadline` is a materialised
 * OpenRegister calculation (`startDate` plus the case type's
 * `processingDeadline`), so the seed cannot write a deadline: it has to backdate
 * `startDate` by the case type's own processing deadline. Get that subtraction
 * wrong and the seed still succeeds, still reports rows created, and the overdue
 * widget still shows nothing. The assertions below check the START DATE the
 * service asks OpenRegister to store, per case type.
 *
 * Second, it enforces the shipped dataset's shape. The demo exists to fill six
 * dashboard widgets; a dataset that drifts out of its buckets is a demo that
 * shows empty panels, and nothing else in the build would notice.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dossiq-app-scaffold/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\DemoCaseloadGateway;
use OCA\Dossiq\Service\DemoCaseloadSeedDataService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Demo caseload seeding.
 */
final class DemoCaseloadSeedDataServiceTest extends TestCase {
	/**
	 * The shipped dataset, decoded once.
	 *
	 * @var array
	 */
	private array $seedFile = [];

	/**
	 * Processing deadlines of the shipped case types, in days.
	 *
	 * Mirrored from dossiq_register.json so a change to either side shows up
	 * here rather than as an empty widget.
	 *
	 * @var array<string, int>
	 */
	private const PROCESSING_DAYS = [
		'omgevingsvergunning' => 56,
		'subsidieaanvraag' => 42,
		'klacht-behandeling' => 42,
		'melding-openbare-ruimte' => 14,
	];

	/**
	 * Decode the shipped dataset.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$path = (__DIR__ . '/../../../lib/Settings/demo_caseload_seed_data.json');
		$this->assertFileExists($path, 'The demo caseload dataset must ship with the app.');
		$this->seedFile = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($this->seedFile, 'The demo caseload dataset must be valid JSON.');
	}

	/**
	 * A case asking for a deadline N days out is opened N days out MINUS the
	 * case type's processing deadline, so OpenRegister materialises the wanted
	 * deadline back.
	 *
	 * @return void
	 */
	public function testDeadlineOffsetIsBackdatedByTheCaseTypeProcessingDeadline(): void {
		$now = new DateTimeImmutable('2026-09-02');
		$saved = $this->runSeed($now);

		$cases = $this->savedOfSchema($saved, 'case-schema');

		foreach (($this->seedFile['cases'] ?? []) as $seedCase) {
			if (isset($seedCase['deadlineInDays']) === false) {
				continue;
			}

			$written = ($cases[$seedCase['title']] ?? null);
			$this->assertNotNull($written, 'Case "' . $seedCase['title'] . '" was not created.');

			$processing = self::PROCESSING_DAYS[$seedCase['caseType']];
			$expected = $now
				->modify(sprintf('%+d days', (int)$seedCase['deadlineInDays']))
				->modify(sprintf('-%d days', $processing))
				->format('Y-m-d');

			$this->assertSame(
				$expected,
				$written['startDate'],
				'Case "' . $seedCase['title'] . '" must start early enough that its materialised '
				. 'deadline lands ' . $seedCase['deadlineInDays'] . ' days from today.'
			);
		}
	}

	/**
	 * A case giving an explicit start offset is opened on that day, untouched.
	 *
	 * @return void
	 */
	public function testStartOffsetIsUsedVerbatim(): void {
		$now = new DateTimeImmutable('2026-09-02');
		$cases = $this->savedOfSchema($this->runSeed($now), 'case-schema');

		foreach (($this->seedFile['cases'] ?? []) as $seedCase) {
			if (isset($seedCase['startInDays']) === false) {
				continue;
			}

			$expected = $now->modify(sprintf('%+d days', (int)$seedCase['startInDays']))->format('Y-m-d');
			$this->assertSame(
				$expected,
				$cases[$seedCase['title']]['startDate'],
				'Case "' . $seedCase['title'] . '" must open on its stated start offset.'
			);
		}
	}

	/**
	 * Each task is attached to the case it was seeded under, by that case's id.
	 *
	 * @return void
	 */
	public function testTasksAreAttachedToTheirOwnCase(): void {
		$saved = $this->runSeed(new DateTimeImmutable('2026-09-02'));
		$tasks = $this->savedOfSchema($saved, 'task-schema');

		$expectedTasks = 0;
		foreach (($this->seedFile['cases'] ?? []) as $seedCase) {
			$expectedTasks += count(($seedCase['tasks'] ?? []));
		}

		$this->assertCount($expectedTasks, $tasks, 'Every seeded task must be created.');

		foreach ($tasks as $task) {
			$this->assertNotSame('', (string)$task['case'], 'A task must carry its parent case id.');
			$this->assertStringStartsWith(
				'uuid-',
				(string)$task['case'],
				'A task must reference the case that was just created, not an empty id.'
			);
		}
	}

	/**
	 * A case whose title is already present is skipped, and its tasks with it.
	 *
	 * @return void
	 */
	public function testExistingCasesAreSkipped(): void {
		$existingTitle = (string)$this->seedFile['cases'][0]['title'];
		$skippedTasks = count($this->seedFile['cases'][0]['tasks']);

		$service = $this->service(existingCaseTitles: [$existingTitle], saved: $saved);
		$result = $service->seed(new DateTimeImmutable('2026-09-02'));

		$this->assertSame(1, $result['skipped'], 'The already-present case must be skipped.');
		$this->assertSame(
			(count($this->seedFile['cases']) - 1),
			$result['cases'],
			'Every other case must still be created.'
		);
		$this->assertSame(
			($this->totalSeedTasks() - $skippedTasks),
			$result['tasks'],
			'The skipped case must not have its tasks recreated.'
		);
	}

	/**
	 * The shipped dataset fills every dashboard bucket the demo relies on.
	 *
	 * @return void
	 */
	public function testShippedDatasetFillsEveryDashboardBucket(): void {
		$cases = ($this->seedFile['cases'] ?? []);

		$open = [];
		$closed = [];
		foreach ($cases as $case) {
			if (isset($case['endInDays']) === true) {
				$closed[] = $case;
				continue;
			}

			$open[] = $case;
		}

		$overdue = 0;
		$dueSoon = 0;
		foreach ($open as $case) {
			$deadline = $this->deadlineOffset($case);
			if ($deadline < 0) {
				$overdue++;
			}

			if ($deadline >= 0 && $deadline <= 3) {
				$dueSoon++;
			}
		}

		$this->assertGreaterThanOrEqual(5, $overdue, 'The Overdue widget shows up to 7 rows; seed at least 5.');
		$this->assertGreaterThanOrEqual(4, $dueSoon, 'The Deadline Alerts widget shows up to 5 rows.');
		$this->assertGreaterThanOrEqual(3, count($closed), 'The Completed KPI needs closed cases.');

		$openTasksForAdmin = 0;
		$dueSoonTasksForAdmin = 0;
		foreach ($cases as $case) {
			foreach (($case['tasks'] ?? []) as $task) {
				if ($task['assignee'] !== 'admin'
					|| in_array($task['status'], ['completed', 'terminated', 'disabled'], true) === true
				) {
					continue;
				}

				$openTasksForAdmin++;
				if ((int)$task['dueInDays'] <= 3) {
					$dueSoonTasksForAdmin++;
				}
			}
		}

		$this->assertGreaterThanOrEqual(
			7,
			$openTasksForAdmin,
			'The My Tasks widget shows up to 7 rows, all filtered to the current user.'
		);
		$this->assertGreaterThanOrEqual(
			5,
			$dueSoonTasksForAdmin,
			'The Task Due Reminders widget shows up to 5 rows due within three days.'
		);
	}

	/**
	 * A case gives exactly one of the two date offsets, never both.
	 *
	 * Both would be contradictory: the deadline is derived from the start date,
	 * so a case carrying both is asking for two different start dates.
	 *
	 * @return void
	 */
	public function testEveryCaseGivesExactlyOneDateOffset(): void {
		foreach (($this->seedFile['cases'] ?? []) as $case) {
			$has = (int)isset($case['deadlineInDays']) + (int)isset($case['startInDays']);
			$this->assertSame(
				1,
				$has,
				'Case "' . $case['title'] . '" must give either deadlineInDays or startInDays, not both or neither.'
			);
		}
	}

	/**
	 * Titles are unique, because the seed uses the title as its idempotency key.
	 *
	 * @return void
	 */
	public function testCaseTitlesAreUnique(): void {
		$titles = array_column(($this->seedFile['cases'] ?? []), 'title');

		$this->assertSame(
			count($titles),
			count(array_unique($titles)),
			'Two cases sharing a title would make the seed skip the second one forever.'
		);
	}

	/**
	 * A closed case never ends before it started.
	 *
	 * @return void
	 */
	public function testClosedCasesEndOnOrAfterTheyStarted(): void {
		foreach (($this->seedFile['cases'] ?? []) as $case) {
			if (isset($case['endInDays']) === false) {
				continue;
			}

			$this->assertArrayHasKey(
				'startInDays',
				$case,
				'Case "' . $case['title'] . '" is closed, so give it an explicit start.'
			);
			$this->assertGreaterThanOrEqual(
				(int)$case['startInDays'],
				(int)$case['endInDays'],
				'Case "' . $case['title'] . '" cannot close before it opened.'
			);
		}
	}

	/**
	 * Every case type named by the dataset is one the app actually ships.
	 *
	 * @return void
	 */
	public function testEveryCaseTypeIsShipped(): void {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'),
			true
		);

		$shipped = [];
		foreach (($register['components']['objects'] ?? []) as $object) {
			$identifier = ($object['identifier'] ?? null);
			if (is_string($identifier) === true && isset($object['processingDeadline']) === true) {
				$shipped[$identifier] = (string)$object['processingDeadline'];
			}
		}

		foreach (($this->seedFile['cases'] ?? []) as $case) {
			$this->assertArrayHasKey(
				$case['caseType'],
				$shipped,
				'Case "' . $case['title'] . '" names a case type the app does not ship.'
			);
			$this->assertSame(
				'P' . self::PROCESSING_DAYS[$case['caseType']] . 'D',
				$shipped[$case['caseType']],
				'The processing deadline mirrored in this test drifted from dossiq_register.json.'
			);
		}
	}

	/**
	 * The deadline offset a case ends up with, however it was expressed.
	 *
	 * @param array $case The case seed entry.
	 *
	 * @return int The deadline offset in days from today.
	 */
	private function deadlineOffset(array $case): int {
		if (isset($case['deadlineInDays']) === true) {
			return (int)$case['deadlineInDays'];
		}

		return ((int)$case['startInDays'] + self::PROCESSING_DAYS[$case['caseType']]);
	}

	/**
	 * How many tasks the whole dataset declares.
	 *
	 * @return int The task count.
	 */
	private function totalSeedTasks(): int {
		$total = 0;
		foreach (($this->seedFile['cases'] ?? []) as $case) {
			$total += count(($case['tasks'] ?? []));
		}

		return $total;
	}

	/**
	 * Run the seed against a recording fake and return everything it saved.
	 *
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return array<int, array{schema: string, data: array}> Every save, in order.
	 */
	private function runSeed(DateTimeImmutable $now): array {
		$service = $this->service(existingCaseTitles: [], saved: $saved);
		$service->seed($now);

		return $saved;
	}

	/**
	 * The saved payloads for one schema, keyed by title.
	 *
	 * @param array $saved Every save.
	 * @param string $schema The schema id to filter on.
	 *
	 * @return array<string, array> The payloads.
	 */
	private function savedOfSchema(array $saved, string $schema): array {
		$rows = [];
		foreach ($saved as $entry) {
			if ($entry['schema'] === $schema) {
				$rows[$entry['data']['title']] = $entry['data'];
			}
		}

		return $rows;
	}

	/**
	 * Build the service against a fake ObjectService that records every save.
	 *
	 * The fake mirrors the real ObjectService's named parameters, so a rename on
	 * either side fails here instead of silently writing nothing.
	 *
	 * @param string[] $existingCaseTitles Titles the register already holds.
	 * @param array|null $saved Receives every save, by reference.
	 *
	 * @return DemoCaseloadSeedDataService The service under test.
	 */
	private function service(array $existingCaseTitles, ?array &$saved): DemoCaseloadSeedDataService {
		$saved = [];

		$caseTypes = [];
		foreach (self::PROCESSING_DAYS as $identifier => $days) {
			$caseTypes[] = [
				'identifier' => $identifier,
				'processingDeadline' => 'P' . $days . 'D',
				'uuid' => 'ct-' . $identifier,
			];
		}

		$statusTypes = [];
		foreach ($this->seedFile['cases'] as $case) {
			$statusTypes[] = [
				'name' => $case['status'],
				'caseType' => 'ct-' . $case['caseType'],
				'uuid' => 'st-' . $case['caseType'] . '-' . $case['status'],
			];
		}

		$objectService = new class($saved, $caseTypes, $statusTypes, $existingCaseTitles) {
			/**
			 * Sequence used to hand out distinguishable case ids.
			 *
			 * @var int
			 */
			private int $sequence = 0;

			/**
			 * @param array $saved Receives every save, by reference.
			 * @param array $caseTypes The installed case types.
			 * @param array $statusTypes The installed status types.
			 * @param string[] $existingCaseTitles Titles already in the register.
			 */
			public function __construct(
				private array &$saved,
				private array $caseTypes,
				private array $statusTypes,
				private array $existingCaseTitles,
			) {
			}

			/**
			 * Answer lookups the way OpenRegister's paginated shape does.
			 *
			 * @param array $config The find configuration.
			 *
			 * @return array The results envelope.
			 */
			public function findAll(array $config = []): array {
				$schema = (string)($config['filters']['schema'] ?? '');

				if ($schema === 'case-type-schema') {
					return ['results' => $this->entities($this->caseTypes)];
				}

				if ($schema === 'status-type-schema') {
					return ['results' => $this->entities($this->statusTypes)];
				}

				if ($schema === 'case-schema') {
					$title = (string)($config['filters']['title'] ?? '');
					if (in_array($title, $this->existingCaseTitles, true) === true) {
						return ['results' => $this->entities([['title' => $title, 'uuid' => 'existing']])];
					}
				}

				return ['results' => []];
			}

			/**
			 * Record a save and hand back an entity with a fresh uuid.
			 *
			 * @param array $object The object payload.
			 * @param array|null $extend Unused.
			 * @param mixed $register The register id.
			 * @param mixed $schema The schema id.
			 *
			 * @return object The saved entity.
			 */
			public function saveObject(
				array $object,
				?array $extend = [],
				mixed $register = null,
				mixed $schema = null,
			): object {
				$this->saved[] = ['schema' => (string)$schema, 'data' => $object];
				$this->sequence++;

				return self::entity(($object + ['uuid' => 'uuid-' . $this->sequence]));
			}

			/**
			 * Wrap rows as OpenRegister-shaped entities.
			 *
			 * @param array $rows The rows.
			 *
			 * @return array<int, object> The entities.
			 */
			private function entities(array $rows): array {
				return array_map(static fn (array $row): object => self::entity($row), $rows);
			}

			/**
			 * One OpenRegister-shaped entity.
			 *
			 * @param array $row The row.
			 *
			 * @return object The entity.
			 */
			private static function entity(array $row): object {
				return new class($row) {
					/**
					 * @param array $row The row data.
					 */
					public function __construct(private array $row) {
					}

					/**
					 * @return array The object data.
					 */
					public function getObject(): array {
						return $this->row;
					}

					/**
					 * @return string The uuid.
					 */
					public function getUuid(): string {
						return (string)($this->row['uuid'] ?? '');
					}
				};
			}
		};

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '', bool $lazy = false): string {
				return match ($key) {
					'register' => 'register-id',
					'case_schema' => 'case-schema',
					'task_schema' => 'task-schema',
					'case_type_schema' => 'case-type-schema',
					'status_type_schema' => 'status-type-schema',
					default => $default,
				};
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$gateway = new DemoCaseloadGateway($appConfig, $container, new NullLogger(), $appManager);

		return new DemoCaseloadSeedDataService($gateway, new NullLogger());
	}
}
