<?php

/**
 * Dossiq Consultation Controller
 *
 * REST API for inter-departmental consultation management. Provides CRUD,
 * lifecycle transitions and deadline extension for adviesaanvragen.
 *
 * The advisory body directory lives on {@see AdvisoryBodyController} and the
 * token-based external surface on {@see ConsultationPublicController}.
 * Authentication, resolution and the authorization rules are delegated to
 * {@see ConsultationAccessGuard} (ADR-022) — this controller only maps a
 * guard outcome or a service result onto a response.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\Consultation\ConsultationAccessGuard;
use OCA\Dossiq\Service\ConsultationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for consultation (adviesaanvraag) management.
 *
 * Every endpoint carries the NoAdminRequired annotation and applies the
 * ConsultationAccessGuard (OWASP A01:2021, ADR-005 Rule 3).
 *
 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
 */
class ConsultationController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The request
	 * @param ConsultationService $consultationService The consultation service
	 * @param ConsultationAccessGuard $accessGuard The authorization/body-decoding guard
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ConsultationService $consultationService,
		private readonly ConsultationAccessGuard $accessGuard,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List consultations for a case.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return JSONResponse List of consultations
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
	 */
	public function index(string $caseId): JSONResponse {
		$authError = $this->accessGuard->requireUser();
		if ($authError !== null) {
			return $authError;
		}

		$consultations = $this->consultationService->getConsultationsForCase(caseId: $caseId);
		return new JSONResponse(['results' => $consultations]);
	}//end index()

	/**
	 * Get a single consultation by ID.
	 *
	 * @param string $id The consultation UUID
	 *
	 * @return JSONResponse The consultation data or 404
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
	 */
	public function show(string $id): JSONResponse {
		$access = $this->accessGuard->authorize(consultationId: $id);
		if ($access->error !== null) {
			return $access->error;
		}

		return new JSONResponse($access->consultation);
	}//end show()

	/**
	 * Create a new consultation.
	 *
	 * @return JSONResponse Created consultation with HTTP 201
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
	 */
	public function create(): JSONResponse {
		$authError = $this->accessGuard->requireUser();
		if ($authError !== null) {
			return $authError;
		}

		try {
			$data = $this->accessGuard->requestBody();
			$data['applicant'] = $this->accessGuard->currentUid();

			$cycleError = $this->accessGuard->dependencyCycleError(data: $data);
			if ($cycleError !== null) {
				return $cycleError;
			}

			$result = $this->consultationService->createConsultation(data: $data);
			return new JSONResponse($result, Http::STATUS_CREATED);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}//end try
	}//end create()

	/**
	 * Update consultation status.
	 *
	 * @param string $id The consultation UUID
	 *
	 * @return JSONResponse Updated consultation or error
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
	 */
	public function updateStatus(string $id): JSONResponse {
		$access = $this->accessGuard->authorize(consultationId: $id);
		if ($access->error !== null) {
			return $access->error;
		}

		try {
			$data = $this->accessGuard->requestBody();
			$status = $data['status'] ?? '';
			$result = $this->consultationService->updateStatus(
				consultationId: $id,
				newStatus: $status,
			);
			return new JSONResponse($result);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end updateStatus()

	/**
	 * Submit advice response.
	 *
	 * @param string $id The consultation UUID
	 *
	 * @return JSONResponse Updated consultation or error
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
	 */
	public function submitResponse(string $id): JSONResponse {
		$access = $this->accessGuard->authorize(consultationId: $id);
		if ($access->error !== null) {
			return $access->error;
		}

		try {
			$data = $this->accessGuard->requestBody();
			$result = $this->consultationService->submitResponse(
				consultationId: $id,
				response: $data,
			);
			return new JSONResponse($result);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end submitResponse()

	/**
	 * Delete a consultation.
	 *
	 * @param string $id The consultation UUID
	 *
	 * @return JSONResponse Empty 204 on success or error
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
	 */
	public function delete(string $id): JSONResponse {
		$access = $this->accessGuard->authorize(consultationId: $id);
		if ($access->error !== null) {
			return $access->error;
		}

		try {
			$this->consultationService->deleteConsultation(consultationId: $id);
			return new JSONResponse([], Http::STATUS_NO_CONTENT);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end delete()

	/**
	 * Get overdue consultations.
	 *
	 * @return JSONResponse List of overdue consultations
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
	 */
	public function overdue(): JSONResponse {
		$authError = $this->accessGuard->requireUser();
		if ($authError !== null) {
			return $authError;
		}

		$overdue = $this->consultationService->getOverdueConsultations();
		return new JSONResponse(['results' => $overdue]);
	}//end overdue()

	/**
	 * Request a deadline extension for a consultation.
	 *
	 * @param string $id The consultation UUID
	 *
	 * @return JSONResponse Updated consultation summary or error
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
	 */
	public function requestExtension(string $id): JSONResponse {
		$access = $this->accessGuard->authorize(consultationId: $id);
		if ($access->error !== null) {
			return $access->error;
		}

		try {
			$data = $this->accessGuard->requestBody();
			$justification = $data['justification'] ?? '';
			$result = $this->consultationService->requestExtension(
				consultationId: $id,
				justification: $justification,
			);
			return new JSONResponse($result);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end requestExtension()

	/**
	 * Approve a deadline extension for a consultation.
	 *
	 * @param string $id The consultation UUID
	 *
	 * @return JSONResponse Updated consultation summary or error
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
	 */
	public function approveExtension(string $id): JSONResponse {
		$access = $this->accessGuard->authorize(consultationId: $id);
		if ($access->error !== null) {
			return $access->error;
		}

		try {
			$data = $this->accessGuard->requestBody();
			$newDeadline = $data['newDeadline'] ?? '';
			$result = $this->consultationService->approveExtension(
				consultationId: $id,
				newDeadline: $newDeadline,
			);
			return new JSONResponse($result);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end approveExtension()
}//end class
