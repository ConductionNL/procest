<?php

/**
 * Dossiq StufHttpClient.
 *
 * Transports a built StUF envelope to the configured endpoint over HTTPS.
 *
 * Hard rules:
 *   - HTTPS only (URLs not starting with `https://` are rejected before send).
 *   - Server certificate verification is ALWAYS on (never disable `verify`).
 *   - Mutual TLS client cert is loaded from the vault reference; if the
 *     reference is set but the cert cannot be loaded, the call FAILS rather
 *     than silently falling back to anonymous transport.
 *   - The envelope is wired as the SOAP body with `text/xml; charset=UTF-8`
 *     and the SOAPAction header set from the functie.
 *   - Returns [httpStatus, responseXml, durationMs] for the message handler
 *     to persist verbatim into the audit log.
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
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-secure-transport
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Stuf;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Sends StUF SOAP envelopes over HTTPS with WSSE+mTLS auth.
 *
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-secure-transport
 */
class StufHttpClient {
	public const DEFAULT_TIMEOUT_SECONDS = 30;

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService The Nextcloud HTTP client service.
	 * @param StufVaultService $vault The vault adapter.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private IClientService $clientService,
		private StufVaultService $vault,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send an envelope to the configured endpoint.
	 *
	 * @param array $endpoint The StufEndpoint as array.
	 * @param string $envelopeXml The pre-built envelope XML.
	 * @param string $soapActionFunc The SOAPAction value (typically the StUF functie).
	 * @param int $timeoutSeconds Read timeout in seconds.
	 *
	 * @return array{httpStatus:int,responseXml:string,durationMs:int,fout:array<string,string>|null}
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-secure-transport
	 */
	public function send(
		array $endpoint,
		string $envelopeXml,
		string $soapActionFunc = '',
		int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
	): array {
		$url = (string)($endpoint['endpointUrl'] ?? '');
		if (str_starts_with(haystack: $url, needle: 'https://') === false) {
			$this->logger->error(message: 'StUF endpoint URL is not HTTPS', context: ['endpoint' => ($endpoint['id'] ?? '')]);
			return $this->permanentFailure(code: 'TRANSPORT_NON_HTTPS', omschrijving: 'Endpoint URL is not HTTPS');
		}

		$tlsCertPath = null;
		$tlsCertRef = (string)($endpoint['tlsClientCertRef'] ?? '');
		if ($tlsCertRef !== '') {
			try {
				$tlsCertPath = $this->materialiseClientCertificate(reference: $tlsCertRef);
			} catch (\Throwable $e) {
				$this->logger->error(
					message: 'StUF mTLS client cert load failed',
					context: ['endpoint' => ($endpoint['id'] ?? ''), 'error' => $e->getMessage()]
				);
				return $this->permanentFailure(
					code: 'TLS_CERT_LOAD_FAILED',
					omschrijving: 'mTLS client certificate could not be loaded'
				);
			}
		}//end if

		$client = $this->clientService->newClient();
		$headers = [
			'Content-Type' => 'text/xml; charset=UTF-8',
			'SOAPAction' => '"' . $soapActionFunc . '"',
			'User-Agent' => 'Dossiq-StUF/1.0',
		];

		$options = [
			'body' => $envelopeXml,
			'headers' => $headers,
			'timeout' => $timeoutSeconds,
			'verify' => true,
		];

		if ($tlsCertPath !== null) {
			$options['cert'] = $tlsCertPath;
		}

		$started = microtime(as_float: true);
		try {
			$response = $client->post(uri: $url, options: $options);
			$duration = (int)round((microtime(as_float: true) - $started) * 1000);
			$body = (string)$response->getBody();
			$status = (int)$response->getStatusCode();
			$this->logger->debug(
				message: 'StUF HTTP {status} in {ms}ms',
				context: ['status' => $status, 'ms' => $duration, 'endpoint' => ($endpoint['id'] ?? ''), 'url' => $url]
			);
			return [
				'httpStatus' => $status,
				'responseXml' => $body,
				'durationMs' => $duration,
				'fout' => null,
			];
		} catch (\Throwable $e) {
			$duration = (int)round((microtime(as_float: true) - $started) * 1000);
			$code = $this->classifyTransportError(exception: $e);
			$this->logger->warning(
				message: 'StUF HTTP transport error: {error}',
				context: ['error' => $e->getMessage(), 'endpoint' => ($endpoint['id'] ?? '')]
			);

			return [
				'httpStatus' => 0,
				'responseXml' => '',
				'durationMs' => $duration,
				'fout' => [
					'code' => $code,
					'omschrijving' => 'Transport error',
					'details' => $e->getMessage(),
					'kind' => 'transient',
				],
			];
		}//end try
	}//end send()

	/**
	 * Build the result envelope for a permanent, pre-flight transport refusal.
	 *
	 * @param string $code The StUF fout code.
	 * @param string $omschrijving The human-readable refusal reason.
	 *
	 * @return array{httpStatus:int,responseXml:string,durationMs:int,fout:array<string,string>} The refusal envelope.
	 */
	private function permanentFailure(string $code, string $omschrijving): array {
		return [
			'httpStatus' => 0,
			'responseXml' => '',
			'durationMs' => 0,
			'fout' => [
				'code' => $code,
				'omschrijving' => $omschrijving,
				'details' => '',
				'kind' => 'permanent',
			],
		];
	}//end permanentFailure()

	/**
	 * Materialise the mTLS client certificate to a temp file readable by cURL.
	 *
	 * The vault returns the PEM contents; we write them to a unique temp file
	 * for the duration of the call. The file is registered for shutdown
	 * cleanup. Callers MUST treat the path as ephemeral.
	 *
	 * @param string $reference The vault reference for the PEM blob.
	 *
	 * @return string The path to the temp file.
	 *
	 * @throws \RuntimeException When the vault returns no contents.
	 */
	private function materialiseClientCertificate(string $reference): string {
		$pem = $this->vault->resolveSecret(reference: $reference);
		if ($pem === '') {
			throw new RuntimeException(message: 'TLS cert vault reference resolves to empty contents');
		}

		$tmpPath = tempnam(directory: sys_get_temp_dir(), prefix: 'stuf-mtls-');
		if ($tmpPath === false) {
			throw new RuntimeException(message: 'Cannot create temp file for mTLS cert');
		}

		file_put_contents(filename: $tmpPath, data: $pem);
		chmod(filename: $tmpPath, permissions: 0o600);
		register_shutdown_function(
			callback: static function () use ($tmpPath): void {
				// Shutdown-time cleanup of the materialised client cert.
				// clearstatcache() makes the existence check reflect what
				// happened during the request, so the unlink is not racing
				// a stale stat cache and does not need an `@`.
				clearstatcache(clear_realpath_cache: true, filename: $tmpPath);
				if (file_exists(filename: $tmpPath) === true) {
					unlink(filename: $tmpPath);
				}
			}
		);

		return $tmpPath;
	}//end materialiseClientCertificate()

	/**
	 * Classify a transport-layer exception as TIMEOUT or NETWORK.
	 *
	 * @param \Throwable $exception The exception.
	 *
	 * @return string The classification code.
	 */
	private function classifyTransportError(\Throwable $exception): string {
		$message = strtolower(string: $exception->getMessage());
		if (str_contains(haystack: $message, needle: 'timed out') === true || str_contains(haystack: $message, needle: 'timeout') === true) {
			return 'TIMEOUT';
		}

		return 'NETWORK';
	}//end classifyTransportError()
}//end class
