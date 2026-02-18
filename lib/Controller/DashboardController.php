<?php

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

class DashboardController extends Controller
{
    public function __construct(IRequest $request)
    {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function page(): TemplateResponse
    {
        return new TemplateResponse(Application::APP_ID, 'index');
    }
}
