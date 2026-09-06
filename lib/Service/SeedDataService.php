<?php

/**
 * Dossiq Seed Data Service
 *
 * Service for seeding pre-defined case types, status types, role types,
 * and workflow templates into OpenRegister.
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
 * @spec openspec/specs/dossiq-app-scaffold/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Besluitvorming\WorkflowReferenceResolver;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Support\SeedSummary;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for seeding bezwaar/beroep case types and related configuration.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — needs OpenRegister service access
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class SeedDataService {
	use SearchesObjects;

	/**
	 * The tally of the seed call in progress.
	 *
	 * Held on the instance because {@see createObject()} is where a refusal
	 * becomes visible, several frames below the caller that reports it.
	 *
	 * @var SeedSummary|null
	 */
	private ?SeedSummary $summary = null;

	/**
	 * Lazily built workflow reference resolver.
	 *
	 * @var WorkflowReferenceResolver|null
	 */
	private ?WorkflowReferenceResolver $workflowResolver = null;

	/**
	 * Constructor for the SeedDataService.
	 *
	 * @param IAppConfig $appConfig The app configuration service
	 * @param ContainerInterface $container The DI container
	 * @param LoggerInterface $logger The logger interface
	 *
	 * @return void
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Seed the bezwaar and beroep case types with all related objects.
	 *
	 * This method is idempotent — it checks for existing objects by identifier
	 * before creating new ones.
	 *
	 * @return array Result summary with counts of created and skipped objects
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function seedBezwaarBeroepData(): array {
		$seedPath = __DIR__ . '/../Settings/bezwaar_seed_data.json';
		if (file_exists($seedPath) === false) {
			$this->logger->error('Dossiq: Seed data file not found at ' . $seedPath);
			return ['success' => false, 'message' => 'Seed data file not found'];
		}

		$seedContent = file_get_contents($seedPath);
		$seedData = json_decode($seedContent, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->logger->error('Dossiq: Invalid JSON in seed data file');
			return ['success' => false, 'message' => 'Invalid JSON in seed data file'];
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return ['success' => false, 'message' => 'ObjectService not available'];
		}

		$registerId = $this->getConfigValue(key: 'register');
		$caseTypeSchema = $this->getConfigValue(key: 'case_type_schema');
		$statusTypeSchema = $this->getConfigValue(key: 'status_type_schema');
		$roleTypeSchema = $this->getConfigValue(key: 'role_type_schema');
		$workflowSchema = $this->getConfigValue(key: 'workflow_template_schema');

		if ($registerId === '' || $caseTypeSchema === '') {
			$this->logger->warning('Dossiq: Register or case type schema not configured, skipping seed');
			return ['success' => false, 'message' => 'Register or schemas not configured'];
		}

		$this->summary = new SeedSummary();

		// A REPAIR STEP HAS NO SESSION, SO OPENREGISTER RESOLVES THE ACTOR AS
		// 'Anonymous' AND REFUSES EVERY WRITE. `SeedBezwaarBeroepData` calls
		// this from `<install>` and `<post-migration>`; the setup wizard and
		// the occ command call it from a real session, where the elevation is
		// a no-op because the inputs are the app's own shipped seed file
		// either way. Same mechanism its sibling seeds already use.
		$this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: function () use (
				$seedData,
				$objectService,
				$registerId,
				$caseTypeSchema,
				$statusTypeSchema,
				$roleTypeSchema,
				$workflowSchema
			): void {
				foreach (($seedData['caseTypes'] ?? []) as $caseTypeData) {
					$result = $this->seedCaseType(
						objectService: $objectService,
						caseTypeData: $caseTypeData,
						registerId: $registerId,
						caseTypeSchema: $caseTypeSchema,
						statusTypeSchema: $statusTypeSchema,
						roleTypeSchema: $roleTypeSchema,
						workflowSchema: $workflowSchema,
					);

					$this->summary->addCaseTypeResult(result: $result);
				}
			}
		);

		$summary = $this->summary->toArray();

		if ($this->summary->isClean() === false) {
			$this->logger->error('Dossiq: Seed data refused writes', $summary);

			return $summary;
		}

		$this->logger->info('Dossiq: Seed data complete', $summary);

		return $summary;
	}//end seedBezwaarBeroepData()

	/**
	 * Seed a single case type with its status types, role types, and workflow.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param array $caseTypeData The case type seed data
	 * @param string $registerId The register UUID
	 * @param string $caseTypeSchema The case type schema UUID
	 * @param string $statusTypeSchema The status type schema UUID
	 * @param string $roleTypeSchema The role type schema UUID
	 * @param string $workflowSchema The workflow template schema UUID
	 *
	 * @return array Counts of created and skipped objects
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) — all schema IDs are needed
	 */
	private function seedCaseType(
		object $objectService,
		array $caseTypeData,
		string $registerId,
		string $caseTypeSchema,
		string $statusTypeSchema,
		string $roleTypeSchema,
		string $workflowSchema,
	): array {
		$counts = [
			'caseTypes' => 0,
			'statusTypes' => 0,
			'roleTypes' => 0,
			'workflows' => 0,
			'skipped' => 0,
		];

		$identifier = ($caseTypeData['identifier'] ?? '');

		// Check if case type already exists by identifier.
		$alreadySeeded = $this->caseTypeAlreadySeeded(
			objectService: $objectService,
			registerId: $registerId,
			caseTypeSchema: $caseTypeSchema,
			identifier: $identifier,
		);

		if ($alreadySeeded === true) {
			$counts['skipped']++;
			return $counts;
		}

		// Extract nested data before creating the case type.
		$statusTypesData = ($caseTypeData['statusTypes'] ?? []);
		$roleTypesData = ($caseTypeData['roleTypes'] ?? []);
		$workflowData = ($caseTypeData['workflowTemplate'] ?? null);

		unset(
			$caseTypeData['statusTypes'],
			$caseTypeData['roleTypes'],
			$caseTypeData['workflowTemplate']
		);

		// Create the case type and resolve the id its children must point at.
		$caseTypeId = $this->createCaseType(
			objectService: $objectService,
			caseTypeData: $caseTypeData,
			registerId: $registerId,
			caseTypeSchema: $caseTypeSchema,
			identifier: $identifier,
		);

		if ($caseTypeId === null) {
			return $counts;
		}

		$counts['caseTypes']++;

		// Create status types and build a name-to-ID map.
		$statuses = $this->seedChildTypes(
			objectService: $objectService,
			childrenData: $statusTypesData,
			registerId: $registerId,
			schemaId: $statusTypeSchema,
			caseTypeId: $caseTypeId,
		);

		$statusNameToId = $statuses['map'];
		$counts['statusTypes'] += $statuses['created'];

		// Create role types and build a name-to-ID map.
		$roleNameToId = [];
		if ($roleTypeSchema !== '') {
			$roles = $this->seedChildTypes(
				objectService: $objectService,
				childrenData: $roleTypesData,
				registerId: $registerId,
				schemaId: $roleTypeSchema,
				caseTypeId: $caseTypeId,
			);

			$roleNameToId = $roles['map'];
			$counts['roleTypes'] += $roles['created'];
		}

		// Create workflow template with resolved status/role references.
		$counts['workflows'] += $this->seedWorkflowTemplate(
			objectService: $objectService,
			workflowData: $workflowData,
			registerId: $registerId,
			workflowSchema: $workflowSchema,
			statusNameMap: $statusNameToId,
			roleNameMap: $roleNameToId,
			caseTypeId: $caseTypeId,
		);

		return $counts;
	}//end seedCaseType()

	/**
	 * Determine whether a case type with this identifier was already seeded.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param string $registerId The register UUID
	 * @param string $caseTypeSchema The case type schema UUID
	 * @param mixed $identifier The case type identifier from the seed data
	 *
	 * @return bool True when a matching case type already exists
	 */
	private function caseTypeAlreadySeeded(
		object $objectService,
		string $registerId,
		string $caseTypeSchema,
		mixed $identifier,
	): bool {
		$existing = $this->findByFilter(
			objectService: $objectService,
			registerId: $registerId,
			schemaId: $caseTypeSchema,
			filters: ['identifier' => $identifier],
		);

		if ($existing === null) {
			return false;
		}

		$this->logger->info(
			'Dossiq: Case type already exists, skipping seed',
			['identifier' => $identifier]
		);

		return true;
	}//end caseTypeAlreadySeeded()

	/**
	 * Create the case type object and resolve the id its children must point at.
	 *
	 * Prefers the deterministic id from the seed data: OpenRegister's
	 * saveObject() return is not always hydrated with the new id (getId() and
	 * getUuid() can both be empty), which left child status/role types with an
	 * empty caseType and failed their uuid-format validation. The seed assigns
	 * fixed UUIDs to the case types, so use those.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param array $caseTypeData The case type seed data, nested children removed
	 * @param string $registerId The register UUID
	 * @param string $caseTypeSchema The case type schema UUID
	 * @param mixed $identifier The case type identifier from the seed data
	 *
	 * @return string|null The case type UUID, or null when creation failed
	 */
	private function createCaseType(
		object $objectService,
		array $caseTypeData,
		string $registerId,
		string $caseTypeSchema,
		mixed $identifier,
	): ?string {
		$caseType = $this->createObject(
			objectService: $objectService,
			registerId: $registerId,
			schemaId: $caseTypeSchema,
			data: $caseTypeData,
		);

		if ($caseType === null) {
			$this->logger->error(
				'Dossiq: Failed to create case type',
				['identifier' => $identifier]
			);
			return null;
		}

		$caseTypeId = (string)($caseTypeData['id'] ?? $caseTypeData['uuid'] ?? '');
		if ($caseTypeId === '') {
			$caseTypeId = $this->getObjectId(object: $caseType);
		}

		$this->logger->info(
			'Dossiq: Created case type',
			['identifier' => $identifier, 'id' => $caseTypeId]
		);

		return $caseTypeId;
	}//end createCaseType()

	/**
	 * Create the named children of a case type (status types, role types) and
	 * map their names to their ids.
	 *
	 * A fixed UUID is assigned up front so the id is known regardless of
	 * saveObject()'s return shape — the workflow step/transition references
	 * below are resolved from this map.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param array $childrenData The child seed data
	 * @param string $registerId The register UUID
	 * @param string $schemaId The child schema UUID
	 * @param string $caseTypeId The owning case type UUID
	 *
	 * @return array{map: array<string, string>, created: int} The name-to-id map and the created count
	 */
	private function seedChildTypes(
		object $objectService,
		array $childrenData,
		string $registerId,
		string $schemaId,
		string $caseTypeId,
	): array {
		$map = [];
		$created = 0;

		foreach ($childrenData as $childData) {
			$childData['caseType'] = $caseTypeId;
			$childId = (string)($childData['id'] ?? $this->generateUUID());
			$childData['id'] = $childId;
			$childObj = $this->createObject(
				objectService: $objectService,
				registerId: $registerId,
				schemaId: $schemaId,
				data: $childData,
			);

			if ($childObj !== null) {
				$map[$childData['name']] = $childId;
				$created++;
			}
		}

		return ['map' => $map, 'created' => $created];
	}//end seedChildTypes()

	/**
	 * Create the workflow template of a case type with resolved status/role references.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param array|null $workflowData The raw workflow template seed data, or null when absent
	 * @param string $registerId The register UUID
	 * @param string $workflowSchema The workflow template schema UUID (empty disables seeding)
	 * @param array $statusNameMap Status name to UUID mapping
	 * @param array $roleNameMap Role name to UUID mapping
	 * @param string $caseTypeId The owning case type UUID
	 *
	 * @return int The number of workflow templates created (0 or 1)
	 */
	private function seedWorkflowTemplate(
		object $objectService,
		?array $workflowData,
		string $registerId,
		string $workflowSchema,
		array $statusNameMap,
		array $roleNameMap,
		string $caseTypeId,
	): int {
		if ($workflowData === null || $workflowSchema === '') {
			return 0;
		}

		$resolvedWorkflow = $this->workflowResolver()->resolveWorkflowReferences(
			workflowData: $workflowData,
			statusNameMap: $statusNameMap,
			roleNameMap: $roleNameMap,
			caseTypeId: $caseTypeId,
		);

		$workflowObj = $this->createObject(
			objectService: $objectService,
			registerId: $registerId,
			schemaId: $workflowSchema,
			data: $resolvedWorkflow,
		);

		return (int)($workflowObj !== null);
	}//end seedWorkflowTemplate()

	/**
	 * Create an object in OpenRegister via ObjectService.
	 *
	 * @param object $objectService The ObjectService instance
	 * @param string $registerId The register UUID
	 * @param string $schemaId The schema UUID
	 * @param array $data The object data
	 *
	 * @return object|null The created object or null on failure
	 */
	private function createObject(
		object $objectService,
		string $registerId,
		string $schemaId,
		array $data,
	): ?object {
		try {
			return $objectService->saveObject(
				register: $registerId,
				schema: $schemaId,
				object: $data,
			);
		} catch (\Throwable $e) {
			// \Throwable, not \Exception: an OpenRegister refusal can surface
			// as a PHP `Error` (a TypeError on a shifted signature, say), which
			// `catch (\Exception)` does not catch — the seed would then abort
			// the whole install instead of counting one refused row.
			$this->summary?->recordFailure();
			$this->logger->error(
				'Dossiq: Failed to create seed object',
				[
					'schema' => $schemaId,
					'exception' => $e->getMessage(),
				]
			);
			return null;
		}
	}//end createObject()

	/**
	 * Find an existing object by filter criteria.
	 *
	 * @param object $objectService The ObjectService instance
	 * @param string $registerId The register UUID
	 * @param string $schemaId The schema UUID
	 * @param array $filters Filter criteria
	 *
	 * @return object|null The found object or null
	 */
	private function findByFilter(
		object $objectService,
		string $registerId,
		string $schemaId,
		array $filters,
	): ?object {
		try {
			$results = $objectService->findAll(
				[
					'filters' => (['register' => $registerId, 'schema' => $schemaId] + $filters),
					'limit' => 1,
				],
			);

			if (is_array($results) === true && count($results) > 0) {
				return $results[0];
			}

			// Handle paginated result format.
			if (is_array($results) === true
				&& isset($results['results']) === true
				&& count($results['results']) > 0
			) {
				return $results['results'][0];
			}

			return null;
		} catch (\Exception $e) {
			$this->logger->debug(
				'Dossiq: Could not search for existing object',
				['exception' => $e->getMessage()]
			);
			return null;
		}//end try
	}//end findByFilter()

	/**
	 * Get the ObjectService from OpenRegister via the DI container.
	 *
	 * @return object|null The ObjectService or null if unavailable
	 */
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Exception $e) {
			$this->logger->error(
				'Dossiq: Could not access ObjectService',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end getObjectService()

	/**
	 * Get a configuration value from the app config.
	 *
	 * @param string $key The configuration key
	 *
	 * @return string The configuration value
	 */
	private function getConfigValue(string $key): string {
		return $this->appConfig->getValueString(Application::APP_ID, $key, '');
	}//end getConfigValue()

	/**
	 * Extract the id/uuid from a saved OpenRegister object.
	 *
	 * @param object $object The saved object.
	 *
	 * @return string The uuid (preferred) or numeric id, or '' when neither resolves.
	 */
	private function getObjectId(object $object): string {
		// Prefer the UUID: seeded cross-references (statusType.caseType,
		// workflow step ids) are uuid-format properties, and OpenRegister's
		// saved entity exposes the UUID via getUuid() while getId() can be the
		// (empty/internal) numeric id — checking getId() first yielded '' and
		// broke every child reference.
		if (method_exists($object, 'getUuid') === true) {
			$uuid = (string)$object->getUuid();
			if ($uuid !== '') {
				return $uuid;
			}
		}

		if (method_exists($object, 'getId') === true) {
			return (string)$object->getId();
		}

		return '';
	}//end getObjectId()

	/**
	 * Generate a UUID v4 string.
	 *
	 * @return string A new UUID
	 */
	private function generateUUID(): string {
		$data = random_bytes(16);
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40);
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80);

		return vsprintf(
			'%s%s-%s-%s-%s-%s%s%s',
			str_split(bin2hex($data), 4)
		);
	}//end generateUUID()
	/**
	 * The one resolver that maps workflow name references onto created ids.
	 *
	 * Built here rather than injected so the constructor signature stays put:
	 * it is stateless, and `TemplateBundleSeeder` already takes the same class
	 * from the container. Two copies of this mapping is how a seeded workflow
	 * ends up carrying role references the engine cannot address.
	 *
	 * @return WorkflowReferenceResolver The shared resolver.
	 */
	private function workflowResolver(): WorkflowReferenceResolver {
		$this->workflowResolver ??= new WorkflowReferenceResolver();

		return $this->workflowResolver;
	}//end workflowResolver()
}//end class
