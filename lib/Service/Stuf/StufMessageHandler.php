<?php

/**
 * Dossiq StufMessageHandler.
 *
 * Owns the per-call audit log (StufMessage) — creates one row per outbound
 * envelope, updates it when the response arrives, appends retries to the
 * `retries[]` array, and transitions the status.
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
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-audit-log
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Stuf;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Persists and updates StufMessage audit rows.
 *
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-audit-log
 */
class StufMessageHandler {

	/**
	 * Message direction: sent by us.
	 *
	 * @var string
	 */
	public const DIRECTION_OUTBOUND = 'outbound';

	/**
	 * Message direction: received by us.
	 *
	 * @var string
	 */
	public const DIRECTION_INBOUND = 'inbound';

	/**
	 * The pre-rename Dutch spelling of DIRECTION_OUTBOUND.
	 *
	 * Read-only, and referenced from exactly one place: the transition fallback
	 * in findOutboundByReferentienummer(). Nothing writes it. It exists so a row
	 * stored before RenameDutchDirectionValues ran is still findable, and it is
	 * deleted together with that fallback.
	 *
	 * @var string
	 */
	public const LEGACY_DIRECTION_OUTBOUND = 'uitgaand';

	/**
	 * Constructor.
	 *
	 * @param StufRegisterAccess $register The register access helper.
	 */
	public function __construct(
		private StufRegisterAccess $register,
	) {
	}//end __construct()

	/**
	 * Create an outbound audit row with status=verzonden.
	 *
	 * @param array $endpoint The StufEndpoint.
	 * @param string $envelopeXml The full envelope XML.
	 * @param string $referentienummer The outbound referentienummer.
	 * @param string $messageKind The bericht code (Lk01, Lv01, ...).
	 * @param string $role The functie (creeerZaak, ...).
	 * @param string|null $caseId Optional zaak identificatie.
	 * @param string|null $sourceEntity Optional dossiq source-entity type (case, contact).
	 * @param string|null $sourceId Optional dossiq source-entity id.
	 *
	 * @return array The persisted StufMessage as array.
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-audit-log
	 */
	public function logOutbound(
		array $endpoint,
		string $envelopeXml,
		string $referentienummer,
		string $messageKind,
		string $role,
		?string $caseId = null,
		?string $sourceEntity = null,
		?string $sourceId = null,
	): array {
		$data = [
			'id' => $this->newId(prefix: 'stuf-msg'),
			'endpointId' => (string)($endpoint['id'] ?? ''),
			'direction' => self::DIRECTION_OUTBOUND,
			'messageKind' => $messageKind,
			'role' => $role,
			'entiteittype' => 'ZAK',
			'referenceNumber' => $referentienummer,
			'caseIdentification' => ($caseId ?? ''),
			'relatedCaseId' => ($caseId ?? ''),
			'envelopeXml' => $envelopeXml,
			'sentOn' => $this->isoNow(),
			'sourceEntity' => ($sourceEntity ?? ''),
			'sourceId' => ($sourceId ?? ''),
			'status' => 'verzonden',
			'retries' => [],
		];
		return $this->register->saveObject(schema: StufRegisterAccess::SCHEMA_MESSAGE, data: $data);
	}//end logOutbound()

	/**
	 * Create an inbound audit row from a received envelope.
	 *
	 * @param array $endpoint The StufEndpoint that received.
	 * @param string $responseXml The full inbound envelope XML.
	 * @param string $messageKind The bericht code (Bv01, Lk02, ...).
	 * @param string $crossRefnummer The crossRefnummer (matches an outbound referentienummer).
	 * @param string|null $caseId Optional zaak identificatie.
	 * @param string|null $role Optional functie.
	 *
	 * @return array The persisted StufMessage as array.
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-audit-log
	 */
	public function logInbound(
		array $endpoint,
		string $responseXml,
		string $messageKind,
		string $crossRefnummer,
		?string $caseId = null,
		?string $role = null,
	): array {
		$data = [
			'id' => $this->newId(prefix: 'stuf-msg'),
			'endpointId' => (string)($endpoint['id'] ?? ''),
			'direction' => self::DIRECTION_INBOUND,
			'messageKind' => $messageKind,
			'role' => ($role ?? ''),
			'entiteittype' => 'ZAK',
			'crossRefnummer' => $crossRefnummer,
			'caseIdentification' => ($caseId ?? ''),
			'relatedCaseId' => ($caseId ?? ''),
			'envelopeXml' => $responseXml,
			'sentOn' => $this->isoNow(),
			'receivedOn' => $this->isoNow(),
			'status' => 'bevestigd',
		];
		return $this->register->saveObject(schema: StufRegisterAccess::SCHEMA_MESSAGE, data: $data);
	}//end logInbound()

