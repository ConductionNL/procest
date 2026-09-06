<?php

/**
 * BeroepService OpenRegister-contract Unit Tests.
 *
 * Pins the repaired find/save contract: before the is_array-on-find sweep,
 * `register()`, `addFileInspectionRequest()` and `executeCascade()` guarded
 * `ObjectService::find()` results with `is_array()`. The service returns an
 * ObjectEntity, never an array, so every guard fired on objects that EXIST
 * and each of these operations always threw 'not found'. These tests run
 * against the shared entity-shaped FakeTermijnStore, so a regression to the
 * array-shape assumption fails the way it fails live.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Bezwaar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Bezwaar;

use OCA\Dossiq\Service\Bezwaar\BeroepService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for BeroepService against the honest OpenRegister fake.
 *
 * @covers \OCA\Dossiq\Service\Bezwaar\BeroepService
 */
class BeroepServiceOrContractTest extends TestCase {

	/**
	 * The entity-shaped in-memory ObjectService fake.
	 *
	 * @var FakeTermijnStore
	 */
	private FakeTermijnStore $store;

	/**
	 * The service under test.
	 *
	 * @var BeroepService
	 */
	private BeroepService $service;

	/**
	 * The status-transition engine mock (cascade tests).
	 *
	 * @var StatusTransitionService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private StatusTransitionService $transitions;

	/**
	 * Set up the service with the honest fake and slug-mapped config.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new FakeTermijnStore();

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'beroep_schema' => 'beroep',
				'appeal_decision_schema' => 'appealDecision',
				'bezwaar_schema' => 'bezwaar',
				default => '',
			}
		);

		$this->transitions = $this->createMock(StatusTransitionService::class);

		$this->service = new BeroepService(
			settingsService: $settings,
			transitions: $this->transitions,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * register() resolves an EXISTING contested beslissing (the pre-fix
	 * is_array() guard threw 'Contested beslissing not found' here) and
	 * persists a beroep with the Awb 6:7 deadline derived from it.
	 *
	 * @return void
	 */
	public function testRegisterResolvesTheContestedDecisionAndPersists(): void {
		$this->store->seed('appealDecision', [
			'id' => 'decision-1',
			'effectiveDate' => '2026-01-01',
		]);

		$result = $this->service->register(
			caseId: 'case-1',
			sourceObjectionId: 'objection-1',
			contestedDecisionId: 'decision-1',
			filingDate: '2026-03-01',
		);

		// 6 weeks after 2026-01-01, and 2026-03-01 is past it.
		$this->assertSame('2026-02-12', $result['filingDeadline']);
		$this->assertTrue($result['latefilingNotice']);
		$this->assertSame('decision-1', $result['contestedDecision']);

		// The record actually landed in the store.
		$stored = $this->store->get('beroep', (string)$result['id']);
		$this->assertNotNull($stored);
		$this->assertSame('case-1', $stored['case']);
	}//end testRegisterResolvesTheContestedDecisionAndPersists()

	/**
	 * register() still refuses a contested beslissing that truly does not
	 * exist — the fake throws DoesNotExistException exactly like live.
	 *
	 * @return void
	 */
	public function testRegisterThrowsForAMissingContestedDecision(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Contested beslissing not found');

		$this->service->register(
			caseId: 'case-1',
			sourceObjectionId: 'objection-1',
			contestedDecisionId: 'decision-missing',
			filingDate: '2026-03-01',
		);
	}//end testRegisterThrowsForAMissingContestedDecision()

	/**
	 * addFileInspectionRequest() reads the EXISTING beroep (pre-fix: always
	 * 'Beroep not found') and appends the Awb 8:42 entry with its 28-day
	 * deadline.
	 *
	 * @return void
	 */
	public function testAddFileInspectionRequestAppendsToTheExistingBeroep(): void {
		$this->store->seed('beroep', [
			'id' => 'beroep-1',
			'fileInspectionRequests' => [],
		]);

		$result = $this->service->addFileInspectionRequest(
			appealId: 'beroep-1',
			requestedAt: '2026-04-01',
		);

		$requests = $result['fileInspectionRequests'];
		$this->assertCount(1, $requests);
		$this->assertSame('2026-04-01', $requests[0]['requestedAt']);
		$this->assertSame('2026-04-29', $requests[0]['deadline']);

		$stored = $this->store->get('beroep', 'beroep-1');
		$this->assertCount(1, $stored['fileInspectionRequests']);
	}//end testAddFileInspectionRequestAppendsToTheExistingBeroep()

	/**
	 * executeCascade() with reopen_objection resolves the EXISTING source
	 * bezwaar (pre-fix: the dead guard skipped the reopen silently) and
	 * triggers the beroep-reopen transition on its case.
	 *
	 * @return void
	 */
	public function testExecuteCascadeReopensTheSourceObjectionCase(): void {
		$this->store->seed('beroep', [
			'id' => 'beroep-1',
			'sourceObjection' => 'objection-1',
		]);
		$this->store->seed('bezwaar', [
			'id' => 'objection-1',
			'case' => 'case-7',
		]);

		$this->transitions->expects($this->once())
			->method('execute')
			->with('case-7', 'beroep-reopen', 'Reopened via beroep beroep-1');

		$result = $this->service->executeCascade(
			appealId: 'beroep-1',
			action: 'reopen_objection',
		);

		$this->assertSame('reopen_objection', $result['cascadeAction']);
		$this->assertSame('case-7', $result['cascadeObjectionCase']);
	}//end testExecuteCascadeReopensTheSourceObjectionCase()
}//end class
