<?php

/**
 * Dossiq Shillinq Integration Service
 *
 * Exports tenant billing events into Shillinq invoices.
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

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Shillinq HTTP integration with retry + backoff.
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-10-billing-shillinq/tasks.md
 */
class ShillinqIntegrationService {
	/**
	 * Maximum retry attempts.
	 */
	public const MAX_RETRIES = 3;

	/**
	 * Backoff sleep base in seconds.
	 */
	public const BACKOFF_BASE_SECONDS = 2;

	/**
	 * Constructor.
	 *
	 * @param IClientService $httpClientService The HTTP client service.
	 * @param LoggerInterface $logger The logger.
	 * @param string $shillinqBaseUrl The Shillinq base URL.
	 * @param string $shillinqApiKey The Shillinq API key.
	 */
	public function __construct(
		private readonly IClientService $httpClientService,
		private readonly LoggerInterface $logger,
		private readonly string $shillinqBaseUrl = '',
		private readonly string $shillinqApiKey = '',
	) {
	}//end __construct()

	/**
	 * Group events by tenant + month for invoicing.
	 *
	 * @param array<int, array<string,mixed>> $events Events.
	 *
	 * @return array<string, array<int, array<string,mixed>>> Keyed by `<tenantId>:<month>`.
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-10-billing-shillinq/tasks.md
	 */
	public function groupForInvoicing(array $events): array {
		$grouped = [];
		foreach ($events as $event) {
			$tenantId = (string)($event['tenantRef'] ?? '');
			$month = substr((string)($event['occurredAt'] ?? ''), 0, 7);
			if ($tenantId === '' || $month === '' || ($event['invoiceRef'] ?? null) !== null) {
				continue;
			}

			$key = $tenantId . ':' . $month;
			if (isset($grouped[$key]) === false) {
				$grouped[$key] = [];
			}

			$grouped[$key][] = $event;
		}

		return $grouped;
	}//end groupForInvoicing()

	/**
	 * Build the Shillinq invoice payload from a group of events.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $month YYYY-MM.
	 * @param array<int, array<string,mixed>> $events Events.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-10-billing-shillinq/tasks.md
	 */
	public function buildInvoicePayload(string $tenantId, string $month, array $events): array {
		$lineItems = [];
		foreach ($events as $event) {
			$lineItems[] = [
				'description' => (string)($event['eventType'] ?? 'usage'),
				'quantity' => (float)($event['quantity'] ?? 1),
				'unit_price' => (float)($event['unitPrice'] ?? 0),
				'currency' => (string)($event['currency'] ?? 'EUR'),
				'occurred_at' => (string)($event['occurredAt'] ?? ''),
			];
		}

		return [
			'tenant_id' => $tenantId,
			'period' => $month,
			'currency' => $lineItems[0]['currency'] ?? 'EUR',
			'line_items' => $lineItems,
		];
	}//end buildInvoicePayload()

	/**
	 * POST a built invoice payload to Shillinq with retry + backoff.
	 *
	 * @param array<string,mixed> $payload Payload.
	 *
	 * @return array{success:bool, invoiceRef?:string, attempts:int, lastError?:string}
	 *
	 * @spec openspec/specs/tenant-billing/spec.md#requirement-daily-billing-export-to-shillinq-req-007-b
	 */
	public function exportInvoice(array $payload): array {
		if ($this->shillinqBaseUrl === '' || $this->shillinqApiKey === '') {
			return ['success' => false, 'attempts' => 0, 'lastError' => 'Shillinq not configured'];
		}

		$client = $this->httpClientService->newClient();
		$attempt = 0;
		$lastErr = '';
		while ($attempt < self::MAX_RETRIES) {
			$attempt++;
			try {
				$resp = $client->post(
					$this->shillinqBaseUrl . '/invoices',
					[
						'headers' => [
							'Authorization' => 'Bearer ' . $this->shillinqApiKey,
							'Content-Type' => 'application/json',
						],
						'body' => json_encode($payload),
						'timeout' => 30,
					]
				);
				$body = (string)$resp->getBody();
				$json = json_decode($body, true);
				$ref = (string)($json['invoiceRef'] ?? $json['id'] ?? '');
				if ($ref !== '') {
					return ['success' => true, 'invoiceRef' => $ref, 'attempts' => $attempt];
				}
			} catch (Throwable $e) {
				$lastErr = $e->getMessage();
				if ($attempt < self::MAX_RETRIES) {
					sleep(self::BACKOFF_BASE_SECONDS ** $attempt);
				}
			}//end try
		}//end while

		$this->logger->error('Dossiq: Shillinq export failed after retries', ['attempts' => $attempt, 'lastError' => $lastErr]);
		return ['success' => false, 'attempts' => $attempt, 'lastError' => $lastErr];
	}//end exportInvoice()
}//end class
