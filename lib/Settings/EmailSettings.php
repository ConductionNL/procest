<?php

/**
 * Dossiq Email (shared-mailbox) Admin Settings
 *
 * Registers the case-email-integration admin settings surface inside the
 * Dossiq settings section. The form mounts the same settings SPA
 * (`settings/admin`); the email-specific panel is rendered by
 * `src/views/settings/EmailSettings.vue` inside `AdminRoot`. This class
 * scopes the shared-mailbox IMAP config keys for delegated admins.
 *
 * @category Settings
 * @package  OCA\Dossiq\Settings
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Settings;

use OCA\Dossiq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\IDelegatedSettings;

/**
 * Admin settings registration for the shared case-email mailbox.
 *
 * Implements IDelegatedSettings so the form can be guarded by
 * #[AuthorizedAdminSetting(settings: EmailSettings::class)] on the
 * controllers that mutate the shared-mailbox configuration, and so the
 * email config keys can be delegated to non-root admins.
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */
class EmailSettings implements IDelegatedSettings {
	/**
	 * Shared-mailbox IMAP + poller config keys this section manages.
	 *
	 * Mirrors EmailTemplateController::IMAP_KEYS. The password key is
	 * stored sensitive and never delegated as a readable value.
	 *
	 * @var string[]
	 */
	private const MANAGED_KEYS = [
		'email_imap_host',
		'email_imap_port',
		'email_imap_encryption',
		'email_imap_username',
		'email_imap_folder',
		'email_transport',
		'email_poll_interval',
		'email_poll_batch_size',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager The app manager.
	 * @param IInitialState $initialState The initial state service.
	 */
	public function __construct(
		private IAppManager $appManager,
		private IInitialState $initialState,
	) {
	}//end __construct()

	/**
	 * Get the settings form template.
	 *
	 * Renders the shared Dossiq settings SPA; the email panel is mounted
	 * by AdminRoot. The app version is published for the version card.
	 *
	 * @return TemplateResponse
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 */
	public function getForm(): TemplateResponse {
		$version = $this->appManager->getAppVersion(appId: Application::APP_ID);

		$this->initialState->provideInitialState('version', $version);

		return new TemplateResponse(
			Application::APP_ID,
			'settings/email',
			[]
		);
	}//end getForm()

	/**
	 * Get the section ID this settings page belongs to.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 */
	public function getSection(): string {
		return 'dossiq';
	}//end getSection()

	/**
	 * Get the priority for ordering within the section.
	 *
	 * Higher than AdminSettings (10) so the SPA mounts once at the top and
	 * this entry orders after it within the same section.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 */
	public function getPriority(): int {
		return 60;
	}//end getPriority()

	/**
	 * Human-readable name of the delegated settings entry.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 */
	public function getName(): ?string {
		return 'Case email (shared mailbox)';
	}//end getName()

	/**
	 * App config keys an authorized (delegated) admin may manage.
	 *
	 * The sensitive `email_imap_password` is intentionally excluded from the
	 * delegatable set — it is written via the controller with the sensitive
	 * flag and never surfaced as a readable delegated value.
	 *
	 * @return array<string,string[]> Map of appId to allowed config keys.
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 */
	public function getAuthorizedAppConfig(): array {
		return [Application::APP_ID => self::MANAGED_KEYS];
	}//end getAuthorizedAppConfig()
}//end class
