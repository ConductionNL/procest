<?php

/**
 * Workflow Engine Schema Unit Tests
 *
 * Validates that the dossiq_register.json schema configuration is valid
 * and contains all required schema definitions for the workflow engine feature.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests verifying the workflow engine schema registration.
 *
 * The workflow engine feature requires a `workflowTemplate` schema in
 * dossiq_register.json. These tests validate the schema file is well-formed
 * and contains the expected schema definitions.
 */
class WorkflowEngineSchemaTest extends TestCase {

	/**
	 * Path to the register schema file.
	 *
	 * @var string
	 */
	private string $schemaFilePath;

	/**
	 * The decoded register schema data.
	 *
	 * @var array
	 */
	private array $registerData;

	/**
	 * Set up test fixtures — load dossiq_register.json.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->schemaFilePath = __DIR__ . '/../../../lib/Settings/dossiq_register.json';

		$content = file_get_contents($this->schemaFilePath);
		$this->registerData = json_decode($content, true);

	}//end setUp()

	/**
	 * Test that dossiq_register.json exists and is valid JSON.
	 *
	 * @return void
	 */
	public function testRegisterFileExistsAndIsValidJson(): void {
		$this->assertFileExists($this->schemaFilePath, 'dossiq_register.json must exist');
		$this->assertSame(JSON_ERROR_NONE, json_last_error(), 'dossiq_register.json must be valid JSON');
		$this->assertIsArray($this->registerData);

	}//end testRegisterFileExistsAndIsValidJson()

	/**
	 * Test that dossiq_register.json follows OpenAPI structure.
	 *
	 * @return void
	 */
	public function testRegisterFileFollowsOpenApiStructure(): void {
		$this->assertArrayHasKey('openapi', $this->registerData, 'Register must have openapi key');
		$this->assertArrayHasKey('info', $this->registerData, 'Register must have info key');
		$this->assertArrayHasKey('components', $this->registerData, 'Register must have components key');
		$this->assertArrayHasKey('schemas', $this->registerData['components'], 'Register must have components.schemas');

	}//end testRegisterFileFollowsOpenApiStructure()

	/**
	 * Test that the workflowTemplate schema is registered.
	 *
	 * The workflow engine feature adds the `workflowTemplate` schema which
	 * stores process steps, transitions, guards, and automatic actions
	 * per zaaktype.
	 *
	 * @return void
	 */
	public function testWorkflowTemplateSchemaIsRegistered(): void {
		$schemas = $this->registerData['components']['schemas'];

		$this->assertArrayHasKey(
			'workflowTemplate',
			$schemas,
			'workflowTemplate schema must be defined in dossiq_register.json for the workflow engine feature'
		);

	}//end testWorkflowTemplateSchemaIsRegistered()

	/**
	 * Test that the workflowTemplate schema has required properties.
	 *
	 * @return void
	 */
	public function testWorkflowTemplateSchemaHasRequiredProperties(): void {
		$schemas = $this->registerData['components']['schemas'];
		$workflow = $schemas['workflowTemplate'];

		$this->assertArrayHasKey('properties', $workflow, 'workflowTemplate must have properties');

		$properties = $workflow['properties'];

		// Steps and transitions are the core of any workflow definition.
		$this->assertArrayHasKey('steps', $properties, 'workflowTemplate must have steps property');
		$this->assertArrayHasKey('transitions', $properties, 'workflowTemplate must have transitions property');

	}//end testWorkflowTemplateSchemaHasRequiredProperties()

	/**
	 * Test that all core case management schemas are present.
	 *
	 * These schemas must not be accidentally removed when adding new workflow
	 * engine schemas.
	 *
	 * @return void
	 */
	public function testCoreSchemasPresentAfterWorkflowEngineMigration(): void {
		$schemas = $this->registerData['components']['schemas'];
		$required = ['case', 'caseTask', 'caseType', 'statusType', 'roleType', 'workflowTemplate'];

		foreach ($required as $schemaName) {
			$this->assertArrayHasKey(
				$schemaName,
				$schemas,
				"Core schema '{$schemaName}' must be present in dossiq_register.json"
			);
		}

	}//end testCoreSchemasPresentAfterWorkflowEngineMigration()

}//end class
