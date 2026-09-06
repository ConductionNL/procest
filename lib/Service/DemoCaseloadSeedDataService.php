<?php

/**
 * Dossiq Demo Caseload Seed Data Service
 *
 * Seeds a demonstrable caseload: cases spread across the shipped case types and
 * the tasks hanging off them, positioned so every dashboard widget has rows.
 *
 * 🔴 DATES IN THE SEED FILE ARE OFFSETS, NOT DATES. A demo dataset with absolute
 * dates is correct on the day it is written and wrong every day after, which is
 * how a "the dashboard is empty again" report is really a stale fixture. Every
 * date here is resolved against the moment the seed runs.
 *
 * 🔴 A CASE'S DEADLINE CANNOT BE WRITTEN, ONLY CAUSED. `case.deadline` is a
 * materialised OpenRegister calculation (`startDate` plus the case type's
 * `processingDeadline`), so a deadline written directly is overwritten on save.
 * To place a case in the overdue bucket this service backdates `startDate` by
 * the case type's own processing deadline. {@see resolveStartDate()}.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/dossiq-app-scaffold/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateInterval;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Seeds the demo caseload described by lib/Settings/demo_caseload_seed_data.json.
 *
 * @spec openspec/specs/dossiq-app-scaffold/spec.md
 */
class DemoCaseloadSeedDataService {
	/**
	 * The shipped dataset, relative to this file.
	 *
	 * @var string
	 */
	private const SEED_FILE = '/../Settings/demo_caseload_seed_data.json';

