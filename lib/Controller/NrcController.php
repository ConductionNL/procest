<?php

/**
 * Procest NRC (Notificaties) Controller
 *
 * Controller for serving ZGW Notificaties API endpoints (kanaal, abonnement,
 * notificaties). Delegates standard CRUD to ZgwService and provides a
 * simple notificatie acceptance endpoint.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
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

namespace OCA\Procest\Controller;

use OCA\Procest\Service\ZgwService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * NRC (Notificaties) API Controller
 *
 * Handles ZGW Notificaties register resources: kanaal and abonnement,
 * plus a notificatie acceptance endpoint.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class NrcController extends Controller
{

    /**
     * The ZGW API identifier for the Notificaties register.
     *
     * @var string
     */
    private const ZGW_API = 'notificaties';

    /**
     * Constructor.
     *
     * @param string     $appName    The app name.
     * @param IRequest   $request    The incoming request.
     * @param ZgwService $zgwService The shared ZGW service.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ZgwService $zgwService,
    ) {
        parent::__construct($appName, $request);
    }//end __construct()

    /**
     * List resources of the given type.
     *
     * @param string $resource The ZGW resource name (e.g. kanaal, abonnement).
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function index(string $resource): JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        return $this->zgwService->handleIndex($this->request, self::ZGW_API, $resource);
    }//end index()

    /**
     * Create a new resource of the given type.
     *
     * @param string $resource The ZGW resource name.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function create(string $resource): JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        return $this->zgwService->handleCreate($this->request, self::ZGW_API, $resource);
    }//end create()

    /**
     * Retrieve a single resource by UUID.
     *
     * @param string $resource The ZGW resource name.
     * @param string $uuid     The resource UUID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function show(string $resource, string $uuid): JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        return $this->zgwService->handleShow($this->request, self::ZGW_API, $resource, $uuid);
    }//end show()

    /**
     * Full update (PUT) a resource by UUID.
     *
     * @param string $resource The ZGW resource name.
     * @param string $uuid     The resource UUID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function update(string $resource, string $uuid): JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        return $this->zgwService->handleUpdate(
            $this->request,
            self::ZGW_API,
            $resource,
            $uuid,
            false
        );
    }//end update()

    /**
     * Partial update (PATCH) a resource by UUID.
     *
     * @param string $resource The ZGW resource name.
     * @param string $uuid     The resource UUID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function patch(string $resource, string $uuid): JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        return $this->zgwService->handleUpdate(
            $this->request,
            self::ZGW_API,
            $resource,
            $uuid,
            true
        );
    }//end patch()

    /**
     * Delete a resource by UUID.
     *
     * @param string $resource The ZGW resource name.
     * @param string $uuid     The resource UUID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function destroy(string $resource, string $uuid): JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        return $this->zgwService->handleDestroy(
            $this->request,
            self::ZGW_API,
            $resource,
            $uuid
        );
    }//end destroy()

    /**
     * Accept a notificatie (echo back the body with 201).
     *
     * This endpoint receives incoming ZGW notifications and acknowledges them.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function notificatieCreate(): JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        $body = $this->request->getParams();
        unset($body['_route']);

        return new JSONResponse(data: $body, statusCode: Http::STATUS_CREATED);
    }//end notificatieCreate()

    /**
     * List audit trail entries for a resource.
     *
     * @param string $resource The ZGW resource name.
     * @param string $uuid     The resource UUID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function audittrailIndex(string $resource, string $uuid): JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        return $this->zgwService->handleAudittrailIndex($this->request, self::ZGW_API, $resource, $uuid);
    }//end audittrailIndex()

    /**
     * Retrieve a single audit trail entry for a resource.
     *
     * @param string $resource  The ZGW resource name.
     * @param string $uuid      The resource UUID.
     * @param string $auditUuid The audit trail entry UUID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function audittrailShow(string $resource, string $uuid, string $auditUuid): JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        return $this->zgwService->handleAudittrailShow(
            $this->request,
            self::ZGW_API,
            $resource,
            $uuid,
            $auditUuid
        );
    }//end audittrailShow()
}//end class
