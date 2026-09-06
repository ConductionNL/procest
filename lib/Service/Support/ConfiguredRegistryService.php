<?php

/**
 * Dossiq configured-registry service.
 *
 * Generic list/save/delete over an OpenRegister schema named by a dossiq
 * config key. Dossiq keeps roughly two hundred schema ids in app config
 * (`SettingsService::CONFIG_KEYS`), and several admin surfaces need nothing
 * more than "show me every row of schema X, and let an admin edit one".
 * Repeating the resolve-register-resolve-schema-guard-empty dance per surface
 * is how `/api/mandate/rollen` and `/api/termijn/definities` came to have
 * frontends and no backends at all (procest#794).
 *
 * Deliberately NOT a general object API: it has no per-object authorization of
 * its own, so every caller must be a controller that carries an explicit guard
 * — in practice `#[AuthorizedAdminSetting]`. Routing a user-facing surface
 * through this class would reproduce the authorization bypass that the first
 * attempt at procest#784 shipped and had to be retracted.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
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
 * @spec openspec/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * List, save and delete objects of a config-key-named schema.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
class ConfiguredRegistryService {

	use SearchesObjects;

	/**
	 * Upper bound on a single registry listing.
	 *
	 * Admin registries are organisational reference data — tens of rows, not
	 * thousands. The cap is stated rather than implied so that a truncated list
	 * is a documented outcome rather than a silent one.
	 *
	 * @var int
	 */
	public const LIST_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings (config + ObjectService).
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * List every object of a configured schema.
	 *
	 * Degrades to an empty array when OpenRegister or the schema is
	 * unconfigured, matching the rest of dossiq's read paths.
	 *
	 * @param string $schemaConfigKey Config key naming the schema.
	 * @param array<string, mixed> $filters Optional object-field filters.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function list(string $schemaConfigKey, array $filters = []): array {
		$context = $this->resolveContext(schemaConfigKey: $schemaConfigKey);
		if ($context === null) {
			return [];
		}

		try {
			return $this->searchObjectsAsArrays(
				objectService: $context['objectService'],
				register: $context['register'],
				schema: $context['schema'],
				filters: array_merge(['_limit' => self::LIST_LIMIT], $filters)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Configured registry listing failed',
				['key' => $schemaConfigKey, 'error' => $e->getMessage()]
			);
			return [];
		}
	}//end list()

	/**
	 * Create or update a single object.
	 *
	 * ⚠️ `saveObject()` is PUT-semantic in OpenRegister — keys omitted from the
	 * payload are nulled, not left alone. An update caller must therefore send
	 * the whole object, not a patch fragment.
	 *
	 * @param string $schemaConfigKey Config key naming the schema.
	 * @param array<string, mixed> $data The object payload.
	 * @param string|null $id Existing id, or null to create.
	 *
	 * @return array<string, mixed> The saved object.
	 *
	 * @throws RuntimeException When the register or schema is not configured.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function save(string $schemaConfigKey, array $data, ?string $id = null): array {
		$context = $this->resolveContext(schemaConfigKey: $schemaConfigKey);
		if ($context === null) {
			throw new RuntimeException(
				'Not configured: no register or schema for ' . $schemaConfigKey
			);
		}

		// 🔴 IDENTITY COMES FROM THE PARAMETER, NEVER FROM THE PAYLOAD.
		//
		// `saveObject()` does not take its target as an argument you control:
		// `extractUuidAndNormalizeObject()` reads
		// `$object['@self']['id'] ?? $object['id']` and treats a match as the
		// uuid to UPDATE. The write is PUT-semantic, so keys the payload omits
		// are NULLED rather than left alone.
		//
		// Every caller here builds `$data` from `$this->request->getParams()`.
		// Several stripped `id` for exactly this reason and did not know about
		// `@self`, which is the key saveObject reads FIRST — so a POST that
		// CREATES could carry `@self: {id: <someone else's object>}` and
		// silently replace it. Measured on a running instance: the create
		// returned 201 carrying the victim's own uuid, and the victim's row
		// came back with the attacker's values.
		//
		// This method already takes `$id` for the update case. Stripping the
		// payload's identity makes that parameter the ONLY way to address an
		// existing object, which is what it was always for.
		unset($data['id'], $data['uuid'], $data['@self']);

		if ($id !== null && $id !== '') {
			$data['id'] = $id;
		}

		$saved = $context['objectService']->saveObject(
			register: $context['register'],
			schema: $context['schema'],
			object: $data
		);

		return $this->toArray(value: $saved, fallback: $data);
	}//end save()

	/**
	 * Delete a single object.
	 *
	 * ⚠️ OpenRegister's `deleteObject()` is a SOFT delete — the row keeps
	 * existing with `_deleted` set. Any count taken afterwards must exclude
	 * soft-deleted rows or it will still see this object.
	 *
	 * ⚠️ The identifier parameter is named `$uuid`, NOT `$id`. Passing `id:` as
	 * a named argument raises `Unknown named parameter $id` at runtime, which
	 * the caller then reports as a generic save/delete failure. This is not
	 * hypothetical: `InspectionChecklistService::deleteChecklist()` shipped with
	 * exactly that mistake and its admin Delete button 500'd on every use.
	 *
	 * @param string $schemaConfigKey Config key naming the schema.
	 * @param string $id The object id.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the register or schema is not configured.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function delete(string $schemaConfigKey, string $id): void {
		$context = $this->resolveContext(schemaConfigKey: $schemaConfigKey);
		if ($context === null) {
			throw new RuntimeException(
				'Not configured: no register or schema for ' . $schemaConfigKey
			);
		}

		$context['objectService']->deleteObject(
			uuid: $id,
			register: $context['register'],
			schema: $context['schema']
		);
	}//end delete()

	/**
	 * Resolve the ObjectService, register and schema for a config key.
	 *
	 * @param string $schemaConfigKey Config key naming the schema.
	 *
	 * @return array{objectService: object, register: string, schema: string}|null
	 *                                                                             The context, or null when OpenRegister or the schema is absent.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function resolveContext(string $schemaConfigKey): ?array {
		try {
			$objectService = $this->settingsService->getObjectService();
		} catch (Throwable $e) {
			return null;
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue($schemaConfigKey);
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		return [
			'objectService' => $objectService,
			'register' => $register,
			'schema' => $schema,
		];
	}//end resolveContext()

	/**
	 * Normalise an OpenRegister save result into a plain array.
	 *
	 * @param mixed $value The save result.
	 * @param array<string, mixed> $fallback The payload to return when the
	 *                                       result is neither array nor object.
	 *
	 * @return array<string, mixed> The saved object as an array.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function toArray(mixed $value, array $fallback): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true) {
			if (method_exists($value, 'jsonSerialize') === true) {
				return (array)$value->jsonSerialize();
			}

			return get_object_vars(object: $value);
		}

		return $fallback;
	}//end toArray()
}//end class
