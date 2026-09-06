<?php

/**
 * Dossiq StufAdapterService.
 *
 * The orchestrator. Each public method takes a dossiq entity (a `case`) and a
 * StufEndpoint, builds the right envelope through StufMessageBuilder, logs the
 * outbound row, and hands the envelope to {@see StufOutboundTransport} to be
 * sent and classified.
 *
 * Retries, circuit-breaker bookkeeping and the permanent-failure signal live in
 * StufOutboundTransport; the case → zaak mapping lives in
 * {@see StufCaseMappingStore}. This class is left with WHAT to send and what to
 * report back to the caller.
 *
 * The retry schedule (5s, 30s, 2m, 10m) reuses the same referentienummer for
 * idempotency — the queued retry job calls back into `retrySend()`, which
 * short-circuits on circuit-open and feeds the retry log on the existing
 * StufMessage row.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Stuf
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-orchestration
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Stuf;

use OCA\Dossiq\Service\StufMessageBuilder;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates StUF operations against legacy zaaksystemen.
 *
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-orchestration
 */
class StufAdapterService {
	/**
	 * Exponential-backoff schedule for kennisgeving retries (seconds).
	 *
	 * Canonically owned by {@see StufOutboundTransport}, which is the only code
	 * that reads it; re-exported here because it is part of this service's
	 * published surface.
	 */
	public const RETRY_BACKOFF_SECONDS = StufOutboundTransport::RETRY_BACKOFF_SECONDS;

	/**
	 * Constructor.
	 *
	 * @param StufMessageBuilder $builder The outbound envelope builder.
	 * @param StufOutboundTransport $transport The send + response classifier.
	 * @param StufMessageHandler $messageHandler The audit log handler.
	 * @param StufMessageParser $parser The response parser.
	 * @param CircuitBreakerService $circuitBreaker The circuit breaker.
	 * @param StufRegisterAccess $register The register access helper.
	 * @param StufCaseMappingStore $mappings The case → zaak mapping store.
	 * @param NeedsInputDispatcher $needsInput The needs-input dispatcher.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private StufMessageBuilder $builder,
		private StufOutboundTransport $transport,
		private StufMessageHandler $messageHandler,
		private StufMessageParser $parser,
		private CircuitBreakerService $circuitBreaker,
		private StufRegisterAccess $register,
		private StufCaseMappingStore $mappings,
		private NeedsInputDispatcher $needsInput,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create a zaak in the zaaksysteem from a dossiq case.
	 *
	 * @param array $case The case array (id, type, omschrijving, startdatum, betrokkenen, documenten).
	 * @param array $endpoint The StufEndpoint.
	 * @param array $opts Options: includeDocuments (bool), payloadLimitBytes (int).
	 *
	 * @return array{success:bool,referentienummer:string,stufMessageId:string,caseIdentification:?string,mappingId:?string,fout:?array<string,mixed>}
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-orchestration
	 */
	public function creeerZaak(array $case, array $endpoint, ?array $opts = []): array {
		if ($this->circuitBreaker->checkEndpoint(endpoint: $endpoint) === false) {
			$this->needsInput->dispatch(type: 'stuf_circuit_open', context: ['endpointId' => ($endpoint['id'] ?? '')]);
			throw new CircuitOpenException(message: 'Circuit breaker is open');
		}

		$caseId = null;
		if (($endpoint['caseIdentificationStrategy'] ?? '') === 'vooraf') {
			$caseId = $this->genereerZaakIdentificatie(endpoint: $endpoint);
			// Anticipatory mapping.
			$this->mappings->persist(case: $case, externId: $caseId, endpoint: $endpoint);
		}

		$envelope = $this->builder->buildLk01CreeerZaak(
			case: $case,
			endpoint: $endpoint,
			caseId: $caseId,
			opts: ($opts ?? [])
		);

		$referentienummer = $this->extractReferentienummer(envelope: $envelope);
		$result = $this->transport->dispatch(
			endpoint: $endpoint,
			envelope: $envelope,
			message: $this->messageHandler->logOutbound(
				endpoint: $endpoint,
				envelopeXml: $envelope,
				referentienummer: $referentienummer,
				messageKind: 'Lk01',
				role: 'creeerZaak',
				caseId: $caseId,
				sourceEntity: 'case',
				sourceId: (string)($case['id'] ?? '')
			),
			role: 'creeerZaak'
		);

		$serverCaseId = ($result['caseIdentification'] ?? $caseId);
		$mapping = null;
		if ($result['success'] === true && $serverCaseId !== null && $serverCaseId !== '') {
			$mapping = $this->mappings->persist(case: $case, externId: $serverCaseId, endpoint: $endpoint);
		}

		$caseIdentification = $serverCaseId;
		if ($serverCaseId === '') {
			$caseIdentification = null;
		}

		return [
			'success' => $result['success'],
			'referentienummer' => $referentienummer,
			'stufMessageId' => $result['messageId'],
			'caseIdentification' => $caseIdentification,
			'mappingId' => ($mapping['id'] ?? null),
			'fout' => ($result['fout'] ?? null),
		];
	}//end creeerZaak()

