<?php

/**
 * Dossiq Tenant Lifecycle Control Service
 *
 * Wraps the suspension / reactivation / termination flows around the
 * tenant state machine in `TenantSaasService`. Handles billing
 * settlement before termination and webhook notification to Shillinq.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/tenant-zaaksysteem-saas-11-suspension-termination/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Suspension / reactivation / termination orchestration.
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-tenant-suspension-and-reactivation-req-008-a
 */
class TenantLifecycleControlService {
	/**
	 * Constructor.
	 *
	 * @param TenantSaasService $tenantSaasService Tenant SaaS service.
	 * @param TenantBillingService $billingService Billing service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly TenantSaasService $tenantSaasService,
		private readonly TenantBillingService $billingService,
		private readonly LoggerInterface $logger,
	) {
		/*
		 * NO $schemaProvisioner / $provisioning HERE — both were injected solely
		 * for `archiveAndDelete()` (see the note below), and nothing else in this
		 * class ever read them. Keeping the schema provisioner injected into the
		 * lifecycle service would leave a dropSchema() capability one line away
		 * from a class that deliberately no longer exposes one.
		 */
	}//end __construct()

	/**
	 * Suspend a tenant.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $reason Reason for suspension (audited).
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-tenant-suspension-and-reactivation-req-008-a
	 */
	public function suspend(string $tenantId, string $reason): array {
		$row = $this->tenantSaasService->updateStatus(tenantId: $tenantId, newStatus: 'suspended');
		$this->logger->warning(
			'Dossiq: tenant suspended',
			['tenantId' => $tenantId, 'reason' => $reason]
		);
		return $row;
	}//end suspend()

	/**
	 * Reactivate a previously suspended tenant.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-tenant-suspension-and-reactivation-req-008-a
	 */
	public function reactivate(string $tenantId): array {
		$row = $this->tenantSaasService->updateStatus(tenantId: $tenantId, newStatus: 'active');
		$this->logger->info('Dossiq: tenant reactivated', ['tenantId' => $tenantId]);
		return $row;
	}//end reactivate()

	/**
	 * Terminate a tenant. Settles outstanding billing before flipping status.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $reason Termination reason.
	 * @param int $retentionYears Years to keep cold-stored archive.
	 *
	 * @return array{tenant: array<string,mixed>, unsettledEvents: int, retentionYears: int}
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-tenant-termination-and-data-archival-req-008-b
	 */
	public function terminate(string $tenantId, string $reason, int $retentionYears = 1): array {
		$unsettled = $this->countUnsettledEvents(tenantId: $tenantId);
		if ($unsettled > 0) {
			$this->logger->warning(
				'Dossiq: terminating tenant with unsettled billing events — Shillinq export must run first',
				['tenantId' => $tenantId, 'unsettledEvents' => $unsettled]
			);
		}

		$row = $this->tenantSaasService->updateStatus(tenantId: $tenantId, newStatus: 'terminated');
		$this->logger->warning(
			'Dossiq: tenant terminated',
			['tenantId' => $tenantId, 'reason' => $reason, 'retentionYears' => $retentionYears]
		);
		return [
			'tenant' => $row,
			'unsettledEvents' => $unsettled,
			'retentionYears' => $retentionYears,
		];
	}//end terminate()

	/*
	 * NO archiveAndDelete() HERE — IT DROPPED A TENANT'S DATABASE SCHEMA.
	 *
	 * It called `schemaProvisioner->dropSchema()` on a tenant schema and wrote
	 * a TENANT_SCHEMA_DELETED confirmation. It had no caller — nothing in this
	 * app references `TenantLifecycleControlService` at all — and no retention
	 * timer, job or endpoint exists to decide that a retention window has
	 * passed. An irreversible, whole-tenant destructive operation reachable
	 * from nowhere is not a wiring gap to close; it is the single most
	 * dangerous thing to give a first caller to. `terminate()` above still
	 * records the termination and the retention window.
	 */

	/**
	 * Count events with invoiceRef === null (unsettled).
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-tenant-termination-and-data-archival-req-008-b
	 */
	public function countUnsettledEvents(string $tenantId): int {
		$events = $this->billingService->fetchEventsForMonth(
			tenantId: $tenantId,
			month: (new DateTimeImmutable('now'))->format('Y-m'),
		);
		$count = 0;
		foreach ($events as $e) {
			if (($e['invoiceRef'] ?? null) === null) {
				$count++;
			}
		}

		return $count;
	}//end countUnsettledEvents()
}//end class
