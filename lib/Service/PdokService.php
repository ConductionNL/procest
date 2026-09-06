<?php

/**
 * Dossiq PDOK Service.
 *
 * Thin shim that fronts the openconnector PDOK source adapters
 * (`PdokGeocodingClient`, `PdokWmsSourceAdapter`, `PdokWfsSourceAdapter`)
 * for dossiq backend callers. Per ADR-022 (apps-consume-OR-abstractions),
 * dossiq does NOT re-implement Locatieserver, BAG, WMS or WFS access
 * itself: every PDOK request is dispatched through the openconnector
 * HTTP shim at `/index.php/apps/openconnector/api/pdok/*`. The
 * `pdok.feature_flag` openconnector key gates the live binding; while
 * the flag is `0` openconnector returns synthetic deferred responses.
 *
 * Capabilities exposed:
 *   - `searchAddress(query, ...)` — address autocomplete via
 *     {@see PdokLocatieserverService::suggest()}.
 *   - `lookupAddress(id)` — single-result lookup by Locatieserver id.
 *   - `searchParcel(criteria)` — kadastraal perceel search via the
 *     openconnector WFS adapter (BAG / Kadaster intersection).
 *   - `getServiceStatus()` — health + flag introspection so the caller
 *     can render the dormant-vs-live mode.
 *
 * Error handling:
 *   - openconnector 503 (PDOK unavailable / circuit open): the shim
 *     returns an empty result and exposes the openconnector
 *     `message_key` via `lastWarning()` so the caller can surface the
 *     i18n string.
 *   - openconnector 404 (not installed): the shim records the
 *     missing-shim warning and resolves with an empty result so the
 *     containing case form stays submittable.
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
 * @spec openspec/specs/gis-integration/spec.md
 * @spec openspec/changes/migrate-pdok-to-openconnector/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Pdok\PdokLocatieserverService;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Backend-side PDOK shim consuming the openconnector PDOK source adapters.
 *
 * @spec openspec/specs/gis-integration/spec.md
 */
class PdokService {
	/**
	 * Openconnector app id.
	 */
	public const OPENCONNECTOR_APP = 'openconnector';

	/**
	 * Feature-flag key checked on the openconnector side.
	 */
	public const FEATURE_FLAG_KEY = 'pdok.feature_flag';

	/**
	 * Path template at openconnector for PDOK Locatieserver methods.
	 */
	private const SHIM_BASE_PATH = '/apps/openconnector/api/pdok';

	/**
	 * Last recorded degraded-mode warning, accessible to callers for UI
	 * surfacing. Reset to null on every successful call.
	 *
	 * @var array{messageKey:string,status:int}|null
	 */
	private ?array $lastWarning = null;

	/**
	 * HTTP client created lazily.
	 *
	 * @var IClient
	 */
	private ?IClient $client = null;

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService HTTP client factory.
	 * @param IAppManager $appManager For openconnector
	 *                                installed-check.
	 * @param IAppConfig $appConfig App-config accessor.
	 * @param IURLGenerator $urlGenerator Builds the absolute
	 *                                    openconnector URL.
	 * @param PdokLocatieserverService $locatieserver Existing in-app PDOK
	 *                                                ingress (cache +
	 *                                                outage tracking).
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly IAppManager $appManager,
		private readonly IAppConfig $appConfig,
		private readonly IURLGenerator $urlGenerator,
		private readonly PdokLocatieserverService $locatieserver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Autocomplete an address query via the openconnector PDOK shim.
	 *
	 * @param string $query Free-text address fragment.
	 * @param array<int, string> $filters Optional Solr-style filter queries.
	 * @param int $rows Maximum suggestions to return.
	 *
	 * @return array<int, array<string,mixed>> Normalised suggestion list.
	 *
	 * @spec openspec/changes/migrate-pdok-to-openconnector/tasks.md
	 */
	public function searchAddress(string $query, array $filters = [], int $rows = 10): array {
		$this->lastWarning = null;
		if (strlen(trim($query)) < 3) {
			return [];
		}

		try {
			$response = $this->locatieserver->suggest($query, $filters, $rows);
		} catch (Throwable $e) {
			return $this->handleDegradedMode(error: $e, messageKey: 'pdok.unavailable');
		}

		$docs = (array)($response['response']['docs'] ?? []);
		return array_values(
			array_filter(
				$docs,
				static fn ($doc): bool => is_array($doc),
			)
		);
	}//end searchAddress()

	/**
	 * Look up a single Locatieserver result by id.
	 *
	 * @param string $id Locatieserver id returned by `searchAddress`.
	 *
	 * @return array<string,mixed>|null The normalised address envelope,
	 *                                  or null when not found / degraded.
	 *
	 * @spec openspec/changes/migrate-pdok-to-openconnector/tasks.md
	 */
	public function lookupAddress(string $id): ?array {
		$this->lastWarning = null;
		if ($id === '') {
			return null;
		}

		try {
			$response = $this->locatieserver->lookup($id);
		} catch (Throwable $e) {
			$this->handleDegradedMode(error: $e, messageKey: 'pdok.unavailable');
			return null;
		}

		$docs = (array)($response['response']['docs'] ?? []);
		if (is_array($docs[0] ?? null) === true) {
			return $docs[0];
		}

		return null;
	}//end lookupAddress()

