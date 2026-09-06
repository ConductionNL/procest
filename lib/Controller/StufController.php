<?php

/**
 * Dossiq StUF Controller
 *
 * Handles BOTH directions of StUF-ZKN/BG:
 *   - INBOUND server reception: raw XML POST at /api/stuf/{zaken,personen},
 *     parses SOAP envelopes, dispatches per message type (zakLk01/zakLv01/
 *     npsLv01/edcLk01) and returns SOAP XML responses (Bv01/La01/Fo01).
 *   - OUTBOUND gateway (admin REST): vrijBericht send, endpoint listing with
 *     health, audit-log query — JSON. Plus an async confirmation receiver
 *     (`inbound`) that matches a Bv01 crossRefnummer back to the outbound
 *     StufMessage row and transitions it to "bevestigd".
 *
 * The controller owns only the HTTP surface. Envelope parsing and per-message
 * dispatch live in {@see \OCA\Dossiq\Service\Stuf\StufSoapRequestDispatcher};
 * the raw-envelope reads the async webhook needs live in
 * {@see \OCA\Dossiq\Service\Stuf\StufEnvelopeInspector}.
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
 * @spec openspec/specs/stuf-integration/spec.md
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-rest-surface
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\Stuf\CircuitOpenException;
use OCA\Dossiq\Service\Stuf\StufEnvelopeInspector;
use OCA\Dossiq\Service\Stuf\StufException;
use OCA\Dossiq\Service\Stuf\StufRegisterAccess;
use OCA\Dossiq\Service\Stuf\StufServices;
use OCA\Dossiq\Service\Stuf\StufSoapRequestDispatcher;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for inbound + outbound StUF SOAP messages.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class StufController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request object.
	 * @param StufServices $stuf The bundled StUF collaborators.
	 * @param StufSoapRequestDispatcher $dispatcher The inbound SOAP dispatcher.
	 * @param StufEnvelopeInspector $inspector The raw-envelope inspector.
	 * @param IL10N $l10n The localization service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly StufServices $stuf,
		private readonly StufSoapRequestDispatcher $dispatcher,
		private readonly StufEnvelopeInspector $inspector,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Handle inbound StUF-ZKN SOAP messages for case operations.
	 *
	 * Rate-limit rationale: StUF-ZKN SOAP receivers. The caller is a municipal
	 * middleware component on its own retry schedule, so the ceiling is
	 * generous — dropping a StUF delivery is worse than absorbing a burst.
	 *
	 * @return DataDisplayResponse SOAP XML response.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function cases(): DataDisplayResponse {
		return $this->dispatcher->dispatch(
			rawBody: $this->readRawBody(),
			service: StufSoapRequestDispatcher::SERVICE_CASES
		);

	}//end cases()

	/**
	 * Serve the deprecated Dutch URL, /api/stuf/zaken.
	 *
	 * Delegates to cases(). Its own method because the URL is a WIRE CONTRACT
	 * held in the sending zaaksysteem's configuration, and because
	 * openregister's AppHost Routes::standard() rejects duplicate route names
	 * by `name` alone and never reads `postfix` — the two-entries-one-name form
	 * throws at boot and takes the whole app's routing down.
	 *
	 * Remove once every configured sender posts to /api/stuf/cases.
	 *
	 * @return DataDisplayResponse SOAP XML response.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 *
	 * @contract exclude Deprecated path alias for cases(); behaviour covered by the cases() tests.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function casesLegacyPath(): DataDisplayResponse {
		return $this->cases();
	}//end casesLegacyPath()

	/**
	 * Handle inbound StUF-BG SOAP messages for person operations.
	 *
	 * @return DataDisplayResponse SOAP XML response.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function persons(): DataDisplayResponse {
		return $this->dispatcher->dispatch(
			rawBody: $this->readRawBody(),
			service: StufSoapRequestDispatcher::SERVICE_PERSONS
		);

	}//end persons()

	/**
	 * Serve the deprecated Dutch URL, /api/stuf/personen.
	 *
	 * Delegates to persons(). Its own method for the same reason
	 * casesLegacyPath() is — see the note there.
	 *
	 * @return DataDisplayResponse SOAP XML response.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 *
	 * @contract exclude Deprecated path alias for persons(); behaviour covered by the persons() tests.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function personsLegacyPath(): DataDisplayResponse {
		return $this->persons();
	}//end personsLegacyPath()

	/**
	 * Send a vrijBericht to the named endpoint (outbound).
	 *
	 * Admin-only via #[AuthorizedAdminSetting]. Body: { endpointId, berichtNaam, payload }.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-free-message-templates
	 *
	 * @contract exclude SOAP vrijBericht proxy needs a seeded endpoint + vault + live peer; covered by PHPUnit + env-gated live-e2e/Newman.
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function outbound(): JSONResponse {
		$endpointId = (string)$this->request->getParam(key: 'endpointId', default: '');
		$messageName = (string)$this->request->getParam(key: 'berichtNaam', default: '');
		$payload = (array)$this->request->getParam(key: 'payload', default: []);

		if ($endpointId === '' || $messageName === '') {
			return new JSONResponse(['error' => $this->l10n->t('endpointId and berichtNaam are required')], Http::STATUS_BAD_REQUEST);
		}

		$endpoint = $this->stuf->register->findById(
			schema: StufRegisterAccess::SCHEMA_ENDPOINT,
			id: $endpointId
		);
		if ($endpoint === null) {
			return new JSONResponse(['error' => $this->l10n->t('Endpoint not found')], Http::STATUS_NOT_FOUND);
		}

		try {
			$result = $this->stuf->adapter->vrijBericht(name: $messageName, payload: $payload, endpoint: $endpoint);
			return new JSONResponse($result);
		} catch (CircuitOpenException $e) {
			return new JSONResponse(
				['error' => $this->l10n->t('Circuit breaker open for this endpoint'), 'errorCode' => 'CIRCUIT_OPEN'],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		} catch (StufException $e) {
			return new JSONResponse(
				['error' => $e->getMessage(), 'errorCode' => 'STUF_VALIDATION'],
				Http::STATUS_BAD_REQUEST
			);
		} catch (\Throwable $e) {
			$this->logger->error(message: 'StUF outbound failed: {error}', context: ['error' => $e->getMessage()]);
			return new JSONResponse(['error' => $this->l10n->t('Internal error')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end outbound()

	/**
	 * Receive an inbound async confirmation/notification from the zaaksysteem.
	 *
	 * Public (no user session) but authenticates the caller via WSSE
	 * UsernameToken matched against the StufEndpoint vault reference.
	 * Persists the inbound envelope as a StufMessage row and, when the
	 * envelope is a Bv01 bevestiging, transitions the matching outbound row
	 * from "verzonden" → "bevestigd".
	 *
	 * @return DataResponse
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-async-confirmation
	 *
	 * @contract exclude WSSE SOAP webhook needs a signed XML body + seeded endpoint/vault; covered by PHPUnit + env-gated live-e2e/Newman.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function inbound(): DataResponse {
		$rawXml = $this->readRawBody();
		if ($rawXml === '') {
			return new DataResponse(data: 'empty body', statusCode: Http::STATUS_BAD_REQUEST);
		}

		$endpoint = $this->inspector->resolveEndpoint(
			envelopeXml: $rawXml,
			headerEndpointId: (string)$this->request->getHeader(name: 'x-dossiq-endpoint-id')
		);
		if ($endpoint === null) {
			$this->logger->warning(message: 'StUF inbound: could not resolve endpoint from envelope');
			return new DataResponse(data: 'unknown endpoint', statusCode: Http::STATUS_BAD_REQUEST);
		}

		if ($this->inspector->verifyWsse(envelopeXml: $rawXml, endpoint: $endpoint) === false) {
			$this->logger->warning(message: 'StUF inbound: WSSE signature mismatch for endpoint {id}', context: ['id' => ($endpoint['id'] ?? '')]);
			// 422 (Unprocessable Entity) signals "invalid signature" without
			// surfacing an NC session-auth status to the upstream zaaksysteem.
			// This is WSSE signature verification of a PublicPage webhook, not
			// NC session auth — so the semantic-auth gate stays unambiguous.
			return new DataResponse(data: 'invalid signature', statusCode: Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$messageKind = $this->inspector->detectBerichtSoort(envelopeXml: $rawXml);
		$crossRef = $this->inspector->extractCrossRefnummer(envelopeXml: $rawXml);
		$caseId = ($this->stuf->parser->parseBevestiging(responseXml: $rawXml)['caseIdentification'] ?? null);

		$this->stuf->messageHandler->logInbound(
			endpoint: $endpoint,
			responseXml: $rawXml,
			messageKind: $messageKind,
			crossRefnummer: $crossRef,
			caseId: $caseId,
			role: $this->inspector->extractFunctie(envelopeXml: $rawXml)
		);

		$this->confirmOutbound(messageKind: $messageKind, crossRef: $crossRef, rawXml: $rawXml, caseId: $caseId);

		return new DataResponse(data: 'ack', statusCode: Http::STATUS_OK);
	}//end inbound()

	/**
	 * Serve the deprecated Dutch URL, /api/stuf/inkomend.
	 *
	 * Delegates to inbound(). It exists as its own method only because the URL
	 * is a WIRE CONTRACT held in the upstream zaaksysteem's configuration:
	 * renaming the path alone would turn a working webhook into a silent 404 on
	 * somebody else's schedule.
	 *
	 * It cannot be expressed as a second routes.php entry reusing
	 * `stuf#inbound` with a `postfix`, because openregister's AppHost
	 * Routes::standard() rejects duplicates by `name` alone and never reads
	 * `postfix` — that form throws "Duplicate route name" at boot and takes the
	 * whole app's routing down. Measured: it failed dossiq's E2E seed.
	 *
	 * Remove once every configured sender posts to /api/stuf/inbound.
	 *
	 * @return DataResponse
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-async-confirmation
	 *
	 * @contract exclude Deprecated path alias for inbound(); the behaviour is covered by StufControllerInboundTest.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function inboundLegacyPath(): DataResponse {
		return $this->inbound();
	}//end inboundLegacyPath()

	/**
	 * Read the raw request body.
	 *
	 * A seam, and the reason this endpoint had no tests while its two siblings
	 * did. `php://input` cannot be driven from a unit test, so the WSSE refusal
	 * below it could never be watched to refuse — and a guard nobody has
	 * watched refuse is a guard nobody has tested. Overriding this one method
	 * is all a test needs; the production path is unchanged.
	 *
	 * `OCP\IRequest` exposes no raw-body accessor, so this cannot simply read
	 * from the injected request.
	 *
	 * @return string The raw request body, or an empty string when there is none.
	 *
	 * @psalm-return   string
	 * @phpstan-return string
	 */
	protected function readRawBody(): string {
		return (string)file_get_contents(filename: 'php://input');
	}//end readRawBody()

	/**
	 * List all configured StufEndpoint objects (admin REST).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-rest-surface
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function endpoints(): JSONResponse {
		$items = $this->stuf->register->findAll(schema: StufRegisterAccess::SCHEMA_ENDPOINT, filters: [], limit: 500);
		$items = array_map(
			callback: function (array $endpoint): array {
				return $this->enrichEndpointWithHealth(endpoint: $endpoint);
			},
			array: $items
		);
		return new JSONResponse(['items' => $items, 'total' => count(value: $items)]);
	}//end endpoints()

	/**
	 * Query the StufMessage audit log (admin REST).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-audit-log
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function messages(): JSONResponse {
		$limit = max(1, min(500, (int)$this->request->getParam(key: 'limit', default: 50)));
		$filters = $this->messageFilters();

		$items = $this->stuf->register->findAll(schema: StufRegisterAccess::SCHEMA_MESSAGE, filters: $filters, limit: $limit);
		return new JSONResponse(['items' => $items, 'total' => count(value: $items), 'limit' => $limit]);
	}//end messages()

	/**
	 * Collect the optional audit-log filters present on the request.
	 *
	 * @return array<string, string> The non-empty filters.
	 */
	private function messageFilters(): array {
		$filters = [];
		foreach (['endpointId', 'messageKind', 'status'] as $key) {
			$value = (string)$this->request->getParam(key: $key, default: '');
			if ($value !== '') {
				$filters[$key] = $value;
			}
		}

		return $filters;
	}//end messageFilters()

	/**
	 * Transition the matching outbound message to "bevestigd" on a Bv01.
	 *
	 * @param string $messageKind The detected bericht-soort.
	 * @param string $crossRef The cross-reference to the outbound row.
	 * @param string $rawXml The raw inbound envelope.
	 * @param string|null $caseId The zaak identificatie from the bevestiging.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-async-confirmation
	 */
	private function confirmOutbound(string $messageKind, string $crossRef, string $rawXml, ?string $caseId): void {
		if ($messageKind !== 'Bv01' || $crossRef === '') {
			return;
		}

		$outbound = $this->stuf->messageHandler->findOutboundByReferentienummer(referentienummer: $crossRef);
		if ($outbound === null) {
			return;
		}

		$this->stuf->messageHandler->transitionStatus(
			msg: $outbound,
			newStatus: 'bevestigd',
			extras: [
				'responseEnvelopeXml' => $rawXml,
				'caseIdentification' => ($caseId ?? ($outbound['caseIdentification'] ?? '')),
			]
		);
	}//end confirmOutbound()

	/**
	 * Enrich an endpoint with its health snapshot (status badge + last 5 messages).
	 *
	 * @param array $endpoint The raw endpoint row.
	 *
	 * @return array
	 */
	private function enrichEndpointWithHealth(array $endpoint): array {
		$snapshot = $this->stuf->circuitBreaker->snapshot(endpointId: (string)($endpoint['id'] ?? ''));
		$recent = $this->stuf->register->findAll(
			schema: StufRegisterAccess::SCHEMA_MESSAGE,
			filters: ['endpointId' => (string)($endpoint['id'] ?? '')],
			limit: 5
		);
		$endpoint['health'] = [
			'state' => $snapshot['state'],
			'failureCount' => $snapshot['failureCount'],
			'openedAt' => $snapshot['openedAt'],
			'recentCount' => count(value: $recent),
		];
		return $endpoint;
	}//end enrichEndpointWithHealth()
}//end class
