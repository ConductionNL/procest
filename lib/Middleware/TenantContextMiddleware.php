<?php

/**
 * Dossiq Tenant Context Middleware
 *
 * Resolves the requesting tenant from the SESSION and binds it onto the
 * request-scoped `TenantContext` service. Runs after the existing
 * `TenantMiddleware` (which does the older Organisation-shaped resolution) and
 * before `TenantIsolationMiddleware` (which sets the Postgres search_path).
 *
 * Resolution: `TenantSessionService::activeTenantId()`, which re-verifies the
 * session's choice against the user's `tenantUser` memberships on every read.
 *
 * WHAT CHANGED AND WHY. This used to return the `X-Tenant-Id` header verbatim.
 * The header is supplied by the caller, and the only check that would have
 * caught a forged value — `TenantClaimValidationMiddleware` — returns early
 * unless the request carries a Bearer token. A session-authenticated user
 * could therefore name any tenant and be believed, because each middleware was
 * individually reasonable and the gap was between them.
 *
 * The header no longer binds. Switching tenant is an explicit act that
 * verifies membership first; see `TenantSessionService`.
 *
 * @category Middleware
 * @package  OCA\Dossiq\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-04-tenant-context-isolation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Middleware;

use OCA\Dossiq\Service\TenantContext;
use OCA\Dossiq\Service\TenantProvisioningService;
use OCA\Dossiq\Service\TenantSaasService;
use OCA\Dossiq\Service\TenantSessionService;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Middleware that resolves the tenant and binds it to the TenantContext.
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-04-tenant-context-isolation/tasks.md
 */
class TenantContextMiddleware extends Middleware {
	/**
	 * Controllers whose endpoints do not require a tenant binding.
	 *
	 * @var array<int, string>
	 */
	private const EXEMPT_CONTROLLERS = [
		'OCA\Dossiq\Controller\SettingsController',
		// Health + metrics are served by the OpenRegister AppHost engine
		// (ADR-040); the dispatched controller is the generic class.
		'OCA\OpenRegister\AppHost\Controller\GenericHealthController',
		'OCA\OpenRegister\AppHost\Controller\GenericMetricsController',
		'OCA\Dossiq\Controller\TenantController',
		'OCA\Dossiq\Controller\TenantSaasController',
		'OCA\Dossiq\Controller\DashboardController',
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request Request.
	 * @param TenantSessionService $tenantSession Session-held active tenant.
	 * @param TenantSaasService $tenantSaasService Tenant SaaS service.
	 * @param TenantProvisioningService $provisioning Provisioning service (schema-name builder).
	 * @param TenantContext $context Request-scoped context.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly TenantSessionService $tenantSession,
		private readonly TenantSaasService $tenantSaasService,
		private readonly TenantProvisioningService $provisioning,
		private readonly TenantContext $context,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the tenant for the incoming request and bind it to the context.
	 *
	 * @param \OCP\AppFramework\Controller $controller Controller.
	 * @param string $methodName Method name.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $methodName is fixed by
	 * OCP\AppFramework\Middleware::beforeController(); tenant resolution keys off
	 * the controller class and the request, not the action name.
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-04-tenant-context-isolation/tasks.md
	 */
	public function beforeController($controller, $methodName): void {
		if (in_array(get_class($controller), self::EXEMPT_CONTROLLERS, true) === true) {
			return;
		}

		$tenantId = $this->resolveTenantIdFromRequest();
		if ($tenantId === null) {
			return;
		}

		$tenant = $this->tenantSaasService->getById($tenantId);
		if ($tenant === null) {
			$this->logger->info(
				'Dossiq: TenantContextMiddleware could not resolve tenant',
				['tenantId' => $tenantId]
			);
			return;
		}

		try {
			$schemaName = $this->provisioning->buildSchemaName(
				uuid: (string)($tenant['uuid'] ?? $tenant['id'] ?? $tenantId),
				slug: (string)($tenant['slug'] ?? '')
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: schema-name build failed in TenantContextMiddleware',
				['tenantId' => $tenantId, 'exception' => $e->getMessage()]
			);
			return;
		}

		$this->context->bind($tenant, $schemaName);
	}//end beforeController()

	/**
	 * Pre-controller exceptions surface to the dispatcher unchanged.
	 *
	 * @param \OCP\AppFramework\Controller $controller Controller.
	 * @param string $methodName Method name.
	 * @param \Exception $exception Exception.
	 *
	 * @return \OCP\AppFramework\Http\Response
	 *
	 * @throws \Exception
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $controller and $methodName are
	 * fixed by OCP\AppFramework\Middleware::afterException(); this hook only
	 * re-throws.
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-04-tenant-context-isolation/tasks.md
	 */
	public function afterException($controller, $methodName, \Exception $exception): \OCP\AppFramework\Http\Response {
		throw $exception;
	}//end afterException()

	/**
	 * Resolve the tenant UUID for the current request.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/multi-tenancy/spec.md#req-002-user-to-tenant-resolution-via-or-organisation-with-nc-group-fallback
	 */
	public function resolveTenantIdFromRequest(): ?string {
		$header = $this->request->getHeader('X-Tenant-Id');
		if ($header !== '') {
			// Deliberately IGNORED, and logged rather than silently dropped: a
			// caller still sending this is relying on behaviour that has been
			// removed, and would otherwise find their requests quietly acting
			// as a different tenant than they asked for.
			$this->logger->warning(
				'Dossiq: X-Tenant-Id was supplied and ignored; the session decides the tenant',
				['supplied' => $header]
			);
		}

		return $this->tenantSession->activeTenantId();
	}//end resolveTenantIdFromRequest()
}//end class
