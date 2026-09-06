<?php

/**
 * Dossiq mandaat repository.
 *
 * The single OpenRegister access path for the mandaat matrix: the
 * rolNaam→rolId index, the prior vastgesteld besluit for a besluit number, the
 * mandaten belonging to a besluit, the schema/register context an approval
 * needs, the bulk activation of a besluit's mandaten, and the generic
 * configured-schema save. Split out of MandaatImportService so that service
 * keeps the import *decision* — new vs changed vs removed, and the approval
 * state machine — while every register/schema lookup lives here.
 *
 * The read paths degrade rather than throw when OpenRegister or a schema is
 * unconfigured: the caller's own guards decide whether an empty result is
 * fatal, exactly as before the split.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Mandaat
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
 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Mandaat;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * OpenRegister access for MandateringsBesluiten, Mandaten and OrganisatieRollen.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
 */
class MandaatRepository {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings (config + ObjectService).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the object service, register and schemas needed to approve an import.
	 *
	 * @return array<string, mixed> {objectService, register, bSchema, mSchema}
	 *
	 * @throws RuntimeException When the mandaat services are not configured.
	 *
	 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
	 */
	public function resolveApprovalContext(): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$bSchema = (string)$this->settingsService->getConfigValue('mandaterings_besluit_schema');
		$mSchema = (string)$this->settingsService->getConfigValue('mandaat_schema');
		if ($objectService === null || $register === '' || $bSchema === '' || $mSchema === '') {
			throw new RuntimeException('Mandaat services not configured');
		}

		return [
			'objectService' => $objectService,
			'register' => $register,
			'bSchema' => $bSchema,
			'mSchema' => $mSchema,
		];
	}//end resolveApprovalContext()

	/**
	 * Flip every mandaat of a besluit to active, defaulting a missing validFrom.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register The register id.
	 * @param string $mSchema The mandaat schema id.
	 * @param string $decisionId The owning MandateringsBesluit id.
	 * @param string $now The activation date (Y-m-d).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
	 */
	public function activateMandatenForBesluit(
		object $objectService,
		string $register,
		string $mSchema,
		string $decisionId,
		string $now,
	): void {
		try {
			$mandaten = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $mSchema,
				filters: ['mandateDecision' => $decisionId]
			);
		} catch (\Throwable $e) {
			$mandaten = [];
		}

		foreach ($mandaten as $m) {
			$m['status'] = 'active';
			if (isset($m['validFrom']) === false || $m['validFrom'] === '') {
				$m['validFrom'] = $now;
			}

			$this->saveObjectAsArray(objectService: $objectService, register: $register, schema: $mSchema, object: $m);
		}
	}//end activateMandatenForBesluit()

	/**
	 * Build a rolNaam to rolId index from OrganisatieRol objects.
	 *
	 * @return array<string, string> rolNaam → rolId.
	 *
	 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
	 */
	public function loadRoleIndex(): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('organisatie_rol_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: []
			);
		} catch (\Throwable $e) {
			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			$roleName = (string)($row['roleName'] ?? '');
			if ($roleName !== '') {
				$out[$roleName] = (string)($row['id'] ?? '');
			}
		}

		return $out;
	}//end loadRoleIndex()

	/**
	 * Find the prior adopted decision for a decision number.
	 *
	 * @param string $decisionNumber Number.
	 * @param string|null $excludeId Optional id to exclude.
	 *
	 * @return array<string, mixed>|null The prior adopted decision, or null.
	 *
	 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
	 */
	public function findPriorDecision(string $decisionNumber, ?string $excludeId = null): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('mandaterings_besluit_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['decisionNumber' => $decisionNumber]
			);
		} catch (\Throwable $e) {
			return null;
		}

		foreach ($rows as $row) {
			if ($excludeId !== null && (string)($row['id'] ?? '') === $excludeId) {
				continue;
			}

			if (($row['status'] ?? '') === 'determined') {
				return $row;
			}
		}

		return null;
	}//end findPriorDecision()

	/**
	 * Find the mandaten linked to a besluit.
	 *
	 * @param string $decisionId Besluit id.
	 *
	 * @return array<int, array<string, mixed>> The besluit's mandaten.
	 *
	 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
	 */
	public function findMandatenForBesluit(string $decisionId): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('mandaat_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		try {
			return (array)$this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['mandateDecision' => $decisionId]
			);
		} catch (\Throwable $e) {
			return [];
		}
	}//end findMandatenForBesluit()

	/**
	 * Persist a single object for the configured schema.
	 *
	 * @param string $schemaConfigKey Config key naming the schema.
	 * @param array<string, mixed> $object Payload.
	 *
	 * @return array<string, mixed> The saved object, or the payload when the
	 *                              save could not be performed.
	 *
	 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
	 */
	public function save(string $schemaConfigKey, array $object): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue($schemaConfigKey);
		if ($objectService === null || $register === '' || $schema === '') {
			return $object;
		}

		try {
			$saved = $this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				object: $object
			);
			return ($saved ?? $object);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Mandaat import persist failed',
				['key' => $schemaConfigKey, 'error' => $e->getMessage()]
			);

			return $object;
		}
	}//end save()
}//end class