	/**
	 * Constructor.
	 *
	 * @param DemoCaseloadGateway $gateway OpenRegister access.
	 * @param LoggerInterface $logger The logger interface.
	 *
	 * @return void
	 */
	public function __construct(
		private DemoCaseloadGateway $gateway,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Seed the demo caseload.
	 *
	 * Idempotent by case title: a case whose title is already present is left
	 * alone, and its tasks are left alone with it.
	 *
	 * 🔴 THROWS RATHER THAN RETURNING A QUIET FAILURE. The only caller is an
	 * operator who just asked for this, so "nothing happened" must never be
	 * presentable as success.
	 *
	 * @param DateTimeImmutable|null $now The clock, injectable for tests.
	 *
	 * @return array{cases: integer, tasks: integer, skipped: integer} What was created.
	 *
	 * @throws RuntimeException When the dataset, OpenRegister or the configuration is missing.
	 *
	 * @spec openspec/specs/dossiq-app-scaffold/spec.md
	 */
	public function seed(?DateTimeImmutable $now = null): array {
		$now = ($now ?? new DateTimeImmutable('today'));
		$seed = $this->readSeedFile();
		$objectService = $this->gateway->objectService();
		$ids = $this->gateway->schemaIds();

		$caseTypes = $this->caseTypesByIdentifier(objectService: $objectService, ids: $ids);
		$statuses = $this->statusTypesByCaseTypeAndName(objectService: $objectService, ids: $ids);

		$summary = ['cases' => 0, 'tasks' => 0, 'skipped' => 0];

		foreach (($seed['cases'] ?? []) as $caseSeed) {
			$title = (string)($caseSeed['title'] ?? '');
			if ($title === '') {
				continue;
			}

			$present = $this->gateway->exists(
				objectService: $objectService,
				registerId: $ids['register'],
				schemaId: $ids['case'],
				filters: ['title' => $title]
			);

			if ($present === true) {
				$summary['skipped']++;
				continue;
			}

			$caseId = $this->createCase(
				objectService: $objectService,
				ids: $ids,
				caseSeed: $caseSeed,
				caseTypes: $caseTypes,
				statuses: $statuses,
				now: $now
			);

			if ($caseId === '') {
				continue;
			}

			$summary['cases']++;
			$summary['tasks'] += $this->createTasks(
				objectService: $objectService,
				ids: $ids,
				caseId: $caseId,
				tasks: (array)($caseSeed['tasks'] ?? []),
				now: $now
			);
		}//end foreach

		$this->logger->info('Dossiq: Demo caseload seeded', $summary);

		return $summary;
	}//end seed()

	/**
	 * Create one case from its seed entry.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param array<string, string> $ids The register and schema ids.
	 * @param array $caseSeed The case seed entry.
	 * @param array<string, array> $caseTypes Case types keyed by identifier.
	 * @param array<string, string> $statuses Status ids keyed by "caseTypeId|name".
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return string The created case id, or '' when it could not be created.
	 */
	private function createCase(
		object $objectService,
		array $ids,
		array $caseSeed,
		array $caseTypes,
		array $statuses,
		DateTimeImmutable $now,
	): string {
		$identifier = (string)($caseSeed['caseType'] ?? '');
		$caseType = ($caseTypes[$identifier] ?? null);
		if ($caseType === null) {
			$this->logger->warning(
				'Dossiq: Demo seed skipped a case whose case type is not installed',
				['case' => ($caseSeed['title'] ?? ''), 'caseType' => $identifier]
			);
			return '';
		}

		return $this->gateway->create(
			objectService: $objectService,
			registerId: $ids['register'],
			schemaId: $ids['case'],
			data: $this->casePayload(
				caseSeed: $caseSeed,
				caseType: $caseType,
				statuses: $statuses,
				now: $now
			)
		);
	}//end createCase()

	/**
	 * Build the payload for one case.
	 *
	 * @param array $caseSeed The case seed entry.
	 * @param array $caseType The resolved case type.
	 * @param array<string, string> $statuses Status ids keyed by "caseTypeId|name".
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return array The case payload.
	 */
	private function casePayload(
		array $caseSeed,
		array $caseType,
		array $statuses,
		DateTimeImmutable $now,
	): array {
		$startDate = $this->resolveStartDate(
			caseSeed: $caseSeed,
			processingDeadline: (string)($caseType['processingDeadline'] ?? ''),
			now: $now
		);

		$data = [
			'title' => (string)($caseSeed['title'] ?? ''),
			'description' => (string)($caseSeed['description'] ?? ''),
			'caseType' => (string)$caseType['id'],
			'assignee' => (string)($caseSeed['assignee'] ?? ''),
			'priority' => (string)($caseSeed['priority'] ?? 'normal'),
			'intakeChannel' => (string)($caseSeed['intakeChannel'] ?? 'manual'),
			'confidentiality' => (string)($caseSeed['confidentiality'] ?? 'openbaar'),
			'startDate' => $startDate->format('Y-m-d'),
		];

		$statusId = ($statuses[$caseType['id'] . '|' . (string)($caseSeed['status'] ?? '')] ?? '');
		if ($statusId !== '') {
			$data['status'] = $statusId;
		}

		if (isset($caseSeed['endInDays']) === true) {
			$data['endDate'] = $this->offset(now: $now, days: (int)$caseSeed['endInDays'])->format('Y-m-d');
		}

		return $data;
	}//end casePayload()

	/**
	 * Create the tasks belonging to one case.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param array<string, string> $ids The register and schema ids.
	 * @param string $caseId The parent case id.
	 * @param array $tasks The task seed entries.
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return int How many tasks were created.
	 */
	private function createTasks(
		object $objectService,
		array $ids,
		string $caseId,
		array $tasks,
		DateTimeImmutable $now,
	): int {
		$created = 0;

		foreach ($tasks as $taskSeed) {
			$id = $this->gateway->create(
				objectService: $objectService,
				registerId: $ids['register'],
				schemaId: $ids['caseTask'],
				data: $this->taskPayload(taskSeed: $taskSeed, caseId: $caseId, now: $now)
			);

			if ($id !== '') {
				$created++;
			}
		}

		return $created;
	}//end createTasks()

	/**
	 * Build the payload for one task.
	 *
	 * @param array $taskSeed The task seed entry.
	 * @param string $caseId The parent case id.
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return array The task payload.
	 */
	private function taskPayload(array $taskSeed, string $caseId, DateTimeImmutable $now): array {
		$data = [
			'title' => (string)($taskSeed['title'] ?? ''),
			'description' => (string)($taskSeed['description'] ?? ''),
			'case' => $caseId,
			'assignee' => (string)($taskSeed['assignee'] ?? ''),
			'status' => (string)($taskSeed['status'] ?? 'available'),
			'priority' => (string)($taskSeed['priority'] ?? 'normal'),
		];

		if (isset($taskSeed['dueInDays']) === true) {
			$data['dueDate'] = $this->offset(now: $now, days: (int)$taskSeed['dueInDays'])
				->format('Y-m-d\TH:i:sP');
		}

		if (isset($taskSeed['completedInDays']) === true) {
			$data['completedDate'] = $this->offset(now: $now, days: (int)$taskSeed['completedInDays'])
				->format('Y-m-d\TH:i:sP');
		}

		return $data;
	}//end taskPayload()

	/**
	 * Work out the start date that puts a case in its intended bucket.
	 *
	 * A seed entry gives EITHER `startInDays` (the case simply opened then) OR
	 * `deadlineInDays` (the case must land in a deadline bucket). For the second
	 * form the start date is the wanted deadline minus the case type's own
	 * processing deadline, because OpenRegister recomputes
	 * `deadline = startDate + processingDeadline` on every save.
	 *
	 * @param array $caseSeed The case seed entry.
	 * @param string $processingDeadline The case type's ISO-8601 duration, e.g. "P56D".
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return DateTimeImmutable The start date to write.
	 */
	private function resolveStartDate(
		array $caseSeed,
		string $processingDeadline,
		DateTimeImmutable $now,
	): DateTimeImmutable {
		if (isset($caseSeed['startInDays']) === true) {
			return $this->offset(now: $now, days: (int)$caseSeed['startInDays']);
		}

		$deadline = $this->offset(now: $now, days: (int)($caseSeed['deadlineInDays'] ?? 0));

		try {
			return $deadline->sub(new DateInterval($processingDeadline));
		} catch (\Exception $e) {
			// A case type with no usable processing deadline cannot be placed in
			// a bucket, so open the case today and say so rather than guessing.
			$this->logger->warning(
				'Dossiq: Demo seed could not read a processing deadline, opening the case today',
				['case' => ($caseSeed['title'] ?? ''), 'processingDeadline' => $processingDeadline]
			);
			return $now;
		}
	}//end resolveStartDate()

	/**
	 * Shift the clock by a whole number of days, forwards or backwards.
	 *
	 * @param DateTimeImmutable $now The clock.
	 * @param integer $days The offset in days, negative for the past.
	 *
	 * @return DateTimeImmutable The shifted moment.
	 */
	private function offset(DateTimeImmutable $now, int $days): DateTimeImmutable {
		$shifted = $now->modify(sprintf('%+d days', $days));
		if ($shifted === false) {
			return $now;
		}

		return $shifted;
	}//end offset()

	/**
	 * Read and decode the shipped dataset.
	 *
	 * @return array The decoded dataset.
	 *
	 * @throws RuntimeException When the file is missing or not valid JSON.
	 */
	private function readSeedFile(): array {
		$path = (__DIR__ . self::SEED_FILE);
		if (is_file($path) === false) {
			throw new RuntimeException('The demo caseload dataset is missing: ' . $path);
		}

		$raw = file_get_contents($path);
		if ($raw === false) {
			throw new RuntimeException('The demo caseload dataset could not be read: ' . $path);
		}

		$data = json_decode($raw, true);
		if (is_array($data) === false) {
			throw new RuntimeException('The demo caseload dataset is not valid JSON: ' . $path);
		}

		return $data;
	}//end readSeedFile()

	/**
	 * Installed case types keyed by their identifier.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param array<string, string> $ids The register and schema ids.
	 *
	 * @return array<string, array{id: string, processingDeadline: string}> The case types.
	 */
	private function caseTypesByIdentifier(object $objectService, array $ids): array {
		$map = [];

		$caseTypes = $this->gateway->findMany(
			objectService: $objectService,
			registerId: $ids['register'],
			schemaId: $ids['caseType'],
			filters: []
		);

		foreach ($caseTypes as $caseType) {
			$row = $this->gateway->toArray(object: $caseType);
			$identifier = (string)($row['identifier'] ?? '');
			if ($identifier === '') {
				continue;
			}

			$map[$identifier] = [
				'id' => $this->gateway->idOf(object: $caseType),
				'processingDeadline' => (string)($row['processingDeadline'] ?? ''),
			];
		}

		return $map;
	}//end caseTypesByIdentifier()

	/**
	 * Status type ids keyed by "caseTypeId|statusName".
	 *
	 * Keyed by case type as well as by name because the same status name is
	 * reused across case types: all four shipped types have an "Ontvangen", and
	 * pointing a case at another type's status would break its own transitions.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param array<string, string> $ids The register and schema ids.
	 *
	 * @return array<string, string> The status ids.
	 */
	private function statusTypesByCaseTypeAndName(object $objectService, array $ids): array {
		$map = [];

		$statusTypes = $this->gateway->findMany(
			objectService: $objectService,
			registerId: $ids['register'],
			schemaId: $ids['statusType'],
			filters: []
		);

		foreach ($statusTypes as $statusType) {
			$row = $this->gateway->toArray(object: $statusType);
			$name = (string)($row['name'] ?? '');
			$caseType = (string)($row['caseType'] ?? '');
			if ($name === '' || $caseType === '') {
				continue;
			}

			$map[$caseType . '|' . $name] = $this->gateway->idOf(object: $statusType);
		}

		return $map;
	}//end statusTypesByCaseTypeAndName()
}//end class
