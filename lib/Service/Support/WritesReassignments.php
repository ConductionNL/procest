<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Service
 * @package   OCA\Dossiq\Service\Support
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

use RuntimeException;
use Throwable;

/**
 * Writing one reassignment, and the audit entry that records it.
 *
 * Two services reassign, and they answer different questions with the same
 * write: CaseReassignmentService moves everything belonging to one handler,
 * SelectionReassignmentService moves the rows a user ticked on the Cases page.
 *
 * Only the selection differs; the write is identical. One copy is what stops
 * the audit entry drifting between them.
 */
trait WritesReassignments {

	/**
	 * Resolve the object service and register, or refuse.
	 *
	 * @return array{0: object, 1: string} The service and the register.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 *
	 * @spec openspec/changes/reassignment-is-a-bulk-action/specs/reassignment-bulk-action/spec.md
	 */
	private function context(): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		if ($objectService === null || $register === '') {
			throw new RuntimeException('OpenRegister is not available');
		}

		return [$objectService, $register];

	}//end context()

	/**
	 * Reassign one item and append the audit entry.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param string               $register      The register.
	 * @param string               $schema        The schema.
	 * @param string               $id            The object id.
	 * @param array<string, mixed> $item          The stored object.
	 * @param ReassignmentBatch    $batch         Who, to whom, and under which batch.
	 *
	 * @return boolean Whether the write succeeded.
	 *
	 * @spec openspec/changes/reassignment-is-a-bulk-action/specs/reassignment-bulk-action/spec.md
	 */
	private function reassignItem(
		object $objectService,
		string $register,
		string $schema,
		string $id,
		array $item,
		ReassignmentBatch $batch,
	): bool {
		if ($id === '' || $schema === '') {
			return false;
		}

		try {
			$item['assignee'] = $batch->toUser;

			// Append a batch audit entry onto the activity log when present
			// (cases carry an activity property; tasks may not).
			$activity = $this->extractActivityLog(item: $item);

			$activity[] = [
				'type' => 'reassignment',
				'reassignedFrom' => $batch->fromUser,
				'reassignedTo' => $batch->toUser,
				'reassignedBy' => $batch->actorId,
				'batchId' => $batch->batchId,
				'timestamp' => $batch->now,
			];

			if (array_key_exists('activity', $item) === true
				|| $schema === (string)$this->settingsService->getConfigValue('case_schema')
			) {
				$item['activity'] = json_encode($activity);
			}

			$objectService->updateObject($register, $schema, $id, $item);

			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Reassignment item failed',
				['id' => $id, 'batchId' => $batch->batchId, 'error' => $e->getMessage()]
			);

			return false;
		}//end try

	}//end reassignItem()

	/**
	 * Read the item's activity log, tolerating the JSON-string form.
	 *
	 * @param array<string, mixed> $item The stored object.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 *
	 * @spec openspec/changes/reassignment-is-a-bulk-action/specs/reassignment-bulk-action/spec.md
	 */
	private function extractActivityLog(array $item): array {
		$raw = ($item['activity'] ?? null);
		if (is_string($raw) === true) {
			$decoded = json_decode($raw, true);
			if (is_array($decoded) === true) {
				return $decoded;
			}

			return [];
		}

		if (is_array($raw) === true) {
			return $raw;
		}

		return [];

	}//end extractActivityLog()

	/**
	 * Generate a unique batch id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/reassignment-is-a-bulk-action/specs/reassignment-bulk-action/spec.md
	 */
	private function generateBatchId(): string {
		try {
			return 'batch-' . bin2hex(random_bytes(8));
		} catch (\Throwable $e) {
			return 'batch-' . uniqid('', true);
		}
	}//end generateBatchId()
}//end trait
