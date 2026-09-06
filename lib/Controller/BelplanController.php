<?php

/**
 * Dossiq Belplan Controller.
 *
 * Belplan listing (KCC-medewerker readable), belplan creation/update
 * (admin-only), and a routing endpoint that resolves the destination specialist
 * for a dialed number and keuzemenu selection.
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
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\BelplanRoutingService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;
use Throwable;

/**
 * REST API for belplannen and datagedreven routing.
 *
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T12
 */
class BelplanController extends Controller {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param BelplanRoutingService $routingService The belplan routing service.
	 * @param SettingsService $settingsService The settings service.
	 * @param IUserSession $userSession The user session.
	 * @param IGroupManager $groupManager The group manager.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly BelplanRoutingService $routingService,
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Whether the current session belongs to an administrator.
	 *
	 * @return bool True when the current user is an admin.
	 */
	private function isAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}//end isAdmin()

	/**
	 * List all belplannen.
	 *
	 * @return JSONResponse The belplannen.
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T12
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// Belplannen are loaded through the local configured-state-aware helper.
		return new JSONResponse(['belplannen' => $this->loadBelplannen()]);
	}//end index()

	/**
	 * Create a belplan (admin only).
	 *
	 * @return JSONResponse The created belplan.
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T12
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function create(): JSONResponse {
		if ($this->isAdmin() === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('belplan_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return new JSONResponse(['error' => 'Belplan schema not configured'], Http::STATUS_BAD_REQUEST);
		}

		$name = (string)$this->request->getParam('name', '');
		if (trim($name) === '') {
			return new JSONResponse(['error' => 'naam is required'], Http::STATUS_BAD_REQUEST);
		}

		$record = [
			'name' => $name,
			'triggerNumber' => (array)$this->request->getParam('triggerNumber', []),
			'routingSteps' => (array)$this->request->getParam('routingSteps', []),
			'openingHours' => (string)$this->request->getParam('openingHours', ''),
			'fallbackAction' => (string)$this->request->getParam('fallbackAction', 'voicemail'),
			'priority' => (int)$this->request->getParam('priority', 0),
			'isActive' => (bool)$this->request->getParam('isActive', true),
		];

		try {
			$created = $objectService->saveObject(object: $record, register: $register, schema: $schema);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => 'Could not create belplan'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($this->toArray(result: $created));
	}//end create()

	/**
	 * Update a belplan (admin only).
	 *
	 * @param string $id The belplan UUID.
	 *
	 * @return JSONResponse The updated belplan.
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T12
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function update(string $id): JSONResponse {
		if ($this->isAdmin() === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('belplan_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return new JSONResponse(['error' => 'Belplan schema not configured'], Http::STATUS_BAD_REQUEST);
		}

		$patch = [];
		foreach (['name', 'triggerNumber', 'routingSteps', 'openingHours', 'fallbackAction', 'priority', 'isActive'] as $field) {
			$value = $this->request->getParam($field, null);
			if ($value !== null) {
				$patch[$field] = $value;
			}
		}

		try {
			$updated = $objectService->saveObject(object: $patch, register: $register, schema: $schema, uuid: $id);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => 'Could not update belplan'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($this->toArray(result: $updated));
	}//end update()

	/**
	 * Resolve a routing destination for a dialed number and menu selection.
	 *
	 * @return JSONResponse The routing decision.
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T12
	 */
	#[NoAdminRequired]
	public function route(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$phoneNumber = (string)$this->request->getParam('phoneNumber', '');
		$menuSelection = (string)$this->request->getParam('menuSelection', '');

		try {
			$result = $this->routingService->routeCall($phoneNumber, $menuSelection);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($result);
	}//end route()

	/**
	 * Load all belplannen via the routing service (configured-state aware).
	 *
	 * @return array<int, array<string, mixed>> The belplan records.
	 */
	private function loadBelplannen(): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('belplan_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		try {
			$results = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: ['_limit' => 200]);
		} catch (Throwable $e) {
			return [];
		}

		$records = [];
		foreach ((array)$results as $result) {
			$records[] = $this->toArray(result: $result);
		}

		return $records;
	}//end loadBelplannen()

	/**
	 * Normalise an ObjectService result into a plain array.
	 *
	 * @param mixed $result The ObjectService result.
	 *
	 * @return array<string, mixed> The normalised record.
	 */
	private function toArray($result): array {
		if (is_array($result) === true) {
			return $result;
		}

		if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
			return (array)$result->jsonSerialize();
		}

		if (is_object($result) === true) {
			return (array)$result;
		}

		return [];
	}//end toArray()
}//end class
