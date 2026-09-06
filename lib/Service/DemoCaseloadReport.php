<?php

/**
 * Dossiq Demo Caseload Report
 *
 * Counts what the dashboard widgets will actually find, by reading the register
 * back.
 *
 * 🔴 THIS READS THE STORE, NEVER THE SEED FILE, AND THAT IS THE WHOLE POINT. The
 * seed asks for a deadline indirectly, by backdating `startDate` so OpenRegister
 * materialises the deadline it wants. Whether the deadline it materialised
 * actually landed in the intended bucket is a separate question, and a count
 * taken from the seed file would agree with the seed file by construction.
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

use DateTimeImmutable;
use RuntimeException;

/**
 * Reports the caseload buckets the dashboard reads.
 *
 * @spec openspec/specs/dossiq-app-scaffold/spec.md
 */
class DemoCaseloadReport {
	/**
	 * Task statuses OpenRegister treats as terminal.
	 *
	 * Mirrors the `isTerminalStatus` calculation on the task schema, which is
	 * what the My Tasks and Task Due Reminders widgets filter on.
	 *
	 * @var array<int, string>
	 */
	private const TERMINAL_TASK_STATUSES = ['completed', 'terminated', 'disabled'];

	/**
	 * Constructor.
	 *
	 * @param DemoCaseloadGateway $gateway OpenRegister access.
	 *
	 * @return void
	 */
	public function __construct(private DemoCaseloadGateway $gateway) {
	}//end __construct()

	/**
	 * Count the buckets the dashboard widgets read.
	 *
	 * @param DateTimeImmutable|null $now The clock, injectable for tests.
	 *
	 * @return array{open: integer, overdue: integer, dueSoon: integer, closed: integer, tasksOpen: integer, tasksDue: integer}
	 *         The bucket counts.
	 *
	 * @throws RuntimeException When OpenRegister or the configuration is missing.
	 *
	 * @spec openspec/specs/dossiq-app-scaffold/spec.md
	 */
	public function buckets(?DateTimeImmutable $now = null): array {
		$now = ($now ?? new DateTimeImmutable('today'));
		$objectService = $this->gateway->objectService();
		$ids = $this->gateway->schemaIds();

		$today = $now->format('Y-m-d');
		$horizon = $now->modify('+3 days')->format('Y-m-d');

		$cases = $this->gateway->findMany(
			objectService: $objectService,
			registerId: $ids['register'],
			schemaId: $ids['case'],
			filters: []
		);

		$tasks = $this->gateway->findMany(
			objectService: $objectService,
			registerId: $ids['register'],
			schemaId: $ids['caseTask'],
			filters: []
		);

		return ($this->caseBuckets(cases: $cases, today: $today, horizon: $horizon)
			+ $this->taskBuckets(tasks: $tasks, horizon: $horizon));
	}//end buckets()

	/**
	 * Count the case buckets.
	 *
	 * @param array<int, mixed> $cases The case rows.
	 * @param string $today Today, as Y-m-d.
	 * @param string $horizon Three days out, as Y-m-d.
	 *
	 * @return array{open: integer, overdue: integer, dueSoon: integer, closed: integer} The counts.
	 */
	private function caseBuckets(array $cases, string $today, string $horizon): array {
		$counts = ['open' => 0, 'overdue' => 0, 'dueSoon' => 0, 'closed' => 0];

		foreach ($cases as $case) {
			$row = $this->gateway->toArray(object: $case);

			if (($row['isFinalStatus'] ?? false) === true) {
				$counts['closed']++;
				continue;
			}

			$counts['open']++;

			$deadline = substr((string)($row['deadline'] ?? ''), 0, 10);
			if ($deadline === '') {
				continue;
			}

			if ($deadline < $today) {
				$counts['overdue']++;
				continue;
			}

			if ($deadline <= $horizon) {
				$counts['dueSoon']++;
			}
		}//end foreach

		return $counts;
	}//end caseBuckets()

	/**
	 * Count the task buckets.
	 *
	 * @param array<int, mixed> $tasks The task rows.
	 * @param string $horizon Three days out, as Y-m-d.
	 *
	 * @return array{tasksOpen: integer, tasksDue: integer} The counts.
	 */
	private function taskBuckets(array $tasks, string $horizon): array {
		$counts = ['tasksOpen' => 0, 'tasksDue' => 0];

		foreach ($tasks as $task) {
			$row = $this->gateway->toArray(object: $task);

			$status = (string)($row['status'] ?? '');
			if (in_array($status, self::TERMINAL_TASK_STATUSES, true) === true) {
				continue;
			}

			$counts['tasksOpen']++;

			$due = substr((string)($row['dueDate'] ?? ''), 0, 10);
			if ($due !== '' && $due <= $horizon) {
				$counts['tasksDue']++;
			}
		}//end foreach

		return $counts;
	}//end taskBuckets()
}//end class
