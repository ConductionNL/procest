<?php

/**
 * Dossiq Doorlooptijd (throughput-time) Controller
 *
 * Thin REST entry-point for the throughput-time dashboard. Reads query
 * parameters, validates types, delegates to {@see DoorlooptijdService}.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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
 *
 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\DoorlooptijdService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for the throughput-time dashboard.
 *
 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#T02
 */
class DoorlooptijdController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request Inbound request.
	 * @param DoorlooptijdService $leadTimeService Metrics service.
	 * @param IUserSession $userSession Current user session.
	 */
	public function __construct(
		IRequest $request,
		private readonly DoorlooptijdService $leadTimeService,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Return the metrics payload.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse Metrics body or 400 on invalid parameters.
	 *
	 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#T02
	 */
	public function metrics(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseType = $this->request->getParam('caseType');
		$period = $this->request->getParam('period', '12m');
		$atRiskRaw = $this->request->getParam('atRiskDays', 5);

		if ($caseType !== null && is_string($caseType) === false) {
			return new JSONResponse(['message' => 'caseType must be a string'], Http::STATUS_BAD_REQUEST);
		}

		if (is_string($period) === false || preg_match('/^\d+m$/', $period) !== 1) {
			return new JSONResponse(['message' => 'period must look like 12m'], Http::STATUS_BAD_REQUEST);
		}

		if (is_numeric($atRiskRaw) === false) {
			return new JSONResponse(['message' => 'atRiskDays must be a number'], Http::STATUS_BAD_REQUEST);
		}

		$params = [
			'period' => $period,
			'atRiskDays' => (int)$atRiskRaw,
		];
		if (is_string($caseType) === true && $caseType !== '') {
			$params['caseType'] = $caseType;
		}

		return new JSONResponse($this->leadTimeService->getMetrics(params: $params));
	}//end metrics()
}//end class
