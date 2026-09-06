<?php

/**
 * Dossiq Dossier Compiler.
 *
 * Assembles a read-only, AWB-conventionally ordered view of the
 * documents that make up a bezwaar (or beroep) dossier. The compiler
 * collects `caseDocument` references from the bezwaar case AND from the
 * linked primair besluit case (via `case.relatedCases`) and orders them
 * by document type per the conventional dossier sequence:
 *
 *   primair besluit -> bezwaarschrift -> verweerschrift ->
 *   hoorzittingverslag -> advies commissie -> beslissing op bezwaar ->
 *   overige stukken
 *
 * The compiler performs NO file copying and NO mutation: it returns an
 * ordered list of the existing caseDocument records so that a downstream
 * exporter (or a manifest-rendered panel) can present the complete
 * dossier across both cases. This keeps OpenRegister the single source
 * of truth for the documents — the dossier is a projection, not a copy.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Compiles an ordered, read-only bezwaar/beroep dossier view.
 *
 * @spec openspec/specs/bezwaar-beroep-workflow/spec.md
 */
class DossierCompiler {

	use SearchesObjects;

	/**
	 * AWB-conventional ordering of dossier document categories. Keys are
	 * normalised (lower-case) document-type fragments; the value is the
	 * sort rank (lower = earlier in the dossier). Anything not matched
	 * sorts after all known categories (rank self::RANK_OTHER) but keeps
	 * its relative input order (stable sort).
	 *
	 * @var array<string, int>
	 */
	private const ORDER_RANK = [
		'primair besluit' => 10,
		'primair' => 10,
		'bezwaarschrift' => 20,
		'verweerschrift' => 30,
		'hoorzittingverslag' => 40,
		'minutes' => 40,
		'advice' => 50,
		'decision' => 60,
	];

	/**
	 * Sort rank applied to any document type not present in ORDER_RANK.
	 */
	private const RANK_OTHER = 900;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema/register + OR bridge.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Compile the ordered dossier for a case.
	 *
	 * Resolves the case, gathers caseDocument records for the case itself
	 * and every related case referenced via `relatedCases`, then orders
	 * them by the AWB-conventional document sequence. The result is a
	 * read-only list — no records are created or mutated.
	 *
	 * @param string $caseId UUID of the bezwaar (or beroep) case.
	 *
	 * @return array<int, array<string, mixed>> Ordered caseDocument records,
	 *                                          each augmented with a
	 *                                          `_sourceCase` UUID marker.
	 *
	 * @throws RuntimeException When OpenRegister or the schemas are
	 *                          unavailable, or the case cannot be loaded.
	 *
	 * @spec openspec/specs/bezwaar-beroep-workflow/spec.md
	 */
	public function compile(string $caseId): array {
		if (trim($caseId) === '') {
			throw new RuntimeException('A case id is required to compile a dossier');
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');
		$docSchema = $this->settingsService->getConfigValue(key: 'case_document_schema');

		if ($register === '' || $caseSchema === '' || $docSchema === '') {
			throw new RuntimeException('Case or document schema is not configured');
		}

		$case = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $caseSchema,
			id: $caseId
		);
		if ($case === null) {
			throw new RuntimeException('Case not found');
		}

		// Build the ordered set of case UUIDs whose documents belong in
		// the dossier: the primair besluit case(s) first, then the case
		// itself. This keeps inherited documents ahead of bezwaar-own
		// documents within the same rank.
		$caseUuids = $this->resolveDossierCaseUuids(case: $case, caseId: $caseId);

		$documents = [];
		foreach ($caseUuids as $uuid) {
			$documents = array_merge(
				$documents,
				$this->collectCaseDocuments(
					objectService: $objectService,
					register: $register,
					docSchema: $docSchema,
					caseUuid: $uuid
				)
			);
		}

		return $this->orderDocuments(documents: $documents);
	}//end compile()

	/**
	 * Resolve the ordered list of case UUIDs that contribute documents.
	 *
	 * Related cases (primair besluit, source bezwaar for a beroep) are
	 * listed before the case itself so inherited documents precede the
	 * case's own documents within each document-type rank.
	 *
	 * @param array<string, mixed> $case The resolved case record.
	 * @param string $caseId The requested case UUID.
	 *
	 * @return array<int, string> Ordered, de-duplicated case UUIDs.
	 */
	private function resolveDossierCaseUuids(array $case, string $caseId): array {
		$related = [];
		$rawRelated = ($case['relatedCases'] ?? []);
		if (is_array($rawRelated) === true) {
			foreach ($rawRelated as $entry) {
				$uuid = $this->extractUuid(value: $entry);
				if ($uuid !== '' && $uuid !== $caseId) {
					$related[] = $uuid;
				}
			}
		}

		$ordered = array_merge($related, [$caseId]);

		// De-duplicate while preserving first-seen order.
		$seen = [];
		$result = [];
		foreach ($ordered as $uuid) {
			if (isset($seen[$uuid]) === true) {
				continue;
			}

			$seen[$uuid] = true;
			$result[] = $uuid;
		}

		return $result;
	}//end resolveDossierCaseUuids()

