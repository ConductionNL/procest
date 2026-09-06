<?php

/**
 * TermijnController Wire-Contract Tests
 *
 * Contract coverage for the four TermijnInstance lifecycle actions (gate-25):
 * `pauze`, `hervat`, `verleng` and `voltooi` on
 * `/api/termijn/instances/{id}/...`. Every one of them moves an Awb statutory
 * deadline — pausing one under art. 4:5, resuming it, extending it under
 * art. 4:14, or closing it — which is the clock a dwangsom is calculated
 * against. These tests pin:
 *
 *  - all four refuse a session-less caller with **403**. That is what the
 *    controller's own `ensureAuthenticated()` returns; it is NOT the 401 the
 *    rest of dossiq uses, and the asymmetry is asserted explicitly so a
 *    "consistency" edit cannot change the wire contract unnoticed. The
 *    refusal is checked with `never()` on all three services, so no clock
 *    moves;
 *  - `verleng` takes the STANDARD extension path by default. The supervisor
 *    path bypasses the TermijnDefinitie's `aantalVerlengingen` ceiling, so if
 *    an absent `supervisorOverride` fell through to it, any caseworker could
 *    extend a deadline past its statutory limit. The test asserts
 *    `requestSupervisorExtension()` is NEVER called on a body that did not ask
 *    for it, and asserts the standard call really is made;
 *  - `hervat` resumes with a null date (meaning "now") when the body carries
 *    no `aanvullingDatum`, rather than forwarding a bogus date object;
 *  - `voltooi` distinguishes a missing instance (404) from a domain rejection
 *    (400) — closing a termijn that does not exist is not the same failure as
 *    closing one that refuses to close;
 *  - a domain `Throwable` is a 400 carrying the domain message.
 *
 * All four methods read their body via `file_get_contents('php://input')`,
 * which is empty under PHPUnit — so the arguments asserted below are the
 * documented defaults, which is precisely the path an empty-body POST takes.
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

use OCA\Dossiq\Controller\TermijnController;
use OCA\Dossiq\Service\CaseTypeSlugResolver;
use OCA\Dossiq\Service\DeadlineExtensionService;
use OCA\Dossiq\Service\DeadlinePauseService;
use OCA\Dossiq\Service\TermijnService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Wire-contract tests for TermijnController's four lifecycle actions.
 *
 * @covers \OCA\Dossiq\Controller\TermijnController
 */
class TermijnControllerContractTest extends TestCase {

	/**
	 * The IRequest mock.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The termijn service.
	 *
	 * @var TermijnService|MockObject
	 */
	private TermijnService $term;

	/**
	 * The pause service.
	 *
	 * @var DeadlinePauseService|MockObject
	 */
	private DeadlinePauseService $pause;

	/**
	 * The extension service.
	 *
	 * @var DeadlineExtensionService|MockObject
	 */
	private DeadlineExtensionService $extension;

	/**
	 * The user session.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The controller under test.
	 *
	 * @var TermijnController
	 */
	private TermijnController $controller;

	/**
	 * Build the controller with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->term = $this->createMock(TermijnService::class);
		$this->pause = $this->createMock(DeadlinePauseService::class);
		$this->extension = $this->createMock(DeadlineExtensionService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// A real resolver would need OpenRegister; the contract this file
		// pins is the controller's, so the reference passes straight through
		// exactly as a slug does in production.
		$caseTypeSlugs = $this->createMock(CaseTypeSlugResolver::class);
		$caseTypeSlugs->method('toSlug')->willReturnArgument(0);

		$this->controller = new TermijnController(
			appName: 'dossiq',
			request: $this->request,
			term: $this->term,
			pause: $this->pause,
			extension: $this->extension,
			caseTypeSlugs: $caseTypeSlugs,
			userSession: $this->userSession,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * Put a signed-in user on the session.
	 *
	 * @return void
	 */
	private function signIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * All four lifecycle actions refuse a session-less caller with 403 — this
	 * controller's refusal status, deliberately NOT 401 — and no clock moves.
	 *
	 * @return void
	 */
	public function testAllFourLifecycleActionsRefuseASessionLessCallerWith403AndMoveNoClock(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->pause->expects($this->never())->method('registerPauze');
		$this->pause->expects($this->never())->method('resumeAfterPauze');
		$this->extension->expects($this->never())->method('requestExtension');
		$this->extension->expects($this->never())->method('requestSupervisorExtension');
		$this->term->expects($this->never())->method('markTermijnCompleted');

		$responses = [
			'pauze' => $this->controller->pauze(id: 'ti-1'),
			'hervat' => $this->controller->hervat(id: 'ti-1'),
			'verleng' => $this->controller->verleng(id: 'ti-1'),
			'voltooi' => $this->controller->voltooi(id: 'ti-1'),
		];

		foreach ($responses as $action => $response) {
			$this->assertSame(
				Http::STATUS_FORBIDDEN,
				$response->getStatus(),
				$action . ' must refuse an anonymous caller with 403'
			);
			$this->assertNotSame(
				Http::STATUS_UNAUTHORIZED,
				$response->getStatus(),
				$action . ' uses 403, not 401 — changing it changes the wire contract'
			);
			$this->assertSame(['message' => 'Not authenticated'], $response->getData());
		}
	}//end testAllFourLifecycleActionsRefuseASessionLessCallerWith403AndMoveNoClock()

