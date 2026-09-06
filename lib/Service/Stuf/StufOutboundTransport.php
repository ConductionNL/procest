<?php

/**
 * Dossiq StUF outbound transport.
 *
 * Everything that happens to an outbound envelope AFTER it has been built and
 * logged: send it, classify the answer (Bv01 bevestiging, Fo02 fout, transport
 * failure), transition the StufMessage row, drive the circuit breaker, and
 * either schedule an exponential-backoff retry or raise the permanent-failure
 * needs-input signal.
 *
 * Split out of {@see StufAdapterService}, which built envelopes AND owned this
 * whole response-classification machine. The adapter now decides WHAT to send;
 * this class decides what the answer MEANS. The retry schedule lives here too,
 * next to the only code that reads it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Stuf
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-circuit-breaker-and-retry
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Stuf;

use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * Sends outbound StUF envelopes and classifies what comes back.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-circuit-breaker-and-retry
 */
class StufOutboundTransport {
	/**
	 * Exponential-backoff schedule for kennisgeving retries (seconds).
	 */
	public const RETRY_BACKOFF_SECONDS = [5, 30, 120, 600];

	/**
	 * Constructor.
	 *
	 * @param StufHttpClient $httpClient The HTTP transport.
	 * @param StufMessageHandler $messageHandler The audit log handler.
	 * @param StufMessageParser $parser The response parser.
	 * @param CircuitBreakerService $circuitBreaker The circuit breaker.
	 * @param NeedsInputDispatcher $needsInput The needs-input dispatcher.
	 * @param IJobList $jobList The background job list (for retry scheduling).
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private StufHttpClient $httpClient,
		private StufMessageHandler $messageHandler,
		private StufMessageParser $parser,
		private CircuitBreakerService $circuitBreaker,
		private NeedsInputDispatcher $needsInput,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send an envelope over the wire without interpreting the answer.
	 *
	 * Used by the two synchronous flows (Lv01 geefZaakDetails, Du01
	 * genereerZaakIdentificatie), which read the response body themselves
	 * instead of going through the kennisgeving classification.
	 *
	 * @param array $endpoint The StufEndpoint.
	 * @param string $envelope The envelope XML.
	 * @param string $role The functie for SOAPAction.
	 *
	 * @return array The raw httpClient response.
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-synchronous-zaak-query
	 */
	public function send(array $endpoint, string $envelope, string $role): array {
		return $this->httpClient->send(
			endpoint: $endpoint,
			envelopeXml: $envelope,
			soapActionFunc: $role,
			timeoutSeconds: StufHttpClient::DEFAULT_TIMEOUT_SECONDS
		);
	}//end send()

	/**
	 * Dispatch a kennisgeving envelope (Lk01/Lk02/Du01, Bv01-expecting) —
	 * send, parse, log, retry-on-transient.
	 *
	 * @param array $endpoint The StufEndpoint.
	 * @param string $envelope The envelope XML.
	 * @param array $message The persisted StufMessage row.
	 * @param string $role The functie for SOAPAction.
	 *
	 * @return array{success:bool,messageId:string,caseIdentification:?string,fout:?array<string,mixed>}
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-orchestration
	 */
	public function dispatch(array $endpoint, string $envelope, array $message, string $role): array {
		return $this->handleResponse(
			endpoint: $endpoint,
			response: $this->send(endpoint: $endpoint, envelope: $envelope, role: $role),
			message: $message,
			role: $role,
			attempt: 1
		);
	}//end dispatch()

	/**
	 * Handle an HTTP response: parse Bv01/Fo02, classify, persist, and retry on transient.
	 *
	 * @param array $endpoint The StufEndpoint.
	 * @param array $response The httpClient response.
	 * @param array $message The StufMessage row.
	 * @param string $role The functie.
	 * @param int $attempt The current attempt number (1-indexed).
	 *
	 * @return array{success:bool,messageId:string,caseIdentification:?string,fout:?array<string,mixed>}
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-circuit-breaker-and-retry
	 */
	public function handleResponse(array $endpoint, array $response, array $message, string $role, int $attempt): array {
		$messageId = (string)($message['id'] ?? '');
		$httpStatus = (int)($response['httpStatus'] ?? 0);
		$duration = (int)($response['durationMs'] ?? 0);
		$body = (string)($response['responseXml'] ?? '');

		if ($httpStatus >= 200 && $httpStatus < 300) {
			return $this->acceptConfirmation(
				endpoint: $endpoint,
				message: $message,
				messageId: $messageId,
				httpStatus: $httpStatus,
				duration: $duration,
				body: $body
			);
		}

		$fout = $this->classifyFailure(transportFout: ($response['fout'] ?? null), body: $body);

		$isTransient = ($this->isTransientHttp(httpStatus: $httpStatus) === true || ($fout['kind'] ?? '') === 'transient');
		if ($isTransient === true && $attempt < (count(value: self::RETRY_BACKOFF_SECONDS) + 1)) {
			$this->messageHandler->recordRetry(
				msg: $message,
				attempt: $attempt,
				httpStatus: $httpStatus,
				fout: ($fout ?? []),
				durationMs: $duration
			);
			$this->circuitBreaker->recordFailure(endpoint: $endpoint, fout: ($fout ?? []));
			$this->scheduleRetry(messageId: $messageId, attempt: $attempt);
			return $this->failure(messageId: $messageId, fout: $fout);
		}

		// Permanent failure path.
		$this->messageHandler->transitionStatus(
			msg: $message,
			newStatus: 'fout',
			extras: [
				'httpStatus' => $httpStatus,
				'durationMs' => $duration,
				'responseEnvelopeXml' => $body,
				'fout' => $fout,
			]
		);
		$this->circuitBreaker->recordFailure(endpoint: $endpoint, fout: ($fout ?? []));
		$this->needsInput->dispatch(
			type: 'stuf_permanent_error',
			context: [
				'endpointId' => (string)($endpoint['id'] ?? ''),
				'stufMessageId' => $messageId,
				'fout' => ($fout ?? []),
				'role' => $role,
			]
		);

		return $this->failure(messageId: $messageId, fout: $fout);
	}//end handleResponse()

