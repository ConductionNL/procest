<?php

/**
 * Cases Overview Dashboard Widget
 *
 * Displays a list of recent open cases in the Nextcloud Dashboard.
 *
 * @category Dashboard
 * @package  OCA\Dossiq\Dashboard
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
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-procest/tasks.md#task-5
 * @spec openspec/specs/dashboard/spec.md
 * @spec openspec/specs/dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Dashboard;

use OCA\Dossiq\AppInfo\Application;
use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

/**
 * Dashboard widget showing an overview of recent cases.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
class CasesOverviewWidget implements IWidget {
	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n L10N service
	 * @param IURLGenerator $url URL generator
	 */
	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $url,
	) {
	}//end __construct()

	/**
	 * Get the unique identifier for this widget.
	 *
	 * @inheritDoc
	 * @return string The widget identifier
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	public function getId(): string {
		// FROZEN at `procest_*` — deliberately NOT renamed with the app id.
		// Nextcloud's Dashboard app persists each user's chosen widgets by
		// WIDGET ID, in its own `dashboard` app namespace in oc_preferences.
		// This app's MigrateUserPreferences cannot reach that namespace, so a
		// renamed id would not be migrated: the widget would silently vanish
		// from the dashboard of every user who had added it, with no error and
		// nothing in the log. Renaming these is safe only once a migration that
		// rewrites the `dashboard` app's stored layout ships alongside it.
		return 'procest_cases_overview_widget';
	}//end getId()

	/**
	 * Get the display title for this widget.
	 *
	 * @inheritDoc
	 * @return string The widget title
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	public function getTitle(): string {
		return $this->l10n->t('Cases overview');
	}//end getTitle()

	/**
	 * Get the display order for this widget.
	 *
	 * @inheritDoc
	 * @return int The widget order
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	public function getOrder(): int {
		return 10;
	}//end getOrder()

	/**
	 * Get the CSS icon class for this widget.
	 *
	 * @inheritDoc
	 * @return string The icon CSS class
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	public function getIconClass(): string {
		return 'icon-dossiq-widget';
	}//end getIconClass()

	/**
	 * Get the URL for the widget's full view.
	 *
	 * @inheritDoc
	 * @return string|null The widget URL or null
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	public function getUrl(): ?string {
		return $this->url->linkToRouteAbsolute(Application::APP_ID . '.dashboard.page');
	}//end getUrl()

	/**
	 * Load the widget scripts and styles.
	 *
	 * @inheritDoc
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) — Nextcloud Util API is static by design
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	public function load(): void {
		// Shared vendor chunks emitted by webpack splitChunks (see webpack.config.js).
		Util::addScript(Application::APP_ID, Application::APP_ID . '-shared-vendor');
		Util::addScript(Application::APP_ID, Application::APP_ID . '-shared-nc-vue');
		Util::addScript(Application::APP_ID, Application::APP_ID . '-casesOverviewWidget');
		Util::addStyle(Application::APP_ID, 'dashboardWidgets');

	}//end load()
}//end class
