<?php

/**
 * Dossiq Advisory Body Service
 *
 * Service for managing advisory bodies — departments and external organizations
 * that can receive consultation requests (adviesaanvragen). Supports registry
 * CRUD, specialization-weighted search, and secure-token issuance for external
 * body access per ADR-034 and the Awb 3:5-3:9 external consultation pattern.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-03
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for advisory body registry management.
 *
 * Advisory bodies are departments (internal) or organizations (external) that
 * can be consulted during case processing. This service exposes CRUD, weighted
 * specialization search, and secure-token issuance for external notification.
 *
 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-03
 */
class AdvisoryBodyService {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * List all advisory bodies, optionally filtered.
	 *
	 * @param array<string, mixed> $filters Optional filter params
	 *
	 * @return array<int, array<string, mixed>> List of advisory bodies
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-03
	 */
	public function findAll(array $filters = []): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('advisory_body_schema');

		if (empty($register) === true || empty($schema) === true) {
			return [];
		}

		$results = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: array_merge($filters, ['_limit' => 200]),
		);

		return $results;
	}//end findAll()

	/**
	 * Find a single advisory body by ID.
	 *
	 * @param string $id The advisory body UUID
	 *
	 * @return array<string, mixed>|null The advisory body or null if not found
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-03
	 */
	public function findById(string $id): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('advisory_body_schema');

		if (empty($register) === true || empty($schema) === true) {
			return null;
		}

		// A top-level `['id' => $id]` filter does not resolve in OpenRegister
		// (ids are metadata, not schema properties) and silently matches
		// nothing. The get-by-uuid path resolves the id directly.
		return $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			id: $id
		);
	}//end findById()

	/**
	 * Create or update an advisory body.
	 *
	 * When $id is empty a new record is created; otherwise the existing record
	 * is updated.
	 *
	 * @param array<string, mixed> $data The advisory body data
	 * @param string $id The UUID for update (empty for create)
	 *
	 * @return array<string, mixed> The saved advisory body data
	 *
	 * @throws \RuntimeException If OpenRegister is unavailable or schema not configured
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-03
	 */
	public function save(array $data, string $id = ''): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('advisory_body_schema');

		if (empty($register) === true || empty($schema) === true) {
			throw new RuntimeException('Advisory body schema not configured');
		}

		$saveArgs = [$register, $schema, $data];
		if ($id !== '') {
			$saveArgs[] = $id;
		}

		$result = $objectService->saveObject(...$saveArgs);

		$savedId = '';
		if ($id !== '') {
			$savedId = $id;
		}

		if (is_object($result) === true) {
			$savedId = $result->getUuid();
		}

		$this->logger->info(
			'Advisory body saved: ' . $savedId,
			['app' => Application::APP_ID],
		);

		if (is_array($result) === true) {
			return $result;
		}

		return ['id' => $savedId];
	}//end save()

	/**
	 * Delete an advisory body by ID.
	 *
	 * @param string $id The advisory body UUID
	 *
	 * @return bool True on success
	 *
	 * @throws \RuntimeException If OpenRegister is unavailable
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-03
	 */
	public function delete(string $id): bool {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('advisory_body_schema');

		if (empty($register) === true || empty($schema) === true) {
			throw new RuntimeException('Advisory body schema not configured');
		}

		$objectService->deleteObject(uuid: $id, register: $register, schema: $schema);

		$this->logger->info(
			'Advisory body deleted: ' . $id,
			['app' => Application::APP_ID],
		);

		return true;
	}//end delete()

	/**
	 * Search advisory bodies by specialization tag (case-insensitive substring).
	 *
	 * Returns results ranked so that bodies with a matching specialization tag
	 * appear first, followed by all remaining active bodies. Bodies with
	 * active=false are excluded from results.
	 *
	 * @param string $query Search query for specialization tags
	 *
	 * @return array<int, array<string, mixed>> Ranked list of advisory bodies
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-03
	 */
	public function searchBySpecialization(string $query): array {
		$all = $this->findAll(filters: ['active' => true]);

		$lowerQuery = mb_strtolower($query);
		$matching = [];
		$rest = [];

		foreach ($all as $body) {
			$specializations = $body['specializations'] ?? [];
			if (is_array($specializations) === false) {
				$specializations = [];
			}

			$hasMatch = false;
			foreach ($specializations as $tag) {
				if (str_contains(mb_strtolower((string)$tag), $lowerQuery) === true) {
					$hasMatch = true;
					break;
				}
			}

			if ($hasMatch === true) {
				$matching[] = $body;
				continue;
			}

			$rest[] = $body;
		}//end foreach

		return array_merge($matching, $rest);
	}//end searchBySpecialization()

	/*
	 * NO issueSecureToken() / sendExternalNotification() HERE.
	 *
	 * `issueSecureToken()` minted a 32-byte hex token and wrote it onto a
	 * consultation object as `secureToken`; `sendExternalNotification()` was a
	 * single logger->info() whose own docblock said "the actual HTTP call to
	 * n8n is intentionally not implemented here — it is triggered by the
	 * x-openregister-notifications schema configuration on the consultation
	 * schema" (ADR-031). Neither had a caller.
	 *
	 * The read half IS live: `ConsultationPublicController::publicResponseGet`
	 * / `publicResponsePost` serve `/api/public/consultations/{token}` and
	 * `Consultation\ConsultationRepository` looks a consultation up by
	 * `secureToken`. Because nothing ever minted one, that public surface can
	 * never be entered — which is a capability gap, reported as such, not a
	 * reason to add a public-token minter with no route and no guard in front
	 * of it. Wiring the notification stub would have been worse still: it
	 * would have made "no mail is sent" look like "mail is sent".
	 */
}//end class
