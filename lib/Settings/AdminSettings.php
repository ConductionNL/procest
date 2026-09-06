<?php

/**
 * Dossiq Admin Settings
 *
 * Provides the admin settings form for the Dossiq application.
 *
 * @category Settings
 * @package  OCA\Dossiq\Settings
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Settings;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\IDelegatedSettings;

/**
 * Provides the admin settings form for the Dossiq application.
 *
 * Implements IDelegatedSettings so the form can be guarded by
 * #[AuthorizedAdminSetting(settings: AdminSettings::class)] on the
 * controllers that mutate Dossiq configuration.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
class AdminSettings implements IDelegatedSettings {
	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager The app manager.
	 * @param IInitialState $initialState The initial state service.
	 * @param SettingsService $settingsService Reads the stored config values.
	 */
	public function __construct(
		private IAppManager $appManager,
		private IInitialState $initialState,
		private SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * Get the settings form template.
	 *
	 * Also seeds the initial state the consultation and mandate-matrix tabs
	 * already call `loadState()` for. Both tabs shipped reading
	 * `consultationSettings` / `mandaatSettings` and both defaulted to `{}`
	 * because nothing ever provided them, so the form showed hardcoded
	 * defaults regardless of what an administrator had saved — the read half of
	 * the same silent failure as procest#794's dead write routes.
	 *
	 * @return TemplateResponse
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function getForm(): TemplateResponse {
		$version = $this->appManager->getAppVersion(appId: Application::APP_ID);

		$this->initialState->provideInitialState('version', $version);
		$this->initialState->provideInitialState(
			'consultationSettings',
			$this->consultationSettings()
		);
		$this->initialState->provideInitialState(
			'mandaatSettings',
			$this->mandateSettings()
		);

		return new TemplateResponse(
			Application::APP_ID,
			'settings/admin',
			[]
		);
	}//end getForm()

	/**
	 * Build the consultation tab's initial state.
	 *
	 * Unset keys fall back to the same defaults the Vue component declares, so
	 * a fresh instance renders identically to before this was wired up.
	 *
	 * @return array<string, mixed> The consultation settings.
	 */
	private function consultationSettings(): array {
		return [
			'defaultDeadlineDays' => (int)$this->settingsService->getConfigValue(
				'consultation_default_deadline_days',
				'28'
			),
			'warningOffsetDays' => (int)$this->settingsService->getConfigValue(
				'consultation_warning_offset_days',
				'5'
			),
			'externalResponseUrl' => $this->settingsService->getConfigValue(
				'consultation_external_response_url',
				''
			),
			'bottleneckThreshold' => (float)$this->settingsService->getConfigValue(
				'consultation_bottleneck_threshold',
				'0.2'
			),
			'writable' => true,
		];
	}//end consultationSettings()

	/**
	 * Build the mandate-matrix tab's initial state.
	 *
	 * @return array<string, mixed> The mandate matrix settings.
	 */
	private function mandateSettings(): array {
		return [
			'decideskConnection' => $this->settingsService->getConfigValue(
				'mandaat_decidesk_connection',
				'decidesk-default'
			),
			'defaultExtensionDays' => (int)$this->settingsService->getConfigValue(
				'mandaat_default_extension_days',
				'14'
			),
			'autoFinalizeApproved' => $this->settingsService->getConfigValue(
				'mandaat_auto_finalize_approved',
				''
			) === '1',
			'writable' => true,
		];
	}//end mandaatSettings()

	/**
	 * Get the section ID this settings page belongs to.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function getSection(): string {
		return 'dossiq';
	}//end getSection()

	/**
	 * Get the priority for ordering within the section.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function getPriority(): int {
		return 10;
	}//end getPriority()

	/**
	 * Human-readable name of the delegated settings section.
	 *
	 * @return string|null The section name, or null to use the section default.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function getName(): ?string {
		return null;
	}//end getName()

	/**
	 * App config keys an authorized (delegated) admin may manage.
	 *
	 * Returned as a map of appId => list of allowed config keys. Dossiq
	 * exposes no delegatable sub-keys yet, so this is intentionally empty;
	 * the attribute still scopes the endpoint to full admins.
	 *
	 * @return array<string,string[]> Map of appId to allowed config keys.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function getAuthorizedAppConfig(): array {
		return [];
	}//end getAuthorizedAppConfig()
}//end class
