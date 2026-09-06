<?php

/**
 * Dossiq Inspection Checklist Service
 *
 * Admin CRUD on `inspectionChecklist` schemas and per-case completion via
 * `inspectionResult` records. Used by the checklist admin UI and the
 * mobiel-inspectie consumer.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/vth-module/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Service for managing inspection checklists (admin CRUD + case completion).
 *
 * Distinct from the existing ChecklistService (which handles per-item
 * conformity completion during a mobile inspection run). This service
 * manages the template lifecycle: create/read/update/delete of
 * `inspectionChecklist` objects and submission of `inspectionResult` records.
 *
 * @spec openspec/changes/vth-module/tasks.md#task-4
 */
class InspectionChecklistService {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings bridge to OpenRegister
	 * @param LoggerInterface $logger Logger
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-4
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * List all inspection checklists, optionally filtered by case type ref.
	 *
	 * @param string|null $caseTypeRef Optional UUID of the case type to filter by
	 *
	 * @return array<int, array<string, mixed>> List of checklist objects
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-4
	 */
	public function listChecklists(?string $caseTypeRef = null): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = 'inspectionChecklist';
		$params = ['_limit' => 100, '_order' => 'name'];

		if ($caseTypeRef !== null && $caseTypeRef !== '') {
			$params['caseTypeRef'] = $caseTypeRef;
		}

		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: $params
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Failed to list inspection checklists: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return [];
		}
	}//end listChecklists()

	/**
	 * Create a new inspection checklist.
	 *
	 * @param array<string, mixed> $data Checklist data (name, caseTypeRef, items, active, validFrom)
	 *
	 * @return array<string, mixed> Created checklist object
	 *
	 * @throws RuntimeException If OpenRegister is unavailable
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-4
	 */
	public function createChecklist(array $data): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');

		$data['version'] = $data['version'] ?? 1;
		$data['active'] = $data['active'] ?? true;

		$result = $objectService->saveObject(
			register: $register,
			schema: 'inspectionChecklist',
			object: $data
		);

		if (is_array($result) === true) {
			return $result;
		}

		if (is_object($result) === true) {
			return get_object_vars(object: $result);
		}

		return [];
	}//end createChecklist()

	/**
	 * Update an existing inspection checklist.
	 *
	 * Bumps the version number on every update to support versioned
	 * in-progress inspections.
	 *
	 * @param string $id UUID of the checklist to update
	 * @param array<string, mixed> $data Updated fields
	 *
	 * @return array<string, mixed> Updated checklist object
	 *
	 * @throws RuntimeException If OpenRegister is unavailable
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-4
	 */
	public function updateChecklist(string $id, array $data): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');

		$data['id'] = $id;
		$data['version'] = ($data['version'] ?? 1) + 1;

		$result = $objectService->saveObject(
			register: $register,
			schema: 'inspectionChecklist',
			object: $data
		);

		if (is_array($result) === true) {
			return $result;
		} return [];
	}//end updateChecklist()

	/**
	 * Delete an inspection checklist.
	 *
	 * ⚠️ The identifier parameter on `ObjectService::deleteObject()` is named
	 * `$uuid`, not `$id`. This method shipped passing `id:` as a named argument,
	 * so every call raised `Unknown named parameter $id`, was swallowed by the
	 * catch below, and returned false — the admin Delete button 500'd on every
	 * checklist and no checklist has ever been deleted through the UI. Verified
	 * against a live instance before and after this change.
	 *
	 * @param string $id UUID of the checklist to delete
	 *
	 * @return bool True on success
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function deleteChecklist(string $id): bool {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return false;
		}

		$register = $this->settingsService->getConfigValue('register');

		try {
			$objectService->deleteObject(
				uuid: $id,
				register: $register,
				schema: 'inspectionChecklist'
			);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Failed to delete inspection checklist ' . $id . ': ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return false;
		}
	}//end deleteChecklist()

	/**
	 * Submit an inspection result for a case.
	 *
	 * Validates that required-photo items have a photo reference when answered
	 * non-conformant. Saves the result and calculates the overall result.
	 *
	 * @param string $caseId UUID of the case
	 * @param string $checklistId UUID of the inspectionChecklist
	 * @param array<string, mixed> $resultData Answers and metadata
	 * @param string $completedBy User UID of the inspector
	 *
	 * @return array<string, mixed> Saved inspectionResult object
	 *
	 * @throws RuntimeException If validation fails or OpenRegister unavailable
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-4
	 */
	public function submitResult(
		string $caseId,
		string $checklistId,
		array $resultData,
		string $completedBy,
	): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');

		// Validate required-photo items.
		$answers = $resultData['answers'] ?? [];
		$this->validatePhotoRequirements(answers: $answers, register: $register, objectService: $objectService);

		// Calculate overall result.
		$overallResult = $this->calculateOverallResult(answers: $answers);

		$payload = [
			'caseRef' => $caseId,
			'checklistRef' => $checklistId,
			'completedBy' => $completedBy,
			'completedAt' => date(format: 'c'),
			'answers' => $answers,
			'overallResult' => $overallResult,
			'remarks' => $resultData['remarks'] ?? '',
			'location' => $resultData['location'] ?? '',
		];

		$saved = $objectService->saveObject(
			register: $register,
			schema: 'inspectionResult',
			object: $payload
		);

		$this->logger->info(
			'Inspection result submitted for case ' . $caseId . ' (result=' . $overallResult . ')',
			['app' => Application::APP_ID]
		);

		if (is_array($saved) === true) {
			return $saved;
		} return [];
	}//end submitResult()

	/**
	 * Get all inspection results for a case.
	 *
	 * @param string $caseId UUID of the case
	 *
	 * @return array<int, array<string, mixed>> List of inspectionResult objects
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-4
	 */
	public function getResultsForCase(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');

		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: 'inspectionResult',
				filters: ['caseRef' => $caseId, '_limit' => 50, '_order' => 'completedAt']
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Failed to get inspection results for case ' . $caseId . ': ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return [];
		}
	}//end getResultsForCase()

	/**
	 * Validate that non-conformant answers with fotoRequired have a photoRef.
	 *
	 * @param array<int, mixed> $answers Array of answer objects
	 * @param string $register Register slug
	 * @param object $objectService OpenRegister object service
	 *
	 * @return void
	 *
	 * @throws RuntimeException If a required photo is missing
	 */
	private function validatePhotoRequirements(
		array $answers,
		string $register,
		object $objectService,
	): void {
		foreach ($answers as $answer) {
			if (is_array($answer) === false) {
				continue;
			}

			$value = $answer['value'] ?? '';
			$photoRef = $answer['photoRef'] ?? '';
			$itemRef = $answer['itemRef'] ?? '';

			if ($value !== 'non_conform' || $photoRef !== '') {
				continue;
			}

			// Look up the checklistItem to see if fotoRequired=true.
			if ($itemRef === '') {
				continue;
			}

			$this->assertItemPhotoRequirement(
				objectService: $objectService,
				register: $register,
				itemRef: $itemRef
			);
		}//end foreach
	}//end validatePhotoRequirements()

	/**
	 * Raise when the referenced checklistItem demands a photo.
	 *
	 * A failed item lookup is tolerated — submission is allowed rather than
	 * blocked on an infrastructure error.
	 *
	 * @param object $objectService OpenRegister object service
	 * @param string $register Register slug
	 * @param mixed $itemRef Reference to the checklistItem
	 *
	 * @return void
	 *
	 * @throws RuntimeException If a required photo is missing
	 */
	private function assertItemPhotoRequirement(
		object $objectService,
		string $register,
		mixed $itemRef,
	): void {
		try {
			// The find() call returns an ObjectEntity whose data lives in
			// protected properties, so get_object_vars() from out here read
			// an EMPTY array and the photo requirement never fired. The
			// array bridge goes through jsonSerialize(), which exposes the
			// real fields.
			$item = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: 'checklistItem',
				id: (string)$itemRef
			);

			if ($item !== null && ($item['photoRequired'] ?? false) === true) {
				throw new RuntimeException(
					'Photo required for non-conformant checklist item ' . $itemRef
				);
			}
		} catch (RuntimeException $e) {
			throw $e;
		} catch (Throwable) {
			// Item lookup failed — allow submission rather than blocking.
		}//end try
	}//end assertItemPhotoRequirement()

	/**
	 * Calculate the overall result based on answer values.
	 *
	 * - All answers conform → 'conform'
	 * - Any answer niet_conform → 'non_conform'
	 * - Otherwise → 'partly_conform'
	 *
	 * @param array<int, mixed> $answers Array of answer objects
	 *
	 * @return string 'conform'|'partly_conform'|'non_conform'
	 */
	private function calculateOverallResult(array $answers): string {
		$hasNietConform = false;
		$hasConform = false;

		foreach ($answers as $answer) {
			if (is_array($answer) === false) {
				continue;
			}

			$value = $answer['value'] ?? '';
			if ($value === 'non_conform') {
				$hasNietConform = true;
			} elseif ($value === 'conform') {
				$hasConform = true;
			}
		}

		if ($hasNietConform === true && $hasConform === false) {
			return 'non_conform';
		}

		if ($hasNietConform === true) {
			return 'partly_conform';
		}

		return 'conform';
	}//end calculateOverallResult()
}//end class
