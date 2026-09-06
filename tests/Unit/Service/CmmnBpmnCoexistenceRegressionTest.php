<?php

/**
 * BPMN/CMMN coexistence regression test (cmmn-adaptive-case).
 *
 * Proves the additive `case.casePlanState` / `caseType.handlingModel`
 * schema fields introduced for the CMMN engine do not change
 * `StatusTransitionService`'s behaviour for BPMN-managed caseTypes: no
 * `caseModel` lookup occurs, `casePlanState` is never read or written, and
 * `getAvailableTransitions()` returns exactly what the active
 * `workflowTemplate` says, unaffected by an unrelated (unused) field being
 * present on the case record.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-008
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\GuardRegistry;
use OCA\Dossiq\Service\Transitions\SideEffectDispatcher;
use OCA\Dossiq\Service\Transitions\TransitionAuthorizer;
use OCA\Dossiq\Service\Transitions\TransitionSpecReader;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\StatusTransitionService
 *
 * @uses \OCA\Dossiq\Service\Transitions\CaseStatusStore
 * @uses \OCA\Dossiq\Service\Transitions\TransitionAuthorizer
 * @uses \OCA\Dossiq\Service\Transitions\TransitionSpecReader
 */
final class CmmnBpmnCoexistenceRegressionTest extends TestCase {

	/**
	 * A BPMN-managed case (no `handlingModel`, an unused `casePlanState`
	 * field present from the additive schema change) resolves its
	 * available transitions exactly per the active workflowTemplate.
	 *
	 * @return void
	 */
	public function testBpmnCaseUnaffectedByCmmnSchemaFields(): void {
		$store = new FakeTermijnStore();
		$store->store['case-schema']['case-1'] = [
			'id' => 'case-1',
			'caseType' => 'ct-1',
			'status' => 'st-1',
			// Present because the schema now carries it for every case
			// (additive field), but never populated or read for a BPMN
			// case type — StatusTransitionService must ignore it entirely.
			'casePlanState' => '',
		];

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => '1',
				'case_schema' => 'case-schema',
				default => '',
			},
		);

		// The engine resolves the workflow through the CASE, not its case type:
		// a case type may carry several routes, each with an active definition.
		// See openspec/specs/workflow-variants/spec.md.
		$templateLoader = $this->createMock(\OCA\Dossiq\Service\WorkflowTemplateLoader::class);
		$templateLoader->method('getTemplateForCase')->willReturn(
			[
				'transitions' => [
					['id' => 't1', 'fromStatus' => 'st-1', 'toStatus' => 'st-2', 'label' => 'Go'],
				],
			],
		);

		$guardRegistry = $this->createMock(GuardRegistry::class);
		$guardRegistry->method('evaluateAll')->willReturn([]);

		$logger = $this->createMock(LoggerInterface::class);

		$service = new StatusTransitionService(
			$templateLoader,
			$guardRegistry,
			$this->createMock(SideEffectDispatcher::class),
			new CaseStatusStore($settings, $logger),
			new TransitionAuthorizer($this->createMock(IGroupManager::class), $logger),
			new TransitionSpecReader(),
			$this->createMock(IUserSession::class),
			$logger,
		);

		$result = $service->getAvailableTransitions(caseId: 'case-1');

		self::assertCount(1, $result['transitions']);
		self::assertSame('t1', $result['transitions'][0]['id']);
		self::assertTrue($result['transitions'][0]['guardsPassed']);

		// The stored case is untouched — no casePlanState write occurred.
		self::assertSame('', $store->store['case-schema']['case-1']['casePlanState']);
	}//end testBpmnCaseUnaffectedByCmmnSchemaFields()
}//end class
