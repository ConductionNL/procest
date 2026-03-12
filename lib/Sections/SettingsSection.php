<?php

/**
 * Procest Settings Section
 *
 * Defines the Procest section in the Nextcloud admin settings.
 *
 * @category Sections
 * @package  OCA\Procest\Sections
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Sections;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Defines the Procest section in the Nextcloud admin settings.
 */
class SettingsSection implements IIconSection
{
    /**
     * Constructor for SettingsSection.
     *
     * @param IL10N         $l            The localization service
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
     */
    public function getID(): string
    {
        return 'procest';
    }//end getID()

    /**
     * Get the display name of this section.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->l->t('Procest');
    }//end getName()

    /**
     * Get the priority for ordering this section.
     *
     * @return int
     */
    public function getPriority(): int
    {
        return 75;
    }//end getPriority()

    /**
     * Get the icon path for this section.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return $this->urlGenerator->imagePath(appName: 'procest', file: 'app-dark.svg');
    }//end getIcon()
}//end class
