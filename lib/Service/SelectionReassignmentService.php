<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Service
 * @package   OCA\Dossiq\Service
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Dossiq\Service\Support\ReassignmentBatch;
use OCA\Dossiq\Service\Support\WritesReassignments;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reassign an explicitly selected set of cases.
 *
 * {@see CaseReassignmentService} answers "move everything open that belongs to
 * handler A over to handler B". This answers the question the CASES PAGE asks:
 * "these rows, to this person" — the rows a user ticked, whose current
 * assignees may all differ and may include none at all.
 *
 * Separate class rather than a second method on the sibling, because they are
 * separate operations that happen to share a write, and putting both on one
 * class took it past the complexity threshold. The shared write lives in
 * {@see WritesReassignments}, so the audit entry cannot drift between them.
 *
 * @spec openspec/changes/reassignment-is-a-bulk-action/specs/reassignment-bulk-action/spec.md
 */
class SelectionReassignmentService {

	use WritesReassignments;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register/schema configuration.
	 * @param LoggerInterface $logger          The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Reassign an explicit set of cases to one handler.
	 *
	 * The sibling {@see self::execute()} answers a different question: "move
	 * everything open that belongs to handler A over to handler B". This one
	 * answers the question the CASES PAGE asks, which is "these rows, to this
	 * person" — the rows a user ticked, whose current assignees may all differ
	 * and may include none at all.
	 *
	 * 🔴 `reassignedFrom` is read PER CASE, from that case's own assignee, not
	 * from one batch value. A batch-level `from` is truthful only when every
	 * row came from the same handler, which is exactly what a hand-picked
	 * selection does not guarantee; recording one would write an audit trail
	 * that names the wrong person for most of the rows.
	 *
	 * The rows share one `batchId`, so the selection is still recoverable as a
	 * single act.
	 *
	 * @param array<int, string> $caseIds The case ids to move.
	 * @param string             $toUser  The receiving handler.
	 * @param string             $actorId Who is doing it.
	 *
	 * @return array{batchId: string, requested: int, succeeded: int, results: array<int, array<string, mixed>>}
	 *         What happened, per case.
	 *
	 * @throws InvalidArgumentException When no case or no receiver is named.
	 *
	 * @spec openspec/changes/reassignment-is-a-bulk-action/specs/reassignment-bulk-action/spec.md
	 */
	public function executeForCases(array $caseIds, string $toUser, string $actorId = ''): array {
		$toUser = trim($toUser);
		if ($toUser === '') {
			throw new InvalidArgumentException('toUser is required');
		}

		$caseIds = array_values(array_filter(array_map('strval', $caseIds), static fn (string $id): bool => trim($id) !== ''));
		if ($caseIds === []) {
			throw new InvalidArgumentException('At least one case id is required');
		}

		[$objectService, $register] = $this->context();
		$caseSchema = (string)$this->settingsService->getConfigValue('case_schema');
		if ($caseSchema === '') {
			throw new InvalidArgumentException('The case schema is not configured');
		}

		$batchId = $this->generateBatchId();
		$now = (new DateTimeImmutable())->format('Y-m-d\TH:i:sP');

		$results = [];
		$succeeded = 0;
		foreach ($caseIds as $id) {
			$case = $this->readCase(objectService: $objectService, register: $register, schema: $caseSchema, id: $id);
			if ($case === null) {
				$results[] = ['type' => 'case', 'id' => $id, 'success' => false, 'reason' => 'not found'];
				continue;
			}

			$currentAssignee = trim((string)($case['assignee'] ?? ''));
			if ($currentAssignee === $toUser) {
				// Not a failure, and not work either. Rewriting the row would
				// add an audit entry saying it moved from someone to themselves.
				$results[] = ['type' => 'case', 'id' => $id, 'success' => true, 'reason' => 'already assigned'];
				$succeeded += 1;
				continue;
			}

			$success = $this->reassignItem(
				objectService: $objectService,
				register: $register,
				schema: $caseSchema,
				id: $id,
				item: $case,
				batch: new ReassignmentBatch(
					fromUser: $currentAssignee,
					toUser: $toUser,
					actorId: $actorId,
					batchId: $batchId,
					now: $now
				),
			);

			$results[] = ['type' => 'case', 'id' => $id, 'success' => $success];
			if ($success === true) {
				$succeeded += 1;
			}
		}//end foreach

		return [
			'batchId' => $batchId,
			'requested' => count($caseIds),
			'succeeded' => $succeeded,
			'results' => $results,
		];

	}//end executeForCases()
	/**
	 * Read one case, or null when it is not there.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The register.
	 * @param string $schema        The case schema.
	 * @param string $id            The case id.
	 *
	 * @return array<string, mixed>|null The case.
	 *
	 * @spec openspec/changes/reassignment-is-a-bulk-action/specs/reassignment-bulk-action/spec.md
	 */
	private function readCase(object $objectService, string $register, string $schema, string $id): ?array {
		try {
			$found = $objectService->find($id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Reassignment could not read a case',
				['id' => $id, 'error' => $e->getMessage()]
			);

			return null;
		}

		if (is_array($found) === true) {
			return $found;
		}

		if (is_object($found) === true && method_exists($found, 'getObject') === true) {
			$object = $found->getObject();
			if (is_array($object) === true) {
				return $object;
			}

			return null;
		}

		return null;

	}//end readCase()
}//end class