	/**
	 * Update an existing zaak via Lk02.
	 *
	 * @param array $case The case with updated fields.
	 * @param array $endpoint The StufEndpoint.
	 *
	 * @return array{success:bool,referentienummer:string,stufMessageId:string,fout:?array<string,mixed>}
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-orchestration
	 */
	public function actualiseerZaak(array $case, array $endpoint): array {
		if ($this->circuitBreaker->checkEndpoint(endpoint: $endpoint) === false) {
			throw new CircuitOpenException(message: 'Circuit breaker is open');
		}

		$mapping = $this->mappings->find(case: $case, endpoint: $endpoint);
		if ($mapping === null) {
			$this->logger->warning(
				message: 'StUF actualiseerZaak: no mapping for case {id}',
				context: ['id' => ($case['id'] ?? '')]
			);
			return [
				'success' => false,
				'referentienummer' => '',
				'stufMessageId' => '',
				'fout' => [
					'code' => 'NO_MAPPING',
					'omschrijving' => 'Geen mapping voor case',
					'details' => '',
					'kind' => 'permanent',
				],
			];
		}

		$envelope = $this->builder->buildLk02ActualiseerZaak(case: $case, mapping: $mapping, endpoint: $endpoint);
		$referentienummer = $this->extractReferentienummer(envelope: $envelope);
		$result = $this->transport->dispatch(
			endpoint: $endpoint,
			envelope: $envelope,
			message: $this->messageHandler->logOutbound(
				endpoint: $endpoint,
				envelopeXml: $envelope,
				referentienummer: $referentienummer,
				messageKind: 'Lk02',
				role: 'actualiseerZaak',
				caseId: (string)($mapping['externalIdentification'] ?? ''),
				sourceEntity: 'case',
				sourceId: (string)($case['id'] ?? '')
			),
			role: 'actualiseerZaak'
		);

		return [
			'success' => $result['success'],
			'referentienummer' => $referentienummer,
			'stufMessageId' => $result['messageId'],
			'fout' => $result['fout'],
		];
	}//end actualiseerZaak()

