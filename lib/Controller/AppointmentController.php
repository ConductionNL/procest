<?php

/**
 * Dossiq Appointment Controller.
 *
 * REST endpoints for citizen appointment scheduling flows (list, create,
 * cancel, mark no-show, query timeslots).
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\AppointmentService;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Controller exposing citizen appointment endpoints.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class AppointmentController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param AppointmentService $appointmentService The appointment service.
	 * @param IUserSession $userSession The user session.
	 * @param CaseAccessGuard $caseAccessGuard Per-case authorization (fails closed).
	 */
	public function __construct(
		IRequest $request,
		private AppointmentService $appointmentService,
		private IUserSession $userSession,
		private readonly CaseAccessGuard $caseAccessGuard,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List appointments scheduled for a case.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = (string)($this->request->getParam('caseId') ?? '');
		if ($caseId === '') {
			return new JSONResponse(
				['success' => false, 'error' => 'caseId required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		// Appointment records carry the citizen's name, e-mail and phone.
		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(
				['success' => false, 'error' => 'Not authorized'],
				Http::STATUS_FORBIDDEN
			);
		}

		$appointments = $this->appointmentService->getAppointmentsForCase($caseId);
		return new JSONResponse(['success' => true, 'appointments' => $appointments]);
	}//end index()

	/**
	 * Book a new citizen appointment for a case.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function create(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = $this->request->getParam('caseId');
		if (empty($caseId) === true) {
			return new JSONResponse(['success' => false, 'error' => 'caseId required'], Http::STATUS_BAD_REQUEST);
		}

		// Books onto an arbitrary case and stores citizen contact details.
		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: (string)$caseId, user: $user) === false) {
			return new JSONResponse(
				['success' => false, 'error' => 'Not authorized'],
				Http::STATUS_FORBIDDEN
			);
		}

		$data = [
			'productId' => $this->request->getParam('productId'),
			'locationId' => $this->request->getParam('locationId'),
			'dateTime' => $this->request->getParam('dateTime'),
			'duration' => (int)$this->request->getParam('duration', '30'),
			'citizenName' => $this->request->getParam('citizenName', ''),
			'citizenEmail' => $this->request->getParam('citizenEmail', ''),
			'citizenPhone' => $this->request->getParam('citizenPhone'),
			'notes' => $this->request->getParam('notes'),
		];

		$result = $this->appointmentService->bookAppointment($caseId, $data);
		return new JSONResponse(['success' => true, 'appointment' => $result]);
	}//end create()

	/**
	 * Cancel an existing appointment.
	 *
	 * @param string $appointmentId The appointment UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function cancel(string $appointmentId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->mayMutateAppointment(appointmentId: $appointmentId, user: $user) === false) {
			return new JSONResponse(
				['success' => false, 'error' => 'Not authorized'],
				Http::STATUS_FORBIDDEN
			);
		}

		$result = $this->appointmentService->cancelAppointment($appointmentId);
		return new JSONResponse(['success' => true, 'appointment' => $result]);
	}//end cancel()

	/**
	 * Mark an appointment as a no-show.
	 *
	 * @param string $appointmentId The appointment UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function noShow(string $appointmentId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->mayMutateAppointment(appointmentId: $appointmentId, user: $user) === false) {
			return new JSONResponse(
				['success' => false, 'error' => 'Not authorized'],
				Http::STATUS_FORBIDDEN
			);
		}

		$result = $this->appointmentService->markNoShow($appointmentId);
		return new JSONResponse(['success' => true, 'appointment' => $result]);
	}//end noShow()

	/**
	 * List available timeslots for a product/location/date combination.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @no-admin-idor-exempt Availability probe, not an object read. The three
	 * parameters (productId, locationId, date) name an entry in the appointment
	 * BACKEND's public service catalogue and a calendar day; none of them is an
	 * identifier of anything a user owns. AppointmentService::getTimeslots()
	 * forwards straight to the external scheduling backend and touches no
	 * OpenRegister object, so there is no per-object read to authorise and no
	 * value of the parameters that reveals another caller's data. The sibling
	 * mutating routes (cancel/noShow) DO carry an appointment id and are guarded
	 * by mayMutateAppointment() — this one has nothing of that shape.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function timeslots(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$productId = $this->request->getParam('productId', '');
		$locationId = $this->request->getParam('locationId', '');
		$date = $this->request->getParam('date', date('Y-m-d'));

		$slots = $this->appointmentService->getTimeslots($productId, $locationId, $date);
		return new JSONResponse(['success' => true, 'timeslots' => $slots]);
	}//end timeslots()

	/**
	 * Whether the caller may mutate the appointment with the given id.
	 *
	 * The route carries only an appointment id, so the owning case is resolved
	 * first and the ordinary per-case guard applied. An appointment that cannot
	 * be resolved DENIES, so an unknown id is not an existence oracle.
	 *
	 * @param string $appointmentId The appointment UUID.
	 * @param IUser $user The authenticated user.
	 *
	 * @return bool True when the caller handles the owning case.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	private function mayMutateAppointment(string $appointmentId, IUser $user): bool {
		$caseId = $this->appointmentService->getCaseIdForAppointment($appointmentId);
		if ($caseId === null) {
			return false;
		}

		return $this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user);
	}//end mayMutateAppointment()
}//end class
