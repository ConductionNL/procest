<?php

/**
 * Dossiq Settings Section
 *
 * Defines the Dossiq section in the Nextcloud admin settings.
 *
 * @category Sections
 * @package  OCA\Dossiq\Sections
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

namespace OCA\Dossiq\Sections;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Defines the Dossiq section in the Nextcloud admin settings.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
class SettingsSection implements IIconSection {
	/**
	 * Constructor for SettingsSection.
	 *
	 * @param IL10N $l The localization service
	 * @param IURLGenerator $urlGenerator The URL generator service
	 *
	 * @return void
	 */
	public function __construct(
		private IL10N $l,
		private IURLGenerator $urlGenerator,
	) {
	}//end __construct()

	/**
	 * Get the section identifier.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function getID(): string {
		return 'dossiq';
	}//end getID()

	/**
	 * Get the display name of this section.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function getName(): string {
		return $this->l->t('Dossiq');
	}//end getName()

	/**
	 * Get the priority for ordering this section.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function getPriority(): int {
		return 75;
	}//end getPriority()

	/**
	 * Get the icon path for this section.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function getIcon(): string {
		return $this->urlGenerator->imagePath(appName: 'dossiq', file: 'app-dark.svg');
	}//end getIcon()
}//end class
