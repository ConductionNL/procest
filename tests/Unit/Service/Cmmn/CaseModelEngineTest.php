<?php

/**
 * CaseModelEngine runtime tests.
 *
 * Covers plan-item lifecycle (auto-cascade, illegal transitions, stage
 * auto-completion, discretionary disable-on-exit), sentry-driven cascades,
 * discretionary enablement gating, milestone achievement, the single-OR-
 * write-path guarantee, the BPMN/CMMN mutual-refusal guard, and a full
 * end-to-end case-driven run (REQ-CMMN-009).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Cmmn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/cmmn-adaptive-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cmmn;

use OCA\Dossiq\Service\Cmmn\CaseModelEngine;
use OCA\Dossiq\Service\Cmmn\CaseModelLoader;
use OCA\Dossiq\Service\Cmmn\CasePlanRepository;
use OCA\Dossiq\Service\Cmmn\IllegalPlanItemTransitionException;
use OCA\Dossiq\Service\Cmmn\PlanItemCascade;
use OCA\Dossiq\Service\Cmmn\PlanItemStateMachine;
use OCA\Dossiq\Service\Cmmn\PlanItemTransitions;
use OCA\Dossiq\Service\Cmmn\PlanItemTree;
use OCA\Dossiq\Service\Cmmn\SentryEvaluator;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Unit\Service\FakeStoredObject;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A FakeTermijnStore that counts saveObject() invocations, for the
 * single-OR-write-path assertion (REQ-CMMN-006).
 */
class CountingFakeStore extends FakeTermijnStore {

	/**
	 * Number of saveObject() calls made.
	 *
	 * @var int
	 */
	public int $saveCount = 0;

	/**
	 * {@inheritDoc}
	 */
	public function saveObject(
		array $object,
		?array $extend = [],
		string|int|null $register = null,
		string|int|null $schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $silent = false,
		bool $_validation = true,
	): FakeStoredObject {
		$this->saveCount++;
		return parent::saveObject(
			object: $object,
			extend: $extend,
			register: $register,
			schema: $schema,
			uuid: $uuid,
			_rbac: $_rbac,
			_multitenancy: $_multitenancy,
			silent: $silent,
			_validation: $_validation,
		);
	}//end saveObject()
}//end class

/**
 * @covers \OCA\Dossiq\Service\Cmmn\CaseModelEngine
 *
 * @uses \OCA\Dossiq\Service\Cmmn\CaseModelLoader
 * @uses \OCA\Dossiq\Service\Cmmn\CasePlanRepository
 * @uses \OCA\Dossiq\Service\Cmmn\IllegalPlanItemTransitionException
 * @uses \OCA\Dossiq\Service\Cmmn\PlanItemCascade
 * @uses \OCA\Dossiq\Service\Cmmn\PlanItemStateMachine
 * @uses \OCA\Dossiq\Service\Cmmn\PlanItemTransitions
 * @uses \OCA\Dossiq\Service\Cmmn\PlanItemTree
 * @uses \OCA\Dossiq\Service\Cmmn\SentryEvaluator
 */
final class CaseModelEngineTest extends TestCase {

	private const REGISTER = '1';
	private const CASE_SCHEMA = 'case-schema';
	private const TYPE_SCHEMA = 'casetype-schema';
	// CaseModelLoader queries via searchObjects(), which int-casts the
	// schema id into the '@self' block (matching real OpenRegister schema
	// ids, which are numeric) — must be a numeric string here so the fake
	// store's string-keyed lookup still resolves.
	private const MODEL_SCHEMA = '42';

