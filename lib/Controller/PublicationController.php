<?php

/**
 * Dossiq PublicationController
 *
 * REST API controller for publishing besluitvorming decisions on a case.
 * Authenticated-user only; per-case publication authorization is enforced
 * via OpenRegister object permissions (ADR-022).
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/besluitvorming-workflow/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\PublicationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller exposing besluitvorming publication endpoints.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/besluitvorming-workflow/tasks.md#task-7
 */
class PublicationController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PublicationService $publicationService Publication service.
	 * @param IUserSession $userSession User session for guard.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly PublicationService $publicationService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Publish a besluit on a case.
	 *
	 * @param string $id The case id.
	 *
	 * @return JSONResponse The publication record.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/besluitvorming-workflow/tasks.md#task-7
	 */
	#[NoAdminRequired]
	public function publish(string $id): JSONResponse {
		$unauthorized = $this->requireAuthenticated();
		if ($unauthorized !== null) {
			return $unauthorized;
		}

		$payload = $this->bodyParams();

		try {
			$result = $this->publicationService->publish(caseId: $id, payload: $payload);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error(
				'PublicationController::publish failed: ' . $e->getMessage(),
				['app' => Application::APP_ID, 'caseId' => $id]
			);
			return new JSONResponse(
				['error' => $e->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse($result, Http::STATUS_OK);
	}//end publish()

	/**
	 * Read JSON / form body params, excluding routing params.
	 *
	 * @return array<string, mixed> The body params.
	 */
	private function bodyParams(): array {
		$params = $this->request->getParams();
		unset($params['id'], $params['_route']);
		return $params;
	}//end bodyParams()

	/**
	 * Require an authenticated user; return a response otherwise.
	 *
	 * @return JSONResponse|null Null when authorised, a response when blocked.
	 */
	private function requireAuthenticated(): ?JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(
				['error' => 'Authenticatie vereist'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return null;
	}//end requireAuthenticated()
}//end class
