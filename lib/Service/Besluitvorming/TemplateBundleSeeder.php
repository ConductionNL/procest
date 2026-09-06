<?php

/**
 * Dossiq TemplateBundleSeeder.
 *
 * The OpenRegister write path behind besluitvorming template activation:
 * given a decoded bundle it creates the caseType, its five child collections
 * (statusTypes, roleTypes, propertyDefinitions, documentTypes, resultTypes),
 * and the workflowTemplate — tallying what it
 * created as it goes. It also owns the idempotency probe that decides whether
 * any of that should happen at all.
 *
 * Split out of BesluitvormingTemplateService so that service keeps only the
 * activation orchestration (which slug, which bundle file, which schemas).
 * Every create is best-effort: a failed child does not abort the bundle, it
 * simply is not counted, so a partially-configured register still yields a
 * usable caseType.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Besluitvorming
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
 * @spec openspec/specs/besluitvorming-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Besluitvorming;

use OCA\Dossiq\Service\Support\JsonEncodedStringProperties;
use Psr\Log\LoggerInterface;

/**
 * Writes a decoded besluitvorming bundle into OpenRegister.
 *
 * @spec openspec/specs/besluitvorming-workflow/spec.md
 */
class TemplateBundleSeeder {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger.
	 * @param WorkflowReferenceResolver $workflowResolver Name→id rewriter for the workflow payload.
	 * @param JsonEncodedStringProperties $jsonProperties Restores the declared string shape of JSON-encoded properties.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly WorkflowReferenceResolver $workflowResolver,
		private readonly JsonEncodedStringProperties $jsonProperties,
	) {
	}//end __construct()

	/**
	 * Seed all records of a bundle once idempotency has been cleared.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The register slug.
	 * @param array<string, string> $schemas Map of schema-key => schema id.
	 * @param string $slug The template slug.
	 * @param array<string, mixed> $caseTypeData The caseType payload (with nested arrays).
	 *
	 * @return array<string, mixed> Creation counts.
	 *
	 * @spec openspec/specs/besluitvorming-workflow/spec.md
	 */
	public function seedBundle(
		object $objectService,
		string $register,
		array $schemas,
		string $slug,
		array $caseTypeData,
	): array {
		$counts = [
			'success' => true,
			'slug' => $slug,
			'caseType' => 0,
			'statusTypes' => 0,
			'roleTypes' => 0,
			'propertyDefinitions' => 0,
			'documentTypes' => 0,
			'resultTypes' => 0,
			'workflowTemplate' => 0,
		];

		$split = $this->splitBundle(caseTypeData: $caseTypeData);
		$caseTypeData = $split['caseTypeData'];
		$childData = $split['childData'];
		$workflowData = $split['workflowData'];
		$initialStatusName = $split['initialStatusName'];

		$caseType = $this->createObject(
			objectService: $objectService,
			register: $register,
			schema: $schemas['caseType'],
			data: $caseTypeData,
		);
		if ($caseType === null) {
			return ['success' => false, 'slug' => $slug, 'message' => 'caseType_create_failed'];
		}

		$caseTypeId = $this->getObjectId(object: $caseType);
		$counts['caseType']++;

		$nameMaps = $this->seedCaseTypeChildren(
			objectService: $objectService,
			register: $register,
			schemas: $schemas,
			childData: $childData,
			caseTypeId: $caseTypeId,
			counts: $counts,
		);

		$this->linkInitialStatus(
			objectService: $objectService,
			register: $register,
			schema: $schemas['caseType'],
			caseTypeId: $caseTypeId,
			caseTypeData: $caseTypeData,
			initialStatusName: $initialStatusName,
			statusNameMap: $nameMaps['statusTypes'],
			slug: $slug,
		);

		$this->seedWorkflowTemplate(
			objectService: $objectService,
			register: $register,
			schemas: $schemas,
			workflowData: $workflowData,
			nameMaps: $nameMaps,
			caseTypeId: $caseTypeId,
			counts: $counts,
		);

		$this->logger->info('Dossiq: besluitvorming template activated', $counts);

		return $counts;
	}//end seedBundle()

	/**
	 * Find an existing object by its identifier field.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The register slug.
	 * @param string $schema The schema id.
	 * @param string $identifier The identifier value.
	 *
	 * @return array<string, mixed>|null The found object, or null.
	 *
	 * @spec openspec/specs/besluitvorming-workflow/spec.md
	 */
	public function findByIdentifier(
		object $objectService,
		string $register,
		string $schema,
		string $identifier,
	): ?array {
		try {
			$results = $objectService->findAll(
				[
					'filters' => ['register' => $register, 'schema' => $schema, 'identifier' => $identifier],
					'limit' => 1,
				],
			);

			if (is_array($results) === true && isset($results['results']) === true) {
				$results = $results['results'];
			}

			if (is_array($results) === true && count($results) > 0) {
				return $this->toArray(value: $results[0]);
			}

			return null;
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Dossiq: besluitvorming idempotency lookup failed',
				['exception' => $e->getMessage()],
			);
			return null;
		}//end try
	}//end findByIdentifier()

	/**
	 * Seed the five child collections of a caseType.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The register slug.
	 * @param array<string, string> $schemas Map of schema-key => schema id.
	 * @param array<string, array<int, mixed>> $childData Child payloads keyed by collection.
	 * @param string $caseTypeId The parent caseType id.
	 * @param array<string, mixed> $counts Counts accumulator (by reference).
	 *
	 * @return array<string, array<string, string>> Name => id maps per collection.
	 *
	 * @spec openspec/specs/besluitvorming-workflow/spec.md
	 */
	private function seedCaseTypeChildren(
		object $objectService,
		string $register,
		array $schemas,
		array $childData,
		string $caseTypeId,
		array &$counts,
	): array {
		$nameMaps = [];
		$collections = [
			'statusTypes' => 'statusType',
			'roleTypes' => 'roleType',
			'propertyDefinitions' => 'propertyDefinition',
			'documentTypes' => 'documentType',
			'resultTypes' => 'resultType',
		];

		foreach ($collections as $countKey => $schemaKey) {
			$nameMaps[$countKey] = $this->seedChildren(
				objectService: $objectService,
				register: $register,
				schema: $schemas[$schemaKey],
				records: $childData[$countKey],
				caseTypeId: $caseTypeId,
				counts: $counts,
				countKey: $countKey,
			);
		}//end foreach

		return $nameMaps;
	}//end seedCaseTypeChildren()

	/**
	 * Split a bundle's caseType payload from the collections seeded after it.
	 *
	 * The child collections and the workflow are nested inside the caseType in
	 * the bundle file, but are separate OpenRegister objects that can only be
	 * created once the caseType they link to exists. `initialStatusName` is
	 * pulled out for the same reason in reverse: the caseType names its
	 * initial status, but the statusType it names does not exist yet, so the
	 * link is written back after the children are seeded.
	 *
	 * @param array<string, mixed> $caseTypeData The bundle's caseType payload.
	 *
	 * @return array{caseTypeData: array<string, mixed>, childData: array<string, array<int, mixed>>,
	 *               workflowData: mixed, initialStatusName: string} The split payload.
	 *
	 * @spec openspec/specs/besluitvorming-workflow/spec.md
	 */
	private function splitBundle(array $caseTypeData): array {
		$childData = [
			'statusTypes' => (array)($caseTypeData['statusTypes'] ?? []),
			'roleTypes' => (array)($caseTypeData['roleTypes'] ?? []),
			'propertyDefinitions' => (array)($caseTypeData['propertyDefinitions'] ?? []),
			'documentTypes' => (array)($caseTypeData['documentTypes'] ?? []),
			'resultTypes' => (array)($caseTypeData['resultTypes'] ?? []),
		];
		$workflowData = ($caseTypeData['workflowTemplate'] ?? null);
		$initialStatusName = trim((string)($caseTypeData['initialStatusName'] ?? ''));

		unset(
			$caseTypeData['statusTypes'],
			$caseTypeData['roleTypes'],
			$caseTypeData['propertyDefinitions'],
			$caseTypeData['documentTypes'],
			$caseTypeData['resultTypes'],
			$caseTypeData['workflowTemplate'],
			$caseTypeData['initialStatusName'],
		);

		return [
			'caseTypeData' => $caseTypeData,
			'childData' => $childData,
			'workflowData' => $workflowData,
			'initialStatusName' => $initialStatusName,
		];
	}//end splitBundle()

	/**
	 * Write the caseType's initialStatus link once the statusTypes exist.
	 *
	 * The bundle can only name the initial status (the statusTypes are created
	 * AFTER the caseType they belong to), so this update resolves the name via
	 * the freshly-seeded name map and writes the id back. A bundle that names
	 * no initial status, or names one that did not seed, is logged loudly: the
	 * cost is a case born statusless through the API.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The register slug.
	 * @param string $schema The caseType schema id.
	 * @param string $caseTypeId The freshly-created caseType id.
	 * @param array<string, mixed> $caseTypeData The caseType payload as created.
	 * @param string $initialStatusName The status name the bundle declared.
	 * @param array<string, string> $statusNameMap Map of statusType name => id.
	 * @param string $slug The template slug (for logging).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/besluitvorming-workflow/spec.md
	 */
	private function linkInitialStatus(
		object $objectService,
		string $register,
		string $schema,
		string $caseTypeId,
		array $caseTypeData,
		string $initialStatusName,
		array $statusNameMap,
		string $slug,
	): void {
		if ($caseTypeId === '' || $schema === '') {
			return;
		}

		$statusId = ($statusNameMap[$initialStatusName] ?? '');
		if ($initialStatusName === '' || $statusId === '') {
			$this->logger->warning(
				'Dossiq: besluitvorming template names no resolvable initial status; API-created cases of this type are born statusless',
				['slug' => $slug, 'initialStatusName' => $initialStatusName],
			);
			return;
		}

		try {
			// `referenceProcess` and `relatedCaseTypes` are declared strings
			// but come back from a read DECODED, so a bare array_merge writes
			// arrays into them and OpenRegister refuses the save.
			$objectService->saveObject(
				register: $register,
				schema: $schema,
				object: $this->jsonProperties->mergeForWrite(
					stored: $caseTypeData,
					updates: ['initialStatus' => $statusId],
					schemaSlug: 'caseType',
				),
				uuid: $caseTypeId,
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not write the caseType initialStatus link',
				['slug' => $slug, 'exception' => $e->getMessage()],
			);
		}
	}//end linkInitialStatus()

	/**
	 * Seed the workflow template, resolving its name references first.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The register slug.
	 * @param array<string, string> $schemas Map of schema-key => schema id.
	 * @param mixed $workflowData The raw workflow payload, if any.
	 * @param array<string, array<string, string>> $nameMaps Name => id maps per collection.
	 * @param string $caseTypeId The owning caseType id.
	 * @param array<string, mixed> $counts Counts accumulator (by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/besluitvorming-workflow/spec.md
	 */
	private function seedWorkflowTemplate(
		object $objectService,
		string $register,
		array $schemas,
		mixed $workflowData,
		array $nameMaps,
		string $caseTypeId,
		array &$counts,
	): void {
		if (is_array($workflowData) === false || $schemas['workflowTemplate'] === '') {
			return;
		}

		$resolved = $this->workflowResolver->resolveWorkflowReferences(
			workflowData: $workflowData,
			statusNameMap: $nameMaps['statusTypes'],
			roleNameMap: $nameMaps['roleTypes'],
			caseTypeId: $caseTypeId,
		);
		$created = $this->createObject(
			objectService: $objectService,
			register: $register,
			schema: $schemas['workflowTemplate'],
			data: $resolved,
		);
		if ($created !== null) {
			$counts['workflowTemplate']++;
		}
	}//end seedWorkflowTemplate()

	/**
	 * Seed a list of child records linked to a caseType, returning a name->id map.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The register slug.
	 * @param string $schema The child schema id.
	 * @param array<int, mixed> $records The child record payloads.
	 * @param string $caseTypeId The parent caseType id.
	 * @param array<string, mixed> $counts Counts accumulator (by reference).
	 * @param string $countKey The key in $counts to increment.
	 *
	 * @return array<string, string> Map of record name => created id.
	 *
	 * @spec openspec/specs/besluitvorming-workflow/spec.md
	 */
	private function seedChildren(
		object $objectService,
		string $register,
		string $schema,
		array $records,
		string $caseTypeId,
		array &$counts,
		string $countKey,
	): array {
		$nameToId = [];
		if ($schema === '') {
			return $nameToId;
		}

		foreach ($records as $record) {
			if (is_array($record) === false) {
				continue;
			}

			$record['caseType'] = $caseTypeId;
			$created = $this->createObject(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				data: $record,
			);
			if ($created === null) {
				continue;
			}

			$name = (string)($record['name'] ?? '');
			if ($name !== '') {
				$nameToId[$name] = $this->getObjectId(object: $created);
			}

			$counts[$countKey]++;
		}//end foreach

		return $nameToId;
	}//end seedChildren()

	/**
	 * Create an object via the ObjectService, returning null on failure.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The register slug.
	 * @param string $schema The schema id.
	 * @param array<string, mixed> $data The object payload.
	 *
	 * @return object|null The created object, or null.
	 *
	 * @spec openspec/specs/besluitvorming-workflow/spec.md
	 */
	private function createObject(
		object $objectService,
		string $register,
		string $schema,
		array $data,
	): ?object {
		try {
			$result = $objectService->saveObject(register: $register, schema: $schema, object: $data);
			if (is_object($result) === true) {
				return $result;
			}

			return null;
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: besluitvorming seed object create failed',
				['schema' => $schema, 'exception' => $e->getMessage()],
			);
			return null;
		}
	}//end createObject()

	/**
	 * Extract an object id from an OpenRegister object.
	 *
	 * @param object $object The OpenRegister entity.
	 *
	 * @return string The id (or empty string).
	 *
	 * @spec openspec/specs/besluitvorming-workflow/spec.md
	 */
	private function getObjectId(object $object): string {
		if (method_exists($object, 'getId') === true) {
			return (string)$object->getId();
		}

		if (method_exists($object, 'getUuid') === true) {
			return (string)$object->getUuid();
		}

		return '';
	}//end getObjectId()

	/**
	 * Convert an arbitrary ObjectService return value to an associative array.
	 *
	 * @param mixed $value The returned object/array.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/besluitvorming-workflow/spec.md
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialized = $value->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($value) === true) {
			return (array)$value;
		}

		return [];
	}//end toArray()
}//end class
