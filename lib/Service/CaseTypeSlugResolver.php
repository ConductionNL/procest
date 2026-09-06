<?php

/**
 * Dossiq case-type slug resolver.
 *
 * Dossiq's register holds the case type BOTH ways, and the two halves used to
 * disagree in silence. A `case` object carries `caseType` as a uuid (the
 * property is `format: uuid`, `$ref: caseType`), while a `deadlineDefinition`
 * carries it as a SLUG — its own description says "Zaaktype slug this
 * definition binds to (e.g. omgevingsvergunning-regulier)", the shipped seed
 * writes slugs, and `TermijnService::getTermijnDefinitie()` filters on
 * equality.
 *
 * So `DeadlineCaseCreatedListener` handed a uuid to a filter that only ever
 * matches a slug. No shipped case type could match, no TermijnInstance was
 * bound, and no FlowTimer armed — measured on a fresh rig: zero flow_timers
 * and zero deadlineInstance rows across seven cases. The refusal was logged at
 * DEBUG, so at the default loglevel the whole statutory clock simply did not
 * start and nothing said so.
 *
 * THE SLUG IS THE KEY, and this class is the one place that converts. A value
 * that is not uuid-shaped is already a slug and is returned untouched, so a
 * caller that holds a slug never pays for a lookup and never risks a miss.
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
 *
 * @spec openspec/specs/termijnbewaking-schemas/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns the caseType uuid a case carries into the slug the register keys by.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/termijnbewaking-schemas/spec.md
 */
class CaseTypeSlugResolver {

	use SearchesObjects;

	/**
	 * Resolved slugs keyed by caseType uuid, for the lifetime of the request.
	 *
	 * Misses are memoised too: a case type this instance cannot read must cost
	 * one failed lookup per request, not one per case created.
	 *
	 * @var array<string, string>
	 */
	private array $slugs = [];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register/schema configuration and the ObjectService.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve a case-type reference to the slug the register keys by.
	 *
	 * @param string $reference The caseType uuid a case carries, or a slug.
	 *
	 * @return string The slug, or an empty string when a uuid cannot be
	 *                resolved. An empty string matches no definition, so an
	 *                unresolvable case type keeps the fail-closed behaviour.
	 *
	 * @spec openspec/specs/termijnbewaking-schemas/spec.md
	 */
	public function toSlug(string $reference): string {
		$reference = trim($reference);
		if ($reference === '') {
			return '';
		}

		// Not uuid-shaped: the caller already holds a slug. Deliberately a
		// SHAPE test rather than "try the lookup and fall back" — a fallback
		// would turn every failed read into a silent pass-through, which is
		// the class of bug this resolver exists to end.
		if ($this->isUuid(value: $reference) === false) {
			return $reference;
		}

		if (array_key_exists($reference, $this->slugs) === true) {
			return $this->slugs[$reference];
		}

		$this->slugs[$reference] = $this->readSlug(caseTypeId: $reference);

		return $this->slugs[$reference];
	}//end toSlug()

	/**
	 * Read the slug off the stored caseType.
	 *
	 * THREE PLACES, AND THE ORDER IS LOAD-BEARING.
	 *
	 * `identifier` is a declared caseType property, written into the object
	 * body by the bezwaar and besluitvorming seeds. The case-flow and VTH
	 * seeds instead put a top-level `slug` in the import payload, and
	 * OpenRegister keeps that as object METADATA rather than a stored
	 * property — it comes back under `@self.slug`, and reading `$row['slug']`
	 * answers '' for every one of them (the mistake
	 * {@see \OCA\Dossiq\Repair\VthSeedDataRepairStep::existingSlugs()}
	 * documents, which re-seeded the whole VTH set nine times). The body
	 * `slug` is kept last as a fallback for objects created by some other
	 * path.
	 *
	 * @param string $caseTypeId The caseType uuid.
	 *
	 * @return string The slug, or an empty string.
	 */
	private function readSlug(string $caseTypeId): string {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return '';
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_type_schema');
		if ($register === '' || $schema === '') {
			return '';
		}

		try {
			$caseType = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseTypeId,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not read the case type behind a case, so its statutory term cannot be resolved',
				['caseType' => $caseTypeId, 'error' => $e->getMessage()]
			);

			return '';
		}

		if ($caseType === null) {
			return '';
		}

		$identifier = trim((string)($caseType['identifier'] ?? ''));
		if ($identifier !== '') {
			return $identifier;
		}

		$self = ($caseType['@self'] ?? null);
		if (is_array($self) === true) {
			$metadataSlug = trim((string)($self['slug'] ?? ''));
			if ($metadataSlug !== '') {
				return $metadataSlug;
			}
		}

		return trim((string)($caseType['slug'] ?? ''));
	}//end readSlug()

	/**
	 * Whether a value has the shape of a uuid.
	 *
	 * @param string $value The value.
	 *
	 * @return bool True when the value is uuid-shaped.
	 */
	private function isUuid(string $value): bool {
		return (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1);
	}//end isUuid()

}//end class
