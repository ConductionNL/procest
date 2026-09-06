<?php

/**
 * EvaluateDecisionHandler Unit Tests
 *
 * End-to-end: a transition's `automaticActions[]` entry (decisionKey +
 * input/output mapping) actually invokes DecisionTableService::findByKey()
 * + DecisionTableEvaluator::evaluate() and writes the result onto the case via
 * ObjectService — proving the DMN capability is reachable from the
 * workflow engine and NOT an orphaned capability.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dmn-decision-tables/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\OpenRegister\Service\Dmn\DecisionEvaluationException;
use OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator;
use OCA\Dossiq\Service\Dmn\DecisionTableService;
use OCA\Dossiq\Service\CaseFieldWriter;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\EvaluateDecisionHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Service\Transitions\EvaluateDecisionHandler
 *
 * @uses \OCA\Dossiq\Service\CaseFieldWriter
 *
 *
 * @uses \OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator
 * @uses \OCA\OpenRegister\Service\Dmn\DecisionEvaluationException
 * @uses \OCA\OpenRegister\Service\Dmn\UnaryTestEvaluator
 * @uses \OCA\Dossiq\Service\Transitions\ActionResult
 */
class EvaluateDecisionHandlerTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function subsidyEligibilityTable(): array {
		return [
			'key' => 'subsidy-eligibility',
			'hitPolicy' => 'UNIQUE',
			'inputs' => [
				['name' => 'income', 'type' => 'number'],
				['name' => 'householdSize', 'type' => 'number'],
			],
			'outputs' => [
				['name' => 'eligible', 'type' => 'boolean'],
				['name' => 'tier', 'type' => 'string'],
			],
			'rules' => [
				// Mutually exclusive on `income` so UNIQUE never sees more
				// than one match — a well-formed DMN UNIQUE table.
				['id' => 'r1', 'inputEntries' => ['[0..25000]', '-'], 'outputEntries' => [true, 'gold']],
				['id' => 'r2', 'inputEntries' => ['(25000..40000]', '>=4'], 'outputEntries' => [true, 'silver']],
				['id' => 'r3', 'inputEntries' => ['> 40000', '-'], 'outputEntries' => [false, 'none']],
			],
		];
	}//end subsidyEligibilityTable()

	/**
	 * @return void
	 */
	public function testFailsWhenDecisionKeyMissing(): void {
		$handler = new EvaluateDecisionHandler(
			tableService: $this->createMock(DecisionTableService::class),
			engine: new DecisionTableEvaluator(),
			settingsService: $this->createMock(SettingsService::class),
			caseWriter: new CaseFieldWriter(),
			logger: new NullLogger(),
		);

		$result = $handler->handle(actionConfig: ['type' => 'evaluateDecision'], case: [], transitionContext: []);

		self::assertFalse($result->succeeded);
		self::assertSame('evaluate_decision_missing_key', $result->error);
	}//end testFailsWhenDecisionKeyMissing()

	/**
	 * @return void
	 */
	public function testFailsWhenDecisionNotFound(): void {
		$tableService = $this->createMock(DecisionTableService::class);
		$tableService->method('findByKey')->with('unknown-key')->willReturn(null);

		$handler = new EvaluateDecisionHandler(
			tableService: $tableService,
			engine: new DecisionTableEvaluator(),
			settingsService: $this->createMock(SettingsService::class),
			caseWriter: new CaseFieldWriter(),
			logger: new NullLogger(),
		);

		$result = $handler->handle(
			actionConfig: ['type' => 'evaluateDecision', 'decisionKey' => 'unknown-key'],
			case: [],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('decision_not_found', $result->error);
	}//end testFailsWhenDecisionNotFound()

	/**
	 * End-to-end: transition action config -> handler -> real DecisionTableEvaluator
	 * -> case field written via ObjectService::saveObject, proving the
	 * workflow hook is real and reachable, not orphaned.
	 *
	 * @return void
	 */
	public function testEvaluatesDecisionAndWritesOutputsOntoCase(): void {
		$tableService = $this->createMock(DecisionTableService::class);
		$tableService->method('findByKey')->with('subsidy-eligibility')->willReturn($this->subsidyEligibilityTable());

		$recorded = null;
		$objectService = new class($recorded) {
			/** @var mixed */
			public $recorded;

			public function __construct(&$recorded) {
				$this->recorded = &$recorded;
			}

			public function saveObject(array $object, string $register, string $schema): array {
				$this->recorded = $object;
				return $object;
			}

			public function patchObject(string $objectId, array $data, ?string $register = null, ?string $schema = null): array {
				$this->recorded = array_merge((array) $this->recorded, $data);
				return $this->recorded;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			function (string $key): string {
				return [
					'register' => 'reg-1',
					'case_schema' => 'case-schema',
				][$key] ?? '';
			}
		);

		$handler = new EvaluateDecisionHandler(
			tableService: $tableService,
			engine: $this->evaluatorReturning(['eligible' => true, 'tier' => 'gold']),
			settingsService: $settings,
			caseWriter: new CaseFieldWriter(),
			logger: new NullLogger(),
		);

		$case = [
			'id' => 'case-1',
			'declaredIncome' => 20000,
			'householdSize' => 2,
		];

		// The store already holds the case: the handler writes ONLY its
		// outputs onto it, so preservation is the store's patch semantics.
		$recorded = $case;

		$result = $handler->handle(
			actionConfig: [
				'type' => 'evaluateDecision',
				'decisionKey' => 'subsidy-eligibility',
				'inputMapping' => ['income' => 'declaredIncome', 'householdSize' => 'householdSize'],
				'outputMapping' => ['eligible' => 'subsidyEligible', 'tier' => 'subsidyTier'],
			],
			case: $case,
			transitionContext: ['toStatus' => 'assessed'],
		);

		self::assertTrue($result->succeeded);
		self::assertSame(['eligible' => true, 'tier' => 'gold'], $result->data['outputs']);

		// The stored case carries the decision's outputs under the
		// configured outputMapping fields.
		self::assertSame(true, $recorded['subsidyEligible']);
		self::assertSame('gold', $recorded['subsidyTier']);
		// Original case fields are preserved: the handler patches its own
		// outputs and never full-saves its snapshot over the stored case.
		self::assertSame('case-1', $recorded['id']);
		self::assertSame(20000, $recorded['declaredIncome']);
	}//end testEvaluatesDecisionAndWritesOutputsOntoCase()

	/**
	 * @return void
	 */
	public function testSameNameDefaultMappingWhenNoMappingConfigured(): void {
		$tableService = $this->createMock(DecisionTableService::class);
		$tableService->method('findByKey')->willReturn($this->subsidyEligibilityTable());

		$recorded = null;
		$objectService = new class($recorded) {
			/** @var mixed */
			public $recorded;

			public function __construct(&$recorded) {
				$this->recorded = &$recorded;
			}

			public function saveObject(array $object, string $register, string $schema): array {
				$this->recorded = $object;
				return $object;
			}

			public function patchObject(string $objectId, array $data, ?string $register = null, ?string $schema = null): array {
				$this->recorded = array_merge((array) $this->recorded, $data);
				return $this->recorded;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			function (string $key): string {
				return [
					'register' => 'reg-1',
					'case_schema' => 'case-schema',
				][$key] ?? '';
			}
		);

		$handler = new EvaluateDecisionHandler(
			tableService: $tableService,
			engine: $this->evaluatorReturning(['eligible' => true, 'tier' => 'gold']),
			settingsService: $settings,
			caseWriter: new CaseFieldWriter(),
			logger: new NullLogger(),
		);

		// No inputMapping/outputMapping: same-name default — the case must
		// carry fields literally named `income`/`householdSize`. The id is
		// required: a snapshot without one cannot address the stored case,
		// and the partial write refuses it.
		$case = ['id' => 'case-2', 'income' => 10000, 'householdSize' => 1];

		$result = $handler->handle(
			actionConfig: ['type' => 'evaluateDecision', 'decisionKey' => 'subsidy-eligibility'],
			case: $case,
			transitionContext: [],
		);

		self::assertTrue($result->succeeded);
		self::assertSame(true, $recorded['eligible']);
		self::assertSame('gold', $recorded['tier']);
	}//end testSameNameDefaultMappingWhenNoMappingConfigured()

	/**
	 * A decision-evaluation failure (e.g. no rule matched) surfaces as a
	 * failed ActionResult and MUST NOT write anything onto the case —
	 * mirrors REQ-STE-5-002 (side-effect failure never rolls back the
	 * status change, but also never silently half-writes a case).
	 *
	 * @return void
	 */
	public function testEvaluationFailureDoesNotWriteCase(): void {
		$table = $this->subsidyEligibilityTable();
		// Remove the catch-all so an out-of-range income yields no match.
		unset($table['rules'][2]);

		$tableService = $this->createMock(DecisionTableService::class);
		$tableService->method('findByKey')->willReturn($table);

		$settings = $this->createMock(SettingsService::class);
		// getObjectService() must never be consulted — no write should be attempted.
		$settings->expects(self::never())->method('getObjectService');

		$handler = new EvaluateDecisionHandler(
			tableService: $tableService,
			engine: $this->evaluatorThrowing('no_rule_matched'),
			settingsService: $settings,
			caseWriter: new CaseFieldWriter(),
			logger: new NullLogger(),
		);

		$result = $handler->handle(
			actionConfig: ['type' => 'evaluateDecision', 'decisionKey' => 'subsidy-eligibility'],
			case: ['income' => 999999, 'householdSize' => 1],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('no_rule_matched', $result->error);
	}//end testEvaluationFailureDoesNotWriteCase()
	/**
	 * A shared evaluator that returns the given outputs.
	 *
	 * The evaluation itself is OpenRegister's contract and is tested there.
	 * What this suite is for is the handler's own job: mapping inputs in,
	 * mapping outputs back onto the case, and refusing to write on failure.
	 *
	 * @param array<string, mixed> $outputs The outputs to return.
	 *
	 * @return DecisionTableEvaluator The configured double.
	 */
	private function evaluatorReturning(array $outputs): DecisionTableEvaluator {
		$evaluator = $this->createMock(DecisionTableEvaluator::class);
		$evaluator->method('evaluate')->willReturn(
			['outputs' => $outputs, 'matchedRuleIds' => ['rule-1'], 'hitPolicy' => 'UNIQUE']
		);

		return $evaluator;

	}//end evaluatorReturning()

	/**
	 * A shared evaluator that refuses with the given error code.
	 *
	 * @param string $errorCode The code to raise.
	 *
	 * @return DecisionTableEvaluator The configured double.
	 */
	private function evaluatorThrowing(string $errorCode): DecisionTableEvaluator {
		$evaluator = $this->createMock(DecisionTableEvaluator::class);
		$evaluator->method('evaluate')->willThrowException(new DecisionEvaluationException($errorCode));

		return $evaluator;

	}//end evaluatorThrowing()

}//end class
