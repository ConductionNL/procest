<?php

declare(strict_types=1);

namespace OCA\Procest\Sections;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class SettingsSection implements IIconSection
{
    public function __construct(
        private IL10N $l,
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function getID(): string
    {
        return 'procest';
    }

    public function getName(): string
    {
        return $this->l->t('Procest');
    }

    public function getPriority(): int
    {
        return 75;
    }

    public function getIcon(): string
    {
        return $this->urlGenerator->imagePath('procest', 'app.svg');
    }
}
