<?php

/**
 * Dossiq Workflow Definition Repository.
 *
 * Every OpenRegister read and write the workflow-definition lifecycle
 * performs. Split out of WorkflowDefinitionService so that service keeps only
 * the lifecycle decisions — publish, deprecate, clone, pin — while the
 * mechanics of reaching the object store live here: resolving the
 * ObjectService bridge, reading register/schema ids out of configuration,
 * coercing ObjectEntity results to plain arrays, and swallowing a store
 * failure into the "null / empty / conservative-true" answers the lifecycle
 * expects.
 *
 * The repository spans the four schemas one definition lifecycle touches —
 * `workflowTemplate`, `case`, `caseType` and `statusType` — because they
 * share exactly one concern: they are the persistence surface of a single
 * workflow definition and the referential integrity around it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Workflow
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
 * @spec openspec/specs/workflow-definition-model/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Workflow;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * OpenRegister persistence for workflowTemplate objects and their references.
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */
class WorkflowDefinitionRepository {

	use SearchesObjects;

	/**
	 * Configuration key holding the workflowTemplate schema id.
	 *
	 * @var string
	 */
	public const SCHEMA_DEFINITION = 'workflow_template_schema';

	/**
	 * Configuration key holding the case schema id.
	 *
	 * @var string
	 */
	public const SCHEMA_CASE = 'case_schema';

	/**
	 * Configuration key holding the caseType schema id.
	 *
	 * @var string
	 */
	public const SCHEMA_CASE_TYPE = 'case_type_schema';

	/**
	 * Configuration key holding the statusType schema id.
	 *
	 * @var string
	 */
	public const SCHEMA_STATUS_TYPE = 'status_type_schema';

	/**
	 * The workflowTemplate properties stored as a JSON string.
	 *
	 * A read answers with them decoded, so an update has to encode them again.
	 *
	 * @var array<int, string>
	 */
	private const JSON_STRING_PROPERTIES = ['steps', 'transitions', 'nodePositions'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings/config + ObjectService bridge.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the ObjectService bridge plus the register and schema ids for
	 * one schema configuration key.
	 *
	 * A null return means OpenRegister is absent or the register/schema pair
	 * is not configured — the two cases every caller collapses into its own
	 * "cannot reach the store" answer.
	 *
	 * @param string $schemaKey One of the SCHEMA_* configuration keys.
	 *
	 * @return array{objectService: object, register: string, schema: string}|null
	 *                                                                             The resolved context, or null when the store is unreachable.
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	private function context(string $schemaKey): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue($schemaKey);
		if ($register === '' || $schema === '') {
			return null;
		}

		return [
			'objectService' => $objectService,
			'register' => $register,
			'schema' => $schema,
		];
	}//end context()

