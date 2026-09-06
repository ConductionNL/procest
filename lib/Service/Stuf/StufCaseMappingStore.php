<?php

/**
 * Dossiq StUF case-mapping store.
 *
 * Reads and writes the ZaaksysteemMapping row that ties a dossiq case to its
 * zaak in a legacy zaaksysteem. The write is idempotent: an existing mapping is
 * updated in place rather than duplicated, which is what makes the anticipatory
 * mapping (`zaakIdentificatieStrategie = vooraf`) safe to write before the
 * kennisgeving has been confirmed.
 *
 * Split out of {@see StufAdapterService}.
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
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-orchestration
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Stuf;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Stores and looks up case → zaak mappings.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-orchestration
 */
class StufCaseMappingStore {
	/**
	 * Constructor.
	 *
	 * @param StufRegisterAccess $register The register access helper.
	 *
	 * @return void
	 */
	public function __construct(
		private StufRegisterAccess $register,
	) {
	}//end __construct()

	/**
	 * Find the existing mapping for a case on an endpoint.
	 *
	 * @param array $case The case.
	 * @param array $endpoint The endpoint.
	 *
	 * @return array|null The mapping row, or null when the case has never been sent.
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-orchestration
	 */
	public function find(array $case, array $endpoint): ?array {
		return $this->register->findOne(
			schema: StufRegisterAccess::SCHEMA_MAPPING,
			filters: $this->identity(case: $case, endpoint: $endpoint)
		);
	}//end find()

	/**
	 * Persist a case → zaak mapping (idempotent).
	 *
	 * @param array $case The case.
	 * @param string $externId The external zaak identificatie.
	 * @param array $endpoint The endpoint.
	 *
	 * @return array The mapping row.
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-orchestration
	 */
	public function persist(array $case, string $externId, array $endpoint): array {
		$identity = $this->identity(case: $case, endpoint: $endpoint);
		$data = ($this->find(case: $case, endpoint: $endpoint) ?? array_merge(
			$identity,
			[
				'id' => 'map-' . bin2hex(string: random_bytes(length: 6)),
				'caseId' => $identity['sourceId'],
				'externalEntity' => 'ZAK',
			]
		));

		return $this->register->saveObject(
			schema: StufRegisterAccess::SCHEMA_MAPPING,
			data: array_merge(
				$data,
				[
					'caseId' => $identity['sourceId'],
					'externalIdentification' => $externId,
					'lastSynchronisation' => $this->now(),
					'synchronisationStatus' => 'in_sync',
				]
			)
		);
	}//end persist()

	/**
	 * The (sourceEntity, sourceId, endpointId) triple that identifies one mapping.
	 *
	 * @param array $case The case.
	 * @param array $endpoint The endpoint.
	 *
	 * @return array<string, string> The identity filter.
	 */
	private function identity(array $case, array $endpoint): array {
		return [
			'sourceEntity' => 'case',
			'sourceId' => (string)($case['id'] ?? ''),
			'endpointId' => (string)($endpoint['id'] ?? ''),
		];
	}//end identity()

	/**
	 * The current synchronisation moment in Europe/Amsterdam, ISO-8601.
	 *
	 * @return string The timestamp.
	 */
	private function now(): string {
		return (new DateTimeImmutable(
			datetime: 'now',
			timezone: new DateTimeZone(timezone: 'Europe/Amsterdam')
		))->format(format: 'c');
	}//end now()
}//end class
