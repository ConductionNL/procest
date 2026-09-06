<?php

/**
 * Dossiq NoticeOfDefaultController.
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use DateTimeImmutable;
use OCA\Dossiq\Service\NoticeOfDefaultService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * REST surface for ingebrekestelling registration.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
 */
class NoticeOfDefaultController extends Controller {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request Request.
	 * @param NoticeOfDefaultService $service Service.
	 * @param SettingsService $settings Settings.
	 * @param IUserSession $userSession User session.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly NoticeOfDefaultService $service,
		private readonly SettingsService $settings,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Per-object authorization guard.
	 *
	 * @return JSONResponse|null
	 */
	private function ensureAuthenticated(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end ensureAuthenticated()

	/**
	 * Register an ingebrekestelling.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	public function register(): JSONResponse {
		$denied = $this->ensureAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		// OCP\IRequest::getContent() is marked protected on the concrete
		// OC request — calling it across class scopes throws Error at runtime.
		// Read the raw payload directly from php://input instead.
		$raw = (string)file_get_contents('php://input');
		$body = json_decode($raw, true);
		if (is_array($body) === false) {
			$body = [];
		}

		$instanceId = (string)($body['termijnInstanceId'] ?? '');
		$channel = (string)($body['notificationChannel'] ?? '');
		$whenStr = (string)($body['receiptDate'] ?? '');
		$documentLink = (string)($body['documentLink'] ?? '');
		if ($instanceId === '' || $channel === '' || $whenStr === '') {
			return new JSONResponse(
				['message' => 'termijnInstanceId, ontvangstDatum and kanaal are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$row = $this->service->registerNoticeOfDefault(
				$instanceId,
				new DateTimeImmutable($whenStr),
				$channel,
				$documentLink
			);
			return new JSONResponse($row, Http::STATUS_CREATED);
		} catch (Throwable $e) {
			$this->logger->info('Ingebrekestelling register failed: ' . $e->getMessage());
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end register()

	/**
	 * Get an ingebrekestelling by id.
	 *
	 * @param string $id Id.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	public function show(string $id): JSONResponse {
		$denied = $this->ensureAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		$objectService = $this->settings->getObjectService();
		$register = (string)$this->settings->getConfigValue('register');
		$schema = (string)$this->settings->getConfigValue('ingebrekestelling_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return new JSONResponse(['message' => 'Service unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		try {
			$row = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $id);
		} catch (Throwable $e) {
			return new JSONResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		if ($row === null) {
			return new JSONResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($row);
	}//end show()
}//end class
