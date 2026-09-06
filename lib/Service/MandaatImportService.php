<?php

/**
 * Dossiq MandaatImportService.
 *
 * Imports a MandateringsBesluit + its Mandaten from a CSV payload
 * (header: mandaatNummer,omschrijving,rolNaam,plafondCents,subdelegatie,
 * wettelijkeGrondslag,decisionTypes). Resolves rolNaam to an
 * OrganisatieRol id; missing roles abort the import with a clear
 * error. Returns a diff vs the prior version of the same besluit.
 *
 * Excel (.xlsx) parsing is intentionally out of scope (PhpSpreadsheet
 * would pull in 4 MB of vendor code); the import controller may pre-
 * convert .xlsx to .csv via openconnector before posting.
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
 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\Mandaat\MandaatCsvParser;
use OCA\Dossiq\Service\Mandaat\MandaatRepository;
use OCA\Dossiq\Service\Support\SearchesObjects;
use RuntimeException;

/**
 * CSV import of a MandateringsBesluit from a Decidesk export.
 *
 * The wire format is parsed by {@see MandaatCsvParser} and every register read
 * or write goes through {@see MandaatRepository}; what stays here is the import
 * decision — new vs changed vs removed — and the approval state machine.
 *
 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
 */
class MandaatImportService {
	use SearchesObjects;

	/**
	 * Columns an import CSV must carry.
	 *
	 * Canonically owned by {@see MandaatCsvParser}; aliased here so existing
	 * callers of `MandaatImportService::REQUIRED_COLUMNS` keep working.
	 *
	 * @var string[]
	 */
	public const REQUIRED_COLUMNS = MandaatCsvParser::REQUIRED_COLUMNS;

	/**
	 * Constructor.
	 *
	 * @param MandaatRepository $repository OpenRegister access for the mandaat matrix.
	 * @param MandaatCsvParser $csvParser Decidesk CSV export parser.
	 */
	public function __construct(
		private readonly MandaatRepository $repository,
		private readonly MandaatCsvParser $csvParser,
	) {
	}//end __construct()

	/**
	 * Import a mandate decision from CSV text.
	 *
	 * @param string $decisionNumber Decision identifier.
	 * @param string $decisionName Decision name.
	 * @param string $decideskUuid Source Decidesk decision id.
	 * @param string $csvContents The CSV payload (RFC 4180; first row is header).
	 *
	 * @return array<string, mixed> {mandateDecisionId, totalMandaten, newCount, changedCount, removedCount, diff}
	 *
	 * @throws RuntimeException When the CSV is malformed or a rol cannot be resolved.
	 *
	 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
	 */
	public function importFromCsv(
		string $decisionNumber,
		string $decisionName,
		string $decideskUuid,
		string $csvContents,
	): array {
		$rows = $this->csvParser->parse(csv: $csvContents);
		if (count($rows) === 0) {
			throw new RuntimeException('CSV is empty or missing data rows');
		}

		// Resolve rol-name → rolId.
		$resolved = $this->resolveRoleReferences(rows: $rows);

		// Create the decision (concept). The schemaConfigKey stays
		// 'mandaterings_besluit_schema': it is the app-config key the schema id
		// is already stored under on existing installs, not a display name.
		$decision = $this->repository->save(
			schemaConfigKey: 'mandaterings_besluit_schema',
			object: [
				'decisionNumber' => $decisionNumber,
				'decisionName' => $decisionName,
				'status' => 'draft',
				'decideskUuid' => $decideskUuid,
			]
		);

		// Find the prior decision version (by decisionNumber) for diff.
		$prior = $this->repository->findPriorDecision(decisionNumber: $decisionNumber);
		$priorMandaten = [];
		if ($prior !== null) {
			$priorMandaten = $this->repository->findMandatenForBesluit(
				decisionId: (string)($prior['id'] ?? '')
			);
		}

		// Create one mandaat per CSV row.
		$newCount = 0;
		$changedCount = 0;
		$unchangedCount = 0;
		$diff = [];
		foreach ($resolved as $row) {
			$payload = $this->buildMandatePayload(row: $row, decisionId: (string)$decision['id']);

			$this->repository->save(schemaConfigKey: 'mandaat_schema', object: $payload);

			$existing = $this->findPriorMandate(
				priorMandaten: $priorMandaten,
				mandateNumber: (string)$row['mandaatNummer']
			);

			if ($existing === null) {
				$newCount++;
				$diff[] = ['mandaatNummer' => (string)$row['mandaatNummer'], 'change' => 'NEW'];
				continue;
			}

			$changedFields = $this->collectChangedFields(existing: $existing, payload: $payload);

			if (count($changedFields) > 0) {
				$changedCount++;
				$diff[] = [
					'mandaatNummer' => (string)$row['mandaatNummer'],
					'change' => 'CHANGED',
					'fields' => $changedFields,
				];
				continue;
			}

			$unchangedCount++;
			$diff[] = ['mandaatNummer' => (string)$row['mandaatNummer'], 'change' => 'UNCHANGED'];
		}//end foreach

		// REMOVED = in prior, not in new.
		$removed = $this->collectRemovedMandaten(priorMandaten: $priorMandaten, resolved: $resolved);
		$removedCount = count($removed);
		$diff = array_merge($diff, $removed);

		return [
			'mandateDecisionId' => (string)$decision['id'],
			'totalMandaten' => count($resolved),
			'newCount' => $newCount,
			'changedCount' => $changedCount,
			'removedCount' => $removedCount,
			'unchangedCount' => $unchangedCount,
			'diff' => $diff,
		];
	}//end importFromCsv()