	/**
	 * Append a retry entry to an existing outbound message and persist.
	 *
	 * @param array $msg The existing StufMessage row.
	 * @param int $attempt The retry attempt number.
	 * @param int $httpStatus The HTTP status code on this attempt.
	 * @param array $fout The fout payload (code, omschrijving, details, kind).
	 * @param int $durationMs The wall-clock duration of this attempt.
	 *
	 * @return array The updated row.
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-circuit-breaker-and-retry
	 */
	public function recordRetry(array $msg, int $attempt, int $httpStatus, array $fout, int $durationMs): array {
		$retries = (array)($msg['retries'] ?? []);
		$retries[] = [
			'attempt' => $attempt,
			'timestamp' => $this->isoNow(),
			'httpStatus' => $httpStatus,
			'durationMs' => $durationMs,
			'fout' => $fout,
		];
		$msg['retries'] = $retries;
		$msg['status'] = 'wacht_op_retry';
		$msg['httpStatus'] = $httpStatus;
		return $this->register->saveObject(schema: StufRegisterAccess::SCHEMA_MESSAGE, data: $msg);
	}//end recordRetry()

	/**
	 * Transition the message lifecycle status.
	 *
	 * @param array $msg The existing message row.
	 * @param string $newStatus One of verzonden, bevestigd, fout, wacht_op_retry.
	 * @param array $extras Optional extra fields to merge (httpStatus, durationMs, fout, responseEnvelopeXml, receivedOn).
	 *
	 * @return array The updated row.
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-audit-log
	 */
	public function transitionStatus(array $msg, string $newStatus, array $extras = []): array {
		$msg['status'] = $newStatus;
		if (array_key_exists(key: 'receivedOn', array: $extras) === false) {
			$msg['receivedOn'] = $this->isoNow();
		}

		foreach ($extras as $key => $value) {
			$msg[$key] = $value;
		}

		return $this->register->saveObject(schema: StufRegisterAccess::SCHEMA_MESSAGE, data: $msg);
	}//end transitionStatus()

	/**
	 * Find an outbound message by referentienummer.
	 *
	 * @param string $referentienummer The referentienummer.
	 *
	 * @return array|null The message row, or null.
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md
	 */
	public function findOutboundByReferentienummer(string $referentienummer): ?array {
		$match = $this->register->findOne(
			schema: StufRegisterAccess::SCHEMA_MESSAGE,
			filters: ['referenceNumber' => $referentienummer, 'direction' => self::DIRECTION_OUTBOUND]
		);

		if ($match !== null) {
			return $match;
		}

		// TRANSITION FALLBACK — remove once RenameDutchDirectionValues has run
		// everywhere. This is a READ, and it is the dangerous half of a value
		// rename: a row written before the migration still says `uitgaand`, and
		// a query for `outbound` alone returns null rather than an error. The
		// caller treats null as "no outbound message to confirm", so a Bv01
		// confirmation would be silently dropped instead of failing loudly.
		// findOne() takes scalar filters only, so this cannot be expressed as an
		// IN and has to be a second query.
		return $this->register->findOne(
			schema: StufRegisterAccess::SCHEMA_MESSAGE,
			filters: ['referenceNumber' => $referentienummer, 'direction' => self::LEGACY_DIRECTION_OUTBOUND]
		);
	}//end findOutboundByReferentienummer()

	/**
	 * Mint a new readable id with a millisecond suffix.
	 *
	 * @param string $prefix The prefix.
	 *
	 * @return string The new id.
	 */
	private function newId(string $prefix): string {
		$now = new DateTimeImmutable(datetime: 'now', timezone: new DateTimeZone(timezone: 'Europe/Amsterdam'));
		return $prefix . '-' . $now->format(format: 'Y-m-d-H-i-s') . '-' . bin2hex(string: random_bytes(length: 3));
	}//end newId()

	/**
	 * ISO-8601 timestamp at second precision in Europe/Amsterdam.
	 *
	 * @return string The ISO timestamp.
	 */
	private function isoNow(): string {
		$now = new DateTimeImmutable(datetime: 'now', timezone: new DateTimeZone(timezone: 'Europe/Amsterdam'));
		return $now->format(format: 'c');
	}//end isoNow()
}//end class