	/**
	 * Synchronously query zaak details (Lv01 → La01, up to 30s).
	 *
	 * @param string $caseId The zaak identificatie.
	 * @param array $endpoint The StufEndpoint.
	 * @param array $gewensteElementen Optional gewenste zkn elements to scope.
	 *
	 * @return array|null The Zaak object array, or null on parse failure.
	 *
	 * @throws TimeoutException When the response does not arrive within the timeout.
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-synchronous-zaak-query
	 */
	public function geefZaakDetails(string $caseId, array $endpoint, array $gewensteElementen = []): ?array {
		if ($this->circuitBreaker->checkEndpoint(endpoint: $endpoint) === false) {
			throw new CircuitOpenException(message: 'Circuit breaker is open');
		}

		$envelope = $this->builder->buildLv01GeefDetails(
			caseId: $caseId,
			endpoint: $endpoint,
			gewensteElementen: $gewensteElementen
		);
		$msg = $this->messageHandler->logOutbound(
			endpoint: $endpoint,
			envelopeXml: $envelope,
			referentienummer: $this->extractReferentienummer(envelope: $envelope),
			messageKind: 'Lv01',
			role: 'geefZaakDetails',
			caseId: $caseId
		);

		$response = $this->transport->send(endpoint: $endpoint, envelope: $envelope, role: 'geefZaakDetails');

		if ($response['httpStatus'] === 0 && ($response['fout']['code'] ?? '') === 'TIMEOUT') {
			$this->messageHandler->transitionStatus(
				msg: $msg,
				newStatus: 'fout',
				extras: ['fout' => $response['fout'], 'durationMs' => $response['durationMs']]
			);
			$this->needsInput->dispatch(
				type: 'stuf_timeout',
				context: ['endpointId' => ($endpoint['id'] ?? ''), 'stufMessageId' => ($msg['id'] ?? '')]
			);
			throw new TimeoutException(message: 'StUF geefZaakDetails timed out');
		}

		$this->messageHandler->transitionStatus(
			msg: $msg,
			newStatus: $this->statusForHttp(httpStatus: (int)$response['httpStatus']),
			extras: [
				'httpStatus' => $response['httpStatus'],
				'durationMs' => $response['durationMs'],
				'responseEnvelopeXml' => $response['responseXml'],
				'fout' => $response['fout'],
			]
		);

		if ($this->isSuccessful(httpStatus: (int)$response['httpStatus']) === false) {
			return null;
		}

		return $this->parser->parseZaakDetails(responseXml: $response['responseXml']);
	}//end geefZaakDetails()

	/**
	 * Send a vrijBericht (free message) using a registered template.
	 *
	 * @param string $name The template name.
	 * @param array $payload The payload values.
	 * @param array $endpoint The StufEndpoint.
	 *
	 * @return array{success:bool,referentienummer:string,stufMessageId:string,fout:?array<string,mixed>}
	 *
	 * @throws VrijBerichtNotRegisteredException If the template is not registered.
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-free-message-templates
	 */
	public function vrijBericht(string $name, array $payload, array $endpoint): array {
		if ($this->circuitBreaker->checkEndpoint(endpoint: $endpoint) === false) {
			throw new CircuitOpenException(message: 'Circuit breaker is open');
		}

		$envelope = $this->builder->buildDu01VrijBericht(name: $name, payload: $payload, endpoint: $endpoint);
		$referentienummer = $this->extractReferentienummer(envelope: $envelope);
		$caseId = (string)($payload['caseIdentification'] ?? '');
		$caseIdArg = $caseId;
		if ($caseId === '') {
			$caseIdArg = null;
		}

		$result = $this->transport->dispatch(
			endpoint: $endpoint,
			envelope: $envelope,
			message: $this->messageHandler->logOutbound(
				endpoint: $endpoint,
				envelopeXml: $envelope,
				referentienummer: $referentienummer,
				messageKind: 'Du01',
				role: $name,
				caseId: $caseIdArg
			),
			role: $name
		);

		return [
			'success' => $result['success'],
			'referentienummer' => $referentienummer,
			'stufMessageId' => $result['messageId'],
			'fout' => $result['fout'],
		];
	}//end vrijBericht()

