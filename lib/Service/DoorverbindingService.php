<?php

/**
 * Dossiq Doorverbinding Service.
 *
 * Warm-transfer orchestration: captures an immutable context-snapshot at
 * transfer time, records the doorverbinding, and lets the receiving specialist
 * accept or reject. Handover notes are append-only; the original context
 * snapshot is never mutated, preserving the full context trail.
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
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T08
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Orchestrates warm doorverbindingen with immutable context-overdracht.
 *
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T08
 */
class DoorverbindingService {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/*
	 * NO createContextSnapshot() HERE.
	 *
	 * It JSON-encoded a context snapshot for a warm transfer and had no
	 * caller — not even `initiateWarmTransfer()` below, which builds and
	 * persists the doorverbinding record itself. The snapshot the class
	 * docblock describes is produced on the live path; this was a second,
	 * unreached builder for it.
	 */

	/**
	 * Initiate a warm transfer and persist the doorverbinding record.
	 *
	 * @param array<string, mixed> $data The transfer fields.
	 *
	 * @return array<string, mixed> The created doorverbinding record.
	 *
	 * @throws RuntimeException When the schema is unconfigured or the write fails.
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T08
	 */
	public function initiateWarmTransfer(array $data): array {
		$interactionId = trim((string)($data['interactionId'] ?? ''));
		$fromEmployeeId = trim((string)($data['fromEmployeeId'] ?? ''));
		if ($interactionId === '' || $fromEmployeeId === '') {
			throw new RuntimeException('contactmomentId and vanMedewerkerId are required');
		}

		[$objectService, $register, $schema] = $this->resolve();

		$record = [
			'interactionId' => $interactionId,
			'fromEmployeeId' => $fromEmployeeId,
			'toEmployeeId' => ($data['toEmployeeId'] ?? null),
			'toQueue' => ($data['toQueue'] ?? null),
			'transferReason' => (string)($data['transferReason'] ?? ''),
			'contextTransfer' => (string)($data['contextTransfer'] ?? ''),
			'contextSnapshot' => (string)($data['contextSnapshot'] ?? '{}'),
			'accepted' => null,
			'warmTransferStarted' => date('c'),
		];

		try {
			$created = $objectService->saveObject(object: $record, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: failed to initiate doorverbinding: ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			throw new RuntimeException('Could not initiate doorverbinding');
		}

		return $this->toArray(result: $created);
	}//end initiateWarmTransfer()

	/**
	 * Mark a doorverbinding as accepted by the receiving specialist.
	 *
	 * @param string $doorverbindingId The doorverbinding UUID.
	 * @param string $callerUid The UID of the medewerker answering this transfer.
	 *
	 * @return array<string, mixed> The updated record.
	 *
	 * @throws RuntimeException When already answered, caller is not the assigned recipient, or the update fails.
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T08
	 */
	public function acceptTransfer(string $doorverbindingId, string $callerUid = ''): array {
		$current = $this->load(doorverbindingId: $doorverbindingId);
		if (($current['accepted'] ?? null) !== null) {
			throw new RuntimeException('Doorverbinding already answered');
		}

		$assignedTo = ($current['toEmployeeId'] ?? null);
		if ($assignedTo !== null && $callerUid !== '' && $assignedTo !== $callerUid) {
			throw new RuntimeException('Not authorized to answer this doorverbinding');
		}

		return $this->update(
			doorverbindingId: $doorverbindingId,
			patch: [
				'accepted' => true,
				'acceptanceTime' => date('c'),
			],
		);
	}//end acceptTransfer()

	/**
	 * Mark a doorverbinding as rejected with a reason.
	 *
	 * @param string $doorverbindingId The doorverbinding UUID.
	 * @param string $reason The rejection reason.
	 * @param string $callerUid The UID of the medewerker answering this transfer.
	 *
	 * @return array<string, mixed> The updated record.
	 *
	 * @throws RuntimeException When already answered, caller is not the assigned recipient, reason missing, or update fails.
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T08
	 */
	public function rejectTransfer(string $doorverbindingId, string $reason, string $callerUid = ''): array {
		$reason = trim($reason);
		if ($reason === '') {
			throw new RuntimeException('Rejection reason is required');
		}

		$current = $this->load(doorverbindingId: $doorverbindingId);
		if (($current['accepted'] ?? null) !== null) {
			throw new RuntimeException('Doorverbinding already answered');
		}

		$assignedTo = ($current['toEmployeeId'] ?? null);
		if ($assignedTo !== null && $callerUid !== '' && $assignedTo !== $callerUid) {
			throw new RuntimeException('Not authorized to answer this doorverbinding');
		}

		return $this->update(
			doorverbindingId: $doorverbindingId,
			patch: [
				'accepted' => false,
				'rejectedReason' => $reason,
			],
		);
	}//end rejectTransfer()

	/**
	 * Append handover notes to a doorverbinding without overwriting prior notes.
	 *
	 * @param string $doorverbindingId The doorverbinding UUID.
	 * @param string $notes The notes to append.
	 * @param string $specialistUid The appending specialist UID.
	 *
	 * @return array<string, mixed> The updated record.
	 *
	 * @throws RuntimeException When the update fails.
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T08
	 */
	public function appendContextNotes(string $doorverbindingId, string $notes, string $specialistUid): array {
		$current = $this->load(doorverbindingId: $doorverbindingId);
		$existing = (string)($current['contextTransfer'] ?? '');

		$entry = '[' . date('c') . ' ' . $specialistUid . '] ' . trim($notes);
		$merged = $entry;
		if ($existing !== '') {
			$merged = $existing . "\n" . $entry;
		}

		return $this->update(doorverbindingId: $doorverbindingId, patch: ['contextTransfer' => $merged]);
	}//end appendContextNotes()

	/**
	 * Load a doorverbinding record by id.
	 *
	 * @param string $doorverbindingId The doorverbinding UUID.
	 *
	 * @return array<string, mixed> The record.
	 *
	 * @throws RuntimeException When not found or unconfigured.
	 */
	private function load(string $doorverbindingId): array {
		[$objectService, $register, $schema] = $this->resolve();

		try {
			$record = $objectService->find($doorverbindingId, register: $register, schema: $schema);
		} catch (Throwable $e) {
			throw new RuntimeException('Doorverbinding not found');
		}

		return $this->toArray(result: $record);
	}//end load()

	/**
	 * Persist a partial update to a doorverbinding.
	 *
	 * @param string $doorverbindingId The doorverbinding UUID.
	 * @param array<string, mixed> $patch The fields to update.
	 *
	 * @return array<string, mixed> The updated record.
	 *
	 * @throws RuntimeException When the update fails.
	 */
	private function update(string $doorverbindingId, array $patch): array {
		[$objectService, $register, $schema] = $this->resolve();

		try {
			$updated = $objectService->saveObject(object: $patch, register: $register, schema: $schema, uuid: $doorverbindingId);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: failed to update doorverbinding: ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			throw new RuntimeException('Could not update doorverbinding');
		}

		return $this->toArray(result: $updated);
	}//end update()

	/**
	 * Resolve the ObjectService, register and schema for doorverbinding.
	 *
	 * @return array{0: object, 1: string, 2: string}
	 *
	 * @throws RuntimeException When OpenRegister or schema is unavailable.
	 */
	private function resolve(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('doorverbinding_schema');
		if ($register === '' || $schema === '') {
			throw new RuntimeException('Doorverbinding schema is not configured');
		}

		return [$objectService, $register, $schema];
	}//end resolve()

	/**
	 * Normalise an ObjectService result into a plain array.
	 *
	 * @param mixed $result The ObjectService result.
	 *
	 * @return array<string, mixed> The normalised record.
	 */
	private function toArray($result): array {
		if (is_array($result) === true) {
			return $result;
		}

		if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
			return (array)$result->jsonSerialize();
		}

		if (is_object($result) === true) {
			return (array)$result;
		}

		return [];
	}//end toArray()
}//end class
