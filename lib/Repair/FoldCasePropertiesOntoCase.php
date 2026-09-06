<?php

/**
 * Dossiq repair step: fold caseProperty rows onto the case that owns them.
 *
 * A case type declares extra properties its cases must answer, via
 * `propertyDefinition` records. Those answers used to be stored one object per
 * answer in the `caseProperty` schema, keyed by `case` + `propertyDefinition`.
 * They now live in a `properties` array on the case itself, so a case reads in
 * one request instead of a query per case, and its answers save in the same
 * write as the case rather than in a second write that can be left behind.
 *
 * This backfills that array for cases created before the change.
 *
 * NON-DESTRUCTIVE ON PURPOSE. The `caseProperty` rows are left exactly where
 * they are. If this projection is wrong, the source is still there to redo it
 * from; deleting the rows in the same step that writes the copy would leave no
 * way back. Removing them is a separate decision for a later release, once the
 * array has been read in anger.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copy caseProperty answers into the owning case's `properties` array.
 *
 * @spec exclude one-off data migration; it projects existing rows into the new shape and has no behaviour of its own to specify
 */
class FoldCasePropertiesOntoCase implements IRepairStep {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister.
	 * @param IAppConfig      $appConfig       App configuration.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string The step name.
	 *
	 * @spec exclude one-off data migration; it projects existing rows into the new shape and has no behaviour of its own to specify
	 */
	public function getName(): string {
		return 'Fold caseProperty answers onto the case that owns them';
	}//end getName()

	/**
	 * Run the backfill.
	 *
	 * Non-fatal by construction: an upgrade must not fail because a projection
	 * could not complete, and the source rows are untouched so a later run can
	 * repeat the work.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec exclude one-off data migration; it projects existing rows into the new shape and has no behaviour of its own to specify
	 */
	public function run(IOutput $output): void {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->info('OpenRegister unavailable, skipping case-properties backfill.');
			return;
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$caseSchema = $this->appConfig->getValueString(Application::APP_ID, 'case_schema', '');
		$valueSchema = $this->appConfig->getValueString(Application::APP_ID, 'case_property_schema', '');
		$defSchema = $this->appConfig->getValueString(Application::APP_ID, 'property_definition_schema', '');
		if ($register === '' || $caseSchema === '' || $valueSchema === '' || $defSchema === '') {
			$output->info('Case-property schemas not configured, skipping backfill.');
			return;
		}

		// A BLOCK closure, not `fn () => ...`: an arrow function implicitly
		// returns its expression, and returning the result of a void method is
		// a fatal.
		$objectService->runAsSystem(
			function () use ($objectService, $register, $caseSchema, $valueSchema, $defSchema, $output): void {
				$this->backfill(
					objectService: $objectService,
					register: $register,
					caseSchema: $caseSchema,
					valueSchema: $valueSchema,
					defSchema: $defSchema,
					output: $output,
				);
			}
		);
	}//end run()

	/**
	 * Read the answers and their definitions, then write one array per case.
	 *
	 * @param object  $objectService OpenRegister's ObjectService.
	 * @param string  $register      The register id.
	 * @param string  $caseSchema    The case schema id.
	 * @param string  $valueSchema   The caseProperty schema id.
	 * @param string  $defSchema     The propertyDefinition schema id.
	 * @param IOutput $output        Progress reporting.
	 *
	 * @return void
	 */
	private function backfill(
		object $objectService,
		string $register,
		string $caseSchema,
		string $valueSchema,
		string $defSchema,
		IOutput $output,
	): void {
		$values = $this->readAll(objectService: $objectService, register: $register, schema: $valueSchema);
		if ($values === []) {
			$output->info('No caseProperty rows to fold.');
			return;
		}

		$names = $this->definitionNames(objectService: $objectService, register: $register, schema: $defSchema);
		$byCase = $this->groupByCase(values: $values, names: $names);

		$tally = ['written' => 0, 'skipped' => 0, 'failed' => 0];
		foreach ($byCase as $caseId => $entries) {
			$result = $this->foldOne(
				objectService: $objectService,
				caseSchema: $caseSchema,
				caseId: (string) $caseId,
				entries: $entries,
			);
			$tally[$result]++;
		}

		$output->info(
			sprintf(
				'Case properties folded: %d written, %d skipped, %d failed.',
				$tally['written'],
				$tally['skipped'],
				$tally['failed']
			)
		);
	}//end backfill()

