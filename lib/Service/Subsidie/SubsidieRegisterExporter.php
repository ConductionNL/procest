<?php

/**
 * Dossiq Subsidieregister Exporter.
 *
 * Builds the Wet open overheid (art. 3.3 lid 2 onder f) subsidieregister
 * feed (REQ-SUB-006): a structured, JSON-LD-annotated list of granted and
 * settled subsidies for publication on the gemeentewebsite. Individual
 * applicants (natuurlijke personen) are anonymised per the VNG AVG-richtlijn;
 * legal persons are listed by name. The feed-shaping logic is pure and
 * unit-tested; the data is supplied by the calling controller/service.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Subsidie
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Subsidie;

/**
 * Wet open overheid subsidieregister feed builder.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/subsidieverlening-keten/specs.md
 */
class SubsidieRegisterExporter {
	/**
	 * JSON-LD context for linked-data consumers.
	 */
	public const JSON_LD_CONTEXT = 'https://standaarden.overheid.nl/owms/terms/';

	/**
	 * Anonymise an applicant for the public feed (REQ-SUB-006). Legal
	 * persons (with a KvK reference) keep their name; natuurlijke personen
	 * are reduced to "Particulier".
	 *
	 * @param array<string, mixed> $request The application record.
	 *
	 * @return string The display name for the public register.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function publicOntvanger(array $request): string {
		$kvk = (string)($request['applicantKvkRef'] ?? '');
		if ($kvk !== '') {
			return (string)($request['aanvragerNaam'] ?? ('KvK ' . $kvk));
		}

		// No KvK -> treated as a natural person and anonymised.
		return 'Particulier';
	}//end publicOntvanger()

	/**
	 * Map one subsidy dossier into a feed entry (REQ-SUB-006).
	 *
	 * @param array<string, mixed> $request The application record.
	 * @param array<string, mixed> $regeling The regeling record.
	 * @param array<string, mixed> $decision The (latest) decision record.
	 *
	 * @return array<string, mixed> The feed entry.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function toFeedEntry(array $request, array $regeling, array $decision): array {
		$determined = (string)($decision['beschikkingtype'] ?? '') === 'vaststellingsbeschikking';
		$status = 'granted';
		if ($determined === true) {
			$status = 'determined';
		}

		return [
			'@type' => 'Subsidie',
			'regeling' => (string)($regeling['schemeName'] ?? ''),
			'ontvanger' => $this->publicOntvanger(request: $request),
			'amount' => (float)($decision['grantedAmount'] ?? 0),
			'looptijd' => [
				'start' => (string)($decision['termStart'] ?? ''),
				'eind' => (string)($decision['termEnd'] ?? ''),
			],
			'doel' => (string)($regeling['targetGroup'] ?? ''),
			'status' => $status,
			'basis' => (string)($decision['legalBasis'] ?? ''),
		];
	}//end toFeedEntry()

	/**
	 * Build a complete, paginated JSON-LD feed document (REQ-SUB-006).
	 *
	 * @param array<int, array<string, mixed>> $entries The pre-built feed entries.
	 * @param int $limit Page size.
	 * @param int $offset Page offset.
	 *
	 * @return array<string, mixed> The feed document.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function buildFeed(array $entries, int $limit = 100, int $offset = 0): array {
		$limit = max(1, $limit);
		$offset = max(0, $offset);
		$total = count($entries);
		$page = array_slice($entries, $offset, $limit);

		return [
			'@context' => self::JSON_LD_CONTEXT,
			'@type' => 'Subsidieregister',
			'total' => $total,
			'limit' => $limit,
			'offset' => $offset,
			'results' => array_values($page),
		];
	}//end buildFeed()
}//end class
