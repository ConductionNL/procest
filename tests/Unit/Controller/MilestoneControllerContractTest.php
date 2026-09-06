<?php

/**
 * MilestoneController Wire-Contract Tests
 *
 * Contract coverage for the three milestone endpoints (gate-25). All are
 * `@NoAdminRequired`.
 *
 * The contract pinned here:
 *
 *  - no session answers 401 on all three, before MilestoneService is entered;
 *  - `progress` is guarded per case by `CaseAccessGuard::hasCaseReadAccess()`,
 *    asked about the caseId from the URL and the session user, and refuses with
 *    403 without reading any progress;
 *  - `progress` forwards caseId and caseTypeId in that order — the route
 *    carries two uuids and transposing them still answers 200;
 *  - `mark` stamps the provenance `manual` and attributes the milestone to the
 *    SESSION user, never to a caller-supplied id;
 *  - `reverse` demands a reason and rejects a whitespace-only one (the code
 *    trims), because the reason is the audit record for undoing a milestone;
 *  - and, asserted deliberately: `mark` and `reverse` consult NO per-case guard
 *    at all, unlike their sibling `progress`. That asymmetry is the live
 *    behaviour of the code, so it is pinned rather than assumed — these two
 *    tests are tripwires that must be updated the day a guard is added, and
 *    they document the gap in the meantime.
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

use OCA\Dossiq\Controller\MilestoneController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\MilestoneService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for MilestoneController.
 *
 * @covers \OCA\Dossiq\Controller\MilestoneController
 */
class MilestoneControllerContractTest extends TestCase {

	/**
	 * The IRequest mock.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The MilestoneService mock.
	 *
	 * @var MilestoneService|MockObject
	 */
	private MilestoneService $milestoneService;

	/**
	 * The IUserSession mock.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The per-case authorization guard mock.
	 *
	 * @var CaseAccessGuard|MockObject
	 */
	private CaseAccessGuard $caseAccessGuard;

	/**
	 * The controller under test.
	 *
	 * @var MilestoneController
	 */
	private MilestoneController $controller;

	/**
	 * Build the controller with all collaborators mocked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->milestoneService = $this->createMock(MilestoneService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->caseAccessGuard = $this->createMock(CaseAccessGuard::class);

		$this->controller = new MilestoneController(
			appName: 'dossiq',
			request: $this->request,
			milestoneService: $this->milestoneService,
			userSession: $this->userSession,
			caseAccessGuard: $this->caseAccessGuard,
		);
	}//end setUp()

	/**
	 * Put a signed-in user on the session.
	 *
	 * @param string $uid The UID of the signed-in user.
	 *
	 * @return IUser|MockObject The user placed on the session.
	 */
	private function signIn(string $uid = 'alice'): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

