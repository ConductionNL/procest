<?php

/**
 * Dossiq Tenant Middleware
 *
 * Middleware for enforcing multi-tenant data isolation.
 *
 * @category Middleware
 * @package  OCA\Dossiq\Middleware
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

namespace OCA\Dossiq\Middleware;

use Exception;
use OCA\Dossiq\Service\TenantService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Middleware that resolves and enforces tenant context for all requests.
 *
 * Ensures that users can only access data belonging to their tenant.
 * Platform admins can access any tenant via context switching.
 * Returns 404 (not 403) for cross-tenant access to prevent information leakage.
 *
 * @spec openspec/specs/tenant-isolation/spec.md
 */
class TenantMiddleware extends Middleware {
	/**
	 * Controllers that are exempt from tenant enforcement.
	 */
	private const EXEMPT_CONTROLLERS = [
		'OCA\Dossiq\Controller\SettingsController',
		// Health + metrics are served by the OpenRegister AppHost engine
		// (ADR-040); the dispatched controller is the generic class.
		'OCA\OpenRegister\AppHost\Controller\GenericHealthController',
		'OCA\OpenRegister\AppHost\Controller\GenericMetricsController',
		'OCA\Dossiq\Controller\DashboardController',
		'OCA\Dossiq\Controller\TenantController',
	];

	/**
	 * Constructor for the TenantMiddleware.
	 *
	 * @param TenantService $tenantService The tenant service
	 * @param IUserSession $userSession The user session
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private TenantService $tenantService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Check tenant context before controller execution.
	 *
	 * @param \OCP\AppFramework\Controller $controller The controller
	 * @param string $methodName The method name
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $methodName is fixed by
	 * OCP\AppFramework\Middleware::beforeController(); the tenant check keys off
	 * the controller class and the request, not the action name.
	 *
	 * @spec openspec/specs/tenant-isolation/spec.md
	 */
	public function beforeController($controller, $methodName): void {
		// Skip for exempt controllers.
		$controllerClass = get_class($controller);
		if (in_array($controllerClass, self::EXEMPT_CONTROLLERS) === true) {
			return;
		}

		// Skip for public pages (no user session).
		$user = $this->userSession->getUser();
		if ($user === null) {
			return;
		}

		$userId = $user->getUID();

		// Platform admins bypass tenant restrictions.
		if ($this->tenantService->isPlatformAdmin($userId) === true) {
			return;
		}

		// Resolve tenant for the current user (delegates to OR Organisation).
		$tenant = $this->tenantService->getTenantForUser($userId);
		if ($tenant === null) {
			$this->logger->warning(
				'Dossiq: User has no tenant assigned',
				['userId' => $userId]
			);
			// Allow access even without tenant (single-tenant deployments).
			return;
		}

		// Per consume-or-tenant-fleet-wide: lifecycle status enforcement lives
		// in OR's tenant-lifecycle. Block requests scoped to a non-active tenant.
		$tenantUuid = ($tenant['uuid'] ?? $tenant['id'] ?? '');
		$status = ($tenant['status'] ?? null);
		if ($status !== null && $status !== '' && $status !== 'active') {
			$this->logger->info(
				'Dossiq: Request blocked because tenant is not active',
				['userId' => $userId, 'tenantUuid' => $tenantUuid, 'status' => $status]
			);
			throw new Exception('Organisation is ' . $status, 403);
		}

		// No tenant context is published onto the request here.
		//
		// This block used to call `$this->request->setParameter(...)`. That
		// method does not exist on `OCP\IRequest` nor on the concrete
		// `OC\AppFramework\Http\Request`, so every request that reached this
		// point died with `Error: Call to undefined method
		// OC\AppFramework\Http\Request::setParameter()` and Nextcloud answered
		// HTTP 500 with an HTML error page. In other words: for any user who
		// DID have a tenant, every non-exempt Dossiq endpoint was a 500 —
		// while the single-tenant deployments that CI and the e2e rig exercise
		// return at the `$tenant === null` branch above and never reached it.
		//
		// Nothing ever read the three keys back (`_tenantId`,
		// `_tenantRegisterId`, `_tenantSlug` have no reader anywhere in `lib/`
		// or `src/`), so they are removed rather than re-homed: adding a
		// request-scoped context service with no consumer would be dead code
		// in a different shape. Tenant *enforcement* — the lifecycle check
		// above — is unaffected and still runs.
	}//end beforeController()

	/**
	 * Handle exceptions from controllers.
	 *
	 * @param \OCP\AppFramework\Controller $controller The controller
	 * @param string $methodName The method name
	 * @param \Exception $exception The exception
	 *
	 * @return JSONResponse The error response
	 *
	 * @throws \Exception Re-throws if not a tenant exception
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $controller and $methodName are
	 * fixed by OCP\AppFramework\Middleware::afterException(); only $exception is
	 * inspected.
	 *
	 * @spec openspec/specs/tenant-isolation/spec.md
	 */
	public function afterException($controller, $methodName, \Exception $exception): JSONResponse {
		if ($exception->getCode() === 404) {
			return new JSONResponse(
				['success' => false, 'error' => 'Not found'],
				404
			);
		}

		if ($exception->getCode() === 403) {
			// Surface OR-Organisation status block to the caller.
			$message = $exception->getMessage();
			$status = 'inactive';
			if (preg_match('/Organisation is (\\w+)/', $message, $matches) === 1) {
				$status = $matches[1];
			}

			return new JSONResponse(
				['success' => false, 'error' => $message, 'status' => $status],
				403
			);
		}

		// Per the Nextcloud middleware contract, re-throw any exception this
		// middleware does not own so MiddlewareDispatcher::afterException() can
		// offer it to the next middleware (a middleware that returns null here
		// would trip the dispatcher's non-nullable Response return type).
		throw $exception;
	}//end afterException()
}//end class
