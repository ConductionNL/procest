<?php

/**
 * Dossiq Template Library Service
 *
 * Service for loading and activating zaaktype templates.
 * Templates are JSON files shipped with the app that define complete
 * case type configurations (statuses, properties, document types, etc.).
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
 * @spec openspec/specs/template-library/spec.md
 * @spec openspec/specs/template-library/spec.md
 * @spec openspec/specs/template-library/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for loading and activating zaaktype templates.
 *
 * @spec openspec/specs/template-library/spec.md
 */
class TemplateLibraryService {

	/**
	 * Path to the templates directory.
	 */
	private const TEMPLATES_DIR = __DIR__ . '/../Settings/templates';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service for register/schema references
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * List all available zaaktype templates.
	 *
	 * Scans the templates directory for JSON files and returns their metadata.
	 *
	 * @return array<int, array<string, mixed>> List of template metadata
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function listTemplates(): array {
		$templates = [];
		$dir = self::TEMPLATES_DIR;

		if (is_dir($dir) === false) {
			return $templates;
		}

		$files = glob($dir . '/*.json');
		if ($files === false) {
			return $templates;
		}

		foreach ($files as $file) {
			$content = file_get_contents($file);
			if ($content === false) {
				continue;
			}

			$data = json_decode($content, true);
			if (json_last_error() !== JSON_ERROR_NONE || is_array($data) === false) {
				$this->logger->warning(
					'Invalid template file: ' . basename($file),
					['app' => Application::APP_ID]
				);
				continue;
			}

			$templates[] = [
				'id' => $data['id'] ?? pathinfo($file, PATHINFO_FILENAME),
				'title' => $data['title'] ?? '',
				'description' => $data['description'] ?? '',
				'category' => $data['category'] ?? 'general',
				'version' => $data['version'] ?? '1.0.0',
				'file' => basename($file),
			];
		}//end foreach

		return $templates;
	}//end listTemplates()

	/**
	 * Load a template by its ID.
	 *
	 * @param string $templateId The template identifier
	 *
	 * @return array<string, mixed>|null The full template data or null if not found
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function loadTemplate(string $templateId): ?array {
		$dir = self::TEMPLATES_DIR;

		if (is_dir($dir) === false) {
			return null;
		}

		$files = glob($dir . '/*.json');
		if ($files === false) {
			return null;
		}

		foreach ($files as $file) {
			$content = file_get_contents($file);
			if ($content === false) {
				continue;
			}

			$data = json_decode($content, true);
			if (json_last_error() !== JSON_ERROR_NONE || is_array($data) === false) {
				continue;
			}

			$fileId = $data['id'] ?? pathinfo($file, PATHINFO_FILENAME);
			if ($fileId === $templateId) {
				return $data;
			}
		}

		return null;
	}//end loadTemplate()

	/**
	 * Activate a template by creating OpenRegister objects for the case type and related entities.
	 *
	 * This creates:
	 * - A caseType object
	 * - statusType objects linked to the caseType
	 * - propertyDefinition objects linked to the caseType
	 * - documentType objects linked to the caseType
	 * - decisionType objects linked to the caseType
	 * - roleType objects linked to the caseType
	 *
	 * @param string $templateId The template identifier
	 *
	 * @return array<string, mixed> Result with created object IDs
	 *
	 * @throws \RuntimeException If template not found or OpenRegister unavailable
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function activateTemplate(string $templateId): array {
		$template = $this->loadTemplate(templateId: $templateId);
		if ($template === null) {
			throw new RuntimeException('Template not found: ' . $templateId);
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		if (empty($register) === true) {
			throw new RuntimeException('Dossiq register not configured');
		}

		// Create the case type.
		$caseTypeSchema = $this->settingsService->getConfigValue('case_type_schema');
		$caseTypeData = $template['caseType'] ?? [];
		$caseType = $objectService->saveObject(
			object: $caseTypeData,
			register: $register,
			schema: $caseTypeSchema,
		);
		$caseTypeId = $caseType->getUuid();

		$created = $this->createTemplateEntities(
			objectService: $objectService,
			register: $register,
			template: $template,
			caseTypeId: $caseTypeId,
		);

		$result = [
			'templateId' => $templateId,
			'caseType' => $caseTypeId,
			'statuses' => $created['statuses'],
			'properties' => $created['properties'],
			'documents' => $created['documents'],
			'decisions' => $created['decisions'],
			'roles' => $created['roles'],
		];

		$this->logger->info(
			'Template activated: ' . $templateId . ' -> caseType ' . $caseTypeId,
			['app' => Application::APP_ID]
		);

		return $result;
	}//end activateTemplate()

	/**
	 * Create every entity a template declares alongside its case type, each linked to the
	 * freshly-created caseType id.
	 *
	 * @param object $objectService The OpenRegister object service
	 * @param string $register The Dossiq register slug
	 * @param array<string, mixed> $template The loaded template definition
	 * @param string $caseTypeId UUID of the caseType just created
	 *
	 * @return array<string, array<int, string>> The created object ids, keyed by collection.
	 */
	private function createTemplateEntities(object $objectService, string $register, array $template, string $caseTypeId): array {
		$created = [
			'statuses' => [],
			'properties' => [],
			'documents' => [],
			'decisions' => [],
			'roles' => [],
		];

		// Create status types.
		$statusTypeSchema = $this->settingsService->getConfigValue('status_type_schema');
		foreach (($template['statusTypes'] ?? []) as $statusData) {
			$statusData['caseType'] = $caseTypeId;
			$status = $objectService->saveObject(
				object: $statusData,
				register: $register,
				schema: $statusTypeSchema,
			);
			$created['statuses'][] = $status->getUuid();
		}

		// Create property definitions.
		$propertySchema = $this->settingsService->getConfigValue('property_definition_schema');
		foreach (($template['propertyDefinitions'] ?? []) as $propData) {
			$propData['caseType'] = $caseTypeId;
			$prop = $objectService->saveObject(
				object: $propData,
				register: $register,
				schema: $propertySchema,
			);
			$created['properties'][] = $prop->getUuid();
		}

		// Create document types.
		$docTypeSchema = $this->settingsService->getConfigValue('document_type_schema');
		foreach (($template['documentTypes'] ?? []) as $docData) {
			$docData['caseType'] = $caseTypeId;
			$doc = $objectService->saveObject(
				object: $docData,
				register: $register,
				schema: $docTypeSchema,
			);
			$created['documents'][] = $doc->getUuid();
		}

		// Create decision types.
		$decisionTypeSchema = $this->settingsService->getConfigValue('decision_type_schema');
		foreach (($template['decisionTypes'] ?? []) as $decData) {
			$decData['caseType'] = $caseTypeId;
			$dec = $objectService->saveObject(
				object: $decData,
				register: $register,
				schema: $decisionTypeSchema,
			);
			$created['decisions'][] = $dec->getUuid();
		}

		// Create role types.
		$roleTypeSchema = $this->settingsService->getConfigValue('role_type_schema');
		foreach (($template['roleTypes'] ?? []) as $roleData) {
			$roleData['caseType'] = $caseTypeId;
			$role = $objectService->saveObject(
				object: $roleData,
				register: $register,
				schema: $roleTypeSchema,
			);
			$created['roles'][] = $role->getUuid();
		}

		return $created;
	}//end createTemplateEntities()
}//end class
