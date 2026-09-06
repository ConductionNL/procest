<?php

/**
 * WorkflowDefinitionRepositoryUpdateTest.
 *
 * One question: what does an update actually send to OpenRegister?
 *
 * It used to send only the properties that changed. OpenRegister validates the
 * payload it is handed as the complete object and stores exactly that, and
 * `workflowTemplate` requires `title` and `caseType`, so every publish was
 * refused with "The required properties (title, caseType) are missing" and the
 * repository turned the throw into a null. Three VTH templates were created as
 * drafts and left at `lifecycleStatus=draft, isActive=false` on every install.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Workflow
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Workflow;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Workflow\WorkflowDefinitionRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the read-modify-write an update has to be.
 *
 * @coversNothing
 */
class WorkflowDefinitionRepositoryUpdateTest extends TestCase {

	private SettingsService&MockObject $settings;

	/**
	 * Wire the settings service the repository resolves its context through.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => '17',
				'workflow_template_schema' => '47',
				'case_type_schema' => '22',
				default => '',
			}
		);
	}

	/**
	 * An ObjectService that hands back one stored row and records what is written.
	 *
	 * @param array<string, mixed>|null $stored The row `find()` answers with, or null to throw.
	 *
	 * @return object The fake.
	 */
	private function objectService(?array $stored): object {
		return new class($stored) {
			/**
			 * The payloads handed to saveObject(), in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $written = [];

			/**
			 * @param array<string, mixed>|null $stored The row find() answers with.
			 */
			public function __construct(private readonly ?array $stored) {
			}

			/**
			 * @param string $id The uuid.
			 * @param string|null $register The register.
			 * @param string|null $schema The schema.
			 *
			 * @return array<string, mixed> The stored row.
			 */
			public function find(string $id, ?string $register = null, ?string $schema = null): array {
				if ($this->stored === null) {
					throw new \RuntimeException('Object not found: ' . $id);
				}

				return $this->stored;
			}

			/**
			 * @param array<string, mixed> $object The payload.
			 * @param string|null $register The register.
			 * @param string|null $schema The schema.
			 * @param string|null $uuid The row being updated.
			 *
			 * @return array<string, mixed> The written row.
			 */
			public function saveObject(
				array $object,
				?string $register = null,
				?string $schema = null,
				?string $uuid = null,
			): array {
				$this->written[] = $object;

				return array_merge($object, ['id' => ($uuid ?? 'created')]);
			}
		};
	}

	/**
	 * Build the repository against one fake ObjectService.
	 *
	 * @param object $objectService The fake.
	 *
	 * @return WorkflowDefinitionRepository The repository.
	 */
	private function repository(object $objectService): WorkflowDefinitionRepository {
		$this->settings->method('getObjectService')->willReturn($objectService);

		return new WorkflowDefinitionRepository(
			$this->settings,
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * The row as it is stored, the shape jsonSerialize() answers with.
	 *
	 * @return array<string, mixed> The stored definition.
	 */
	private function storedDefinition(): array {
		return [
			'@self' => ['id' => 'def-1', 'schema' => 'workflowTemplate'],
			'id' => 'def-1',
			'title' => 'Toezichtbezoek',
			'caseType' => 'case-1',
			'version' => 1,
			'isActive' => false,
			'isDraft' => true,
			'lifecycleStatus' => 'draft',
			'steps' => '[{"slug":"gepland"}]',
			'transitions' => '[]',
		];
	}

	/**
	 * 🔴 THE PUBLISH THAT NEVER LANDED. A three-key payload is a three-key
	 * object, so the required properties went missing and the write was
	 * refused. The update carries the whole row now.
	 *
	 * @return void
	 */
	public function testAnUpdateCarriesTheWholeRow(): void {
		$objectService = $this->objectService($this->storedDefinition());

		$saved = $this->repository($objectService)->save(
			payload: ['lifecycleStatus' => 'published', 'isActive' => true, 'isDraft' => false],
			uuid: 'def-1'
		);

		$this->assertNotNull($saved);
		$this->assertCount(1, $objectService->written);

		$written = $objectService->written[0];
		$this->assertSame('published', $written['lifecycleStatus']);
		$this->assertTrue($written['isActive']);
		$this->assertSame('Toezichtbezoek', $written['title'], 'The title must survive the update.');
		$this->assertSame('case-1', $written['caseType'], 'The caseType must survive the update.');
		$this->assertSame('[{"slug":"gepland"}]', $written['steps'], 'The steps must survive the update.');
	}

	/**
	 * The metadata envelope is OpenRegister's, not ours, so it is not written back.
	 *
	 * @return void
	 */
	public function testAnUpdateDoesNotWriteTheMetadataBack(): void {
		$objectService = $this->objectService($this->storedDefinition());

		$this->repository($objectService)->save(payload: ['isActive' => true], uuid: 'def-1');

		$written = $objectService->written[0];
		$this->assertArrayNotHasKey('@self', $written);
		$this->assertArrayNotHasKey('id', $written);
	}

	/**
	 * A row that cannot be read is not overwritten with the fragment.
	 *
	 * Writing the change on its own would replace a whole workflow with the
	 * two keys that happened to change, which is worse than not writing.
	 *
	 * @return void
	 */
	public function testAnUnreadableRowIsNotOverwritten(): void {
		$objectService = $this->objectService(null);

		$saved = $this->repository($objectService)->save(
			payload: ['lifecycleStatus' => 'published'],
			uuid: 'def-1'
		);

		$this->assertNull($saved);
		$this->assertSame([], $objectService->written, 'Nothing may be written when the row cannot be read.');
	}

	/**
	 * Creating a definition still sends exactly what the caller built.
	 *
	 * @return void
	 */
	public function testACreateIsNotMergedWithAnything(): void {
		$objectService = $this->objectService($this->storedDefinition());

		$this->repository($objectService)->save(
			payload: ['title' => 'Nieuw', 'caseType' => 'case-2', 'lifecycleStatus' => 'draft']
		);

		$this->assertSame(
			['title' => 'Nieuw', 'caseType' => 'case-2', 'lifecycleStatus' => 'draft'],
			$objectService->written[0]
		);
	}

	/**
	 * The caseType pin travels on the whole case type, for the same reason.
	 *
	 * `caseType` requires a title, so a one-key pin was refused and the catch
	 * around it swallowed the refusal.
	 *
	 * @return void
	 */
	public function testThePinCarriesTheWholeCaseType(): void {
		$objectService = $this->objectService(
			[
				'@self' => ['id' => 'case-1'],
				'id' => 'case-1',
				'title' => 'Toezichtzaak Bouw',
				'description' => 'Toezicht op bouwactiviteiten.',
			]
		);

		$this->repository($objectService)->pinWorkflowDefinition(
			caseTypeId: 'case-1',
			definitionId: 'def-1'
		);

		$this->assertCount(1, $objectService->written);
		$this->assertSame(
			['title' => 'Toezichtzaak Bouw', 'description' => 'Toezicht op bouwactiviteiten.', 'workflowDefinition' => 'def-1'],
			$objectService->written[0]
		);
	}
}