	/**
	 * Whether the store is reachable and the given schema is configured.
	 *
	 * @param string $schemaKey One of the SCHEMA_* configuration keys.
	 *
	 * @return bool True when reads/writes against that schema can be attempted.
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	public function isConfiguredFor(string $schemaKey): bool {
		return ($this->context(schemaKey: $schemaKey) !== null);
	}//end isConfiguredFor()

	/**
	 * Load a single definition by UUID.
	 *
	 * @param string $id The definition UUID.
	 *
	 * @return array<string, mixed>|null The definition, or null when unavailable.
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	public function findById(string $id): ?array {
		return $this->findOne(
			schemaKey: self::SCHEMA_DEFINITION,
			uuid: $id,
			failure: 'Dossiq: failed to load workflow definition'
		);
	}//end findById()

	/**
	 * Read one object of one schema by uuid, or null on any condition that makes
	 * it unreadable.
	 *
	 * The three public readers below are the same eight lines with a different
	 * schema and a different log line, and they were three copies of them until
	 * a fourth was nearly added. One copy, three callers.
	 *
	 * @param string $schemaKey The schema configuration key.
	 * @param string $uuid The object UUID.
	 * @param string $failure The message logged when the read throws.
	 *
	 * @return array<string, mixed>|null The row, or null.
	 */
	private function findOne(string $schemaKey, string $uuid, string $failure): ?array {
		if ($uuid === '') {
			return null;
		}

		$context = $this->context(schemaKey: $schemaKey);
		if ($context === null) {
			return null;
		}

		try {
			$obj = $context['objectService']->find(
				$uuid,
				register: $context['register'],
				schema: $context['schema']
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				$failure,
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->normalize(row: $obj);
	}//end findOne()

	/**
	 * Load a case type row, used to resolve its default route.
	 *
	 * `caseType.workflowDefinition` is the one place a case type's default
	 * route is recorded. Reading it needs the caseType schema, which this
	 * repository already configures for the pin write.
	 *
	 * @param string $caseTypeId The caseType UUID.
	 *
	 * @return array<string, mixed>|null The case type, or null when unavailable.
	 *
	 * @spec openspec/specs/workflow-variants/spec.md
	 */
	public function findCaseType(string $caseTypeId): ?array {
		return $this->findOne(
			schemaKey: self::SCHEMA_CASE_TYPE,
			uuid: $caseTypeId,
			failure: 'Dossiq: failed to load case type for its default route'
		);
	}//end findCaseType()

	/**
	 * Fetch all versions of the definition for a caseType, sorted by version
	 * descending.
	 *
	 * @param string $caseTypeId The caseType UUID.
	 *
	 * @return array<int, array<string, mixed>> The versions, newest first.
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	public function listVersionsForCaseType(string $caseTypeId): array {
		$context = $this->context(schemaKey: self::SCHEMA_DEFINITION);
		if ($context === null) {
			return [];
		}

		try {
			$results = $this->searchObjectsAsArrays(
				objectService: $context['objectService'],
				register: $context['register'],
				schema: $context['schema'],
				filters: ['caseType' => $caseTypeId, '_limit' => 500],
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: failed to list workflow definitions for caseType',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$rows = [];
		foreach ($results as $row) {
			$normalized = $this->normalize(row: $row);
			if ($normalized !== null) {
				$rows[] = $normalized;
			}
		}

		usort(
			$rows,
			static function (array $a, array $b): int {
				return (int)($b['version'] ?? 0) <=> (int)($a['version'] ?? 0);
			},
		);

		return $rows;
	}//end listVersionsForCaseType()

	/**
	 * Resolve the next monotonically increasing version number for a given
	 * caseType. Falls back to 1 when no prior versions exist.
	 *
	 * @param string $caseTypeId The caseType UUID.
	 *
	 * @return int Next version number.
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	public function nextVersionFor(string $caseTypeId): int {
		$max = 0;
		foreach ($this->listVersionsForCaseType(caseTypeId: $caseTypeId) as $row) {
			$candidate = (int)($row['version'] ?? 0);
			if ($candidate > $max) {
				$max = $candidate;
			}
		}

		return ($max + 1);
	}//end nextVersionFor()

	/**
	 * Create or update a workflowTemplate row.
	 *
	 * Passing a uuid updates that row; omitting it creates a new one.
	 *
	 * 🔴 AN UPDATE CARRIES THE WHOLE ROW, NOT THE FIELDS THAT CHANGED.
	 * OpenRegister validates the payload it is handed as the complete object
	 * and stores exactly that, so a three-key payload is a three-key object.
	 * `workflowTemplate` requires `title` and `caseType`, so every partial
	 * update was refused with "The required properties (title, caseType) are
	 * missing" and this method turned that throw into a null.
	 *
	 * That null is what `publish()` reported. Measured on a clean rig on
	 * 2026-09-04: three VTH templates created as drafts, all three refused at
	 * publish, all three left at `lifecycleStatus=draft, isActive=false` with
	 * "publish returned null" logged at ERROR on every install. Merging here
	 * rather than in `publish()` covers `deprecate()` and every later caller
	 * for the same reason, and a failed read refuses the write instead of
	 * replacing the row with the fragment.
	 *
	 * @param array<string, mixed> $payload The properties to write.
	 * @param string|null $uuid The row to update, or null to create.
	 *
	 * @return array<string, mixed>|null The written row, or null on failure.
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	public function save(array $payload, ?string $uuid = null): ?array {
		$context = $this->context(schemaKey: self::SCHEMA_DEFINITION);
		if ($context === null) {
			return null;
		}

		try {
			if ($uuid === null) {
				return $this->normalize(
					row: $context['objectService']->saveObject(
						object: $payload,
						register: $context['register'],
						schema: $context['schema'],
					)
				);
			}

			$merged = $this->mergeOntoStored(
				context: $context,
				uuid: $uuid,
				payload: $payload,
				jsonProperties: self::JSON_STRING_PROPERTIES,
			);
			if ($merged === null) {
				return null;
			}

			$written = $context['objectService']->saveObject(
				object: $merged,
				register: $context['register'],
				schema: $context['schema'],
				uuid: $uuid,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: failed to save workflow definition',
				['app' => Application::APP_ID, 'uuid' => $uuid, 'exception' => $e->getMessage()]
			);
			return null;
		}//end try

		return $this->normalize(row: $written);
	}//end save()

	/**
	 * Lay the changed properties over the row as it is stored.
	 *
	 * Returns null when the row cannot be read, because writing the fragment
	 * on its own would replace a whole workflow with three keys.
	 *
	 * The metadata keys are dropped: `@self` is OpenRegister's own envelope and
	 * `id` is the uuid, which travels as the `uuid` argument.
	 *
	 * 🔑 A READ HANDS BACK `steps` AND `transitions` DECODED. They are stored as
	 * JSON strings, and OpenRegister answers a read with the decoded arrays, so
	 * merging the read straight back is refused with "Property 'steps' should
	 * be type 'string or null' but is 'array'". `$jsonProperties` names the
	 * properties to encode again on the way out. The list is per schema, and
	 * `caseType` has none: its array properties really are arrays.
	 *
	 * @param array{objectService: object, register: string, schema: string} $context The store context.
	 * @param string $uuid The row being updated.
	 * @param array<string, mixed> $payload The properties to write.
	 * @param array<int, string> $jsonProperties Properties the schema stores as a JSON string.
	 *
	 * @return array<string, mixed>|null The full object to store, or null when the row is unreadable.
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	private function mergeOntoStored(array $context, string $uuid, array $payload, array $jsonProperties = []): ?array {
		try {
			$current = $this->normalize(
				row: $context['objectService']->find(
					$uuid,
					register: $context['register'],
					schema: $context['schema']
				)
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: refusing to update a row that cannot be read',
				['app' => Application::APP_ID, 'uuid' => $uuid, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($current === null) {
			$this->logger->error(
				'Dossiq: refusing to update a row that cannot be read',
				['app' => Application::APP_ID, 'uuid' => $uuid]
			);
			return null;
		}

		unset($current['@self'], $current['id']);

		$merged = array_merge($current, $payload);
		foreach ($jsonProperties as $property) {
			if (is_array(($merged[$property] ?? null)) === true) {
				$merged[$property] = json_encode($merged[$property]);
			}
		}

		return $merged;
	}//end mergeOntoStored()

	/**
	 * Pin `caseType.workflowDefinition` to a definition id.
	 *
	 * Pinning failure is non-fatal, because the consumer entrypoint falls back
	 * to the published and active row, so the failure is logged and swallowed.
	 *
	 * The pin travels on the whole case type for the reason `save()` gives: a
	 * one-key payload is a one-key object, and `caseType` requires a title.
	 *
	 * @param string $caseTypeId The caseType UUID.
	 * @param string $definitionId The definition UUID to pin.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	public function pinWorkflowDefinition(string $caseTypeId, string $definitionId): void {
		$context = $this->context(schemaKey: self::SCHEMA_CASE_TYPE);
		if ($context === null) {
			return;
		}

		$merged = $this->mergeOntoStored(
			context: $context,
			uuid: $caseTypeId,
			payload: ['workflowDefinition' => $definitionId]
		);
		if ($merged === null) {
			return;
		}

		try {
			$context['objectService']->saveObject(
				object: $merged,
				register: $context['register'],
				schema: $context['schema'],
				uuid: $caseTypeId,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: failed to pin caseType.workflowDefinition',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
		}
	}//end pinWorkflowDefinition()

	/**
	 * Load a case row, used to resolve the definition pinned to a case.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed>|null The case, or null when unavailable.
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	public function findCase(string $caseId): ?array {
		return $this->findOne(
			schemaKey: self::SCHEMA_CASE,
			uuid: $caseId,
			failure: 'Dossiq: failed to load case for definition lookup'
		);
	}//end findCase()

	/**
	 * Fetch every statusType id belonging to a given caseType.
	 *
	 * @param string $caseTypeId The caseType UUID.
	 *
	 * @return array<int, string> The statusType UUIDs.
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	public function listStatusTypeIds(string $caseTypeId): array {
		$context = $this->context(schemaKey: self::SCHEMA_STATUS_TYPE);
		if ($context === null) {
			return [];
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $context['objectService'],
				register: $context['register'],
				schema: $context['schema'],
				filters: ['caseType' => $caseTypeId, '_limit' => 500],
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: failed to list statusTypes for caseType',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$ids = [];
		foreach ($rows as $row) {
			$normalized = $this->normalize(row: $row);
			if ($normalized === null) {
				continue;
			}

			$id = (string)($normalized['id'] ?? '');
			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end listStatusTypeIds()

	/**
	 * Whether the caseType has any cases pinned to it.
	 *
	 * Conservative — returns true when the count cannot be established, so a
	 * deprecation that would strand open cases is refused rather than risked.
	 *
	 * @param string $caseTypeId The caseType UUID.
	 *
	 * @return bool True when cases exist, or when the answer is unknown.
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	public function hasCasesFor(string $caseTypeId): bool {
		$context = $this->context(schemaKey: self::SCHEMA_CASE);
		if ($context === null) {
			return true;
		}

		try {
			$results = $this->searchObjectsAsArrays(
				objectService: $context['objectService'],
				register: $context['register'],
				schema: $context['schema'],
				filters: ['caseType' => $caseTypeId, '_limit' => 1],
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: failed to count open cases for caseType',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			return true;
		}

		return (count($results) > 0);
	}//end hasCasesFor()

	/**
	 * Coerce an OpenRegister result row to an associative array.
	 *
	 * @param mixed $row Result row from ObjectService.
	 *
	 * @return array<string, mixed>|null The row as an array, or null when uncoercible.
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	private function normalize(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$serialized = $row->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return null;
	}//end normalize()
}//end class