		return $user;
	}//end signIn()

	/**
	 * Answer request parameters from the supplied map.
	 *
	 * @param array<string, mixed> $params The parameter map.
	 *
	 * @return void
	 */
	private function withParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				return ($params[$key] ?? $default);
			}
		);
	}//end withParams()

	/**
	 * `progress` refuses an anonymous caller with 401 and reads nothing.
	 *
	 * @return void
	 */
	public function testProgressRefusesAnUnauthenticatedCallerWith401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->milestoneService->expects($this->never())->method('getCaseProgress');

		$response = $this->controller->progress(caseId: 'zaak-1', caseTypeId: 'type-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testProgressRefusesAnUnauthenticatedCallerWith401()

	/**
	 * `progress` is guarded per case: the read guard is asked about the caseId
	 * from the URL and the session user, and a refusal is a 403 with no read.
	 *
	 * @return void
	 */
	public function testProgressDemandsCaseReadAccessAndRefusesWith403(): void {
		$user = $this->signIn(uid: 'mallory');

		$this->caseAccessGuard->expects($this->once())
			->method('hasCaseReadAccess')
			->with('zaak-1', $user)
			->willReturn(false);
		$this->milestoneService->expects($this->never())->method('getCaseProgress');

		$response = $this->controller->progress(caseId: 'zaak-1', caseTypeId: 'type-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'Not authorized'], $response->getData());
	}//end testProgressDemandsCaseReadAccessAndRefusesWith403()

	/**
	 * An authorized progress read forwards caseId and caseTypeId in that order
	 * and answers 200 with the service's payload unchanged.
	 *
	 * @return void
	 */
	public function testProgressForwardsTheCaseAndCaseTypeInThatOrder(): void {
		$this->signIn();
		$this->caseAccessGuard->method('hasCaseReadAccess')->willReturn(true);

		$this->milestoneService->expects($this->once())
			->method('getCaseProgress')
			->with('zaak-1', 'type-1')
			->willReturn(['reached' => 2, 'total' => 5]);

		$response = $this->controller->progress(caseId: 'zaak-1', caseTypeId: 'type-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['reached' => 2, 'total' => 5], $response->getData());
	}//end testProgressForwardsTheCaseAndCaseTypeInThatOrder()

	/**
	 * `caseProgress` carries the SAME two refusals as its two-segment sibling.
	 *
	 * It exists because requiring the caller to supply the case type made a
	 * manifest tile send an empty path segment on its first render, before the
	 * record it reads the type from had loaded. A convenience endpoint that
	 * skipped the auth checks would be a much worse trade, so both are pinned
	 * here rather than assumed to have been copied correctly.
	 *
	 * @return void
	 */
	public function testCaseProgressRefusesAnUnauthenticatedCallerWith401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->milestoneService->expects($this->never())->method('getCaseProgressForCase');

		$response = $this->controller->caseProgress(caseId: 'zaak-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testCaseProgressRefusesAnUnauthenticatedCallerWith401()

	/**
	 * `caseProgress` is guarded per case, asked about the caseId from the URL.
	 *
	 * @return void
	 */
	public function testCaseProgressDemandsCaseReadAccessAndRefusesWith403(): void {
		$user = $this->signIn(uid: 'mallory');

		$this->caseAccessGuard->expects($this->once())
			->method('hasCaseReadAccess')
			->with('zaak-1', $user)
			->willReturn(false);
		$this->milestoneService->expects($this->never())->method('getCaseProgressForCase');

		$response = $this->controller->caseProgress(caseId: 'zaak-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'Not authorized'], $response->getData());
	}//end testCaseProgressDemandsCaseReadAccessAndRefusesWith403()

	/**
	 * An authorized read delegates to the type-resolving service method and
	 * answers 200 with its payload unchanged.
	 *
	 * @return void
	 */
	public function testCaseProgressDelegatesToTheTypeResolvingRead(): void {
		$this->signIn();
		$this->caseAccessGuard->method('hasCaseReadAccess')->willReturn(true);

		$this->milestoneService->expects($this->once())
			->method('getCaseProgressForCase')
			->with('zaak-1')
			->willReturn(['reached' => 1, 'total' => 4, 'percentage' => 25]);

		$response = $this->controller->caseProgress(caseId: 'zaak-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['reached' => 1, 'total' => 4, 'percentage' => 25], $response->getData());
	}//end testCaseProgressDelegatesToTheTypeResolvingRead()

	/**
	 * A failure computing progress answers 500 with the service's message.
	 *
	 * @return void
	 */
	public function testProgressReportsAServiceFailureAs500(): void {
		$this->signIn();
		$this->caseAccessGuard->method('hasCaseReadAccess')->willReturn(true);
		$this->milestoneService->method('getCaseProgress')
			->willThrowException(new \RuntimeException('Milestone schema not configured'));

		$response = $this->controller->progress(caseId: 'zaak-1', caseTypeId: 'type-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Milestone schema not configured'], $response->getData());
	}//end testProgressReportsAServiceFailureAs500()

	/**
	 * `mark` refuses an anonymous caller with 401 and records nothing.
	 *
	 * @return void
	 */
	public function testMarkRefusesAnUnauthenticatedCallerWith401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->milestoneService->expects($this->never())->method('markMilestone');

		$response = $this->controller->mark(caseId: 'zaak-1', milestoneId: 'ms-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testMarkRefusesAnUnauthenticatedCallerWith401()

	/**
	 * `mark` records the milestone against the case and stamps the provenance
	 * `manual`, attributed to the SESSION user — never to a caller-supplied id.
	 *
	 * @return void
	 */
	public function testMarkStampsManualProvenanceAndAttributesTheSessionUser(): void {
		$this->signIn(uid: 'alice');

		$this->milestoneService->expects($this->once())
			->method('markMilestone')
			->with('zaak-1', 'ms-1', 'alice', 'manual')
			->willReturn(['id' => 'record-1', 'source' => 'manual']);

		$response = $this->controller->mark(caseId: 'zaak-1', milestoneId: 'ms-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'record-1', 'source' => 'manual'], $response->getData());
	}//end testMarkStampsManualProvenanceAndAttributesTheSessionUser()

	/**
	 * `mark` consults NO per-case guard — authentication alone is enough.
	 *
	 * This is asserted, not assumed: it is the live behaviour and it differs
	 * from the sibling `progress` on the same case id. The test is a tripwire —
	 * adding a guard (which would bring `mark` in line with `progress`) makes it
	 * fail, forcing a deliberate update rather than a silent drift.
	 *
	 * @return void
	 */
	public function testMarkCurrentlyConsultsNoPerCaseGuard(): void {
		$this->signIn(uid: 'someone-not-on-this-case');
		$this->caseAccessGuard->expects($this->never())->method('hasCaseMutationAccess');
		$this->caseAccessGuard->expects($this->never())->method('hasCaseReadAccess');
		$this->milestoneService->method('markMilestone')->willReturn(['id' => 'record-1']);

		$response = $this->controller->mark(caseId: 'zaak-of-another-handler', milestoneId: 'ms-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testMarkCurrentlyConsultsNoPerCaseGuard()

	/**
	 * A rejected milestone (already reached, unknown definition) answers 400
	 * with the service's message.
	 *
	 * @return void
	 */
	public function testMarkReportsADomainRefusalAs400(): void {
		$this->signIn();
		$this->milestoneService->method('markMilestone')
			->willThrowException(new \RuntimeException('Milestone already reached'));

		$response = $this->controller->mark(caseId: 'zaak-1', milestoneId: 'ms-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Milestone already reached'], $response->getData());
	}//end testMarkReportsADomainRefusalAs400()

	/**
	 * `reverse` refuses an anonymous caller with 401 and reverses nothing.
	 *
	 * @return void
	 */
	public function testReverseRefusesAnUnauthenticatedCallerWith401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->milestoneService->expects($this->never())->method('reverseMilestone');

		$response = $this->controller->reverse(caseId: 'zaak-1', milestoneId: 'ms-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testReverseRefusesAnUnauthenticatedCallerWith401()

	/**
	 * A reversal with no reason is a 400 — the reason IS the audit record for
	 * undoing a milestone.
	 *
	 * @return void
	 */
	public function testReverseRejectsAMissingReasonWith400(): void {
		$this->signIn();
		$this->withParams([]);
		$this->milestoneService->expects($this->never())->method('reverseMilestone');

		$response = $this->controller->reverse(caseId: 'zaak-1', milestoneId: 'ms-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['error' => 'Reason is required for milestone reversal'],
			$response->getData()
		);
	}//end testReverseRejectsAMissingReasonWith400()

	/**
	 * A whitespace-only reason is rejected too — the check trims, so a space
	 * bar cannot buy an audit-free reversal.
	 *
	 * @return void
	 */
	public function testReverseRejectsAWhitespaceOnlyReasonWith400(): void {
		$this->signIn();
		$this->withParams(['reason' => "   \t\n"]);
		$this->milestoneService->expects($this->never())->method('reverseMilestone');

		$response = $this->controller->reverse(caseId: 'zaak-1', milestoneId: 'ms-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['error' => 'Reason is required for milestone reversal'],
			$response->getData()
		);
	}//end testReverseRejectsAWhitespaceOnlyReasonWith400()

	/**
	 * A motivated reversal forwards the case, milestone, session user and the
	 * reason, and answers 200 with the service's boolean outcome.
	 *
	 * @return void
	 */
	public function testReverseForwardsTheReasonAndReportsTheOutcome(): void {
		$this->signIn(uid: 'alice');
		$this->withParams(['reason' => 'Onterecht gemarkeerd']);

		$this->milestoneService->expects($this->once())
			->method('reverseMilestone')
			->with('zaak-1', 'ms-1', 'alice', 'Onterecht gemarkeerd')
			->willReturn(true);

		$response = $this->controller->reverse(caseId: 'zaak-1', milestoneId: 'ms-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['success' => true], $response->getData());
	}//end testReverseForwardsTheReasonAndReportsTheOutcome()

	/**
	 * A service that declines the reversal answers 200 with `success: false` —
	 * the boolean is carried through rather than being coerced to true.
	 *
	 * @return void
	 */
	public function testReverseCarriesAFalseOutcomeThroughRatherThanClaimingSuccess(): void {
		$this->signIn();
		$this->withParams(['reason' => 'Onterecht gemarkeerd']);
		$this->milestoneService->method('reverseMilestone')->willReturn(false);

		$response = $this->controller->reverse(caseId: 'zaak-1', milestoneId: 'ms-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['success' => false], $response->getData());
	}//end testReverseCarriesAFalseOutcomeThroughRatherThanClaimingSuccess()

	/**
	 * `reverse` likewise consults no per-case guard today — pinned as a
	 * tripwire alongside `mark`.
	 *
	 * @return void
	 */
	public function testReverseCurrentlyConsultsNoPerCaseGuard(): void {
		$this->signIn(uid: 'someone-not-on-this-case');
		$this->withParams(['reason' => 'Onterecht gemarkeerd']);
		$this->caseAccessGuard->expects($this->never())->method('hasCaseMutationAccess');
		$this->caseAccessGuard->expects($this->never())->method('hasCaseReadAccess');
		$this->milestoneService->method('reverseMilestone')->willReturn(true);

		$response = $this->controller->reverse(caseId: 'zaak-of-another-handler', milestoneId: 'ms-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testReverseCurrentlyConsultsNoPerCaseGuard()

	/**
	 * A failure during reversal answers 400 with the service's message.
	 *
	 * @return void
	 */
	public function testReverseReportsADomainRefusalAs400(): void {
		$this->signIn();
		$this->withParams(['reason' => 'Onterecht gemarkeerd']);
		$this->milestoneService->method('reverseMilestone')
			->willThrowException(new \RuntimeException('Milestone was never reached'));

		$response = $this->controller->reverse(caseId: 'zaak-1', milestoneId: 'ms-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Milestone was never reached'], $response->getData());
	}//end testReverseReportsADomainRefusalAs400()
}//end class
