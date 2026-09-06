<?php

/**
 * Dossiq Tenant Controller
 *
 * Controller for tenant provisioning, usage aggregation, and current-tenant
 * resolution. Generic tenant CRUD (list/create/update/delete) is delegated
 * to OpenRegister via the manifest renderer; this controller only owns
 * the multi-tenant domain logic that cannot be expressed declaratively.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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
 * @spec openspec/specs/multi-tenancy/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\TenantAuthenticationService;
use OCA\Dossiq\Service\TenantService;
use OCA\Dossiq\Service\TenantSessionService;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * Controller for tenant domain operations (provisioning, usage, current tenant).
 *
 * Generic CRUD (list/create/update/destroy) is no longer routed here — manifest
 * pages call the OpenRegister object endpoints directly. Only the three domain
 * methods below remain: they wrap provisioning workflow, resource-usage
 * aggregation, and current-tenant resolution.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class TenantController extends Controller {
	/**
	 * Constructor for the TenantController.
	 *
	 * @param IRequest $request The request object
	 * @param TenantService $tenantService The tenant service
	 * @param IUserSession $userSession The user session
	 * @param TenantSessionService $tenantSession The session-held active tenant.
	 * @param TenantAuthenticationService $tenantAuthentication Membership lookups.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private TenantService $tenantService,
		private IUserSession $userSession,
		private TenantSessionService $tenantSession,
		private TenantAuthenticationService $tenantAuthentication,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Provision a tenant with register, group, and default schemas.
	 *
	 * @param string $tenantId The tenant UUID
	 *
	 * @return JSONResponse The provisioning result
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function provision(string $tenantId): JSONResponse {
		if ($this->isPlatformAdmin() === false) {
			return new JSONResponse(['success' => false, 'error' => 'Admin required'], 403);
		}

		$result = $this->tenantService->provisionTenant($tenantId);

		if (isset($result['error']) === true) {
			return new JSONResponse(['success' => false, 'error' => $result['error']], 500);
		}

		return new JSONResponse(['success' => true, 'tenant' => $result]);
	}//end provision()

	/**
	 * Get resource usage for a tenant.
	 *
	 * @param string $tenantId The tenant UUID
	 *
	 * @return JSONResponse The resource usage
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function usage(string $tenantId): JSONResponse {
		if ($this->isPlatformAdmin() === false) {
			return new JSONResponse(['success' => false, 'error' => 'Admin required'], 403);
		}

		$usage = $this->tenantService->getResourceUsage($tenantId);
		return new JSONResponse(['success' => true, 'usage' => $usage]);
	}//end usage()

	/**
	 * Get the current user's tenant.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse The current tenant
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function current(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$tenant = $this->tenantService->getTenantForUser($user->getUID());
		if ($tenant === null) {
			return new JSONResponse(
				['success' => true, 'tenant' => null, 'message' => 'No tenant assigned']
			);
		}

		return new JSONResponse(['success' => true, 'tenant' => $tenant]);
	}//end current()

	/**
	 * The tenants the signed-in user may act as.
	 *
	 * The switcher needs this: a user with several memberships resolves to no
	 * tenant until they choose one, and they cannot choose from a list they
	 * cannot see.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse The memberships.
	 *
	 * @spec openspec/changes/tenancy-onto-openregister-organisation/proposal.md
	 */
	public function memberships(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$tenantIds = $this->tenantAuthentication->listTenantsForUser(userId: $user->getUID());
		} catch (Throwable $e) {
			// Fail CLOSED, and say so. An empty list rendered as a clean answer
			// would show the user "you belong to nothing" when the truth is
			// "we could not find out".
			return new JSONResponse(
				['success' => false, 'error' => 'Could not read your tenant memberships'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse(
			[
				'success' => true,
				'tenants' => $tenantIds,
				'active' => $this->tenantSession->activeTenantId(),
			]
		);
	}//end memberships()

	/**
	 * Switch the session to another tenant the user belongs to.
	 *
	 * WHY THIS IS AN ENDPOINT AND NOT A HEADER. The tenant a request acts as
	 * used to come from `X-Tenant-Id`, which the caller supplies — so the
	 * caller chose their own tenant and nothing verified they could. Switching
	 * is now an explicit act whose membership is checked at the moment it
	 * happens, and the result lives in the session rather than in something the
	 * next request can retype.
	 *
	 * A refusal is deliberately a 403 with no detail about whether the tenant
	 * exists: telling an outsider "that tenant is real, you just are not on it"
	 * enumerates the tenant list.
	 *
	 * @param string $tenantId The tenant to switch to.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse The outcome.
	 *
	 * @spec openspec/changes/tenancy-onto-openregister-organisation/proposal.md
	 */
	public function switchTenant(string $tenantId = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->tenantSession->switchTo($tenantId) === false) {
			return new JSONResponse(
				['success' => false, 'error' => 'You cannot act as that tenant'],
				Http::STATUS_FORBIDDEN
			);
		}

		return new JSONResponse(['success' => true, 'active' => $this->tenantSession->activeTenantId()]);
	}//end switchTenant()


	/**
	 * Check if current user is a platform administrator.
	 *
	 * @return bool True if admin
	 */
	private function isPlatformAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->tenantService->isPlatformAdmin($user->getUID());
	}//end isPlatformAdmin()
}//end class
