<?php

/**
 * Dossiq MandaatGebruikService.
 *
 * Append-only audit log of mandate uses. Once logged a row is immutable
 * at the application level (the OpenRegister CRUD itself does not enforce
 * this, but updates/deletes are blocked through the controller surface
 * by returning 403 — see {@see MandaatController}).
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
 * @spec openspec/changes/mandaat-matrix-05-case-decision-integration/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Immutable audit log for mandate uses.
 *
 * @spec openspec/changes/mandaat-matrix-05-case-decision-integration/tasks.md
 */
class MandaatGebruikService {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Log a mandate use.
	 *
	 * @param string $caseId Case id.
	 * @param string $decisionId Decision id.
	 * @param string $mandateId Mandate id.
	 * @param string $userId User id.
	 * @param array<string, mixed> $roleSnapshot Role snapshot at decision time.
	 * @param array<string, mixed> $conditionsApplied Voorwaarden snapshot.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/mandaat-matrix-05-case-decision-integration/tasks.md
	 */
	public function logMandaatGebruik(
		string $caseId,
		string $decisionId,
		string $mandateId,
		string $userId,
		array $roleSnapshot = [],
		array $conditionsApplied = [],
	): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('mandaat_gebruik_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		$row = [
			'caseId' => $caseId,
			'decisionId' => $decisionId,
			'mandateId' => $mandateId,
			'userId' => $userId,
			'moment' => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
			'roleOnMomentFromDecision' => $roleSnapshot,
			'usedTerms' => $conditionsApplied,
			'mandateVersionId' => $mandateId,
		];

		try {
			$saved = $this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				object: $row
			);
			return ($saved ?? $row);
		} catch (\Throwable $e) {
			$this->logger->error('MandaatGebruik log failed', ['caseId' => $caseId, 'error' => $e->getMessage()]);
			return $row;
		}
	}//end logMandaatGebruik()

	/**
	 * Retrieve the decision audit trail for a case.
	 *
	 * @param string $caseId Case id.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/mandaat-matrix-05-case-decision-integration/tasks.md
	 */
	public function getDecisionAuditTrail(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('mandaat_gebruik_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		try {
			return $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: ['caseId' => $caseId]);
		} catch (\Throwable $e) {
			return [];
		}
	}//end getDecisionAuditTrail()

	/**
	 * Retrieve the decisions taken under a mandate in a date range.
	 *
	 * @param string $mandateId Mandate id.
	 * @param DateTimeImmutable|null $from From (inclusive).
	 * @param DateTimeImmutable|null $until Until (inclusive).
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/mandaat-matrix-05-case-decision-integration/tasks.md
	 */
	public function getDecisionByMandaat(string $mandateId, ?DateTimeImmutable $from = null, ?DateTimeImmutable $until = null): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('mandaat_gebruik_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['mandateId' => $mandateId]
			);
		} catch (\Throwable $e) {
			return [];
		}

		if ($from === null && $until === null) {
			return $rows;
		}

		return $this->filterByDateRange(rows: $rows, from: $from, until: $until);
	}//end getDecisionByMandaat()

	/**
	 * Keep only the rows whose `tijdstip` day falls inside the supplied (inclusive) bounds.
	 *
	 * A null bound is not applied, so a row is dropped only by a bound that is actually set.
	 *
	 * @param array<int, array<string, mixed>> $rows The mandate-usage rows.
	 * @param DateTimeImmutable|null $from From (inclusive).
	 * @param DateTimeImmutable|null $until Until (inclusive).
	 *
	 * @return array<int, array<string, mixed>> The rows within the range.
	 */
	private function filterByDateRange(array $rows, ?DateTimeImmutable $from, ?DateTimeImmutable $until): array {
		$out = [];
		foreach ($rows as $row) {
			$when = substr((string)($row['moment'] ?? ''), 0, 10);
			if ($from !== null && $when < $from->format('Y-m-d')) {
				continue;
			}

			if ($until !== null && $when > $until->format('Y-m-d')) {
				continue;
			}

			$out[] = $row;
		}

		return $out;
	}//end filterByDateRange()
}//end class
