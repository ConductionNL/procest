<?php

/**
 * Dormant default Dossiq external-ZGW adapter.
 *
 * Records the would-be ZGW Zaken-API / Documenten-API push to the
 * structured logger and returns a synthetic PUSH_DEFERRED result so
 * the surrounding lifecycle (cross-municipality zaak hand-off,
 * VTH push to a regional uitvoeringsdienst) stays observable
 * until an openconnector-backed binding to the receiving ZGW stack
 * is wired in via `Application::register()`. Mirrors the
 * `LogDigidSamlAdapter` / `LogEHerkenningSamlAdapter`
 * dormant-default pattern used across the Dossiq external surface.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\External\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\External\Zgw;

use Psr\Log\LoggerInterface;

/**
 * Dormant log-backed Dossiq external-ZGW adapter.
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
class LogZgwExternalAdapter implements ZgwExternalAdapterInterface {
	/**
	 * Construct the log-backed external-ZGW adapter.
	 *
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Log the Zaken-API push intent + synthesise a PUSH_DEFERRED
	 * result.
	 *
	 * The Zaak envelope's `rollen[]` may carry BSN values
	 * (initiator role); they are deliberately REDACTED before
	 * logging per AVG / WBP article 9.
	 *
	 * @param array<string,mixed> $caseEnvelope Zaak payload.
	 * @param array<string,mixed> $context Push context.
	 *
	 * @return ZgwPushResult The dispatch outcome.
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md#requirement-zrc-zaken-api-resources-must-be-fully-mappable
	 */
	public function submitZaak(array $caseEnvelope, array $context = []): ZgwPushResult {
		$sanitised = $this->redactBsnFromRoles(caseEnvelope: $caseEnvelope);
		$correlationId = (string)($context['correlationId'] ?? 'zgw-zaak-' . bin2hex(random_bytes(6)));

		$this->logger->info(
			'Dossiq external-ZGW submitZaak deferred (no outbound connector bound)',
			[
				'correlationId' => $correlationId,
				'zaakEnvelope' => $sanitised,
				'context' => $context,
			]
		);

		return new ZgwPushResult(
			pushStatus: 'PUSH_DEFERRED',
			receiverUrl: '',
			correlationId: $correlationId,
			dormant: true,
			extras: [
				'reason' => 'no-outbound-connector-bound',
				'note' => 'Bind openconnector source slug `zgw-external` (per-receiver JWT signing key + Autorisaties-API scope handshake) '
					. 'and override ZgwExternalAdapterInterface in Application::register() to enable real Zaken-API push.',
			],
		);
	}//end submitZaak()

	/**
	 * Log the Documenten-API push intent + synthesise a
	 * PUSH_DEFERRED result.
	 *
	 * The `inhoud` field (often a base64-encoded document body) is
	 * deliberately stripped before logging to avoid spilling
	 * document contents into the structured logger.
	 *
	 * @param array<string,mixed> $documentEnvelope Document payload.
	 * @param array<string,mixed> $context Push context.
	 *
	 * @return ZgwPushResult The dispatch outcome.
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md#requirement-drc-documenten-api-resources-must-be-mappable
	 */
	public function submitDocument(array $documentEnvelope, array $context = []): ZgwPushResult {
		$sanitised = $documentEnvelope;
		if (isset($sanitised['inhoud']) === true) {
			$sanitised['inhoud'] = '[REDACTED-body-bytes=' . strlen((string)$sanitised['inhoud']) . ']';
		}

		$correlationId = (string)($context['correlationId'] ?? 'zgw-doc-' . bin2hex(random_bytes(6)));

		$this->logger->info(
			'Dossiq external-ZGW submitDocument deferred (no outbound connector bound)',
			[
				'correlationId' => $correlationId,
				'documentEnvelope' => $sanitised,
				'context' => $context,
			]
		);

		return new ZgwPushResult(
			pushStatus: 'PUSH_DEFERRED',
			receiverUrl: '',
			correlationId: $correlationId,
			dormant: true,
			extras: [
				'reason' => 'no-outbound-connector-bound',
				'note' => 'Bind openconnector source slug `zgw-external` + map receiver Documenten-API endpoint to enable real document push.',
			],
		);
	}//end submitDocument()

	/**
	 * Whether this adapter is a dormant no-op log adapter.
	 *
	 * @inheritDoc
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md#requirement-drc-documenten-api-resources-must-be-mappable
	 */
	public function isDormant(): bool {
		return true;
	}//end isDormant()

	/**
	 * Redact the `betrokkeneIdentificatie.inpBsn` field on any
	 * `natuurlijk_persoon` row inside `rollen[]`.
	 *
	 * @param array<string,mixed> $caseEnvelope Zaak payload.
	 *
	 * @return array<string,mixed> Sanitised payload.
	 */
	private function redactBsnFromRoles(array $caseEnvelope): array {
		if (isset($caseEnvelope['rollen']) === false || is_array($caseEnvelope['rollen']) === false) {
			return $caseEnvelope;
		}

		foreach ($caseEnvelope['rollen'] as $idx => $role) {
			if (is_array($role) === false) {
				continue;
			}

			if (isset($role['betrokkeneIdentificatie']['inpBsn']) === true) {
				$caseEnvelope['rollen'][$idx]['betrokkeneIdentificatie']['inpBsn'] = '[REDACTED]';
			}
		}

		return $caseEnvelope;
	}//end redactBsnFromRollen()
}//end class