	/**
	 * Resolve every CSV row's rolNaam to a gemandateerdeRol id.
	 *
	 * @param array<int, array<string, string>> $rows Parsed CSV data rows.
	 *
	 * @return array<int, array<string, mixed>> Rows enriched with gemandateerdeRol.
	 *
	 * @throws RuntimeException When a row has no rolNaam or names an unknown OrganisatieRol.
	 */
	private function resolveRoleReferences(array $rows): array {
		$roleIndex = $this->repository->loadRoleIndex();
		$resolved = [];
		foreach ($rows as $idx => $row) {
			$roleName = (string)($row['rolNaam'] ?? '');
			if ($roleName === '') {
				throw new RuntimeException('Row ' . ($idx + 1) . ' missing rolNaam');
			}

			if (isset($roleIndex[$roleName]) === false) {
				throw new RuntimeException('Unknown OrganisatieRol "' . $roleName . '" at row ' . ($idx + 1));
			}

			$resolved[] = $row + ['mandateeRole' => $roleIndex[$roleName]];
		}

		return $resolved;
	}//end resolveRolReferences()

	/**
	 * Build the concept mandaat payload for a single resolved CSV row.
	 *
	 * @param array<string, mixed> $row A resolved CSV row.
	 * @param string $decisionId The owning mandate decision id.
	 *
	 * @return array<string, mixed> The mandaat object payload.
	 *
	 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
	 */
	private function buildMandatePayload(array $row, string $decisionId): array {
		return [
			// Key = schema property (renamed). Value = CSV column header, an
			// external input contract that stays as operators' files write it.
			'mandateNumber' => (string)$row['mandaatNummer'],
			'mandateDecision' => $decisionId,
			// Key = schema property (renamed). Value = CSV column header, an
			// external input contract that stays as operators' files write it.
			'description' => (string)($row['description'] ?? $row['omschrijving'] ?? ''),
			'mandateeRole' => (string)$row['mandateeRole'],
			// Key = the schema property (renamed). Value = a CSV COLUMN HEADER,
			// which is an external input format the operator's file already
			// uses, so both spellings are read.
			'legalBasis' => (string)($row['legalBasis'] ?? $row['wettelijkeGrondslag'] ?? ''),
			'terms' => [
				'plafondCents' => (int)($row['plafondCents'] ?? 0),
				'subdelegatie' => $this->csvParser->parseBool(value: (string)($row['subdelegatie'] ?? 'false')),
				'decisionTypes' => $this->csvParser->parseList(value: (string)($row['decisionTypes'] ?? '')),
			],
			'status' => 'draft',
		];
	}//end buildMandaatPayload()