	/**
	 * Record a 2xx answer: parse the bevestiging, transition the row and reset
	 * the circuit breaker.
	 *
	 * @param array $endpoint The StufEndpoint.
	 * @param array $message The StufMessage row.
	 * @param string $messageId The StufMessage id.
	 * @param int $httpStatus The HTTP status.
	 * @param int $duration The round-trip duration in ms.
	 * @param string $body The response envelope XML.
	 *
	 * @return array{success:bool,messageId:string,caseIdentification:?string,fout:?array<string,mixed>}
	 */
	private function acceptConfirmation(
		array $endpoint,
		array $message,
		string $messageId,
		int $httpStatus,
		int $duration,
		string $body,
	): array {
		$confirmation = $this->parser->parseBevestiging(responseXml: $body);
		$extras = [
			'httpStatus' => $httpStatus,
			'durationMs' => $duration,
			'responseEnvelopeXml' => $body,
		];
		if (($confirmation['caseIdentification'] ?? null) !== null) {
			$extras['caseIdentification'] = $confirmation['caseIdentification'];
		}

		$this->messageHandler->transitionStatus(msg: $message, newStatus: 'bevestigd', extras: $extras);
		$this->circuitBreaker->resetEndpoint(endpoint: $endpoint);

		return [
			'success' => true,
			'messageId' => $messageId,
			'caseIdentification' => ($confirmation['caseIdentification'] ?? null),
			'fout' => null,
		];
	}//end acceptBevestiging()

	/**
	 * Determine the fout for a non-2xx answer.
	 *
	 * A transport-level fout (connection refused, timeout) wins; otherwise the
	 * StUF Fo02 body is parsed. An empty body with no transport fout yields null,
	 * which the caller treats as an unclassified permanent failure.
	 *
	 * @param array<string, mixed>|null $transportFout The httpClient transport fout, if any.
	 * @param string $body The response envelope XML.
	 *
	 * @return array<string, mixed>|null The fout, or null when unclassifiable.
	 */
	private function classifyFailure(?array $transportFout, string $body): ?array {
		if ($transportFout !== null) {
			return $transportFout;
		}

		if ($body === '') {
			return null;
		}

		$parsed = $this->parser->parseError(responseXml: $body);

		return [
			'code' => $parsed['code'],
			'omschrijving' => $parsed['omschrijving'],
			'details' => $parsed['details'],
			'kind' => $parsed['kind'],
		];
	}//end classifyFailure()

	/**
	 * The shared unsuccessful-dispatch result.
	 *
	 * @param string $messageId The StufMessage id.
	 * @param array<string, mixed>|null $fout The classified fout.
	 *
	 * @return array{success:bool,messageId:string,caseIdentification:?string,fout:?array<string,mixed>}
	 */
	private function failure(string $messageId, ?array $fout): array {
		return [
			'success' => false,
			'messageId' => $messageId,
			'caseIdentification' => null,
			'fout' => $fout,
		];
	}//end failure()

	/**
	 * Schedule a delayed retry via the background job list.
	 *
	 * @param string $messageId The StufMessage id.
	 * @param int $attempt The attempt number that just failed (1-indexed).
	 *
	 * @return void
	 */
	private function scheduleRetry(string $messageId, int $attempt): void {
		$delayIndex = max(0, ($attempt - 1));
		$delay = (self::RETRY_BACKOFF_SECONDS[$delayIndex] ?? 600);
		try {
			$this->jobList->add(
				'OCA\\Dossiq\\BackgroundJob\\StufRetryJob',
				['stufMessageId' => $messageId, 'runAt' => (time() + $delay)]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(message: 'StUF retry scheduling failed: {error}', context: ['error' => $e->getMessage()]);
		}

		$this->logger->info(
			message: 'StUF retry scheduled for {id} after {delay}s (attempt {attempt})',
			context: ['id' => $messageId, 'delay' => $delay, 'attempt' => $attempt]
		);
	}//end scheduleRetry()

	/**
	 * Classify an HTTP status code as transient (retry) or permanent.
	 *
	 * @param int $httpStatus The status code.
	 *
	 * @return bool True when the request is worth retrying.
	 */
	private function isTransientHttp(int $httpStatus): bool {
		if ($httpStatus === 0) {
			return true;
		}

		if ($httpStatus >= 500 && $httpStatus < 600) {
			return true;
		}

		return ($httpStatus === 408 || $httpStatus === 429);
	}//end isTransientHttp()
}//end class
