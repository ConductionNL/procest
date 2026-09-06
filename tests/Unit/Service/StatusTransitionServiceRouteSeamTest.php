<?php

/**
 * The seam between the transition engine and the workflow a case runs on.
 *
 * 🔴 THE ENGINE ASKED BY CASE TYPE, AND THE CASE TYPE IS NOT THE ANSWER.
 * `getAvailableTransitions()` and `execute()` both resolved through
 * `WorkflowTemplateLoader::getActiveTemplate($caseTypeId)`, which searches for
 * active definitions and takes the first. A case type may now carry several
 * ROUTES with an active definition each, so asking by case type would offer a
 * spoedeisende case the ordinary route's transitions, silently.
 *
 * This test locks the seam itself. The resolution logic is covered by
 * WorkflowTemplateLoaderVariantTest; what is asserted here is that the engine
 * hands over the case and never falls back to asking by case type, because that
 * is the line a later refactor would quietly put back.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
use OCA\Dossiq\Service\WorkflowTemplateLoader;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The OpenRegister ObjectService shape CaseStatusStore reads through.
 *
 * `register` and `schema` are `mixed` because the production caller hands them
 * over as the strings app-config stores.
 */
interface RouteSeamObjectServiceStub {
	public function find(string $id, mixed $register = null, mixed $schema = null): mixed;

	public function searchObjects(array $query): array;
}//end interface

/**
 * The engine resolves the workflow through the case.
 *
 * @covers \OCA\Dossiq\Service\StatusTransitionService
 * @uses \OCA\Dossiq\Service\Transitions\CaseStatusStore
 * @uses \OCA\Dossiq\Service\Transitions\TransitionAuthorizer
 */
class StatusTransitionServiceRouteSeamTest extends TestCase {

	/**
	 * @var WorkflowTemplateLoader&MockObject
	 */
	private WorkflowTemplateLoader $templateLoader;

	/**
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * The service under test.
	 *
	 * @var StatusTransitionService
	 */
	private StatusTransitionService $service;

	/**
	 * Wire the engine onto a loader we can watch, and a store that answers with
	 * one case.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$this->templateLoader = $this->createMock(WorkflowTemplateLoader::class);

		$objectService = $this->createMock(RouteSeamObjectServiceStub::class);
		$objectService->method('find')->willReturn(
			[
				'id' => 'case-1',
				'caseType' => 'ct-handhaving',
				'status' => 'st-constatering',
				'workflowTemplate' => 'spoed-1',
			]
		);
		$objectService->method('searchObjects')->willReturn([]);

		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => '7',
				'case_schema' => '11',
				'status_type_schema' => '12',
				default => '',
			}
		);

		$this->service = new StatusTransitionService(
			$this->templateLoader,
			$this->createMock(GuardRegistry::class),
			$this->createMock(SideEffectDispatcher::class),
			new CaseStatusStore($this->settingsService, $logger),
			new TransitionAuthorizer($this->createMock(IGroupManager::class), $logger),
			new TransitionSpecReader(),
			$this->createMock(IUserSession::class),
			$logger,
		);
	}//end setUp()

	/**
	 * 🔴 Listing a case's transitions hands the loader the CASE.
	 *
	 * @return void
	 */
	public function testAvailableTransitionsResolveThroughTheCase(): void {
		$this->templateLoader->expects($this->once())
			->method('getTemplateForCase')
			->with(
				$this->callback(
					static fn (array $case): bool => ($case['workflowTemplate'] ?? '') === 'spoed-1'
				)
			)
			->willReturn(['id' => 'spoed-1', 'transitions' => []]);

		$this->templateLoader->expects($this->never())->method('getActiveTemplate');

		$result = $this->service->getAvailableTransitions(caseId: 'case-1', userId: 'alice');

		self::assertSame([], $result['transitions']);
	}//end testAvailableTransitionsResolveThroughTheCase()

	/**
	 * 🔴 Executing a transition looks it up inside the case's own workflow.
	 *
	 * @return void
	 */
	public function testExecuteResolvesTheTransitionThroughTheCase(): void {
		$this->templateLoader->expects($this->once())
			->method('getTransitionForCase')
			->with(
				$this->callback(
					static fn (array $case): bool => ($case['workflowTemplate'] ?? '') === 'spoed-1'
				),
				'spoed-t1'
			)
			->willReturn(null);

		$this->templateLoader->expects($this->never())->method('getTransitionById');

		$this->expectExceptionMessage('transition_not_found');

		$this->service->execute(caseId: 'case-1', transitionId: 'spoed-t1', comment: null, userId: 'alice');
	}//end testExecuteResolvesTheTransitionThroughTheCase()
}//end class
