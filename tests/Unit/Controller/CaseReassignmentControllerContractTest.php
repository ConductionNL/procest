<?php

/**
 * CaseReassignmentController Wire-Contract Tests
 *
 * Contract coverage (gate-25) for the two coordinator-only bulk reassignment
 * endpoints, `reassignPreview()` and `reassignExecute()`. Both are
 * `#[NoAdminRequired]`, i.e. any authenticated user may reach the route, and the
 * only thing standing between an ordinary handler and moving somebody else's
 * entire caseload is the private `requireCoordinator()` guard. That guard, and
 * the arguments the controller hands the service once it passes, are what these
 * tests pin:
 *
 *  - an anonymous caller is refused 403 and the service is never entered — the
 *    guard must run BEFORE any parameter is read;
 *  - an authenticated NON-coordinator is refused 403 with the role message, and
 *    the group check is made against THAT caller's uid, not a blank string: a
 *    guard that asked about the wrong user would be no guard at all;
 *  - the optional `caseType` filter is forwarded as `['caseType' => ...]` when
 *    present and as NULL when absent. The null case is the dangerous one — a
 *    filter that silently became `['caseType' => '']` would either match
 *    nothing or, worse, match everything on a permissive backend;
 *  - `reassignExecute()` attributes the run to the SESSION user (`actorId`),
 *    not to the handler being emptied — the audit trail of a bulk move is the
 *    only record of who ordered it;
 *  - a rejected argument is a 400 and an unexpected failure a 500, so a caller
 *    can distinguish "your request was wrong" from "we broke".
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseReassignmentController;
use OCA\Dossiq\Service\CaseReassignmentService;
use OCA\Dossiq\Service\SelectionReassignmentService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Wire-contract tests for CaseReassignmentController.
 *
 * @covers \OCA\Dossiq\Controller\CaseReassignmentController
 */
class CaseReassignmentControllerContractTest extends TestCase {

	/**
	 * The IRequest mock handed to the controller.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The bulk reassignment service mock.
	 *
	 * @var CaseReassignmentService|MockObject
	 */
	private CaseReassignmentService $reassignmentService;

	/**
	 * @var SelectionReassignmentService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $selectionService;

	/**
	 * The user session mock — source of the caller and of `actorId`.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The group manager mock — the coordinator gate.
	 *
	 * @var IGroupManager|MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The controller under test.
	 *
	 * @var CaseReassignmentController
	 */
	private CaseReassignmentController $controller;