	/**
	 * Extract a UUID from a relatedCases entry (string or object/array).
	 *
	 * @param mixed $value The relatedCases entry.
	 *
	 * @return string The UUID, or '' when none could be derived.
	 */
	private function extractUuid(mixed $value): string {
		if (is_string($value) === true) {
			return trim($value);
		}

		if (is_array($value) === true) {
			foreach (['id', 'uuid', '@self.uuid', 'case', 'target'] as $key) {
				if (isset($value[$key]) === true && is_string($value[$key]) === true) {
					return trim($value[$key]);
				}
			}
		}

		return '';
	}//end extractUuid()

	/**
	 * Collect caseDocument records for a single case UUID.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register Register id.
	 * @param string $docSchema case_document schema id.
	 * @param string $caseUuid The case UUID to filter on.
	 *
	 * @return array<int, array<string, mixed>> Normalised caseDocument records.
	 */
	private function collectCaseDocuments(
		object $objectService,
		string $register,
		string $docSchema,
		string $caseUuid,
	): array {
		try {
			$results = $objectService->findAll(
				[
					'filters' => [
						'register' => $register,
						'schema' => $docSchema,
						'case' => $caseUuid,
					],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'DossierCompiler: failed to list case documents',
				['case' => $caseUuid, 'error' => $e->getMessage()]
			);
			return [];
		}

		$rows = $this->unwrapResults(results: $results);

		$documents = [];
		foreach ($rows as $row) {
			$record = $this->toArray(value: $row);
			if ($record === null) {
				continue;
			}

			$record['_sourceCase'] = $caseUuid;
			$documents[] = $record;
		}

		return $documents;
	}//end collectCaseDocuments()

	/**
	 * Order documents by the AWB-conventional dossier sequence.
	 *
	 * Uses a stable sort: documents of the same rank keep their input
	 * order (which already puts inherited cases before the own case).
	 *
	 * @param array<int, array<string, mixed>> $documents Unordered records.
	 *
	 * @return array<int, array<string, mixed>> Ordered records.
	 */
	private function orderDocuments(array $documents): array {
		$indexed = [];
		foreach ($documents as $position => $document) {
			$indexed[] = [
				'rank' => $this->rankFor(document: $document),
				'position' => $position,
				'document' => $document,
			];
		}

		usort(
			$indexed,
			static function (array $left, array $right): int {
				if ($left['rank'] !== $right['rank']) {
					return ($left['rank'] <=> $right['rank']);
				}

				return ($left['position'] <=> $right['position']);
			}
		);

		return array_map(
			static fn (array $entry): array => $entry['document'],
			$indexed
		);
	}//end orderDocuments()

	/**
	 * Determine the sort rank for a single document record.
	 *
	 * @param array<string, mixed> $document The caseDocument record.
	 *
	 * @return int The sort rank.
	 */
	private function rankFor(array $document): int {
		$haystack = strtolower(
			(string)($document['title'] ?? '')
			. ' ' . (string)($document['description'] ?? '')
			. ' ' . (string)($document['documentType'] ?? '')
		);

		foreach (self::ORDER_RANK as $needle => $rank) {
			if (str_contains($haystack, $needle) === true) {
				return $rank;
			}
		}

		return self::RANK_OTHER;
	}//end rankFor()

	/**
	 * Unwrap a findAll result into a flat list of rows.
	 *
	 * Handles both the bare-array and the paginated {results: []} shapes
	 * the OpenRegister ObjectService can return.
	 *
	 * @param mixed $results The findAll return value.
	 *
	 * @return array<int, mixed> The list of rows.
	 */
	private function unwrapResults(mixed $results): array {
		if (is_array($results) === false) {
			return [];
		}

		if (isset($results['results']) === true && is_array($results['results']) === true) {
			return array_values($results['results']);
		}

		return array_values($results);
	}//end unwrapResults()

	/**
	 * Normalise an OpenRegister row (array or entity) to an array.
	 *
	 * @param mixed $value The row.
	 *
	 * @return array<string, mixed>|null The array form, or null.
	 */
	private function toArray(mixed $value): ?array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return null;
	}//end toArray()
}//end class
