<?php

/**
 * Dossiq Demo Caseload Gateway
 *
 * The OpenRegister access the demo caseload needs, in one place: resolving the
 * register and schema ids from appconfig, reading objects back, and creating
 * them. Shared by the seeder and the reporter so neither carries its own copy
 * of the lookup rules.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/specs/dossiq-app-scaffold/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * OpenRegister access for the demo caseload.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — needs OpenRegister service access
 *
 * @spec openspec/specs/dossiq-app-scaffold/spec.md
 */
class DemoCaseloadGateway {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration service.
	 * @param ContainerInterface $container The DI container.
	 * @param LoggerInterface $logger The logger interface.
	 * @param IAppManager $appManager Used to establish that OpenRegister is present.
	 *
	 * @return void
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private ContainerInterface $container,
		private LoggerInterface $logger,
		private IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * The register and schema ids the demo caseload reads and writes.
	 *
	 * @return array{register: string, case: string, caseTask: string, caseType: string, statusType: string} The ids.
	 *
	 * @throws RuntimeException When the app is not configured against a register yet.
	 *
	 * @spec openspec/specs/dossiq-app-scaffold/spec.md
	 */
	public function schemaIds(): array {
		$ids = [
			'register' => $this->config(key: 'register'),
			'case' => $this->config(key: 'case_schema'),
			'caseTask' => $this->config(key: 'task_schema'),
			'caseType' => $this->config(key: 'case_type_schema'),
			'statusType' => $this->config(key: 'status_type_schema'),
		];

		$missing = array_keys(array_filter($ids, static fn (string $value): bool => $value === ''));
		if ($missing !== []) {
			throw new RuntimeException(
				'Dossiq is not configured against a register yet, missing: ' . implode(', ', $missing)
			);
		}

		return $ids;
	}//end schemaIds()

	/**
	 * OpenRegister's ObjectService.
	 *
	 * 🔴 A CROSS-APP CLASS IS A RUNTIME LOOKUP, AND THE APP IS OPTIONAL. It
	 * cannot be a typed constructor property (ADR-083 rule 1): naming a class
	 * from an app that may not be installed makes PHP resolve it whenever this
	 * service is constructed, so an instance without OpenRegister fails with an
	 * error about a class nobody mentioned instead of the message below.
	 *
	 * Establish availability FIRST, then look it up. Checking `isInstalled`
	 * separates "OpenRegister is not here" from "OpenRegister is here and would
	 * not construct", which are different problems for whoever ran the seed.
	 *
	 * @return object The ObjectService, an OCA\OpenRegister\Service\ObjectService.
	 *
	 * @psalm-return \OCA\OpenRegister\Service\ObjectService
	 *
	 * @throws RuntimeException When OpenRegister is not installed or cannot be resolved.
	 *
	 * @spec openspec/specs/dossiq-app-scaffold/spec.md
	 */
	public function objectService(): object {
		if ($this->appManager->isInstalled('openregister') === false) {
			throw new RuntimeException('The demo caseload needs OpenRegister, which is not installed.');
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			throw new RuntimeException(
				'OpenRegister is installed but its ObjectService could not be resolved: ' . $e->getMessage()
			);
		}
	}//end objectService()

	/**
	 * Create an object and return the id it was given.
	 *
	 * Returns the ID rather than the entity because that is the only thing
	 * either caller wants: the seeder needs a case's id to hang its tasks off,
	 * and needs nothing at all from a created task beyond whether it worked.
	 * Handing back the entity made every call site follow it with {@see idOf()}.
	 *
	 * A failure is reported as '' rather than raised: one case that will not
	 * save should not abandon the rest of the seed, and the failure is logged
	 * with the title so it is identifiable.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $registerId The register id.
	 * @param string $schemaId The schema id.
	 * @param array $data The object data.
	 *
	 * @return string The new object's id, or '' when it could not be created.
	 *
	 * @spec openspec/specs/dossiq-app-scaffold/spec.md
	 */
	public function create(
		object $objectService,
		string $registerId,
		string $schemaId,
		array $data,
	): string {
		try {
			$created = $objectService->saveObject(
				register: $registerId,
				schema: $schemaId,
				object: $data,
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Dossiq: Demo seed could not create an object',
				['schema' => $schemaId, 'title' => ($data['title'] ?? ''), 'exception' => $e->getMessage()]
			);
			return '';
		}

		return $this->idOf(object: $created);
	}//end create()

	/**
	 * Whether an object matching the filters already exists.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $registerId The register id.
	 * @param string $schemaId The schema id.
	 * @param array $filters The filter criteria.
	 *
	 * @return boolean True when at least one match exists.
	 *
	 * @spec openspec/specs/dossiq-app-scaffold/spec.md
	 */
	public function exists(
		object $objectService,
		string $registerId,
		string $schemaId,
		array $filters,
	): bool {
		$results = $this->findMany(
			objectService: $objectService,
			registerId: $registerId,
			schemaId: $schemaId,
			filters: $filters,
			limit: 1
		);

		return ($results !== []);
	}//end exists()

	/**
	 * Find objects by filter, tolerating both result shapes ObjectService returns.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $registerId The register id.
	 * @param string $schemaId The schema id.
	 * @param array $filters The filter criteria.
	 * @param integer $limit The page size.
	 *
	 * @return array<int, mixed> The matches, empty when the lookup fails.
	 *
	 * @spec openspec/specs/dossiq-app-scaffold/spec.md
	 */
	public function findMany(
		object $objectService,
		string $registerId,
		string $schemaId,
		array $filters,
		int $limit = 500,
	): array {
		try {
			$results = $objectService->findAll(
				[
					'filters' => (['register' => $registerId, 'schema' => $schemaId] + $filters),
					'limit' => $limit,
				],
			);
		} catch (\Exception $e) {
			$this->logger->debug(
				'Dossiq: Demo caseload lookup failed',
				['schema' => $schemaId, 'exception' => $e->getMessage()]
			);
			return [];
		}

		if (is_array($results) === false) {
			return [];
		}

		if (isset($results['results']) === true && is_array($results['results']) === true) {
			return array_values($results['results']);
		}

		return array_values($results);
	}//end findMany()

	/**
	 * Normalise an OpenRegister object to a plain array.
	 *
	 * @param mixed $object The object entity or array.
	 *
	 * @return array The object's data.
	 *
	 * @spec openspec/specs/dossiq-app-scaffold/spec.md
	 */
	public function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === false) {
			return [];
		}

		if (method_exists($object, 'getObject') === true) {
			return (array)$object->getObject();
		}

		if (method_exists($object, 'jsonSerialize') === true) {
			return (array)$object->jsonSerialize();
		}

		return [];
	}//end toArray()

	/**
	 * The id of a saved OpenRegister object.
	 *
	 * Prefers the UUID: `task.case` is a uuid-format property, and the saved
	 * entity exposes the UUID via getUuid() while getId() can be the internal
	 * numeric id.
	 *
	 * @param object $object The saved object.
	 *
	 * @return string The id, or '' when the object exposes neither.
	 *
	 * @spec openspec/specs/dossiq-app-scaffold/spec.md
	 */
	public function idOf(object $object): string {
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
	}//end idOf()

	/**
	 * Read one appconfig value.
	 *
	 * @param string $key The config key.
	 *
	 * @return string The value, or '' when unset.
	 */
	private function config(string $key): string {
		return $this->appConfig->getValueString(Application::APP_ID, $key, '');
	}//end config()
}//end class
