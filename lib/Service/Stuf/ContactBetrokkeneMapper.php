<?php

/**
 * Dossiq ContactBetrokkeneMapper.
 *
 * Maintains the bidirectional link between a dossiq Contact and a
 * zaaksysteem betrokkene (NPS = natuurlijk persoon, NNP = niet-natuurlijk
 * persoon). Implements duplicate-prevention: before creating a new
 * betrokkene the mapper first queries the zaaksysteem (Lv01 geefBetrokkene)
 * via the supplied callable; only if the BSN is not found does it create
 * the mapping.
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
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-bidirectional-mapping
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Stuf;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;

/**
 * Maps dossiq Contact entities to zaaksysteem betrokkenen.
 *
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-bidirectional-mapping
 */
class ContactBetrokkeneMapper {
	/**
	 * Constructor.
	 *
	 * @param StufRegisterAccess $register The register access helper.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private StufRegisterAccess $register,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Persist a mapping for a contact → betrokkene pair.
	 *
	 * Reuses an existing mapping when one already exists for the same
	 * sourceId+endpointId combo (idempotent on retry).
	 *
	 * @param array $contact The dossiq Contact (array with id, bsn).
	 * @param string $involvedParty The external betrokkene identificatie.
	 * @param array $endpoint The StufEndpoint.
	 * @param string $entiteit The external entiteit (NPS|NNP).
	 *
	 * @return array The persisted ZaaksysteemMapping.
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-bidirectional-mapping
	 */
	public function linkContact(array $contact, string $involvedParty, array $endpoint, string $entiteit = 'NPS'): array {
		$existing = $this->getContactMapping(contact: $contact, endpoint: $endpoint);
		$data = ($existing ?? [
			'id' => $this->newId(prefix: 'map'),
			'sourceEntity' => 'contact',
			'sourceId' => (string)($contact['id'] ?? ''),
			'endpointId' => (string)($endpoint['id'] ?? ''),
		]);

		$data['externalEntity'] = $entiteit;
		$data['externalIdentification'] = $involvedParty;
		$data['lastSynchronisation'] = $this->isoNow();
		$data['synchronisationStatus'] = 'in_sync';

		return $this->register->saveObject(schema: StufRegisterAccess::SCHEMA_MAPPING, data: $data);
	}//end linkContact()

	/**
	 * Find or create a betrokkene for the contact.
	 *
	 * The `$lookupCallable` is a closure that performs the Lv01
	 * geefBetrokkene query against the zaaksysteem and returns either a
	 * betrokkene identificatie or `null`. This signature keeps the mapper
	 * free of HTTP/SOAP knowledge and trivially mockable.
	 *
	 * @param array $contact The Contact array (must carry a BSN for natural-person lookup).
	 * @param array $endpoint The StufEndpoint.
	 * @param callable $lookupCallable function(string $bsn, array $endpoint): ?string.
	 *
	 * @return string The betrokkene identificatie (existing or freshly returned).
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-bidirectional-mapping
	 */
	public function findOrCreateBetrokkene(array $contact, array $endpoint, callable $lookupCallable): string {
		$existing = $this->getContactMapping(contact: $contact, endpoint: $endpoint);
		if ($existing !== null && ($existing['externalIdentification'] ?? '') !== '') {
			return (string)$existing['externalIdentification'];
		}

		$bsn = $this->bsnFromContact(contact: $contact);
		if ($bsn === null) {
			$this->logger->info(
				message: 'StUF: contact {id} has no BSN; will be embedded as full NPS in next Lk01',
				context: ['id' => ($contact['id'] ?? '')]
			);
			return '';
		}

		$found = $lookupCallable($bsn, $endpoint);
		if (is_string(value: $found) === true && $found !== '') {
			$this->linkContact(contact: $contact, involvedParty: $found, endpoint: $endpoint, entiteit: 'NPS');
			return $found;
		}

		// Caller will embed full NPS in Lk01; mapping persisted on the BSN itself for reuse.
		$this->linkContact(contact: $contact, involvedParty: $bsn, endpoint: $endpoint, entiteit: 'NPS');
		return $bsn;
	}//end findOrCreateBetrokkene()

	/**
	 * Look up an existing mapping for a Contact+endpoint pair.
	 *
	 * @param array $contact The Contact.
	 * @param array $endpoint The StufEndpoint.
	 *
	 * @return array|null
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-bidirectional-mapping
	 */
	public function getContactMapping(array $contact, array $endpoint): ?array {
		$contactId = (string)($contact['id'] ?? '');
		$endpointId = (string)($endpoint['id'] ?? '');
		if ($contactId === '' || $endpointId === '') {
			return null;
		}

		return $this->register->findOne(
			schema: StufRegisterAccess::SCHEMA_MAPPING,
			filters: [
				'sourceEntity' => 'contact',
				'sourceId' => $contactId,
				'endpointId' => $endpointId,
			]
		);
	}//end getContactMapping()

	/**
	 * Extract BSN from a contact array (best-effort).
	 *
	 * Supports both flat `bsn` and `identifiers.bsn` shapes used across
	 * the contact schema variants.
	 *
	 * @param array $contact The Contact.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md
	 */
	public function bsnFromContact(array $contact): ?string {
		$candidates = [
			($contact['bsn'] ?? null),
			($contact['identifiers']['bsn'] ?? null),
			($contact['identificatie'] ?? null),
		];
		foreach ($candidates as $candidate) {
			if (is_string(value: $candidate) === true && $candidate !== '') {
				return $candidate;
			}
		}

		return null;
	}//end bsnFromContact()

	/**
	 * Mint a new mapping id.
	 *
	 * @param string $prefix The prefix.
	 *
	 * @return string The id.
	 */
	private function newId(string $prefix): string {
		return $prefix . '-' . bin2hex(string: random_bytes(length: 6));
	}//end newId()

	/**
	 * ISO-8601 timestamp.
	 *
	 * @return string The timestamp.
	 */
	private function isoNow(): string {
		return (new DateTimeImmutable(datetime: 'now', timezone: new DateTimeZone(timezone: 'Europe/Amsterdam')))->format(format: 'c');
	}//end isoNow()
}//end class
