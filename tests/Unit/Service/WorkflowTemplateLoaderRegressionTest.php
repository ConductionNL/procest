<?php

/**
 * WorkflowTemplateLoader Phase-0 Regression Tests
 *
 * Locks the Phase-0 fix where getActiveTemplate() queries OpenRegister via the
 * real `searchObjects(['@self' => [...], <field> => ...])` API instead of the
 * non-existent `findObjects()` method that previously broke every lookup.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Workflow\WorkflowDefinitionRepository;
use OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard;
use OCA\Dossiq\Service\WorkflowTemplateLoader;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Minimal OpenRegister ObjectService shape used by WorkflowTemplateLoader.
 *
 * Declared as an interface so `createMock()` produces a mock whose
 * `searchObjects()` signature accepts the single array query the production
 * code passes. A `\stdClass` add-methods mock would reject the call. The
 * absence of any `findObjects()` method here is deliberate: it documents that
 * the real OpenRegister ObjectService never exposed one.
 */
interface WorkflowTemplateObjectServiceStub {
	public function searchObjects(array $query): array;
}//end interface

/**
 * Regression tests for WorkflowTemplateLoader::getActiveTemplate().
 *
 * @covers \OCA\Dossiq\Service\WorkflowTemplateLoader
 * @uses \OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard
 */
class WorkflowTemplateLoaderRegressionTest extends TestCase {

	/**
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The loader under test.
	 *
	 * @var WorkflowTemplateLoader
	 */
	private WorkflowTemplateLoader $loader;

	/**
	 * Set up the test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$repository = $this->createMock(WorkflowDefinitionRepository::class);

		$this->loader = new WorkflowTemplateLoader(
			$this->settingsService,
			$repository,
			new WorkflowLifecycleGuard($repository, $this->logger),
			$this->logger,
		);

	}//end setUp()

	/**
	 * Configure the SettingsService mock to return register + schema IDs.
	 *
	 * @param object $objectService The ObjectService mock to hand back.
	 *
	 * @return void
	 */
	private function withObjectService(object $objectService): void {
		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturnMap(
			[
				['register', '', '7'],
				['workflow_template_schema', '', '42'],
			]
		);

	}//end withObjectService()

	/**
	 * getActiveTemplate() must call searchObjects() with the @self register/schema
	 * context and the object-level caseType/isActive equality filters — the exact
	 * Phase-0 query shape that replaced the broken findObjects() call.
	 *
	 * @return void
	 */
	public function testGetActiveTemplateUsesSearchObjectsWithSelfBlock(): void {
		$objectService = $this->createMock(WorkflowTemplateObjectServiceStub::class);
		$this->withObjectService($objectService);

		$objectService->expects($this->once())
			->method('searchObjects')
			->with(
				$this->callback(
					static function (array $query): bool {
						return ($query['@self']['register'] ?? null) === 7
							&& ($query['@self']['schema'] ?? null) === 42
							&& ($query['caseType'] ?? null) === 'ct-123'
							&& ($query['isActive'] ?? null) === true;
					}
				)
			)
			->willReturn(
				[
					[
						'id' => 'tmpl-1',
						'caseType' => 'ct-123',
						'transitions' => '[{"id":"t1"}]',
						'steps' => '[{"id":"s1"}]',
					],
				]
			);

		$template = $this->loader->getActiveTemplate('ct-123');

		$this->assertIsArray($template);
		$this->assertSame('tmpl-1', $template['id']);
		// JSON string fields are decoded into arrays.
		$this->assertSame([['id' => 't1']], $template['transitions']);
		$this->assertSame([['id' => 's1']], $template['steps']);

	}//end testGetActiveTemplateUsesSearchObjectsWithSelfBlock()

	/**
	 * An empty searchObjects() result yields null (confirmed miss) and is cached.
	 *
	 * @return void
	 */
	public function testGetActiveTemplateReturnsNullWhenNoMatch(): void {
		$objectService = $this->createMock(WorkflowTemplateObjectServiceStub::class);
		$this->withObjectService($objectService);

		$objectService->expects($this->once())
			->method('searchObjects')
			->willReturn([]);

		$this->assertNull($this->loader->getActiveTemplate('ct-empty'));
		// Second call must be served from cache — searchObjects() once() proves it.
		$this->assertNull($this->loader->getActiveTemplate('ct-empty'));

	}//end testGetActiveTemplateReturnsNullWhenNoMatch()

	/**
	 * A throwing searchObjects() is caught and downgraded to null (no fatal).
	 *
	 * @return void
	 */
	public function testGetActiveTemplateSwallowsSearchObjectsFailure(): void {
		$objectService = $this->createMock(WorkflowTemplateObjectServiceStub::class);
		$this->withObjectService($objectService);

		$objectService->method('searchObjects')
			->willThrowException(new \RuntimeException('boom'));

		$this->assertNull($this->loader->getActiveTemplate('ct-err'));

	}//end testGetActiveTemplateSwallowsSearchObjectsFailure()

	/**
	 * No ObjectService (OpenRegister absent) returns null without querying.
	 *
	 * @return void
	 */
	public function testGetActiveTemplateReturnsNullWithoutObjectService(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$this->assertNull($this->loader->getActiveTemplate('ct-x'));

	}//end testGetActiveTemplateReturnsNullWithoutObjectService()
}//end class
