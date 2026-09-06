<?php

/**
 * Deadline Alerts Dashboard Widget
 *
 * Displays cases approaching or past their processing deadline
 * in the Nextcloud Dashboard.
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
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-procest/tasks.md#task-4
 * @spec openspec/specs/signalering-widgets/spec.md
 * @spec openspec/specs/signalering-widgets/spec.md
 * @spec openspec/specs/signalering-widgets/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Dashboard;

use OCA\Dossiq\AppInfo\Application;
use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

/**
 * Dashboard widget showing deadline alerts for cases.
 *
 * @spec openspec/specs/signalering-widgets/spec.md
 */
class DeadlineAlertsWidget implements IWidget {
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
	 * @spec openspec/specs/signalering-widgets/spec.md
	 */
	public function getId(): string {
		// FROZEN at the old app-id prefix — see CasesOverviewWidget::getId().
		return 'procest_deadline_alerts_widget';
	}//end getId()

	/**
	 * Get the display title for this widget.
	 *
	 * @inheritDoc
	 * @return string The widget title
	 *
	 * @spec openspec/specs/signalering-widgets/spec.md
	 */
	public function getTitle(): string {
		return $this->l10n->t('Deadline Alerts');
	}//end getTitle()

	/**
	 * Get the display order for this widget.
	 *
	 * @inheritDoc
	 * @return int The widget order
	 *
	 * @spec openspec/specs/signalering-widgets/spec.md
	 */
	public function getOrder(): int {
		return 11;
	}//end getOrder()

	/**
	 * Get the CSS icon class for this widget.
	 *
	 * @inheritDoc
	 * @return string The icon CSS class
	 *
	 * @spec openspec/specs/signalering-widgets/spec.md
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
	 * @spec openspec/specs/signalering-widgets/spec.md
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
	 * @spec openspec/specs/signalering-widgets/spec.md
	 */
	public function load(): void {
		// Shared vendor chunks emitted by webpack splitChunks (see webpack.config.js).
		Util::addScript(Application::APP_ID, Application::APP_ID . '-shared-vendor');
		Util::addScript(Application::APP_ID, Application::APP_ID . '-shared-nc-vue');
		Util::addScript(Application::APP_ID, Application::APP_ID . '-deadlineAlertsWidget');
		Util::addStyle(Application::APP_ID, 'dashboardWidgets');

	}//end load()
}//end class
