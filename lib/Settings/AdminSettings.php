<?php

declare(strict_types=1);

namespace OCA\Procest\Settings;

use OCA\Procest\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings
{
    public function getForm(): TemplateResponse
    {
        return new TemplateResponse(Application::APP_ID, 'settings/admin');
    }

    public function getSection(): string
    {
        return 'procest';
    }

    public function getPriority(): int
    {
        return 10;
    }
}