	/**
	 * Read the definition names once, keyed by id.
	 *
	 * Reading them per answer would be one request for a value that repeats.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register      The register id.
	 * @param string $schema        The propertyDefinition schema id.
	 *
	 * @return array<string, string> Definition id to name.
	 */
	private function definitionNames(object $objectService, string $register, string $schema): array {
		$names = [];
		foreach ($this->readAll(objectService: $objectService, register: $register, schema: $schema) as $def) {
			$id = (string) ($def['id'] ?? $def['uuid'] ?? '');
			if ($id !== '') {
				$names[$id] = (string) ($def['name'] ?? '');
			}
		}

		return $names;
	}//end definitionNames()

	/**
	 * Group the answer rows into one entry list per case.
	 *
	 * @param array<int, array<string, mixed>> $values The caseProperty rows.
	 * @param array<string, string>            $names  Definition id to name.
	 *
	 * @return array<string, array<int, array<string, string>>> Entries per case.
	 */
	private function groupByCase(array $values, array $names): array {
		$byCase = [];
		foreach ($values as $row) {
			$caseId = (string) ($row['case'] ?? '');
			$defId = (string) ($row['propertyDefinition'] ?? '');
			if ($caseId === '' || $defId === '') {
				continue;
			}

			$byCase[$caseId][] = [
				'propertyDefinition' => $defId,
				'name' => ($names[$defId] ?? ''),
				'value' => (string) ($row['value'] ?? ''),
			];
		}

		return $byCase;
	}//end groupByCase()

	/**
	 * Write one case's properties array.
	 *
	 * @param object                            $objectService OpenRegister's ObjectService.
	 * @param string                            $caseSchema    The case schema id.
	 * @param string                            $caseId        The case to write.
	 * @param array<int, array<string, string>> $entries       The entries to store.
	 *
	 * @return string One of `written`, `skipped` or `failed`.
	 */
	private function foldOne(object $objectService, string $caseSchema, string $caseId, array $entries): string {
		try {
			$case = $objectService->find($caseId, ['_rbac' => false, '_multitenancy' => false]);
			if (is_object($case) === true && method_exists($case, 'jsonSerialize') === true) {
				$case = $case->jsonSerialize();
			}

			if (is_array($case) === false) {
				return 'skipped';
			}

			// An existing array is the newer truth: it was written either by the
			// form or by an earlier run. Overwriting it with a projection of the
			// old rows would undo a real edit.
			if (empty($case['properties']) === false) {
				return 'skipped';
			}

			$case['properties'] = $entries;
			$objectService->saveObject($caseSchema, $case, ['_rbac' => false, '_multitenancy' => false]);
			return 'written';
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not fold case properties onto a case',
				['case' => $caseId, 'exception' => $e]
			);
			return 'failed';
		}//end try
	}//end foldOne()

	/**
	 * Read every object of one schema as plain arrays.
	 *
	 * `occ` has no session, so every ObjectService call runs as Anonymous and
	 * needs the access flags explicitly.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register      The register id.
	 * @param string $schema        The schema id.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function readAll(object $objectService, string $register, string $schema): array {
		$rows = $objectService->findAll(
			[
				'filters' => ['register' => $register, 'schema' => $schema],
				'_rbac' => false,
				'_multitenancy' => false,
			]
		);
		if (is_array($rows) === false) {
			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end readAll()
}//end class