	/**
	 * Request a pre-allocated zaak identificatie via Du01 → La01.
	 *
	 * @param array $endpoint The StufEndpoint.
	 *
	 * @return string The allocated zaak ID (empty on failure).
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-zaak-identificatie-allocation
	 */
	public function genereerZaakIdentificatie(array $endpoint): string {
		$envelope = $this->builder->buildDu01GenereerZaakId(endpoint: $endpoint);
		$msg = $this->messageHandler->logOutbound(
			endpoint: $endpoint,
			envelopeXml: $envelope,
			referentienummer: $this->extractReferentienummer(envelope: $envelope),
			messageKind: 'Du01',
			role: 'genereerZaakIdentificatie'
		);

		$response = $this->transport->send(
			endpoint: $endpoint,
			envelope: $envelope,
			role: 'genereerZaakIdentificatie'
		);
		$confirmation = $this->parser->parseBevestiging(responseXml: $response['responseXml']);

		$this->messageHandler->transitionStatus(
			msg: $msg,
			newStatus: $this->statusForHttp(httpStatus: (int)$response['httpStatus']),
			extras: [
				'httpStatus' => $response['httpStatus'],
				'durationMs' => $response['durationMs'],
				'responseEnvelopeXml' => $response['responseXml'],
				'caseIdentification' => ($confirmation['caseIdentification'] ?? ''),
			]
		);

		return (string)($confirmation['caseIdentification'] ?? '');
	}//end genereerZaakIdentificatie()

	/**
	 * Re-send an outbound message (called by the StufRetryJob).
	 *
	 * @param string $stufMessageId The audit message id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-circuit-breaker-and-retry
	 */
	public function retrySend(string $stufMessageId): void {
		$msg = $this->register->findById(
			schema: StufRegisterAccess::SCHEMA_MESSAGE,
			id: $stufMessageId
		);
		if ($msg === null) {
			$this->logger->warning(message: 'StUF retry: message {id} not found', context: ['id' => $stufMessageId]);
			return;
		}

		$endpoint = $this->register->findById(
			schema: StufRegisterAccess::SCHEMA_ENDPOINT,
			id: (string)($msg['endpointId'] ?? '')
		);
		if ($endpoint === null) {
			$this->logger->warning(message: 'StUF retry: endpoint {id} not found', context: ['id' => ($msg['endpointId'] ?? '')]);
			return;
		}

		if ($this->circuitBreaker->checkEndpoint(endpoint: $endpoint) === false) {
			$this->logger->info(message: 'StUF retry: circuit open, skip');
			return;
		}

		$role = (string)($msg['role'] ?? '');

		$this->transport->handleResponse(
			endpoint: $endpoint,
			response: $this->transport->send(
				endpoint: $endpoint,
				envelope: (string)($msg['envelopeXml'] ?? ''),
				role: $role
			),
			message: $msg,
			role: $role,
			attempt: (count(value: (array)($msg['retries'] ?? [])) + 1)
		);
	}//end retrySend()

	/**
	 * Whether an HTTP status is a 2xx success.
	 *
	 * @param int $httpStatus The status code.
	 *
	 * @return bool True on 2xx.
	 */
	private function isSuccessful(int $httpStatus): bool {
		return ($httpStatus >= 200 && $httpStatus < 300);
	}//end isSuccessful()

	/**
	 * The StufMessage status a synchronous round-trip lands in.
	 *
	 * @param int $httpStatus The status code.
	 *
	 * @return string Either 'bevestigd' or 'fout'.
	 */
	private function statusForHttp(int $httpStatus): string {
		if ($this->isSuccessful(httpStatus: $httpStatus) === true) {
			return 'bevestigd';
		}

		return 'fout';
	}//end statusForHttp()

	/**
	 * Extract the referentienummer from an envelope (best-effort).
	 *
	 * @param string $envelope The envelope XML.
	 *
	 * @return string The referentienummer (empty if not present).
	 *
	 * @SuppressWarnings(PHPMD.UndefinedVariable) $matches is a preg_match() by-reference
	 * out-parameter, which PHPMD does not model.
	 */
	private function extractReferentienummer(string $envelope): string {
		if (preg_match(pattern: '#<stuf:referentienummer>([^<]+)</stuf:referentienummer>#', subject: $envelope, matches: $matches) === 1) {
			return $matches[1];
		}

		return '';
	}//end extractReferentienummer()
}//end class