	/**
	 * pauze registers the pause on the instance from the URL and returns the
	 * updated row.
	 *
	 * @return void
	 */
	public function testPauzeRegistersThePauseOnTheInstanceFromTheUrl(): void {
		$this->signIn();
		$row = ['id' => 'ti-9', 'status' => 'paused'];

		$this->pause->expects($this->once())
			->method('registerPauze')
			->with('ti-9')
			->willReturn($row);

		$response = $this->controller->pauze(id: 'ti-9');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($row, $response->getData());
	}//end testPauzeRegistersThePauseOnTheInstanceFromTheUrl()

	/**
	 * A domain rejection from the pause service (e.g. a non-positive duration
	 * under Awb 4:5) is a 400 carrying the domain message.
	 *
	 * @return void
	 */
	public function testPauzeMapsADomainRejectionToA400CarryingTheAwbMessage(): void {
		$this->signIn();

		$this->pause->method('registerPauze')
			->willThrowException(new \RuntimeException('Pause duration must be positive (AWB 4:5)'));

		$response = $this->controller->pauze(id: 'ti-9');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Pause duration must be positive (AWB 4:5)'], $response->getData());
	}//end testPauzeMapsADomainRejectionToA400CarryingTheAwbMessage()

	/**
	 * hervat resumes with a null resume-date when the body carries none,
	 * meaning "resume now" rather than a fabricated date.
	 *
	 * @return void
	 */
	public function testHervatResumesWithANullDateWhenTheBodyCarriesNoResumeDate(): void {
		$this->signIn();
		$row = ['id' => 'ti-9', 'status' => 'running'];

		$this->pause->expects($this->once())
			->method('resumeAfterPauze')
			->with('ti-9', $this->identicalTo(null))
			->willReturn($row);

		$response = $this->controller->hervat(id: 'ti-9');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($row, $response->getData());
	}//end testHervatResumesWithANullDateWhenTheBodyCarriesNoResumeDate()

	/**
	 * verleng takes the STANDARD extension path unless the body explicitly
	 * asks for the supervisor override.
	 *
	 * The supervisor path bypasses the aantalVerlengingen ceiling, so falling
	 * through to it by default would let any caseworker extend a statutory
	 * deadline without limit.
	 *
	 * @return void
	 */
	public function testVerlengUsesTheStandardPathAndNeverTheCeilingBypassingSupervisorPath(): void {
		$this->signIn();
		$row = ['id' => 'ti-9', 'endDateCurrent' => '2026-12-01'];

		$this->extension->expects($this->never())->method('requestSupervisorExtension');
		$this->extension->expects($this->once())
			->method('requestExtension')
			->with('ti-9')
			->willReturn($row);

		$response = $this->controller->verleng(id: 'ti-9');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($row, $response->getData());
	}//end testVerlengUsesTheStandardPathAndNeverTheCeilingBypassingSupervisorPath()

	/**
	 * A rejected extension is a 400 carrying the domain message.
	 *
	 * @return void
	 */
	public function testVerlengMapsARejectedExtensionToA400CarryingTheDomainMessage(): void {
		$this->signIn();

		$this->extension->method('requestExtension')
			->willThrowException(new \RuntimeException('Extension ceiling reached (AWB 4:14)'));

		$response = $this->controller->verleng(id: 'ti-9');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Extension ceiling reached (AWB 4:14)'], $response->getData());
	}//end testVerlengMapsARejectedExtensionToA400CarryingTheDomainMessage()

	/**
	 * voltooi closes the instance from the URL and returns the updated row.
	 *
	 * @return void
	 */
	public function testVoltooiClosesTheInstanceFromTheUrlAndReturnsTheUpdatedRow(): void {
		$this->signIn();
		$row = ['id' => 'ti-9', 'status' => 'completed'];

		$this->term->expects($this->once())
			->method('markTermijnCompleted')
			->with('ti-9')
			->willReturn($row);

		$response = $this->controller->voltooi(id: 'ti-9');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($row, $response->getData());
	}//end testVoltooiClosesTheInstanceFromTheUrlAndReturnsTheUpdatedRow()

	/**
	 * A null return from the service means "no such instance" and is a 404
	 * naming the id — DISTINCT from the 400 a domain rejection produces.
	 *
	 * @return void
	 */
	public function testVoltooiDistinguishesAMissingInstance404FromADomainRejection400(): void {
		$this->signIn();

		$this->term->method('markTermijnCompleted')->willReturnCallback(
			static function (string $id): ?array {
				if ($id === 'ti-weg') {
					return null;
				}

				throw new \RuntimeException('Termijn already completed');
			}
		);

		$missing = $this->controller->voltooi(id: 'ti-weg');
		$rejected = $this->controller->voltooi(id: 'ti-9');

		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame(['message' => 'TermijnInstance not found: ti-weg'], $missing->getData());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $rejected->getStatus());
		$this->assertSame(['message' => 'Termijn already completed'], $rejected->getData());
	}//end testVoltooiDistinguishesAMissingInstance404FromADomainRejection400()
}//end class
