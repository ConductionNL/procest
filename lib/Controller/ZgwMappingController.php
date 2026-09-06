<?php

/**
 * Dossiq ZGW Mapping Controller
 *
 * Controller for managing ZGW API mapping configurations via the admin UI.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-procest/tasks.md#task-1
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Repair\LoadDefaultZgwMappings;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\ZgwMappingService;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for managing ZGW API mapping configurations.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class ZgwMappingController extends Controller {
	/**
	 * Constructor for the ZgwMappingController.
	 *
	 * @param IRequest $request The request object
	 * @param ZgwMappingService $zgwMappingService The ZGW mapping service
	 * @param SettingsService $settingsService The settings service
	 * @param LoggerInterface $logger The logger interface
	 * @param IL10N $l10n The localization service
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly ZgwMappingService $zgwMappingService,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List all ZGW mapping configurations.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function index(): JSONResponse {
		return new JSONResponse(
			[
				'success' => true,
				'mappings' => $this->zgwMappingService->listMappings(),
			]
		);
	}//end index()

	/**
	 * Get a single ZGW mapping configuration.
	 *
	 * @param string $resourceKey The ZGW resource key
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function show(string $resourceKey): JSONResponse {
		$mapping = $this->zgwMappingService->getMapping($resourceKey);

		if ($mapping === null) {
			return new JSONResponse(
				[
					'success' => false,
					'message' => $this->l10n->t('No mapping configured for %s', [$resourceKey]),
				]
			);
		}

		return new JSONResponse(
			[
				'success' => true,
				'mapping' => $mapping,
			]
		);
	}//end show()

	/**
	 * Save a ZGW mapping configuration.
	 *
	 * @param string $resourceKey The ZGW resource key
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function update(string $resourceKey): JSONResponse {
		$params = $this->request->getParams();

		// Remove framework params.
		unset($params['_route'], $params['resourceKey']);

		$this->zgwMappingService->saveMapping(resourceKey: $resourceKey, config: $params);

		return new JSONResponse(
			[
				'success' => true,
				'mapping' => $this->zgwMappingService->getMapping($resourceKey),
			]
		);
	}//end update()

	/**
	 * Delete a ZGW mapping configuration.
	 *
	 * @param string $resourceKey The ZGW resource key
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function destroy(string $resourceKey): JSONResponse {
		$this->zgwMappingService->deleteMapping($resourceKey);

		return new JSONResponse(
			[
				'success' => true,
			]
		);
	}//end destroy()

	/**
	 * Reset a single mapping to its default configuration.
	 *
	 * @param string $resourceKey The ZGW resource key
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function reset(string $resourceKey): JSONResponse {
		$registerId = $this->settingsService->getConfigValue(key: 'register', default: '');
		if ($registerId === '') {
			return new JSONResponse(
				[
					'success' => false,
					'message' => $this->l10n->t('No Dossiq register configured'),
				]
			);
		}

		$loader = new LoadDefaultZgwMappings(
			zgwMappingService: $this->zgwMappingService,
			settingsService: $this->settingsService,
			logger: $this->logger,
		);
		$defaults = $loader->getDefaultMappings(registerId: $registerId);

		$this->zgwMappingService->resetToDefault(resourceKey: $resourceKey, defaults: $defaults);

		return new JSONResponse(
			[
				'success' => true,
				'mapping' => $this->zgwMappingService->getMapping($resourceKey),
			]
		);
	}//end reset()
}//end class
