<?php

/**
 * Dossiq Milestone Service
 *
 * Service for managing milestones: configurable progress markers on cases
 * that translate technical workflow states into business-friendly indicators.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/milestone-tracking/spec.md
 * @spec openspec/specs/milestone-tracking/spec.md
 * @spec openspec/specs/milestone-tracking/spec.md
 * @spec openspec/specs/milestone-tracking/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Milestone\MilestoneRepository;
use OCA\Dossiq\Service\Milestone\StalledCaseDetector;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for milestone tracking and progress calculation.
 *
 * Reads go through {@see MilestoneRepository} and the stalled-case report is
 * owned by {@see StalledCaseDetector}; what stays here is milestone mutation
 * (mark/reverse) and per-case progress.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class MilestoneService {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service
	 * @param MilestoneRepository $repository Milestone definitions/records reader
	 * @param StalledCaseDetector $stalledDetector Stalled-case report
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly MilestoneRepository $repository,
		private readonly StalledCaseDetector $stalledDetector,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get milestone definitions for a case type.
	 *
	 * @param string $caseTypeId The case type UUID
	 *
	 * @return array<int, array<string, mixed>> Ordered milestone definitions
	 *
	 * @throws \RuntimeException If OpenRegister unavailable
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getMilestones(string $caseTypeId): array {
		return $this->repository->findDefinitions(caseTypeId: $caseTypeId);
	}//end getMilestones()

	/**
	 * Get milestone progress for a specific case.
	 *
	 * @param string $caseId The case UUID
	 * @param string $caseTypeId The case type UUID
	 *
	 * @return array<string, mixed> Progress data with milestones, reached count, total, percentage
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	/**
	 * Progress for a case whose type the caller does not know.
	 *
	 * Resolves the case's own type, then delegates. This exists so a client does
	 * not have to pass a value the server can read for itself: requiring it made
	 * the caller's first render, before its record has loaded, send an empty
	 * path segment and 404.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> Progress data, or an empty shape when the
	 *                              case has no resolvable type.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getCaseProgressForCase(string $caseId): array {
		$caseTypeId = $this->repository->findCaseTypeId(caseId: $caseId);
		if ($caseTypeId === null) {
			return [
				'milestones' => [],
				'reached' => 0,
				'total' => 0,
				'percentage' => 0,
			];
		}

		return $this->getCaseProgress(caseId: $caseId, caseTypeId: $caseTypeId);
	}//end getCaseProgressForCase()

	/**
	 * Get milestone progress for a specific case.
	 *
	 * @param string $caseId The case UUID
	 * @param string $caseTypeId The case type UUID
	 *
	 * @return array<string, mixed> Progress data with milestones, reached count, total, percentage
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getCaseProgress(string $caseId, string $caseTypeId): array {
		$definitions = $this->getMilestones(caseTypeId: $caseTypeId);
		if (count($definitions) === 0) {
			return [
				'milestones' => [],
				'reached' => 0,
				'total' => 0,
				'percentage' => 0,
			];
		}

		$records = $this->repository->findRecords(caseId: $caseId);
		$recordMap = [];
		foreach ($records as $record) {
			$recordMap[$record['milestoneDefinition'] ?? ''] = $record;
		}

		$milestones = [];
		$reached = 0;
		foreach ($definitions as $def) {
			$defId = $def['id'] ?? $def['uuid'] ?? '';
			$record = $recordMap[$defId] ?? null;
			$isReached = $record !== null;

			$reachedAt = null;
			$reachedBy = null;
			if ($isReached === true) {
				$reached++;
				$reachedAt = $record['reachedAt'] ?? null;
				$reachedBy = $record['reachedBy'] ?? null;
			}

			$milestones[] = [
				'identifier' => $def['identifier'] ?? '',
				'label' => $def['label'] ?? $def['name'] ?? '',
				'order' => $def['order'] ?? 0,
				'description' => $def['description'] ?? '',
				'reached' => $isReached,
				'reachedAt' => $reachedAt,
				'reachedBy' => $reachedBy,
			];
		}//end foreach

		// $definitions is guaranteed non-empty here (early return above when count === 0).
		$total = count($definitions);
		$percentage = (int)round(($reached / $total) * 100);

		return [
			'milestones' => $milestones,
			'reached' => $reached,
			'total' => $total,
			'percentage' => $percentage,
		];
	}//end getCaseProgress()

	/**
	 * Mark a milestone as reached for a case.
	 *
	 * @param string $caseId The case UUID
	 * @param string $definitionId The milestone definition UUID
	 * @param string $userId The user marking the milestone
	 * @param string $trigger How it was triggered (manual, workflow, auto)
	 *
	 * @return array<string, mixed> The created milestone record
	 *
	 * @throws \RuntimeException If OpenRegister unavailable
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function markMilestone(
		string $caseId,
		string $definitionId,
		string $userId,
		string $trigger = 'manual',
	): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('milestone_record_schema');

		if (empty($register) === true || empty($schema) === true) {
			throw new RuntimeException('Milestone record schema not configured');
		}

		$recordData = [
			'case' => $caseId,
			'milestoneDefinition' => $definitionId,
			'reachedAt' => date('Y-m-d\TH:i:s'),
			'reachedBy' => $userId,
			'trigger' => $trigger,
		];

		$record = $objectService->saveObject(object: $recordData, register: $register, schema: $schema);

		$this->logger->info(
			'Milestone marked: ' . $definitionId . ' on case ' . $caseId,
			['app' => Application::APP_ID],
		);

		return [
			'id' => $record->getUuid(),
			'reachedAt' => $recordData['reachedAt'],
			'reachedBy' => $userId,
		];
	}//end markMilestone()

	/**
	 * Reverse a milestone (with reason for audit trail).
	 *
	 * @param string $caseId The case UUID
	 * @param string $definitionId The milestone definition UUID
	 * @param string $userId The user reversing
	 * @param string $reason Reason for reversal
	 *
	 * @return bool True if reversed
	 *
	 * @throws \RuntimeException If OpenRegister unavailable
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function reverseMilestone(
		string $caseId,
		string $definitionId,
		string $userId,
		string $reason,
	): bool {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('milestone_record_schema');

		$records = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: [
				'case' => $caseId,
				'milestoneDefinition' => $definitionId,
			],
		);

		if (empty($records) === true) {
			return false;
		}

		// Delete the milestone record.
		foreach ($records as $record) {
			$recordId = $record['id'] ?? $record['uuid'] ?? '';
			if ($recordId !== '') {
				$objectService->deleteObject(uuid: $recordId, register: $register, schema: $schema);
			}
		}

		$this->logger->info(
			'Milestone reversed: ' . $definitionId . ' on case ' . $caseId
			. ' by ' . $userId . ' reason: ' . $reason,
			['app' => Application::APP_ID],
		);

		return true;
	}//end reverseMilestone()

	/**
	 * Calculate average duration between milestones for a case type.
	 *
	 * @param string $caseTypeId The case type UUID
	 *
	 * @return array<string, mixed> Duration analytics per milestone pair
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getDurationAnalytics(string $caseTypeId): array {
		// Placeholder: in production, this would aggregate milestone records
		// across all cases of this type and calculate averages.
		$this->logger->debug(
			'Duration analytics requested for case type: ' . $caseTypeId,
			['app' => Application::APP_ID],
		);

		return [
			'caseTypeId' => $caseTypeId,
			'phases' => [],
			'message' => 'Duration analytics requires sufficient historical data',
		];
	}//end getDurationAnalytics()

	/**
	 * Find active cases that have stalled past a milestone deadline.
	 *
	 * A case is considered stalled when its earliest unreached milestone has
	 * an expected deadline (case start + cumulative expectedDurationWorkingDays)
	 * that lies more than `$thresholdDays` calendar days in the past. Closed
	 * cases (status containing "closed"/"handled"/"refused") are
	 * skipped. The earliest unreached milestone — ordered by `order` — is the
	 * one a case is "waiting on", so it is the one reported.
	 *
	 * @param int $thresholdDays Grace days past the computed deadline before a
	 *                           case is flagged (default 0 = flag on overdue).
	 *
	 * @return array<int, array<string, mixed>> One entry per stalled case:
	 *                                          caseId, caseTitle, caseType,
	 *                                          assignee, milestoneIdentifier,
	 *                                          milestoneLabel, deadline,
	 *                                          daysOverdue.
	 *
	 * @spec openspec/specs/milestone-tracking/spec.md
	 */
	public function findStalledCases(int $thresholdDays = 0): array {
		return $this->stalledDetector->findStalledCases(thresholdDays: $thresholdDays);
	}//end findStalledCases()
}//end class
