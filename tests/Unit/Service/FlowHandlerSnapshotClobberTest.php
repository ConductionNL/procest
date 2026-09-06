<?php

/**
 * A flow handler's save must not clobber what another writer stored after the snapshot.
 *
 * THE CLASS UNDER TEST IS A DEFECT CLASS, not one handler. Every flow handler
 * receives the case as a snapshot (the flow item's json, taken when the token
 * reached the step) and used to save that whole snapshot back. `saveObject()`
 * is PUT-semantic, so the save erased everything that landed on the stored case
 * after the snapshot was taken. Measured live on the closure rig (case
 * a53cfc92/dc16d6dd, audits 512→515 and 725→728, same second): the document
 * step wrote `besluitDocument` to storage, and the status step one hop later,
 * holding the older snapshot, full-saved it away again.
 *
 * So every case-saving handler gets the same test: seed a store, let "another
 * writer" add a field the snapshot does not carry, run the handler with the
 * stale snapshot, and require BOTH the handler's own field and the other
 * writer's field on the stored case afterwards.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseFieldWriter;
use OCA\Dossiq\Service\Dmn\DecisionTableService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Actions\MergeTemplateHandler;
use OCA\Dossiq\Service\Transitions\EvaluateDecisionHandler;
use OCA\Dossiq\Service\Transitions\SetFieldHandler;
use OCA\Dossiq\Service\Transitions\SetStatusHandler;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

class FlowHandlerSnapshotClobberTest extends TestCase {

	/**
	 * The stale snapshot every handler is handed.
	 *
	 * It predates the other writer's work, so it carries NO besluitDocument —
	 * exactly the state a flow item holds one step after the document was made.
	 *
	 * @var array<string, mixed>
	 */
	private const SNAPSHOT = [
		'id' => 'case-1',
		'caseType' => 'ct-1',
		'title' => 'Dakkapel Kerkstraat 14',
	];

	/**
	 * What another writer stored AFTER the snapshot was taken.
	 *
	 * @var array<string, mixed>
	 */
	private const LATER_WRITE = [
		'besluitDocument' => 'Besluit op de aanvraag Dakkapel Kerkstraat 14',
	];

	/**
	 * An object service double over one stored case.
	 *
	 * It models the REAL service's write semantics, because a softer fake
	 * cannot fail this suite: `saveObject()` replaces the stored case with the
	 * payload (PUT — an absent property is gone), `patchObject()` merges the
	 * payload onto the stored case (the fleet's PATCH seam), and `find()`
	 * answers the current stored case.
	 *
	 * @param array<string, mixed> $stored The initial stored case.
	 *
	 * @return object The double; read `$double->stored` for the outcome.
	 */
	private function storeWith(array $stored): object {
		return new class($stored) {

			/**
			 * @param array<string, mixed> $stored The stored case.
			 */
			public function __construct(public array $stored) {
			}

			/**
			 * PUT-semantic, like the real one: the payload IS the new row.
			 *
			 * @param array<string, mixed> $object   The full payload.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 *
			 * @return ObjectEntity The saved entity.
			 */
			public function saveObject(array $object, string $register, string $schema): ObjectEntity {
				$this->stored = $object;

				return $this->entity();
			}

			/**
			 * PATCH-semantic, like the real one: the payload merges onto the row.
			 *
			 * @param string               $objectId The case id.
			 * @param array<string, mixed> $data     The partial payload.
			 * @param string|null          $register The register.
			 * @param string|null          $schema   The schema.
			 *
			 * @return ObjectEntity The patched entity.
			 */
			public function patchObject(string $objectId, array $data, ?string $register = null, ?string $schema = null): ObjectEntity {
				foreach ($data as $field => $value) {
					$this->stored[$field] = $value;
				}

				return $this->entity();
			}

			/**
			 * @param int|string  $id       The case id.
			 * @param array|null  $_extend  Unused.
			 * @param bool        $files    Unused.
			 * @param string|null $register The register.
			 * @param string|null $schema   The schema.
			 *
			 * @return ObjectEntity The stored entity.
			 */
			public function find(int|string $id, ?array $_extend = [], bool $files = false, ?string $register = null, ?string $schema = null): ObjectEntity {
				return $this->entity();
			}

			/**
			 * @return ObjectEntity The stored case as an entity.
			 */
			private function entity(): ObjectEntity {
				$entity = new ObjectEntity();
				$entity->setUuid((string) ($this->stored['id'] ?? 'case-1'));
				$entity->setObject($this->stored);

				return $entity;
			}
		};
	}//end storeWith()

	/**
	 * Settings wired to this object service and the usual register/schema.
	 *
	 * @param object $objectService The object service double.
	 *
	 * @return SettingsService The settings double.
	 */
	private function settingsOver(object $objectService): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'register') ? 'dossiq' : 'case'
		);

		return $settings;
	}//end settingsOver()

	/**
	 * Both fields must be on the stored case afterwards.
	 *
	 * @param object               $store The object service double.
	 * @param array<string, mixed> $own   The handler's own expected fields.
	 *
	 * @return void
	 */
	private function assertNothingWasClobbered(object $store, array $own): void {
		foreach (self::LATER_WRITE as $field => $value) {
			self::assertSame(
				$value,
				($store->stored[$field] ?? null),
				sprintf(
					'The handler\'s save erased "%s", which another writer stored after the snapshot was taken.',
					$field
				)
			);
		}

		foreach ($own as $field => $value) {
			self::assertSame($value, ($store->stored[$field] ?? null), sprintf('The handler\'s own field "%s" must be stored.', $field));
		}
	}//end assertNothingWasClobbered()

	/**
	 * 🔴 The live defect: setStatus one hop after the document step.
	 */
	public function testSetStatusPreservesAFieldWrittenAfterTheSnapshot(): void {
		$store = $this->storeWith(array_merge(self::SNAPSHOT, self::LATER_WRITE));

		$lookup = $this->createMock(StatusTypeLookup::class);
		$lookup->method('idForName')->willReturn('status-uuid-9');

		$handler = new SetStatusHandler(
			$this->settingsOver($store),
			$lookup,
			new CaseFieldWriter(),
			new NullLogger()
		);

		$result = $handler->handle(
			actionConfig: ['type' => 'setStatus', 'status' => 'Afgehandeld'],
			case: self::SNAPSHOT,
			transitionContext: []
		);

		self::assertTrue($result->succeeded);
		$this->assertNothingWasClobbered($store, ['status' => 'status-uuid-9']);
	}//end testSetStatusPreservesAFieldWrittenAfterTheSnapshot()

	public function testSetFieldPreservesAFieldWrittenAfterTheSnapshot(): void {
		$store = $this->storeWith(array_merge(self::SNAPSHOT, self::LATER_WRITE));

		$handler = new SetFieldHandler(
			$this->settingsOver($store),
			new CaseFieldWriter(),
			new NullLogger()
		);

		$result = $handler->handle(
			actionConfig: ['type' => 'setField', 'field' => 'result', 'value' => 'toegekend'],
			case: self::SNAPSHOT,
			transitionContext: []
		);

		self::assertTrue($result->succeeded);
		$this->assertNothingWasClobbered($store, ['result' => 'toegekend']);
	}//end testSetFieldPreservesAFieldWrittenAfterTheSnapshot()

	public function testEvaluateDecisionPreservesAFieldWrittenAfterTheSnapshot(): void {
		$store = $this->storeWith(array_merge(self::SNAPSHOT, self::LATER_WRITE));

		$tableService = $this->createMock(DecisionTableService::class);
		$tableService->method('findByKey')->willReturn(
			[
				'key' => 'closing-tier',
				'hitPolicy' => 'UNIQUE',
				'inputs' => [['name' => 'title', 'type' => 'string']],
				'outputs' => [['name' => 'tier', 'type' => 'string']],
				'rules' => [],
			]
		);

		$engine = $this->createMock(DecisionTableEvaluator::class);
		$engine->method('evaluate')->willReturn(
			['outputs' => ['tier' => 'gold'], 'matchedRuleIds' => ['r1'], 'hitPolicy' => 'UNIQUE']
		);

		$handler = new EvaluateDecisionHandler(
			tableService: $tableService,
			engine: $engine,
			settingsService: $this->settingsOver($store),
			caseWriter: new CaseFieldWriter(),
			logger: new NullLogger(),
		);

		$result = $handler->handle(
			actionConfig: ['type' => 'evaluateDecision', 'decisionKey' => 'closing-tier'],
			case: self::SNAPSHOT,
			transitionContext: []
		);

		self::assertTrue($result->succeeded);
		$this->assertNothingWasClobbered($store, ['tier' => 'gold']);
	}//end testEvaluateDecisionPreservesAFieldWrittenAfterTheSnapshot()

	public function testMergeTemplatePreservesAFieldWrittenAfterTheSnapshot(): void {
		// Here the OTHER writer's field is the status: the mirror image of the
		// live defect, same mechanism.
		$later = ['status' => 'status-uuid-9'];
		$store = $this->storeWith(array_merge(self::SNAPSHOT, $later));

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($store);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default): string {
				unset($app, $default);

				return ($key === 'register') ? 'dossiq' : 'case';
			}
		);

		$handler = new MergeTemplateHandler(
			container: $container,
			appConfig: $appConfig,
			caseWriter: new CaseFieldWriter(),
			logger: new NullLogger(),
		);

		$result = $handler->handle(
			actionConfig: [
				'type' => 'mergeTemplate',
				'template' => 'Besluit over {{case.title}}',
				'targetField' => 'besluitDocument',
			],
			case: self::SNAPSHOT,
			transitionContext: []
		);

		self::assertTrue($result->succeeded);

		foreach ($later as $field => $value) {
			self::assertSame(
				$value,
				($store->stored[$field] ?? null),
				sprintf(
					'The handler\'s save erased "%s", which another writer stored after the snapshot was taken.',
					$field
				)
			);
		}

		self::assertSame('Besluit over Dakkapel Kerkstraat 14', ($store->stored['besluitDocument'] ?? null));
	}//end testMergeTemplatePreservesAFieldWrittenAfterTheSnapshot()
}//end class
