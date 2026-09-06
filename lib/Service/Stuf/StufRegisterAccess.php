<?php

/**
 * Dossiq StufRegisterAccess.
 *
 * Thin wrapper around OpenRegister's ObjectService for the three StUF
 * schemas. Centralises register/schema resolution and JSON serialisation so
 * the rest of the adapter never touches the OR API directly.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Stuf
 *
 * @author    Conduction <info@conduction.nl>
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
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-audit-log
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Stuf;

use OCA\Dossiq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\IAppContainer;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Thin OpenRegister ObjectService wrapper for StUF schemas.
 *
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-audit-log
 */
class StufRegisterAccess {
	public const SCHEMA_ENDPOINT = 'stufEndpoint';

	public const SCHEMA_MESSAGE = 'stufMessage';

	public const SCHEMA_MAPPING = 'zaaksysteemMapping';

	/**
	 * Constructor.
	 *
	 * @param IAppContainer $container The DI container.
	 * @param IAppConfig $appConfig The app config (register id lookup).
	 * @param LoggerInterface $logger The logger.
	 * @param IAppManager $appManager The app manager (checks openregister is installed).
	 */
	public function __construct(
		private IAppContainer $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * Save (create or update) an object of the given StUF schema.
	 *
	 * @param string $schema The schema slug (one of the SCHEMA_* constants).
	 * @param array $data The object payload.
	 *
	 * @return array The saved object as a plain array.
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-audit-log
	 */
	public function saveObject(string $schema, array $data): array {
		$service = $this->getObjectService();
		if ($service === null) {
			// Returning the payload unsaved would look like a successful write to
			// every caller. An outbound StUF audit record that silently did not
			// persist is worse than a loud failure, so say so.
			throw new RuntimeException(
				'Cannot save StUF object: OpenRegister is unavailable.'
			);
		}

		$registerId = $this->getRegisterId();
		$saved = $service->saveObject($data, [], $registerId, $schema, null);
		return $this->normalise(value: $saved);
	}//end saveObject()

	/**
	 * Find one object by its UUID; returns null when it does not exist.
	 *
	 * The dedicated get-by-id path. A `['id' => $uuid]` entry in the
	 * `findOne()`/`findAll()` filter map does NOT resolve: OpenRegister
	 * treats top-level filter keys as schema properties, none of the StUF
	 * schemas declare an `id` property, and the search silently returns
	 * zero rows. `ObjectService::find()` resolves ids and UUIDs directly.
	 *
	 * @param string $schema The schema slug (one of the SCHEMA_* constants).
	 * @param string $id The object UUID.
	 *
	 * @return array|null The object as plain array, or null.
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md
	 */
	public function findById(string $schema, string $id): ?array {
		try {
			$service = $this->getObjectService();
			if ($service === null || $id === '') {
				return null;
			}

			$object = $service->find(id: $id, register: $this->getRegisterId(), schema: $schema);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: 'StUF register findById failed: {err}',
				context: ['err' => $e->getMessage(), 'schema' => $schema]
			);
			return null;
		}

		if ($object === null) {
			return null;
		}

		$row = $this->normalise(value: $object);
		if ($row === []) {
			return null;
		}

		return $row;
	}//end findById()

	/**
	 * Find one object by filter; returns null when there is no match.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string,scalar> $filters The filter map merged into OR `findAll`.
	 *
	 * @return array|null The object as plain array, or null.
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md
	 */
	public function findOne(string $schema, array $filters): ?array {
		$results = $this->findAll(schema: $schema, filters: $filters, limit: 1);
		return ($results[0] ?? null);
	}//end findOne()

	/**
	 * Find many objects matching the filter.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string,scalar> $filters The filter map.
	 * @param int $limit The page size.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md
	 */
	public function findAll(string $schema, array $filters = [], int $limit = 100): array {
		try {
			$service = $this->getObjectService();
			if ($service === null) {
				// A READ, so an empty list is the honest answer for an absent
				// register — and it matches what this method already returns when
				// the lookup throws, two lines below.
				return [];
			}

			$objects = $service->findAll(
				[
					'filters' => array_merge(['register' => $this->getRegisterId(), 'schema' => $schema], $filters),
					'limit' => $limit,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: 'StUF register findAll failed: {err}',
				context: ['err' => $e->getMessage(), 'schema' => $schema]
			);
			return [];
		}

		if (is_array(value: $objects) === false) {
			return [];
		}

		$result = [];
		foreach ($objects as $obj) {
			$result[] = $this->normalise(value: $obj);
		}

		return $result;
	}//end findAll()

	/**
	 * Resolve the OR register id for dossiq from IAppConfig.
	 *
	 * @return string The register id.
	 */
	private function getRegisterId(): string {
		return $this->appConfig->getValueString(app: Application::APP_ID, key: 'register', default: '');
	}//end getRegisterId()

	/**
	 * Resolve the ObjectService from the DI container.
	 *
	 * ADR-083 rule 1 (gate-66): this used to be a bare `$container->get()`, which
	 * declares the OpenRegister dependency NOWHERE a reader or a gate can see it —
	 * the app looked constructable without OpenRegister and then failed at the
	 * first StUF write, with a container exception rather than a stated reason.
	 *
	 * OpenRegister is genuinely OPTIONAL on this path (StUF outbound is one
	 * integration among several), so the rule's second remedy applies: establish
	 * availability first and keep the lookup. `OpenRegisterSharingGateway` in this
	 * same app already does exactly this, so this is adopting the shape the app
	 * had rather than inventing one.
	 *
	 * @return object|null The ObjectService, or null when OpenRegister is unavailable.
	 */
	private function getObjectService(): ?object {
		if ($this->appManager->isInstalled('openregister') === false) {
			$this->logger->warning(
				'Dossiq StUF: OpenRegister is not installed; the StUF register is unavailable.'
			);
			return null;
		}

		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Dossiq StUF: could not resolve OpenRegister ObjectService: ' . $e->getMessage()
			);
			return null;
		}
	}//end getObjectService()

	/**
	 * Normalise an OR result (entity, array, or JsonSerializable) to plain array.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<string,mixed>
	 */
	private function normalise(mixed $value): array {
		if (is_array(value: $value) === true) {
			return $value;
		}

		if (is_object(value: $value) === true && method_exists(object_or_class: $value, method: 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array(value: $serialised) === true) {
				return $serialised;
			}
		}

		return [];
	}//end normalise()
}//end class