	/**
	 * Build the controller with all collaborators mocked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->reassignmentService = $this->createMock(CaseReassignmentService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->selectionService = $this->createMock(SelectionReassignmentService::class);
		$this->controller = new CaseReassignmentController(
			appName: 'dossiq',
			request: $this->request,
			reassignmentService: $this->reassignmentService,
			selectionService: $this->selectionService,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * Put an authenticated user in the session.
	 *
	 * @param string $uid The user id the session reports.
	 *
	 * @return void
	 */
	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * Serve the given request parameters, defaulting like the real request.
	 *
	 * @param array<string, mixed> $overrides The parameter values to serve.
	 *
	 * @return void
	 */
	private function withRequestParams(array $overrides): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($overrides): mixed {
				return ($overrides[$key] ?? $default);
			}
		);
	}//end withRequestParams()

	/**
	 * An anonymous caller is refused 403 by both endpoints and the reassignment
	 * service is never entered.
	 *
	 * @return void
	 */
	public function testBothEndpointsRefuseAnAnonymousCallerWithoutTouchingTheService(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->reassignmentService->expects($this->never())->method('preview');
		$this->reassignmentService->expects($this->never())->method('execute');

		$preview = $this->controller->reassignPreview();
		$execute = $this->controller->reassignExecute();

		$this->assertSame(Http::STATUS_FORBIDDEN, $preview->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $execute->getStatus());
		$this->assertSame(['error' => 'Not authorised'], $preview->getData());
		$this->assertSame(['error' => 'Not authorised'], $execute->getData());
	}//end testBothEndpointsRefuseAnAnonymousCallerWithoutTouchingTheService()

	/**
	 * An authenticated non-coordinator is refused 403 with the role message, and
	 * the group check is asked about THE CALLER's uid — a guard that queried a
	 * blank or hard-coded uid would answer for the wrong person.
	 *
	 * @return void
	 */
	public function testANonCoordinatorIsRefusedAndTheGroupCheckNamesTheCaller(): void {
		$this->signIn(uid: 'handler-1');

		$this->groupManager->expects($this->atLeastOnce())
			->method('isAdmin')
			->with('handler-1')
			->willReturn(false);
		$this->reassignmentService->expects($this->never())->method('preview');
		$this->reassignmentService->expects($this->never())->method('execute');

		$preview = $this->controller->reassignPreview();
		$execute = $this->controller->reassignExecute();

		$this->assertSame(Http::STATUS_FORBIDDEN, $preview->getStatus());
		$this->assertSame(
			['error' => 'This action requires the coordinator role'],
			$preview->getData()
		);
		$this->assertSame(Http::STATUS_FORBIDDEN, $execute->getStatus());
	}//end testANonCoordinatorIsRefusedAndTheGroupCheckNamesTheCaller()

	/**
	 * A coordinator's preview forwards `fromUser` and the `caseType` filter and
	 * returns the service's preview verbatim at 200.
	 *
	 * @return void
	 */
	public function testReassignPreviewForwardsTheCaseTypeFilterAndReturnsThePreview(): void {
		$this->signIn(uid: 'coordinator-1');
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->withRequestParams(['fromUser' => 'handler-1', 'caseType' => 'bezwaar']);

		$seen = [];
		$this->reassignmentService->expects($this->once())
			->method('preview')
			->willReturnCallback(
				static function (string $fromUser, ?array $filter = null) use (&$seen): array {
					$seen = ['fromUser' => $fromUser, 'filter' => $filter];
					return ['total' => 7];
				}
			);

		$response = $this->controller->reassignPreview();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['total' => 7], $response->getData());
		$this->assertSame(['fromUser' => 'handler-1', 'filter' => ['caseType' => 'bezwaar']], $seen);
	}//end testReassignPreviewForwardsTheCaseTypeFilterAndReturnsThePreview()

	/**
	 * A rejected argument is a 400 carrying the service's message, not a 500 —
	 * the caller can fix a bad `fromUser`, it cannot fix an outage.
	 *
	 * @return void
	 */
	public function testReassignPreviewAnswers400WhenTheServiceRejectsTheArguments(): void {
		$this->signIn(uid: 'coordinator-1');
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->withRequestParams([]);

		$this->reassignmentService->method('preview')
			->willThrowException(new \InvalidArgumentException('fromUser is required'));

		$response = $this->controller->reassignPreview();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'fromUser is required'], $response->getData());
	}//end testReassignPreviewAnswers400WhenTheServiceRejectsTheArguments()

	/**
	 * A coordinator's execute forwards both handlers, attributes the run to the
	 * SESSION user rather than to the handler being emptied, and sends a NULL
	 * filter when no `caseType` was supplied — an empty-string filter would
	 * change which cases move.
	 *
	 * @return void
	 */
	public function testReassignExecuteAttributesTheRunToTheSessionUserAndSendsANullFilter(): void {
		$this->signIn(uid: 'coordinator-1');
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->withRequestParams(['fromUser' => 'handler-1', 'toUser' => 'handler-2']);

		$seen = [];
		$this->reassignmentService->expects($this->once())
			->method('execute')
			->willReturnCallback(
				static function (
					string $fromUser,
					string $toUser,
					?array $filter = null,
					string $actorId = '',
				) use (&$seen): array {
					$seen = [
						'fromUser' => $fromUser,
						'toUser' => $toUser,
						'filter' => $filter,
						'actorId' => $actorId,
					];
					return ['moved' => 7];
				}
			);

		$response = $this->controller->reassignExecute();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['moved' => 7], $response->getData());
		$this->assertSame(
			[
				'fromUser' => 'handler-1',
				'toUser' => 'handler-2',
				'filter' => null,
				'actorId' => 'coordinator-1',
			],
			$seen
		);
	}//end testReassignExecuteAttributesTheRunToTheSessionUserAndSendsANullFilter()

	/**
	 * An unexpected failure is a 500 and is logged — a bulk move that half
	 * happened must not be reported as a clean 200.
	 *
	 * @return void
	 */
	public function testReassignExecuteAnswers500AndLogsAnUnexpectedFailure(): void {
		$this->signIn(uid: 'coordinator-1');
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->withRequestParams(['fromUser' => 'handler-1', 'toUser' => 'handler-2']);

		$this->reassignmentService->method('execute')
			->willThrowException(new \RuntimeException('register unavailable'));
		$this->logger->expects($this->once())
			->method('error')
			->with('Reassignment execute failed', ['error' => 'register unavailable']);

		$response = $this->controller->reassignExecute();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'register unavailable'], $response->getData());
	}//end testReassignExecuteAnswers500AndLogsAnUnexpectedFailure()
	/**
	 * The selection endpoint forwards the ticked ids and the session actor.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reassignment-is-a-bulk-action/specs/reassignment-bulk-action/spec.md
	 */
	public function testReassignSelectionForwardsTheSelectionAndTheActor(): void {
		$this->signIn(uid: 'coordinator-1');
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->withRequestParams(['caseIds' => ['c1', 'c2'], 'toUser' => 'handler-2']);

		$seen = [];
		$this->selectionService->expects($this->once())
			->method('executeForCases')
			->willReturnCallback(
				static function (array $caseIds, string $toUser, string $actorId = '') use (&$seen): array {
					$seen = ['caseIds' => $caseIds, 'toUser' => $toUser, 'actorId' => $actorId];

					return ['batchId' => 'rb-1', 'requested' => 2, 'succeeded' => 2, 'results' => []];
				}
			);

		$response = $this->controller->reassignSelection();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['caseIds' => ['c1', 'c2'], 'toUser' => 'handler-2', 'actorId' => 'coordinator-1'],
			$seen
		);

	}//end testReassignSelectionForwardsTheSelectionAndTheActor()

	/**
	 * A non-array `caseIds` is a 400, not a crash.
	 *
	 * The parameter arrives off the wire, so a caller can send a string.
	 *
	 * @return void
	 */
	public function testReassignSelectionAnswers400WhenCaseIdsIsNotAnArray(): void {
		$this->signIn(uid: 'coordinator-1');
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->withRequestParams(['caseIds' => 'c1', 'toUser' => 'handler-2']);

		$this->selectionService->expects($this->never())->method('executeForCases');

		$response = $this->controller->reassignSelection();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testReassignSelectionAnswers400WhenCaseIdsIsNotAnArray()

	/**
	 * An empty selection is refused by the service and surfaces as a 400.
	 *
	 * @return void
	 */
	public function testReassignSelectionAnswers400WhenTheServiceRejectsIt(): void {
		$this->signIn(uid: 'coordinator-1');
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->withRequestParams(['caseIds' => [], 'toUser' => '']);

		$this->selectionService->method('executeForCases')
			->willThrowException(new \InvalidArgumentException('toUser is required'));

		$response = $this->controller->reassignSelection();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'toUser is required'], $response->getData());

	}//end testReassignSelectionAnswers400WhenTheServiceRejectsIt()

	/**
	 * An unexpected failure is a 500 and is logged.
	 *
	 * A selection that half moved must not be reported as a clean 200.
	 *
	 * @return void
	 */
	public function testReassignSelectionAnswers500AndLogsAnUnexpectedFailure(): void {
		$this->signIn(uid: 'coordinator-1');
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->withRequestParams(['caseIds' => ['c1'], 'toUser' => 'handler-2']);

		$this->selectionService->method('executeForCases')
			->willThrowException(new \RuntimeException('register unavailable'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->reassignSelection();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());

	}//end testReassignSelectionAnswers500AndLogsAnUnexpectedFailure()

}//end class
