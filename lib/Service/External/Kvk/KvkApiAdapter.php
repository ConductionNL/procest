<?php

/**
 * Live Dossiq KvK Handelsregister adapter (external-integrations-test-environments).
 *
 * Calls the KvK Handelsregister Zoeken API. The DEFAULT tier targets the
 * KvK Developer Portal TEST environment
 * (`https://api.kvk.nl/test/api/v2/zoeken`) with the OPEN, publicly
 * published shared test key `l7xx1f2691f2520d487b902f4e0b57a0b197`
 * (documented on developers.kvk.nl/documentation/testing) — no
 * registration, a fixed set of fictitious companies (KVK 69599084,
 * 68750110, 69599068, 55344526, …). Selected by `integration.kvk.mode`;
 * base URL / apikey overridable via `integration.kvk.baseUrl` /
 * `integration.kvk.apiKey`.
 *
 * Any transport/HTTP failure degrades to a `LOOKUP_ERROR` result (never
 * throws into the lifecycle), mirroring the dormant Log adapter.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\External\Kvk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 * @link https://developers.kvk.nl/documentation/testing
 *
 * @spec openspec/specs/external-integration-test-wiring/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\External\Kvk;

use OCA\Dossiq\Service\External\IntegrationMode;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Live KvK Zoeken adapter (test / live tiers).
 *
 * @spec openspec/specs/external-integration-test-wiring/spec.md
 */
class KvkApiAdapter implements KvkHandelsregisterAdapterInterface {
	/**
	 * Default base URL — the KvK Developer Portal test environment.
	 */
	public const DEFAULT_BASE_URL = 'https://api.kvk.nl/test/api';

	/**
	 * Publicly published shared KvK TEST api key (developers.kvk.nl).
	 * Not a secret — it is printed on the official testing page and only
	 * unlocks the fixed fictitious-company set on api.kvk.nl/test.
	 */
	public const PUBLIC_TEST_API_KEY = 'l7xx1f2691f2520d487b902f4e0b57a0b197';

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService HTTP client factory.
	 * @param IntegrationMode $mode Config-tier resolver.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly IntegrationMode $mode,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Look up a legal entity by KvK number against the configured tier.
	 *
	 * @param string $kvkNumber 8-digit KvK number.
	 * @param array<string,mixed> $context Lookup context.
	 *
	 * @return KvkLookupResult
	 *
	 * @spec openspec/specs/external-integration-test-wiring/spec.md
	 */
	public function lookup(string $kvkNumber, array $context = []): KvkLookupResult {
		$baseUrl = $this->mode->setting(integration: 'kvk', key: 'baseUrl', default: self::DEFAULT_BASE_URL);
		$apiKey = $this->mode->setting(integration: 'kvk', key: 'apiKey', default: self::PUBLIC_TEST_API_KEY);

		try {
			$response = $this->clientService->newClient()->get(
				rtrim($baseUrl, '/') . '/v2/zoeken',
				[
					'timeout' => 10,
					'query' => ['kvkNumber' => $kvkNumber],
					'headers' => ['apikey' => $apiKey, 'Accept' => 'application/json'],
				]
			);

			$data = json_decode((string)$response->getBody(), true);
			$results = [];
			if (is_array($data) === true) {
				$results = (array)($data['resultaten'] ?? []);
			}

			if ($results === []) {
				return new KvkLookupResult(lookupStatus: 'NOT_FOUND', kvkNumber: $kvkNumber, entity: [], dormant: false);
			}

			// Prefer the hoofdvestiging (carries the address); else first.
			$entity = (array)$results[0];
			foreach ($results as $row) {
				if (is_array($row) === true && ($row['type'] ?? '') === 'hoofdvestiging') {
					$entity = $row;
					break;
				}
			}

			return new KvkLookupResult(
				lookupStatus: 'FOUND',
				kvkNumber: $kvkNumber,
				entity: $entity,
				dormant: false,
				extras: ['tier' => $this->mode->resolve(integration: 'kvk', allowed: [IntegrationMode::TEST, IntegrationMode::LIVE])]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq KvK Handelsregister lookup failed',
				[
					'kvkNumber' => $kvkNumber,
					'error' => $e->getMessage(),
					'context' => $context,
				]
			);

			return new KvkLookupResult(
				lookupStatus: 'LOOKUP_ERROR',
				kvkNumber: $kvkNumber,
				entity: [],
				dormant: false,
				extras: ['reason' => 'transport-error']
			);
		}//end try

	}//end lookup()

	/**
	 * A configured live adapter is not dormant.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/external-integration-test-wiring/spec.md
	 */
	public function isDormant(): bool {
		return false;
	}//end isDormant()
}//end class
