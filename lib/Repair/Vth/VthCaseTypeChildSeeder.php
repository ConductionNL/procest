<?php

/**
 * Dossiq VTH case-type child seeder.
 *
 * Writes the four collections a VTH case type declares — statusTypes,
 * roleTypes, documentTypes and propertyDefinitions — as objects of their own,
 * each carrying the `caseType` back-reference.
 *
 * 🔑 THE LINK LIVES ON THE CHILD. `caseType` declares no `statusTypes`
 * property, and OpenRegister's magic mapper is a whitelist by omission: a key
 * the schema does not declare is answered 200 and stored nowhere. So the four
 * collections CANNOT ride along on the case-type row, which is why
 * {@see \OCA\Dossiq\Repair\VthSeedDataRepairStep} strips them from it. What was
 * missing was the other half — writing them where the schema can hold them.
 * Until this class existed nothing did: measured on a clean rig on 2026-09-04,
 * six VTH case types with not one statusType attached to any of them, and every
 * VTH workflow template skipped for want of a status map it could never build.
 *
 * The same shape three other seeders already use:
 * {@see \OCA\Dossiq\Service\SeedDataService::seedChildTypes()},
 * {@see \OCA\Dossiq\Service\Besluitvorming\TemplateBundleSeeder::seedChildren()}
 * and {@see \OCA\Dossiq\Repair\CaseFlowSeedDataRepairStep::seedStatuses()}.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair\Vth
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
 * @spec openspec/specs/vth-case-type-seed/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair\Vth;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Migration\IOutput;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seeds a VTH case type's child collections into their own schemas.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/vth-case-type-seed/spec.md
 */
class VthCaseTypeChildSeeder {

	use SearchesObjects;

