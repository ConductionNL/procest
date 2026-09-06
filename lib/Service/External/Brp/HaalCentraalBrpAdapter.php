<?php

/**
 * Live Dossiq BRP / Haal Centraal Personen adapter (external-integrations-test-environments).
 *
 * Calls the Haal Centraal BRP Personen bevragen API — the same
 * koppelvlak served offline by `ghcr.io/brp-api/personen-mock` (port
 * 5010, `/haalcentraal/api/brp/personen`) and by the official
 * proefomgeving (`https://proefomgeving.haalcentraal.nl/haalcentraal/api/brp`).
 * Selected by `integration.brp.mode` ∈ {mock, test}; the base URL and
 * X-API-KEY come from `integration.brp.baseUrl` / `integration.brp.apiKey`.
 *
 * Never logs the BSN (AVG / WBP art. 9). Any transport/HTTP failure
 * degrades to a `LOOKUP_ERROR` result (never throws into the lifecycle),
 * mirroring the dormant Log adapter's fail-soft contract.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\External\Brp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 * @link https://github.com/BRP-API/Haal-Centraal-BRP-bevragen
 *
 * @spec openspec/specs/external-integration-test-wiring/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\External\Brp;

use OCA\Dossiq\Service\External\IntegrationMode;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Live BRP Personen bevragen adapter (mock / proefomgeving tiers).
 *
 * @spec openspec/specs/external-integration-test-wiring/spec.md
 */
class HaalCentraalBrpAdapter implements BrpHaalCentraalAdapterInterface {
	/**
	 * Default base URL — the offline docker mock's koppelvlak.
	 */
	private const DEFAULT_BASE_URL = 'http://localhost:5010/haalcentraal/api/brp';

	/**
	 * Person fields requested from the API (no more than the lifecycle needs).
	 *
	 * @var array<int, string>
	 */
	private const FIELDS = ['citizenServiceNumber', 'name', 'birth', 'residence'];

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService HTTP client factory.
	 * @param IntegrationMode $mode Config-tier resolver.
	 * @param LoggerInterface $logger Structured logger (BSN never passed).
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly IntegrationMode $mode,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Look up a natural person by BSN against the configured BRP tier.
	 *
	 * @param string $bsn 9-digit Burgerservicenummer — never logged.
	 * @param array<string,mixed> $context Lookup context.
	 *
	 * @return BrpLookupResult
	 *
	 * @spec openspec/specs/external-integration-test-wiring/spec.md
	 */
	public function lookup(string $bsn, array $context = []): BrpLookupResult {
		$baseUrl = $this->mode->setting(integration: 'brp', key: 'baseUrl', default: self::DEFAULT_BASE_URL);
		$apiKey = $this->mode->setting(integration: 'brp', key: 'apiKey');

		$payload = [
			'type' => 'RaadpleegMetBurgerservicenummer',
			'citizenServiceNumber' => [$bsn],
			'fields' => self::FIELDS,
		];

		$headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
		if ($apiKey !== '') {
			$headers['X-API-KEY'] = $apiKey;
		}

		try {
			$response = $this->clientService->newClient()->post(
				rtrim($baseUrl, '/') . '/personen',
				[
					'timeout' => 10,
					'json' => $payload,
					'headers' => $headers,
				]
			);

			$data = json_decode((string)$response->getBody(), true);
			$personen = [];
			if (is_array($data) === true) {
				$personen = (array)($data['personen'] ?? []);
			}

			if ($personen === []) {
				return new BrpLookupResult(lookupStatus: 'NOT_FOUND', persoon: [], dormant: false);
			}

			$persoon = (array)$personen[0];
			// Strip the BSN back out — the caller already holds it and it
			// MUST NOT persist beyond the autorisatieprofiel-protected need.
			unset($persoon['citizenServiceNumber']);

			return new BrpLookupResult(
				lookupStatus: 'FOUND',
				persoon: $persoon,
				dormant: false,
				extras: ['tier' => $this->mode->resolve(integration: 'brp', allowed: [IntegrationMode::MOCK, IntegrationMode::TEST])]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq BRP / Haal Centraal lookup failed',
				[
					'bsn' => '[REDACTED]',
					'error' => $e->getMessage(),
					'context' => $context,
				]
			);

			return new BrpLookupResult(
				lookupStatus: 'LOOKUP_ERROR',
				persoon: [],
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
