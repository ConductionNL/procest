<?php

/**
 * Dossiq Tenant Billing Service
 *
 * Emits insert-only `tenantBillingEvent` rows and aggregates them for the
 * billing dashboard / Shillinq export.
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
 * @spec openspec/changes/tenant-zaaksysteem-saas-10-billing-shillinq/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Billing event service.
 *
 * @spec openspec/specs/tenant-billing/spec.md
 */
class TenantBillingService {
	/**
	 * Allowed event types (mirrors the schema enum).
	 *
	 * @var array<int, string>
	 */
	public const ALLOWED_EVENT_TYPES = [
		'case_created',
		'case_closed',
		'user_activated',
		'storage_increment',
		'api_burst',
		'quota_exceeded',
		'case_refund',
	];

	/**
	 * Monthly subscription price per tier, in EUR. Drives the `user_activated`
	 * billing line emitted at tenant go-live.
	 *
	 * @var array<string, float>
	 */
	public const TIER_MONTHLY_PRICE = [
		'basic' => 49.0,
		'standard' => 149.0,
		'enterprise' => 499.0,
	];

	/**
	 * Resolve the monthly subscription price for a tier (0.0 when unknown).
	 *
	 * @param string $tier Tier slug.
	 *
	 * @return float
	 *
	 * @spec openspec/specs/tenant-billing/spec.md
	 */
	public function tierMonthlyPrice(string $tier): float {
		return (float)(self::TIER_MONTHLY_PRICE[$tier] ?? 0.0);
	}//end tierMonthlyPrice()

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager App manager.
	 * @param ContainerInterface $container Service container.
	 * @param LoggerInterface $logger Logger.
	 * @param ShillinqIntegrationService $shillinq Shillinq invoice exporter.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly ShillinqIntegrationService $shillinq,
	) {
	}//end __construct()

	/**
	 * Run the end-to-end monthly invoicing for one tenant: collect the month's
	 * unbilled usage events, compute the amount, export a Shillinq invoice, and
	 * stamp the events with the returned invoice reference.
	 *
	 * This is the orchestration the billing pipeline lacked: emitEvent /
	 * aggregate / groupForInvoicing / buildInvoicePayload / exportInvoice /
	 * markExported all existed but nothing chained them, so every tenant
	 * invoice was EUR0 and exportInvoice had zero callers (procest#223
	 * finding 2 — orphaned billing capability).
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $month YYYY-MM.
	 *
	 * @return array{tenantId:string, month:string, eventCount:int, amount:float, currency:string, exported:bool, invoiceRef:?string, error:?string}
	 *
	 * @throws InvalidArgumentException When month is malformed.
	 *
	 * @spec openspec/specs/tenant-billing/spec.md
	 */
	public function runInvoicing(string $tenantId, string $month): array {
		if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
			throw new InvalidArgumentException('Month must be YYYY-MM: ' . $month);
		}

		$events = $this->fetchEventsForMonth(tenantId: $tenantId, month: $month);
		$unbilled = array_values(array_filter($events, static fn ($e) => ($e['invoiceRef'] ?? null) === null));
		$summary = $this->aggregate(events: $unbilled);
		$amount = (float)$summary['totalAmount'];
		$currency = 'EUR';
		if ($unbilled !== []) {
			$currency = (string)($unbilled[0]['currency'] ?? 'EUR');
		}

		$result = [
			'tenantId' => $tenantId,
			'month' => $month,
			'eventCount' => count($unbilled),
			'amount' => $amount,
			'currency' => $currency,
			'exported' => false,
			'invoiceRef' => null,
			'error' => null,
		];

		if ($unbilled === []) {
			$result['error'] = 'no unbilled events';
			return $result;
		}

		$payload = $this->shillinq->buildInvoicePayload(tenantId: $tenantId, month: $month, events: $unbilled);
		$exportRc = $this->shillinq->exportInvoice(payload: $payload);
		if ($exportRc['success'] !== true) {
			$result['error'] = (string)($exportRc['lastError'] ?? 'export failed');
			return $result;
		}

		$invoiceRef = (string)($exportRc['invoiceRef'] ?? '');
		$result['exported'] = true;
		$result['invoiceRef'] = $invoiceRef;
		$this->markExported(events: $unbilled, invoiceRef: $invoiceRef);

		return $result;
	}//end runInvoicing()

	/**
	 * Emit a billing event. Insert-only — invoiceRef stays NULL until the
	 * Shillinq exporter sets it.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $eventType Event type (must be in ALLOWED_EVENT_TYPES).
	 * @param float $quantity Quantity (default 1; negative for refunds).
	 * @param float $unitPrice Unit price.
	 * @param string $currency Currency.
	 *
	 * @return array<string,mixed>|null Persisted event row.
	 *
	 * @throws InvalidArgumentException On invalid event type.
	 *
	 * @spec openspec/specs/tenant-billing/spec.md#requirement-billing-event-emission-on-case-lifecycle-req-007-a
	 */
	public function emitEvent(string $tenantId, string $eventType, float $quantity = 1.0, float $unitPrice = 0.0, string $currency = 'EUR'): ?array {
		if (in_array($eventType, self::ALLOWED_EVENT_TYPES, true) === false) {
			throw new InvalidArgumentException('Unknown billing event type: ' . $eventType);
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$event = [
			'tenantRef' => $tenantId,
			'eventType' => $eventType,
			'quantity' => $quantity,
			'unitPrice' => $unitPrice,
			'currency' => $currency,
			'occurredAt' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
			'invoiceRef' => null,
		];

		try {
			return $objectService->saveObject(
				object: $event,
				register: TenantSaasService::REGISTER,
				schema: 'tenantBillingEvent',
				uuid: null,
			);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: emitEvent failed', ['eventType' => $eventType, 'exception' => $e->getMessage()]);
			return null;
		}
	}//end emitEvent()

	/**
	 * Aggregate billing for a month.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $month YYYY-MM.
	 *
	 * @return array{eventCount:int, totalAmount:float, byType:array<string, array{count:float, amount:float}>}
	 *
	 * @throws InvalidArgumentException When month is malformed.
	 *
	 * @spec openspec/specs/tenant-billing/spec.md#requirement-billing-event-emission-on-case-lifecycle-req-007-a
	 */
	public function getMonthBilling(string $tenantId, string $month): array {
		if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
			throw new InvalidArgumentException('Month must be YYYY-MM: ' . $month);
		}

		$events = $this->fetchEventsForMonth(tenantId: $tenantId, month: $month);
		return $this->aggregate(events: $events);
	}//end getMonthBilling()

	/**
	 * Compute the net effect across events (refunds reduce totals).
	 *
	 * @param array<int, array<string,mixed>> $events Event rows.
	 *
	 * @return array{eventCount:int, totalAmount:float, byType:array<string, array{count:float, amount:float}>}
	 *
	 * @spec openspec/specs/tenant-billing/spec.md#requirement-billing-event-emission-on-case-lifecycle-req-007-a
	 */
	public function aggregate(array $events): array {
		$byType = [];
		$totalAmount = 0.0;
		foreach ($events as $event) {
			$type = (string)($event['eventType'] ?? 'unknown');
			$quantity = (float)($event['quantity'] ?? 0);
			$unit = (float)($event['unitPrice'] ?? 0);
			$amount = ($quantity * $unit);

			if (isset($byType[$type]) === false) {
				$byType[$type] = ['count' => 0.0, 'amount' => 0.0];
			}

			$byType[$type]['count'] += $quantity;
			$byType[$type]['amount'] += $amount;
			$totalAmount += $amount;
		}

		return ['eventCount' => count($events), 'totalAmount' => round($totalAmount, 2), 'byType' => $byType];
	}//end aggregate()

	/**
	 * Mark a batch of events as exported under a single invoice reference.
	 *
	 * @param array<int, array<string,mixed>> $events Event rows.
	 * @param string $invoiceRef Shillinq invoice ref.
	 *
	 * @return int Number of events updated.
	 *
	 * @spec openspec/specs/tenant-billing/spec.md#requirement-daily-billing-export-to-shillinq-req-007-b
	 */
	public function markExported(array $events, string $invoiceRef): int {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return 0;
		}

		$updated = 0;
		foreach ($events as $event) {
			if (($event['invoiceRef'] ?? null) !== null) {
				// Already exported — idempotent skip.
				continue;
			}

			$event['invoiceRef'] = $invoiceRef;
			try {
				$uuid = (string)($event['uuid'] ?? $event['id'] ?? '');
				$uuidArg = null;
				if ($uuid !== '') {
					$uuidArg = $uuid;
				}

				$objectService->saveObject(
					object: $event,
					register: TenantSaasService::REGISTER,
					schema: 'tenantBillingEvent',
					uuid: $uuidArg,
				);
				$updated++;
			} catch (Throwable $e) {
				$this->logger->error('Dossiq: markExported write failed', ['exception' => $e->getMessage()]);
			}
		}//end foreach

		return $updated;
	}//end markExported()

	/**
	 * Fetch all events for a given month for a tenant.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $month YYYY-MM.
	 *
	 * @return array<int, array<string,mixed>>
	 *
	 * @spec openspec/specs/tenant-billing/spec.md#requirement-daily-billing-export-to-shillinq-req-007-b
	 */
	public function fetchEventsForMonth(string $tenantId, string $month): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		try {
			// ObjectService::findAll() takes a single $config array — the previous
			// named-argument form threw "Unknown named parameter $register" and
			// was swallowed by the catch below. Register/schema live inside
			// `filters`; limit/offset are top-level config keys.
			$rows = $objectService->findAll(
				[
					'filters' => [
						'register' => TenantSaasService::REGISTER,
						'schema' => 'tenantBillingEvent',
						'tenantRef' => $tenantId,
					],
					'limit' => 5000,
					'offset' => 0,
				]
			);
		} catch (Throwable $e) {
			return [];
		}

		if (is_array($rows) === false) {
			$rows = [];
		}

		return array_values(array_filter($rows, fn ($r) => str_starts_with((string)($r['occurredAt'] ?? ''), $month)));
	}//end fetchEventsForMonth()

	/**
	 * Resolve the OpenRegister ObjectService when available.
	 *
	 * @return mixed|null The ObjectService instance, or null when unavailable.
	 */
	private function getObjectService() {
		// IAppManager::getInstalledApps() declares its array return in PHPDoc
		// only, so normalise defensively before the membership test.
		$installed = (array)$this->appManager->getInstalledApps();
		if (in_array('openregister', $installed, true) === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			return null;
		}
	}//end getObjectService()
}//end class
