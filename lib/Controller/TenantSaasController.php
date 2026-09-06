<?php

/**
 * Dossiq Tenant SaaS Controller
 *
 * REST API for SaaS tenant CRUD + lifecycle transitions, backed by the
 * `tenant` register schema declared in chain member 01.
 *
 * Separate from `TenantController` (which owns the older OR-Organisation
 * shape and current-tenant resolution). All endpoints here are admin-only
 * (Nextcloud SecurityMiddleware default — no `@NoAdminRequired`).
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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
 * @spec openspec/changes/tenant-zaaksysteem-saas-02-tenant-crud-lifecycle/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use InvalidArgumentException;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\TenantBillingService;
use OCA\Dossiq\Service\TenantSaasService;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use RuntimeException;

/**
 * Tenant SaaS CRUD + lifecycle controller.
 *
 * Routes:
 *   POST   /api/saas/tenants                  → create
 *   GET    /api/saas/tenants                  → index (list, optional ?status=)
 *   GET    /api/saas/tenants/{tenantId}       → show
 *   PATCH  /api/saas/tenants/{tenantId}       → update (display + optional status)
 *   DELETE /api/saas/tenants/{tenantId}       → destroy
 *   POST   /api/saas/tenants/{tenantId}/status → transition
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-02-tenant-crud-lifecycle/tasks.md
 */
class TenantSaasController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request.
	 * @param TenantSaasService $tenantSaasService Tenant SaaS service.
	 * @param TenantBillingService $billingService Tenant billing service.
	 */
	public function __construct(
		IRequest $request,
		private readonly TenantSaasService $tenantSaasService,
		private readonly TenantBillingService $billingService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List tenants, optionally filtered by status.
	 *
	 * @param string|null $status Optional status filter.
	 * @param int $limit Page size (default 100).
	 * @param int $offset Page offset.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-02-tenant-crud-lifecycle/tasks.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function index(?string $status = null, int $limit = 100, int $offset = 0): JSONResponse {
		$rows = $this->tenantSaasService->listActive(statusFilter: $status, limit: $limit, offset: $offset);
		return new JSONResponse(['success' => true, 'results' => $rows, 'total' => count($rows)]);
	}//end index()

	/**
	 * Create a new tenant.
	 *
	 * Body: { name, kvkNumber, tier }
	 *
	 * @param string $name Display name.
	 * @param string $kvkNumber KvK number.
	 * @param string $tier Tier (basic|standard|enterprise).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-02-tenant-crud-lifecycle/tasks.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function create(string $name = '', string $kvkNumber = '', string $tier = ''): JSONResponse {
		if ($name === '' || $kvkNumber === '' || $tier === '') {
			return new JSONResponse(
				['success' => false, 'error' => 'name, kvkNumber, and tier are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$row = $this->tenantSaasService->create(name: $name, kvkNumber: $kvkNumber, tier: $tier);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_CONFLICT);
		} catch (RuntimeException $e) {
			return new JSONResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['success' => true, 'tenant' => $row], Http::STATUS_CREATED);
	}//end create()

	/**
	 * Show a single tenant.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-02-tenant-crud-lifecycle/tasks.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function show(string $tenantId): JSONResponse {
		$row = $this->tenantSaasService->getById($tenantId);
		if ($row === null) {
			return new JSONResponse(['success' => false, 'error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['success' => true, 'tenant' => $row]);
	}//end show()

	/**
	 * Update a tenant — currently only the status (other writable fields land
	 * via the OpenRegister manifest renderer).
	 *
	 * Body: { status }
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $status Target status.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-02-tenant-crud-lifecycle/tasks.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function update(string $tenantId, string $status = ''): JSONResponse {
		if ($status === '') {
			return new JSONResponse(['success' => false, 'error' => 'status is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$row = $this->tenantSaasService->updateStatus(tenantId: $tenantId, newStatus: $status);
		} catch (InvalidArgumentException $e) {
			$code = Http::STATUS_CONFLICT;
			if (str_contains($e->getMessage(), 'not found') === true) {
				$code = Http::STATUS_NOT_FOUND;
			}

			return new JSONResponse(['success' => false, 'error' => $e->getMessage()], $code);
		} catch (RuntimeException $e) {
			return new JSONResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['success' => true, 'tenant' => $row]);
	}//end update()

	/**
	 * Delete a tenant. The state machine blocks deletion of non-terminated rows.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-02-tenant-crud-lifecycle/tasks.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function destroy(string $tenantId): JSONResponse {
		$row = $this->tenantSaasService->getById($tenantId);
		if ($row === null) {
			return new JSONResponse(['success' => false, 'error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$current = (string)($row['status'] ?? '');
		if ($current !== 'terminated') {
			return new JSONResponse(
				[
					'success' => false,
					'error' => 'Only terminated tenants can be deleted. Transition to terminated first.',
				],
				Http::STATUS_CONFLICT
			);
		}

		$deleted = $this->tenantSaasService->delete($tenantId);
		if ($deleted === false) {
			return new JSONResponse(['success' => false, 'error' => 'Failed to delete tenant'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['success' => true]);
	}//end destroy()

	/**
	 * Aggregate a tenant's usage billing for a month (computed, not exported).
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $month YYYY-MM.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/tenant-billing/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function billingSummary(string $tenantId, string $month): JSONResponse {
		try {
			$summary = $this->billingService->getMonthBilling(tenantId: $tenantId, month: $month);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['success' => true, 'summary' => $summary]);
	}//end billingSummary()

	/**
	 * Run monthly invoicing for a tenant: aggregate unbilled usage, export a
	 * Shillinq invoice, and stamp the events. Returns the computed amount and
	 * the invoice reference.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $month YYYY-MM.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/tenant-billing/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function runBilling(string $tenantId, string $month): JSONResponse {
		try {
			$result = $this->billingService->runInvoicing(tenantId: $tenantId, month: $month);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['success' => true, 'invoice' => $result]);
	}//end runBilling()
}//end class
