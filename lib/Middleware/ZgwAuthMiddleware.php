<?php

/**
 * ZGW Authentication Middleware
 *
 * Validates JWT tokens and enforces scopes on all ZGW API endpoints.
 *
 * @category Middleware
 * @package  OCA\Procest\Middleware
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

namespace OCA\Procest\Middleware;

use OCA\Procest\Controller\ZgwController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Middleware that validates JWT tokens and enforces ZGW scopes.
 *
 * Applied to all ZgwController requests. Validates the Authorization header,
 * checks JWT signature via OpenRegister's AuthorizationService, and verifies
 * the authenticated applicatie has the required scope for the request.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ZgwAuthMiddleware extends Middleware
{

    /**
     * Map of ZGW API groups to component codes.
     *
     * @var array<string, string>
     */
    private const API_TO_COMPONENT = [
        'zaken'        => 'zrc',
        'catalogi'     => 'ztc',
        'besluiten'    => 'brc',
        'documenten'   => 'drc',
        'notificaties' => 'nrc',
        'autorisaties' => 'ac',
    ];

    /**
     * Map of HTTP methods to ZGW scope suffixes.
     *
     * @var array<string, string>
     */
    private const METHOD_TO_SCOPE = [
        'GET'    => 'lezen',
        'POST'   => 'aanmaken',
        'PUT'    => 'bijwerken',
        'PATCH'  => 'bijwerken',
        'DELETE' => 'verwijderen',
    ];

    /**
     * Confidentiality levels from low to high.
     *
     * @var string[]
     */
    private const CONFIDENTIALITY_ORDER = [
        'openbaar',
        'beperkt_openbaar',
        'intern',
        'zaakvertrouwelijk',
        'vertrouwelijk',
        'confidentieel',
        'geheim',
        'zeer_geheim',
    ];

    /**
     * The OpenRegister AuthorizationService (loaded dynamically).
     *
     * @var object|null
     */
    private $authorizationService = null;

    /**
     * The OpenRegister ConsumerMapper (loaded dynamically).
     *
     * @var object|null
     */
    private $consumerMapper = null;

    /**
     * Constructor.
     *
     * @param IRequest        $request The incoming request
     * @param LoggerInterface $logger  The logger
     *
     * @return void
     */
    public function __construct(
        private readonly IRequest $request,
        private readonly LoggerInterface $logger,
    ) {
        $this->loadOpenRegisterServices();
    }//end __construct()

    /**
     * Load OpenRegister services dynamically.
     *
     * @return void
     */
    private function loadOpenRegisterServices(): void
    {
        try {
            $container = \OC::$server;
            $this->authorizationService = $container->get(
                'OCA\OpenRegister\Service\AuthorizationService'
            );
            $this->consumerMapper       = $container->get(
                'OCA\OpenRegister\Db\ConsumerMapper'
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ZgwAuthMiddleware: OpenRegister auth services not available',
                ['exception' => $e->getMessage()]
            );
        }
    }//end loadOpenRegisterServices()

    /**
     * Validate JWT and enforce scopes before controller execution.
     *
     * @param \OCP\AppFramework\Controller $controller The controller instance
     * @param string                       $methodName The method being called
     *
     * @return void
     *
     * @throws \OCA\Procest\Middleware\ZgwAuthException If authorization fails.
     */
    public function beforeController($controller, $methodName): void
    {
        if (($controller instanceof ZgwController) === false) {
            return;
        }

        $authorization = $this->request->getHeader(name: 'Authorization');
        if ($authorization === '') {
            throw new ZgwAuthException(
                message: 'Authorization header is required',
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        // Extract and validate JWT payload.
        $token   = substr(string: $authorization, offset: strlen(string: 'Bearer '));
        $payload = $this->decodeJwtPayload(token: $token);

        if ($payload === null || isset($payload['iss']) === false) {
            throw new ZgwAuthException(
                message: 'Invalid token payload',
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        // Validate JWT signature via OpenRegister's AuthorizationService.
        try {
            $this->authorizationService->authorizeJwt(authorization: $authorization);
        } catch (\Exception $e) {
            $this->logger->warning(
                'ZGW auth failed: '.$e->getMessage()
            );
            throw new ZgwAuthException(
                message: $e->getMessage(),
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        // Enforce scope-based authorization via ConsumerMapper.
        $consumer = $this->findConsumerByIssuer(issuer: $payload['iss']);
        if ($consumer === null) {
            throw new ZgwAuthException(
                message: 'Unknown issuer',
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        $authConfig = $consumer->getAuthorizationConfiguration();

        // Superuser bypasses all scope checks.
        if (($authConfig['superuser'] ?? false) === true) {
            return;
        }

        // Enforce scope-based authorization.
        $this->enforceScopes(authConfig: $authConfig);
    }//end beforeController()

    /**
     * Handle exceptions thrown during beforeController.
     *
     * @param \OCP\AppFramework\Controller $controller The controller
     * @param string                       $methodName The method name
     * @param \Exception                   $exception  The exception
     *
     * @return JSONResponse|null
     */
    public function afterException($controller, $methodName, \Exception $exception): ?JSONResponse
    {
        if ($exception instanceof ZgwAuthException) {
            return new JSONResponse(
                data: [
                    'type'   => 'https://datatracker.ietf.org/doc/html/rfc7231#section-6.5.3',
                    'code'   => 'permission_denied',
                    'title'  => 'U heeft geen toestemming om deze actie uit te voeren.',
                    'status' => $exception->getStatusCode(),
                    'detail' => $exception->getMessage(),
                ],
                statusCode: $exception->getStatusCode()
            );
        }

        return null;
    }//end afterException()

    /**
     * Enforce ZGW scope-based authorization.
     *
     * @param array $authConfig The consumer's authorization configuration
     *
     * @return void
     *
     * @throws ZgwAuthException If the scope check fails.
     */
    private function enforceScopes(array $authConfig): void
    {
        $scopes    = $authConfig['scopes'] ?? [];
        $zgwApi    = $this->request->getParam(key: 'zgwApi', default: '');
        $method    = $this->request->getMethod();
        $component = self::API_TO_COMPONENT[$zgwApi] ?? null;

        if ($component === null) {
            throw new ZgwAuthException(
                message: 'Unknown API component',
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        $requiredSuffix = self::METHOD_TO_SCOPE[$method] ?? null;
        if ($requiredSuffix === null) {
            throw new ZgwAuthException(
                message: 'Unsupported HTTP method',
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        // Check if any scope grants cover this request.
        foreach ($scopes as $scopeGrant) {
            if ($this->scopeGrantCovers(
                scopeGrant: $scopeGrant,
                component: $component,
                requiredSuffix: $requiredSuffix
            ) === true
            ) {
                return;
            }
        }

        throw new ZgwAuthException(
            message: "Scope '{$component}.{$requiredSuffix}' is required for this operation",
            statusCode: Http::STATUS_FORBIDDEN
        );
    }//end enforceScopes()

    /**
     * Check if a scope grant covers the required component and action.
     *
     * @param array  $scopeGrant     The scope grant configuration
     * @param string $component      The ZGW component code (zrc, ztc, etc.)
     * @param string $requiredSuffix The required scope suffix (lezen, aanmaken, etc.)
     *
     * @return bool True if the scope grant covers the request
     */
    private function scopeGrantCovers(
        array $scopeGrant,
        string $component,
        string $requiredSuffix
    ): bool {
        // Check component match.
        if (($scopeGrant['component'] ?? '') !== $component) {
            return false;
        }

        // Check scope includes the required action.
        $grantedScopes = $scopeGrant['scopes'] ?? [];

        foreach ($grantedScopes as $scope) {
            $parts = explode(separator: '.', string: $scope);
            if (count(value: $parts) === 2 && $parts[1] === $requiredSuffix) {
                return true;
            }
        }

        return false;
    }//end scopeGrantCovers()

    /**
     * Decode the JWT payload without verification (already verified by authorizeJwt).
     *
     * @param string $token The JWT token string
     *
     * @return array|null The decoded payload or null on failure
     */
    private function decodeJwtPayload(string $token): ?array
    {
        $parts = explode(separator: '.', string: $token);
        if (count(value: $parts) !== 3) {
            return null;
        }

        $payload = base64_decode(string: $parts[1]);
        if ($payload === false) {
            return null;
        }

        $decoded = json_decode(json: $payload, associative: true);
        if (is_array(value: $decoded) === false) {
            return null;
        }

        return $decoded;
    }//end decodeJwtPayload()

    /**
     * Find a Consumer entity by its issuer name.
     *
     * @param string $issuer The JWT issuer (maps to Consumer name)
     *
     * @return object|null The Consumer entity or null
     */
    private function findConsumerByIssuer(string $issuer): ?object
    {
        try {
            $consumers = $this->consumerMapper->findAll(
                filters: ['name' => $issuer]
            );
            if (count(value: $consumers) > 0) {
                return $consumers[0];
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                'Failed to find consumer for issuer: '.$issuer,
                ['exception' => $e->getMessage()]
            );
        }

        return null;
    }//end findConsumerByIssuer()

    /**
     * Compare confidentiality levels.
     *
     * @param string $actual The actual confidentiality level
     * @param string $max    The maximum allowed level
     *
     * @return bool True if actual is at or below max
     */
    public function isConfidentialityAllowed(string $actual, string $max): bool
    {
        $actualIndex = array_search(needle: $actual, haystack: self::CONFIDENTIALITY_ORDER);
        $maxIndex    = array_search(needle: $max, haystack: self::CONFIDENTIALITY_ORDER);

        if ($actualIndex === false || $maxIndex === false) {
            return false;
        }

        return $actualIndex <= $maxIndex;
    }//end isConfidentialityAllowed()
}//end class
