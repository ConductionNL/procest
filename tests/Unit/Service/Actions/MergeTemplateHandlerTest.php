<?php

/**
 * Unit tests for MergeTemplateHandler — rendering a template into a case field.
 *
 * The behaviour worth protecting is the WRITE DISCIPLINE: only the target
 * field reaches the stored case, a dry run writes nothing, and a missing
 * target field fails loudly.
 *
 * WHERE THE runAs TESTS WENT. This suite used to assert that the handler
 * wrapped its write in dossiq's FlowRunAsScope (the 'Anonymous' refusal on run
 * f087ae22). That duty moved into the engine: RegistryStepDispatcher executes
 * every contributed node — and the handlers those nodes delegate to — inside
 * `ObjectService::runAs()` as the run's validated acting identity
 * (openregister#3332, proven by its RegistryStepDispatcherRunAsTest). The
 * local wrap is deleted, so asserting it here would re-encode the retired
 * requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Actions;

use OCA\Dossiq\Service\Actions\MergeTemplateHandler;
use OCA\Dossiq\Service\CaseFieldWriter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Service\Actions\MergeTemplateHandler
 *
 * @uses \OCA\Dossiq\Service\CaseFieldWriter
 * @uses \OCA\Dossiq\Service\Actions\ActionResult
 */
class MergeTemplateHandlerTest extends TestCase {

	/**
	 * The case the object service was asked to save, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $saved = null;

	/**
	 * The object service double behind the handler.
	 *
	 * @var object|null
	 */
	private ?object $objectService = null;

	protected function setUp(): void {
		$this->saved = null;

		$saved = &$this->saved;
		$this->objectService = new class($saved) {
			/**
			 * @param array<string, mixed>|null $sink Receives the saved case.
			 */
			public function __construct(private ?array &$sink) {
			}

			/**
			 * @param array<string, mixed> $object   The object to save.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 *
			 * @return array<string, mixed> The saved object.
			 */
			public function saveObject(array $object, string $register, string $schema): array {
				$this->sink = $object;

				return $object;
			}

			/**
			 * PATCH-semantic, like the real seam the handler writes through.
			 *
			 * @param string               $objectId The case id.
			 * @param array<string, mixed> $data     The partial payload.
			 * @param string|null          $register The register.
			 * @param string|null          $schema   The schema.
			 *
			 * @return array<string, mixed> The written fields so far.
			 */
			public function patchObject(string $objectId, array $data, ?string $register = null, ?string $schema = null): array {
				$this->sink = array_merge(($this->sink ?? []), $data);

				return $this->sink;
			}
		};
	}//end setUp()

	/**
	 * A handler over the recording object service.
	 *
	 * @return MergeTemplateHandler The handler under test.
	 */
	private function handler(): MergeTemplateHandler {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($this->objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default): string {
				unset($app, $default);

				return ($key === 'register') ? 'dossiq' : 'case';
			}
		);

		return new MergeTemplateHandler(
			container: $container,
			appConfig: $appConfig,
			caseWriter: new CaseFieldWriter(),
			logger: new NullLogger(),
		);
	}//end handler()

	public function testTheRenderedTemplateIsSavedIntoTheTargetField(): void {
		$result = $this->handler()->handle(
			actionConfig: [
				'type' => 'mergeTemplate',
				'template' => 'Besluit over {{case.title}}',
				'targetField' => 'besluitDocument',
			],
			case: ['id' => 'case-1', 'title' => 'Kapvergunning'],
			transitionContext: []
		);

		self::assertTrue($result->succeeded);
		self::assertSame('Besluit over Kapvergunning', $this->saved['besluitDocument']);
	}//end testTheRenderedTemplateIsSavedIntoTheTargetField()

	public function testADryRunPersistsNothing(): void {
		$result = $this->handler()->handle(
			actionConfig: [
				'type' => 'mergeTemplate',
				'template' => 'Besluit over {{case.title}}',
				'targetField' => 'besluitDocument',
			],
			case: ['id' => 'case-1', 'title' => 'Kapvergunning'],
			transitionContext: ['dryRun' => true]
		);

		self::assertTrue($result->succeeded);
		self::assertSame('Besluit over Kapvergunning', $result->data['rendered']);
		self::assertNull($this->saved, 'A dry run must not write the case.');
	}//end testADryRunPersistsNothing()

	public function testAMissingTargetFieldFailsTheStep(): void {
		$result = $this->handler()->handle(
			actionConfig: ['type' => 'mergeTemplate', 'template' => 'Besluit'],
			case: ['id' => 'case-1'],
			transitionContext: []
		);

		self::assertFalse($result->succeeded);
		self::assertSame('missing_target_field', $result->error);
		self::assertNull($this->saved);
	}//end testAMissingTargetFieldFailsTheStep()
}//end class