	/**
	 * The collections a case type declares, and the settings key naming the
	 * schema each is written to.
	 *
	 * The keys match `vth_seed_data.json` exactly, and the values match the
	 * config keys `InitializeSettings` populates.
	 *
	 * @var array<string, string>
	 */
	private const COLLECTIONS = [
		'statusTypes' => 'status_type_schema',
		'roleTypes' => 'role_type_schema',
		'documentTypes' => 'document_type_schema',
		'propertyDefinitions' => 'property_definition_schema',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Resolves the configured schemas.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Seed every child a case type declares and does not yet have.
	 *
	 * 🔑 IDEMPOTENCY IS PER CHILD, NOT PER CASE TYPE. The six VTH case types
	 * are already installed everywhere, so keying this on "does the case type
	 * exist" would make the fix a no-op on precisely the instances that need
	 * it. Per-child also survives a half-finished run: a pass that created the
	 * statuses and then failed on the document types finishes on the next
	 * `occ maintenance:repair` instead of skipping everything behind the parent.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The register slug.
	 * @param string $caseTypeId The owning case type's uuid.
	 * @param array<string, mixed> $caseType The case type as shipped, children included.
	 * @param IOutput $output Output sink.
	 *
	 * @return array{created: int, present: int} What was written and what was already there.
	 *
	 * @spec openspec/specs/vth-case-type-seed/spec.md
	 */
	public function seed(
		object $objectService,
		string $register,
		string $caseTypeId,
		array $caseType,
		IOutput $output,
	): array {
		$totals = ['created' => 0, 'present' => 0];
		if ($caseTypeId === '') {
			return $totals;
		}

		foreach (self::COLLECTIONS as $collection => $configKey) {
			$records = ($caseType[$collection] ?? []);
			if (is_array($records) === false || $records === []) {
				continue;
			}

			$schema = (string)$this->settingsService->getConfigValue($configKey);
			if ($schema === '') {
				$output->warning(
					'VTH seed: ' . $configKey . ' is not configured, so the '
					. $collection . ' of "' . (string)($caseType['slug'] ?? '') . '" are not seeded.'
				);
				continue;
			}

			$counts = $this->seedCollection(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				caseTypeId: $caseTypeId,
				collection: $collection,
				records: $records,
				output: $output,
			);

			$totals['created'] += $counts['created'];
			$totals['present'] += $counts['present'];
		}//end foreach

		return $totals;
	}//end seed()

	/**
	 * Seed one collection of one case type.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The register slug.
	 * @param string $schema The child schema slug.
	 * @param string $caseTypeId The owning case type's uuid.
	 * @param string $collection The collection key, for logging.
	 * @param array<int|string, mixed> $records The shipped child payloads.
	 * @param IOutput $output Output sink.
	 *
	 * @return array{created: int, present: int} What was written and what was already there.
	 */
	private function seedCollection(
		object $objectService,
		string $register,
		string $schema,
		string $caseTypeId,
		string $collection,
		array $records,
		IOutput $output,
	): array {
		$present = $this->existingNames(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			caseTypeId: $caseTypeId,
		);

		if ($present === null) {
			$output->warning(
				'VTH seed: could not read the existing ' . $collection . ' of this case type, '
				. 'so none are seeded for it. See the log, and re-run `occ maintenance:repair`.'
			);
			return ['created' => 0, 'present' => 0];
		}

		$created = 0;
		$skipped = 0;
		foreach ($records as $record) {
			if (is_array($record) === false) {
				continue;
			}

			$name = trim((string)($record['name'] ?? ''));
			if ($name === '') {
				continue;
			}

			$key = mb_strtolower($name);
			if (isset($present[$key]) === true) {
				$skipped++;
				continue;
			}

			// Marked present BEFORE the write is confirmed, so a seed file that
			// happens to name the same child twice creates it once either way.
			$present[$key] = true;

			$record['caseType'] = $caseTypeId;
			try {
				$objectService->saveObject(
					register: $register,
					schema: $schema,
					object: $record
				);
				$created++;
			} catch (Throwable $e) {
				$output->warning(
					'VTH seed: could not write ' . $collection . ' "' . $name . '": ' . $e->getMessage()
				);
				$this->logger->warning(
					'Dossiq VTH case-type child seed failed',
					[
						'app' => Application::APP_ID,
						'collection' => $collection,
						'name' => $name,
						'caseType' => $caseTypeId,
						'exception' => $e->getMessage(),
					]
				);
			}//end try
		}//end foreach

		return ['created' => $created, 'present' => $skipped];
	}//end seedCollection()

	/**
	 * The names of the children a case type already has, keyed for lookup.
	 *
	 * Compared trimmed and lower-cased, which is the same rule
	 * {@see \OCA\Dossiq\Service\Transitions\StatusTypeLookup::idForName()}
	 * resolves by. Matching more loosely than the reader would seed a second
	 * row the reader can never reach; matching more strictly would seed a
	 * duplicate of a row it already resolves.
	 *
	 * Filtered SERVER-side on the back-reference: reading every row of the
	 * schema and filtering here would miss whatever the first page did not
	 * contain, and would count a same-named child of another case type.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The register slug.
	 * @param string $schema The child schema slug.
	 * @param string $caseTypeId The owning case type's uuid.
	 *
	 * @return array<string, bool>|null The names already present, lower-cased, or
	 *                                   null when the list could not be read.
	 */
	private function existingNames(
		object $objectService,
		string $register,
		string $schema,
		string $caseTypeId,
	): ?array {
		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['caseType' => $caseTypeId, '_limit' => 500]
			);
		} catch (Throwable $e) {
			// An unreadable list is NOT an empty one. Seeding on the strength
			// of a failed read is how a repair step that runs on every upgrade
			// turns one throw into a duplicate set, so refuse the whole
			// collection instead and let the next run do it.
			$this->logger->warning(
				'Dossiq VTH case-type child listing failed, so nothing is seeded for it',
				[
					'app' => Application::APP_ID,
					'schema' => $schema,
					'caseType' => $caseTypeId,
					'exception' => $e->getMessage(),
				]
			);
			return null;
		}

		$names = [];
		foreach ($rows as $row) {
			$name = trim((string)($row['name'] ?? ($row['title'] ?? '')));
			if ($name !== '') {
				$names[mb_strtolower($name)] = true;
			}
		}

		return $names;
	}//end existingNames()
}//end class