	/**
	 * Find the prior-version mandaat carrying a given mandaatNummer.
	 *
	 * @param array<int, array<string, mixed>> $priorMandaten Mandaten of the prior besluit version.
	 * @param string $mandateNumber The mandaat number to look for.
	 *
	 * @return array<string, mixed>|null The matching prior mandaat, or null when it is new.
	 */
	private function findPriorMandate(array $priorMandaten, string $mandateNumber): ?array {
		foreach ($priorMandaten as $pm) {
			if ((string)($pm['mandateNumber'] ?? '') === $mandateNumber) {
				return $pm;
			}
		}

		return null;
	}//end findPriorMandaat()

	/**
	 * Collect the field names that differ between a prior mandaat and its new payload.
	 *
	 * @param array<string, mixed> $existing The prior-version mandaat.
	 * @param array<string, mixed> $payload The freshly built mandaat payload.
	 *
	 * @return array<int, string> Changed field names; empty when unchanged.
	 *
	 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
	 */
	private function collectChangedFields(array $existing, array $payload): array {
		$changedFields = [];
		foreach (['description', 'mandateeRole', 'legalBasis'] as $f) {
			if ((string)($existing[$f] ?? '') !== (string)$payload[$f]) {
				$changedFields[] = $f;
			}
		}

		$exPlafond = (int)(($existing['terms'] ?? [])['plafondCents'] ?? 0);
		if ($exPlafond !== (int)$payload['terms']['plafondCents']) {
			$changedFields[] = 'plafondCents';
		}

		return $changedFields;
	}//end collectChangedFields()

	/**
	 * Build the REMOVED diff entries — mandaten present in the prior besluit but
	 * absent from the new import.
	 *
	 * @param array<int, array<string, mixed>> $priorMandaten Mandaten of the prior besluit version.
	 * @param array<int, array<string, mixed>> $resolved The resolved CSV rows of the new import.
	 *
	 * @return array<int, array<string, string>> REMOVED diff entries.
	 */
	private function collectRemovedMandaten(array $priorMandaten, array $resolved): array {
		$newNumbers = array_map(static fn (array $r): string => (string)($r['mandaatNummer'] ?? ''), $resolved);
		$removed = [];
		foreach ($priorMandaten as $pm) {
			$num = (string)($pm['mandateNumber'] ?? '');
			if ($num !== '' && in_array($num, $newNumbers, true) === false) {
				$removed[] = ['mandaatNummer' => $num, 'change' => 'REMOVED'];
			}
		}

		return $removed;
	}//end collectRemovedMandaten()

	/**
	 * Approve a concept besluit: flip besluit → vastgesteld + every mandaat → active,
	 * and mark the prior besluit (if any) → vervallen.
	 *
	 * @param string $decisionId Besluit id.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
	 */
	public function approveImport(string $decisionId): array {
		$context = $this->repository->resolveApprovalContext();
		$objectService = $context['objectService'];
		$register = $context['register'];
		$bSchema = $context['bSchema'];
		$mSchema = $context['mSchema'];

		try {
			$decision = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $bSchema,
				id: $decisionId
			);
		} catch (\Throwable $e) {
			$decision = null;
		}

		if ($decision === null) {
			throw new RuntimeException('Besluit not found: ' . $decisionId);
		}

		if (($decision['status'] ?? '') !== 'draft') {
			throw new RuntimeException('Besluit is not in concept status');
		}

		$now = (new DateTimeImmutable())->format('Y-m-d');
		$decision['status'] = 'determined';
		$decision['effectiveFrom'] = ($decision['effectiveFrom'] ?? $now);
		$decision = ($this->saveObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $bSchema,
			object: $decision
		) ?? $decision);

		// Flip mandaten to active.
		$this->repository->activateMandatenForBesluit(
			objectService: $objectService,
			register: $register,
			mSchema: $mSchema,
			decisionId: $decisionId,
			now: $now
		);

		// Expire prior besluit.
		$prior = $this->repository->findPriorDecision(
			decisionNumber: (string)$decision['decisionNumber'],
			excludeId: $decisionId
		);
		if ($prior !== null) {
			$prior['status'] = 'lapsed';
			$prior['expiryDate'] = $now;
			$this->saveObjectAsArray(objectService: $objectService, register: $register, schema: $bSchema, object: $prior);
		}

		return $decision;
	}//end approveImport()
}//end class