	/**
	 * Build an engine + a settings mock wired to the given store.
	 *
	 * @param CountingFakeStore $store The fake object store.
	 *
	 * @return CaseModelEngine
	 */
	private function engine(CountingFakeStore $store): CaseModelEngine {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => self::REGISTER,
				'case_schema' => self::CASE_SCHEMA,
				'case_type_schema' => self::TYPE_SCHEMA,
				'case_model_schema' => self::MODEL_SCHEMA,
				default => '',
			},
		);

		$loader = new CaseModelLoader($settings, $this->createMock(LoggerInterface::class));

		// The repository, cascade, state machine and tree are real
		// collaborators, not mocks: every assertion in this class is about
		// behaviour they inherited verbatim from CaseModelEngine, and they
		// stay driven entirely by the mocked SettingsService above.
		$transitions = new PlanItemTransitions();
		$tree = new PlanItemTree($transitions);
		$stateMachine = new PlanItemStateMachine($transitions);

		return new CaseModelEngine(
			new CasePlanRepository($settings, $loader, $this->createMock(LoggerInterface::class)),
			new PlanItemCascade($transitions, new SentryEvaluator(), $tree, $stateMachine),
			$stateMachine,
			$tree,
			$transitions,
		);
	}//end engine()

	/**
	 * Seed a CMMN-managed caseType + case + caseModel.
	 *
	 * @param CountingFakeStore $store The fake object store.
	 * @param array<int, array> $planItems Plan-item definitions.
	 * @param string $caseTypeId CaseType id.
	 * @param string $caseId Case id.
	 *
	 * @return void
	 */
	private function seed(CountingFakeStore $store, array $planItems, string $caseTypeId = 'ct-1', string $caseId = 'case-1'): void {
		$store->store[self::TYPE_SCHEMA][$caseTypeId] = ['id' => $caseTypeId, 'handlingModel' => 'cmmn'];
		$store->store[self::CASE_SCHEMA][$caseId] = ['id' => $caseId, 'caseType' => $caseTypeId];
		$store->store[self::MODEL_SCHEMA]['model-1'] = [
			'id' => 'model-1',
			'caseType' => $caseTypeId,
			'lifecycleStatus' => 'published',
			'planItems' => $planItems,
			'caseFileItems' => [],
		];
	}//end seed()

	// ------------------------------------------------------------------
	// REQ-CMMN-002 — plan-item lifecycle
	// ------------------------------------------------------------------

	/**
	 * A mandatory humanTask with no criteria auto-cascades to `active` on
	 * the first read.
	 *
	 * @return void
	 */
	public function testMandatoryHumanTaskAutoCascadesToActive(): void {
		$store = new CountingFakeStore();
		$this->seed($store, [
			['id' => 't1', 'type' => 'humanTask', 'name' => 'Task 1', 'discretionary' => false, 'parentId' => null],
		]);

		$plan = $this->engine($store)->getCasePlan(caseId: 'case-1');
		self::assertSame('active', $plan['items'][0]['state']);
	}//end testMandatoryHumanTaskAutoCascadesToActive()

	/**
	 * Completing an active human task succeeds; completing it again throws.
	 *
	 * @return void
	 */
	public function testCompleteThenIllegalRepeatCompleteThrows(): void {
		$store = new CountingFakeStore();
		$this->seed($store, [
			['id' => 't1', 'type' => 'humanTask', 'name' => 'Task 1', 'discretionary' => false, 'parentId' => null],
		]);

		$engine = $this->engine($store);
		$plan = $engine->completeTask(caseId: 'case-1', itemId: 't1');
		self::assertSame('completed', $plan['items'][0]['state']);

		$this->expectException(IllegalPlanItemTransitionException::class);
		$engine->completeTask(caseId: 'case-1', itemId: 't1');
	}//end testCompleteThenIllegalRepeatCompleteThrows()

	/**
	 * A discretionary item never enabled is disabled once its stage
	 * completes (its only mandatory sibling finished).
	 *
	 * @return void
	 */
	public function testUnplannedDiscretionaryDisabledWhenStageCompletes(): void {
		$store = new CountingFakeStore();
		$this->seed($store, [
			['id' => 'stage-1', 'type' => 'stage', 'name' => 'Stage', 'discretionary' => false, 'parentId' => null],
			['id' => 'mand', 'type' => 'humanTask', 'name' => 'Mandatory', 'discretionary' => false, 'parentId' => 'stage-1'],
			[
				'id' => 'disc', 'type' => 'humanTask', 'name' => 'Discretionary', 'discretionary' => true, 'parentId' => 'stage-1',
				// Entry criteria that will never be satisfied in this test.
				'entryCriteria' => [['id' => 's1', 'ifPart' => ['field' => 'never', 'operator' => 'eq', 'value' => true]]],
			],
		]);

		$engine = $this->engine($store);
		$plan = $engine->completeTask(caseId: 'case-1', itemId: 'mand');

		$byId = [];
		foreach ($plan['items'] as $item) {
			$byId[$item['id']] = $item;
		}

		self::assertSame('completed', $byId['stage-1']['state']);
		self::assertSame('completed', $byId['mand']['state']);
		self::assertSame('disabled', $byId['disc']['state']);
	}//end testUnplannedDiscretionaryDisabledWhenStageCompletes()

	/**
	 * A milestone moves directly `available -> completed`, never through an
	 * intermediate `enabled`/`active` state.
	 *
	 * @return void
	 */
	public function testMilestoneHasNoIntermediateState(): void {
		$store = new CountingFakeStore();
		$this->seed($store, [
			['id' => 't1', 'type' => 'humanTask', 'name' => 'Task', 'discretionary' => false, 'parentId' => null],
			[
				'id' => 'ms1', 'type' => 'milestone', 'name' => 'Milestone', 'discretionary' => false, 'parentId' => null,
				'entryCriteria' => [['id' => 's1', 'onPart' => ['planItem' => 't1', 'standardEvent' => 'complete']]],
			],
		]);

		$engine = $this->engine($store);
		$plan = $engine->completeTask(caseId: 'case-1', itemId: 't1');

		$byId = [];
		foreach ($plan['items'] as $item) {
			$byId[$item['id']] = $item;
		}

		self::assertSame('completed', $byId['ms1']['state']);
		self::assertTrue($plan['milestones']['ms1']['achieved']);
		self::assertNotEmpty($plan['milestones']['ms1']['achievedAt']);
	}//end testMilestoneHasNoIntermediateState()

	// ------------------------------------------------------------------
	// REQ-CMMN-003 — sentries
	// ------------------------------------------------------------------

	/**
	 * A single-condition entry sentry (planItem completion) enables +
	 * auto-activates its dependent mandatory item in the same pass.
	 *
	 * @return void
	 */
	public function testSingleConditionEntrySentryCascades(): void {
		$store = new CountingFakeStore();
		$this->seed($store, [
			['id' => 'a', 'type' => 'humanTask', 'name' => 'A', 'discretionary' => false, 'parentId' => null],
			[
				'id' => 'b', 'type' => 'humanTask', 'name' => 'B', 'discretionary' => false, 'parentId' => null,
				'entryCriteria' => [['id' => 's1', 'onPart' => ['planItem' => 'a', 'standardEvent' => 'complete']]],
			],
		]);

		$engine = $this->engine($store);
		// Before A completes, B is still available (blocked on its entry sentry).
		$plan = $engine->getCasePlan(caseId: 'case-1');
		$byId = fn (array $p): array => array_column($p['items'], null, 'id');
		self::assertSame('available', $byId($plan)['b']['state']);

		$plan = $engine->completeTask(caseId: 'case-1', itemId: 'a');
		self::assertSame('active', $byId($plan)['b']['state']);
	}//end testSingleConditionEntrySentryCascades()

	/**
	 * A multi-part sentry (onPart + ifPart) requires both; a discretionary
	 * item stays available/enabled correctly as the case-file value changes.
	 *
	 * @return void
	 */
	public function testMultiPartSentryGatesDiscretionaryEnablement(): void {
		$store = new CountingFakeStore();
		$this->seed($store, [
			['id' => 'stage-1', 'type' => 'stage', 'name' => 'Stage', 'discretionary' => false, 'parentId' => null],
			[
				'id' => 'disc', 'type' => 'humanTask', 'name' => 'Urgent review', 'discretionary' => true, 'parentId' => 'stage-1',
				'entryCriteria' => [
					[
						'id' => 's1',
						'onPart' => ['caseFileItem' => 'urgent', 'caseFileEvent' => 'set'],
						'ifPart' => ['field' => 'urgent', 'operator' => 'eq', 'value' => true],
					],
				],
			],
		]);

		$engine = $this->engine($store);
		$plan = $engine->signalCaseFileEvent(caseId: 'case-1', updates: ['urgent' => false]);
		$byId = array_column($plan['items'], null, 'id');
		self::assertSame('available', $byId['disc']['state']);
		self::assertSame([], $plan['enableableDiscretionary']);

		$plan = $engine->signalCaseFileEvent(caseId: 'case-1', updates: ['urgent' => true]);
		$byId = array_column($plan['items'], null, 'id');
		self::assertSame('enabled', $byId['disc']['state']);
		self::assertSame(['disc'], $plan['enableableDiscretionary']);
	}//end testMultiPartSentryGatesDiscretionaryEnablement()

	/**
	 * Multiple entry sentries are OR'd — either sibling completing enables
	 * the dependent item.
	 *
	 * @return void
	 */
	public function testMultipleEntrySentriesAreOred(): void {
		$store = new CountingFakeStore();
		$this->seed($store, [
			['id' => 'a', 'type' => 'humanTask', 'name' => 'A', 'discretionary' => false, 'parentId' => null],
			['id' => 'b', 'type' => 'humanTask', 'name' => 'B', 'discretionary' => false, 'parentId' => null],
			[
				'id' => 'c', 'type' => 'humanTask', 'name' => 'C', 'discretionary' => false, 'parentId' => null,
				'entryCriteria' => [
					['id' => 's1', 'onPart' => ['planItem' => 'a', 'standardEvent' => 'complete']],
					['id' => 's2', 'onPart' => ['planItem' => 'b', 'standardEvent' => 'complete']],
				],
			],
		]);

		$engine = $this->engine($store);
		$plan = $engine->completeTask(caseId: 'case-1', itemId: 'b');
		$byId = array_column($plan['items'], null, 'id');
		self::assertSame('active', $byId['c']['state']);
	}//end testMultipleEntrySentriesAreOred()

	/**
	 * An exit sentry force-terminates an active item.
	 *
	 * @return void
	 */
	public function testExitSentryTerminatesActiveItem(): void {
		$store = new CountingFakeStore();
		$this->seed($store, [
			[
				'id' => 't1', 'type' => 'humanTask', 'name' => 'T1', 'discretionary' => false, 'parentId' => null,
				'exitCriteria' => [['id' => 'x1', 'onPart' => ['caseFileItem' => 'withdrawn', 'caseFileEvent' => 'set']]],
			],
		]);

		$engine = $this->engine($store);
		$plan = $engine->getCasePlan(caseId: 'case-1');
		self::assertSame('active', array_column($plan['items'], null, 'id')['t1']['state']);

		$plan = $engine->signalCaseFileEvent(caseId: 'case-1', updates: ['withdrawn' => true]);
		self::assertSame('terminated', array_column($plan['items'], null, 'id')['t1']['state']);
	}//end testExitSentryTerminatesActiveItem()

	// ------------------------------------------------------------------
	// REQ-CMMN-004 — discretionary enablement gating
	// ------------------------------------------------------------------

	/**
	 * A satisfied discretionary item (enabled, active parent) is surfaced
	 * as enable-able; one outside an active stage is not.
	 *
	 * @return void
	 */
	public function testEnableableDiscretionaryGating(): void {
		$store = new CountingFakeStore();
		$this->seed($store, [
			['id' => 'stage-1', 'type' => 'stage', 'name' => 'Stage', 'discretionary' => false, 'parentId' => null],
			['id' => 'mand', 'type' => 'humanTask', 'name' => 'Mandatory', 'discretionary' => false, 'parentId' => 'stage-1'],
			['id' => 'disc', 'type' => 'humanTask', 'name' => 'Optional', 'discretionary' => true, 'parentId' => 'stage-1'],
		]);

		$engine = $this->engine($store);
		$ids = $engine->getEnableableDiscretionaryItems(caseId: 'case-1');
		self::assertSame(['disc'], $ids);
	}//end testEnableableDiscretionaryGating()

	/**
	 * A discretionary item is NOT enable-able while its parent stage has
	 * not started.
	 *
	 * @return void
	 */
	public function testDiscretionaryNotEnableableOutsideActiveStage(): void {
		$store = new CountingFakeStore();
		$this->seed($store, [
			[
				'id' => 'stage-1', 'type' => 'stage', 'name' => 'Stage', 'discretionary' => false, 'parentId' => null,
				// Stage never activates: entry criteria never satisfied.
				'entryCriteria' => [['id' => 's1', 'ifPart' => ['field' => 'never', 'operator' => 'eq', 'value' => true]]],
			],
			['id' => 'disc', 'type' => 'humanTask', 'name' => 'Optional', 'discretionary' => true, 'parentId' => 'stage-1'],
		]);

		$engine = $this->engine($store);
		self::assertSame([], $engine->getEnableableDiscretionaryItems(caseId: 'case-1'));
	}//end testDiscretionaryNotEnableableOutsideActiveStage()

	/**
	 * Enabling a mandatory item is rejected.
	 *
	 * @return void
	 */
	public function testEnablingMandatoryItemRejected(): void {
		$store = new CountingFakeStore();
		$this->seed($store, [
			['id' => 't1', 'type' => 'humanTask', 'name' => 'Mandatory', 'discretionary' => false, 'parentId' => null],
		]);

		$engine = $this->engine($store);
		$this->expectException(IllegalPlanItemTransitionException::class);
		$engine->enableDiscretionaryItem(caseId: 'case-1', itemId: 't1');
	}//end testEnablingMandatoryItemRejected()

	// ------------------------------------------------------------------
	// REQ-CMMN-006 — single OR write path
	// ------------------------------------------------------------------

	/**
	 * A single signal that trips two sentries (enabling one item, achieving
	 * a milestone) issues exactly one `saveObject()` call.
	 *
	 * @return void
	 */
	public function testSingleMutationIssuesExactlyOneSave(): void {
		$store = new CountingFakeStore();
		$this->seed($store, [
			[
				'id' => 'disc', 'type' => 'humanTask', 'name' => 'Optional', 'discretionary' => true, 'parentId' => null,
				'entryCriteria' => [['id' => 's1', 'onPart' => ['caseFileItem' => 'go', 'caseFileEvent' => 'set']]],
			],
			[
				'id' => 'ms', 'type' => 'milestone', 'name' => 'M', 'discretionary' => false, 'parentId' => null,
				'entryCriteria' => [['id' => 's2', 'onPart' => ['caseFileItem' => 'go', 'caseFileEvent' => 'set']]],
			],
		]);

		$engine = $this->engine($store);
		// Prime the case (getCasePlan initialises + saves once).
		$engine->getCasePlan(caseId: 'case-1');
		$store->saveCount = 0;

		$plan = $engine->signalCaseFileEvent(caseId: 'case-1', updates: ['go' => true]);
		self::assertSame('enabled', array_column($plan['items'], null, 'id')['disc']['state']);
		self::assertTrue($plan['milestones']['ms']['achieved']);
		self::assertSame(1, $store->saveCount);
	}//end testSingleMutationIssuesExactlyOneSave()

	// ------------------------------------------------------------------
	// REQ-CMMN-008 — BPMN/CMMN coexistence guard
	// ------------------------------------------------------------------

	/**
	 * The engine refuses to operate on a BPMN-managed case and makes no write.
	 *
	 * @return void
	 */
	public function testEngineRefusesBpmnManagedCase(): void {
		$store = new CountingFakeStore();
		$store->store[self::TYPE_SCHEMA]['ct-bpmn'] = ['id' => 'ct-bpmn', 'handlingModel' => 'bpmn'];
		$store->store[self::CASE_SCHEMA]['case-bpmn'] = ['id' => 'case-bpmn', 'caseType' => 'ct-bpmn'];

		$engine = $this->engine($store);

		try {
			$engine->getCasePlan(caseId: 'case-bpmn');
			self::fail('expected RuntimeException');
		} catch (RuntimeException $e) {
			self::assertSame('case_not_cmmn_managed', $e->getMessage());
		}

		self::assertSame(0, $store->saveCount);
	}//end testEngineRefusesBpmnManagedCase()

	/**
	 * A caseType with no `handlingModel` set (legacy/unset) defaults to
	 * BPMN and is likewise refused by the CMMN engine.
	 *
	 * @return void
	 */
	public function testUnsetHandlingModelDefaultsToBpmnAndIsRefused(): void {
		$store = new CountingFakeStore();
		$store->store[self::TYPE_SCHEMA]['ct-legacy'] = ['id' => 'ct-legacy'];
		$store->store[self::CASE_SCHEMA]['case-legacy'] = ['id' => 'case-legacy', 'caseType' => 'ct-legacy'];

		$engine = $this->engine($store);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('case_not_cmmn_managed');
		$engine->getCasePlan(caseId: 'case-legacy');
	}//end testUnsetHandlingModelDefaultsToBpmnAndIsRefused()

	// ------------------------------------------------------------------
	// REQ-CMMN-009 — end-to-end case-driven run
	// ------------------------------------------------------------------

	/**
	 * End-to-end: root stage activates, a discretionary item is enabled via
	 * a sentry trip, completed by the worker, and a dependent milestone is
	 * achieved — proving the engine drives a real case, not orphaned.
	 *
	 * @return void
	 */
	public function testEndToEndAdaptiveCaseRun(): void {
		$store = new CountingFakeStore();
		$this->seed($store, [
			['id' => 'intake', 'type' => 'stage', 'name' => 'Intake', 'discretionary' => false, 'parentId' => null],
			['id' => 'register', 'type' => 'humanTask', 'name' => 'Register report', 'discretionary' => false, 'parentId' => 'intake'],
			[
				'id' => 'urgentReview', 'type' => 'humanTask', 'name' => 'Urgent review', 'discretionary' => true, 'parentId' => 'intake',
				'entryCriteria' => [
					[
						'id' => 's1',
						'onPart' => ['caseFileItem' => 'urgent', 'caseFileEvent' => 'set'],
						'ifPart' => ['field' => 'urgent', 'operator' => 'eq', 'value' => true],
					],
				],
			],
			[
				'id' => 'reviewDone', 'type' => 'milestone', 'name' => 'Review done', 'discretionary' => false, 'parentId' => 'intake',
				'entryCriteria' => [['id' => 's2', 'onPart' => ['planItem' => 'urgentReview', 'standardEvent' => 'complete']]],
			],
		]);

		$engine = $this->engine($store);

		// 1. Case starts: root stage activates, mandatory task active.
		$plan = $engine->getCasePlan(caseId: 'case-1');
		$byId = array_column($plan['items'], null, 'id');
		self::assertSame('active', $byId['intake']['state']);
		self::assertSame('active', $byId['register']['state']);
		self::assertSame('available', $byId['urgentReview']['state']);

		// 2. Trip the sentry: discretionary item becomes enable-able.
		$plan = $engine->signalCaseFileEvent(caseId: 'case-1', updates: ['urgent' => true]);
		self::assertSame(['urgentReview'], $plan['enableableDiscretionary']);

		// 3. Worker enables the discretionary item.
		$plan = $engine->enableDiscretionaryItem(caseId: 'case-1', itemId: 'urgentReview');
		self::assertSame('active', array_column($plan['items'], null, 'id')['urgentReview']['state']);

		// 4. Worker completes it.
		$plan = $engine->completeTask(caseId: 'case-1', itemId: 'urgentReview');
		self::assertSame('completed', array_column($plan['items'], null, 'id')['urgentReview']['state']);

		// 5. Milestone achieved as a consequence, all via casePlanState.
		self::assertSame('completed', array_column($plan['items'], null, 'id')['reviewDone']['state']);
		self::assertTrue($plan['milestones']['reviewDone']['achieved']);

		// Runtime state lives entirely in the case's casePlanState field.
		$savedCase = $store->store[self::CASE_SCHEMA]['case-1'];
		self::assertIsString($savedCase['casePlanState']);
		$decoded = json_decode($savedCase['casePlanState'], true);
		self::assertSame('completed', $decoded['planItemStates']['reviewDone']);
	}//end testEndToEndAdaptiveCaseRun()
}//end class
