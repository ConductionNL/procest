<?php

/**
 * Dossiq KCC-Werkplek Seed Data Service.
 *
 * Seeds the default KCC quick-actions (status terugkoppelen, nieuwe zaak,
 * klacht registreren, doorverbinden, bel terug inplannen) and two example
 * belplannen (algemeen gemeentenummer with keuzemenu + vaardigheid routing,
 * and a meldingen-nummer) from `lib/Settings/kcc_werkplek_seed_data.json`
 * into OpenRegister. The seed is idempotent: an existing object with the same
 * id is skipped.
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
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/specs.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seeds the default KCC quick-actions and example belplannen into OpenRegister.
 *
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/specs.md
 */
class KccWerkplekSeedDataService {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings + ObjectService access.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Seed the default KCC quick-actions and example belplannen.
	 *
	 * @return array<string, mixed> Result with 'success' and either 'message' or per-kind counts.
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/specs.md
	 */
	public function seed(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return ['success' => false, 'message' => 'OpenRegister is not available'];
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		if ($register === '') {
			return ['success' => false, 'message' => 'Dossiq register not configured'];
		}

		$quickActionSchema = (string)$this->settingsService->getConfigValue('kcc_quick_action_schema');
		$belplanSchema = (string)$this->settingsService->getConfigValue('belplan_schema');
		if ($quickActionSchema === '' || $belplanSchema === '') {
			return ['success' => false, 'message' => 'KCC-werkplek schemas not configured'];
		}

		$seedPath = __DIR__ . '/../Settings/kcc_werkplek_seed_data.json';
		if (file_exists($seedPath) === false) {
			return ['success' => false, 'message' => 'Seed file not found'];
		}

		$data = json_decode((string)file_get_contents($seedPath), true);
		if (is_array($data) === false) {
			return ['success' => false, 'message' => 'Invalid seed JSON'];
		}

		$counts = ['quickActions' => 0, 'belplannen' => 0, 'skipped' => 0, 'failed' => 0];

		// This service is only ever invoked from boot-time repair steps
		// (SeedKccWerkplekData, SeedDeadlineMonitoringData) — never from a live
		// user request — so it is safe to elevate the whole seed for the
		// duration of this call. Anonymous callers are otherwise fail-closed
		// by OpenRegister RBAC (#1955) on every boot.
		$this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: function () use ($objectService, $register, $quickActionSchema, $belplanSchema, $data, &$counts): void {
				$this->seedRows(
					objectService: $objectService,
					register: $register,
					schema: $quickActionSchema,
					rows: (array)($data['kccQuickActions'] ?? []),
					counterKey: 'quickActions',
					counts: $counts,
				);

				$this->seedRows(
					objectService: $objectService,
					register: $register,
					schema: $belplanSchema,
					rows: (array)($data['belplannen'] ?? []),
					counterKey: 'belplannen',
					counts: $counts,
				);
			}
		);

		// A SEED THAT SEEDED NOTHING MUST NOT REPORT SUCCESS. Every row failure
		// below is counted rather than only logged, and one failure makes the
		// whole call unsuccessful — otherwise "five quick-actions refused" and
		// "five quick-actions already present" produce the same
		// `success: true, quickActions: 0` line, and only one of them means the
		// install is healthy.
		if ($counts['failed'] > 0) {
			$this->logger->error('Dossiq KCC-werkplek: seed refused rows', $counts);

			return array_merge(
				[
					'success' => false,
					'message' => $counts['failed'] . ' row(s) refused; see the log for each refusal',
				],
				$counts
			);
		}

		$this->logger->info('Dossiq KCC-werkplek: seed complete', $counts);

		return array_merge(['success' => true], $counts);
	}//end seed()

	/**
	 * Seed a list of rows into one schema, skipping ids that already exist.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $register Register id.
	 * @param string $schema Schema id.
	 * @param array<int, mixed> $rows Seed rows.
	 * @param string $counterKey Counter key to increment on insert.
	 * @param array<string, int> $counts Counter accumulator (by reference).
	 *
	 * @return void
	 */
	private function seedRows(
		object $objectService,
		string $register,
		string $schema,
		array $rows,
		string $counterKey,
		array &$counts,
	): void {
		$existingIds = $this->existingIds(objectService: $objectService, register: $register, schema: $schema);

		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$rowId = (string)($row['id'] ?? '');
			if ($rowId !== '' && in_array($rowId, $existingIds, true) === true) {
				$counts['skipped']++;
				continue;
			}

			try {
				// ObjectService::saveObject()'s first parameter is the
				// object payload, not the register — the previous
				// positional call passed $register/$schema/$row into
				// $object/$extend/$register, which either threw a
				// TypeError or silently wrote the wrong data. Named
				// arguments make the mapping unambiguous.
				$objectService->saveObject(object: $row, register: $register, schema: $schema);
				$counts[$counterKey]++;
			} catch (Throwable $e) {
				$counts['failed']++;
				$this->logger->error(
					'Dossiq KCC-werkplek seed: row failed',
					['id' => $rowId, 'schema' => $schema, 'error' => $e->getMessage()]
				);
			}//end try
		}//end foreach
	}//end seedRows()

	/**
	 * Collect existing object ids for idempotent skip-detection.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $register Register id.
	 * @param string $schema Schema id.
	 *
	 * @return array<int, string>
	 */
	private function existingIds(object $objectService, string $register, string $schema): array {
		try {
			$rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema);
		} catch (Throwable $e) {
			// An unreadable schema is reported, not assumed empty: "no rows
			// exist" and "the read was refused" both returned [], and the second
			// one makes the seed re-insert every row it already holds.
			$this->logger->warning(
				'Dossiq KCC-werkplek seed: could not read existing rows; treating the schema as empty',
				['schema' => $schema, 'error' => $e->getMessage()]
			);
			return [];
		}

		$ids = [];
		foreach ($rows as $row) {
			$rowId = '';
			if (isset($row['id']) === true) {
				$rowId = (string)$row['id'];
			}

			if ($rowId !== '') {
				$ids[] = $rowId;
			}
		}//end foreach

		return $ids;
	}//end existingIds()
}//end class