	/**
	 * Search a kadastraal perceel via the openconnector WFS adapter.
	 *
	 * Either `bbox` (minLng,minLat,maxLng,maxLat) or `perceelnummer` /
	 * `kadastraleAanduiding` may be passed; passing both narrows the search.
	 *
	 * @param array<string,mixed> $criteria Search criteria.
	 *
	 * @return array<int, array<string,mixed>> Matching parcels (may be empty).
	 *
	 * @spec exclude phpstan dead-code cleanup only — dropped an always-false `$route === null`
	 *       branch on a `string`-typed value; no behavioural or contractual change.
	 */
	public function searchParcel(array $criteria): array {
		$this->lastWarning = null;
		if ($this->appManager->isInstalled(self::OPENCONNECTOR_APP) === false) {
			$this->recordWarning(messageKey: 'pdok.openconnector_missing', status: 404);
			return [];
		}

		$route = $this->urlGenerator->linkToRoute('openconnector.pdok.parcel');
		if ($route === '') {
			$route = self::SHIM_BASE_PATH . '/parcel';
		}

		$url = $this->urlGenerator->getAbsoluteURL($route);

		try {
			$response = $this->getClient()->post(
				$url,
				[
					'timeout' => 10,
					'json' => $criteria,
					'headers' => ['Accept' => 'application/json'],
				]
			);
			$body = (string)$response->getBody();
			$data = json_decode($body, true);
			if (is_array($data) === false) {
				return [];
			}

			return (array)($data['features'] ?? $data['parcels'] ?? []);
		} catch (Throwable $e) {
			$this->handleDegradedMode(error: $e, messageKey: 'pdok.parcel.unavailable');
			return [];
		}
	}//end searchParcel()

	/**
	 * Report on the runtime status of the PDOK shim.
	 *
	 * @return array{
	 *     openconnectorInstalled: bool,
	 *     featureFlagActive: bool,
	 *     lastWarning: array{messageKey:string,status:int}|null,
	 * }
	 *
	 * @spec exclude phpstan dead-code cleanup only — dropped an always-false `$route === null`
	 */
	public function getServiceStatus(): array {
		return [
			'openconnectorInstalled' => $this->appManager->isInstalled(self::OPENCONNECTOR_APP),
			'featureFlagActive' => $this->isFlagActive(),
			'lastWarning' => $this->lastWarning,
		];
	}//end getServiceStatus()

	/**
	 * The most recent degraded-mode warning. The caller may forward the
	 * `messageKey` to the UI for an i18n-backed banner.
	 *
	 * @return array{messageKey:string,status:int}|null
	 *
	 * @spec exclude phpstan dead-code cleanup only — dropped an always-false `$route === null`
	 */
	public function lastWarning(): ?array {
		return $this->lastWarning;
	}//end lastWarning()

	/**
	 * Whether the openconnector `pdok.feature_flag` is on.
	 *
	 * @return bool
	 */
	private function isFlagActive(): bool {
		try {
			$raw = $this->appConfig->getValueString(
				self::OPENCONNECTOR_APP,
				self::FEATURE_FLAG_KEY,
				'0'
			);
		} catch (Throwable $e) {
			$raw = '0';
		}

		return ($raw === '1' || strtolower($raw) === 'true');
	}//end isFlagActive()

	/**
	 * Build a lazily-created HTTP client.
	 *
	 * @return IClient
	 */
	private function getClient(): IClient {
		if ($this->client === null) {
			$this->client = $this->clientService->newClient();
		}

		return $this->client;
	}//end getClient()

	/**
	 * Map a thrown exception into a degraded-mode warning + return value.
	 *
	 * @param Throwable $error The originating error.
	 * @param string $messageKey Default i18n key.
	 *
	 * @return array<int, array<string,mixed>> Empty list for the caller.
	 */
	private function handleDegradedMode(Throwable $error, string $messageKey): array {
		// Surface the openconnector status code when available so the caller
		// can distinguish 503 (PDOK outage) from 404 (shim absent) from a
		// generic error.
		$status = 0;
		$msg = $error->getMessage();
		if (preg_match('/\b(?:HTTP|status)\s*([0-9]{3})\b/i', $msg, $matches) === 1) {
			$status = (int)$matches[1];
		}

		$effectiveKey = match ($status) {
			404 => 'pdok.openconnector_missing',
			503 => 'pdok.unavailable',
			default => $messageKey,
		};

		$this->recordWarning(messageKey: $effectiveKey, status: $status);
		$this->logger->info(
			'Dossiq PdokService degraded',
			['messageKey' => $effectiveKey, 'status' => $status, 'error' => $msg]
		);
		return [];
	}//end handleDegradedMode()

	/**
	 * Record a degraded-mode warning.
	 *
	 * @param string $messageKey i18n key.
	 * @param int $status HTTP status.
	 *
	 * @return void
	 */
	private function recordWarning(string $messageKey, int $status): void {
		$this->lastWarning = ['messageKey' => $messageKey, 'status' => $status];
	}//end recordWarning()
}//end class
