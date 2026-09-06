<?php

/**
 * Dossiq StUF Field Mapping Service
 *
 * Service for bidirectional field mapping between StUF XML paths and
 * OpenRegister object properties. Handles date format conversion,
 * enum value transformation, and configurable custom mappings.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/stuf-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Service for StUF-to-OpenRegister field mapping.
 *
 * Provides bidirectional mapping between StUF XML field paths and
 * OpenRegister object properties, including date format conversion
 * (YYYYMMDD <-> ISO 8601) and enum value transformation.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class StufFieldMappingService {
	/**
	 * StUF date format (YYYYMMDD).
	 */
	private const STUF_DATE_FORMAT = 'Ymd';

	/**
	 * StUF datetime format (YYYYMMDDHHmmss).
	 */
	private const STUF_DATETIME_FORMAT = 'YmdHis';

	/**
	 * Default StUF-ZKN to Dossiq case field mappings.
	 *
	 * @var array<string, array{property: string, transform: string|null}>
	 */
	private const DEFAULT_ZKN_MAPPINGS = [
		'identificatie' => ['property' => 'identifier', 'transform' => null],
		'omschrijving' => ['property' => 'title', 'transform' => null],
		'toelichting' => ['property' => 'description', 'transform' => null],
		'startdatum' => ['property' => 'startDate', 'transform' => 'stufDateToIso'],
		'einddatum' => ['property' => 'endDate', 'transform' => 'stufDateToIso'],
		'einddatumGepland' => ['property' => 'plannedEndDate', 'transform' => 'stufDateToIso'],
		'uiterlijkeEinddatumAfdoening' => ['property' => 'deadline', 'transform' => 'stufDateToIso'],
		'registratiedatum' => ['property' => 'registrationDate', 'transform' => 'stufDateToIso'],
		'vertrouwelijkAanduiding' => ['property' => 'confidentiality', 'transform' => 'confidentialityToInternal'],
	];

	/**
	 * Default StUF-BG to OpenRegister person field mappings.
	 *
	 * @var array<string, array{property: string, transform: string|null}>
	 */
	private const DEFAULT_BG_MAPPINGS = [
		'inp.bsn' => ['property' => 'bsn', 'transform' => null],
		'geslachtsnaam' => ['property' => 'lastName', 'transform' => null],
		'voorvoegselGeslachtsnaam' => ['property' => 'namePrefix', 'transform' => null],
		'voornamen' => ['property' => 'firstName', 'transform' => null],
		'geboortedatum' => ['property' => 'dateOfBirth', 'transform' => 'stufDateToIso'],
	];

	/**
	 * Confidentiality enum mapping: StUF value -> internal value.
	 *
	 * @var array<string, string>
	 */
	private const CONFIDENTIALITY_MAP = [
		'OPENBAAR' => 'public',
		'BEPERKT OPENBAAR' => 'restricted',
		'INTERN' => 'internal',
		'ZAAKVERTROUWELIJK' => 'case_sensitive',
		'VERTROUWELIJK' => 'confidential',
		'CONFIDENTIEEL' => 'highly_confidential',
		'GEHEIM' => 'secret',
		'ZEER GEHEIM' => 'top_secret',
	];

	/**
	 * Custom field mappings (loaded from config).
	 *
	 * @var array<string, array<string, array{property: string, transform: string|null}>>
	 */
	private array $customMappings = [];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger instance.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Map StUF-ZKN fields to OpenRegister case properties.
	 *
	 * @param array<string, string> $stufData StUF field values keyed by field name.
	 *
	 * @return array<string, mixed> OpenRegister property values.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function mapZknToInternal(array $stufData): array {
		$mappings = array_merge(
			self::DEFAULT_ZKN_MAPPINGS,
			$this->customMappings['zkn'] ?? []
		);

		return $this->applyMappings(data: $stufData, mappings: $mappings);
	}//end mapZknToInternal()

	/**
	 * Map OpenRegister case properties to StUF-ZKN fields.
	 *
	 * @param array<string, mixed> $internalData OpenRegister property values.
	 *
	 * @return array<string, string> StUF field values.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function mapInternalToZkn(array $internalData): array {
		$mappings = array_merge(
			self::DEFAULT_ZKN_MAPPINGS,
			$this->customMappings['zkn'] ?? []
		);

		return $this->applyReverseMappings(data: $internalData, mappings: $mappings);
	}//end mapInternalToZkn()

	/**
	 * Map StUF-BG fields to OpenRegister person properties.
	 *
	 * @param array<string, string> $stufData StUF field values.
	 *
	 * @return array<string, mixed> OpenRegister property values.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function mapBgToInternal(array $stufData): array {
		$mappings = array_merge(
			self::DEFAULT_BG_MAPPINGS,
			$this->customMappings['bg'] ?? []
		);

		return $this->applyMappings(data: $stufData, mappings: $mappings);
	}//end mapBgToInternal()

	/**
	 * Map OpenRegister person properties to StUF-BG fields.
	 *
	 * @param array<string, mixed> $internalData OpenRegister property values.
	 *
	 * @return array<string, string> StUF field values.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function mapInternalToBg(array $internalData): array {
		$mappings = array_merge(
			self::DEFAULT_BG_MAPPINGS,
			$this->customMappings['bg'] ?? []
		);

		return $this->applyReverseMappings(data: $internalData, mappings: $mappings);
	}//end mapInternalToBg()

	/**
	 * Convert a StUF date (YYYYMMDD) to ISO 8601 (YYYY-MM-DD).
	 *
	 * @param string $stufDate The StUF date string.
	 *
	 * @return string|null The ISO 8601 date, or null if invalid.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function stufDateToIso(string $stufDate): ?string {
		// `YYYYMMDD` and `YYYYMMDDHHMMSS` are both ISO-8601 *basic* forms that
		// the DateTimeImmutable constructor parses natively; unlike
		// createFromFormat() it rejects out-of-range components (e.g. month 13)
		// instead of silently rolling them over, which matches this method's
		// documented "null if invalid" contract.
		$outputFormat = null;
		if (strlen($stufDate) === 8) {
			$outputFormat = 'Y-m-d';
		}

		if (strlen($stufDate) === 14) {
			$outputFormat = \DateTimeInterface::ATOM;
		}

		if ($outputFormat !== null) {
			try {
				return (new DateTimeImmutable($stufDate))->format($outputFormat);
			} catch (\Exception $e) {
				$this->logger->debug('StUF date rejected by DateTimeImmutable: {msg}', ['msg' => $e->getMessage()]);
			}
		}

		$this->logger->warning('Invalid StUF date format: {date}', ['date' => $stufDate]);
		return null;
	}//end stufDateToIso()

	/**
	 * Convert an ISO 8601 date to StUF date format (YYYYMMDD).
	 *
	 * @param string $isoDate The ISO 8601 date string.
	 *
	 * @return string The StUF date string.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function isoToStufDate(string $isoDate): string {
		$parsed = new DateTimeImmutable($isoDate);
		return $parsed->format(self::STUF_DATE_FORMAT);
	}//end isoToStufDate()

	/**
	 * Convert an ISO 8601 datetime to StUF datetime format (YYYYMMDDHHmmss).
	 *
	 * @param string $isoDateTime The ISO 8601 datetime string.
	 *
	 * @return string The StUF datetime string.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function isoToStufDateTime(string $isoDateTime): string {
		$parsed = new DateTimeImmutable($isoDateTime);
		return $parsed->format(self::STUF_DATETIME_FORMAT);
	}//end isoToStufDateTime()

	/**
	 * Convert a StUF confidentiality value to internal value.
	 *
	 * @param string $stufValue The StUF confidentiality value.
	 *
	 * @return string The internal value.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function confidentialityToInternal(string $stufValue): string {
		return self::CONFIDENTIALITY_MAP[strtoupper($stufValue)] ?? $stufValue;
	}//end confidentialityToInternal()

	/**
	 * Convert an internal confidentiality value to StUF value.
	 *
	 * @param string $internalValue The internal value.
	 *
	 * @return string The StUF value.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function confidentialityToStuf(string $internalValue): string {
		$flipped = array_flip(self::CONFIDENTIALITY_MAP);
		return $flipped[$internalValue] ?? strtoupper($internalValue);
	}//end confidentialityToStuf()

	/**
	 * Add custom field mappings.
	 *
	 * @param string $type The mapping type ('zkn' or 'bg').
	 * @param array<string, array{property: string, transform: string|null}> $mappings The custom mappings.
	 *
	 * @return void
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function addCustomMappings(string $type, array $mappings): void {
		$this->customMappings[$type] = array_merge(
			$this->customMappings[$type] ?? [],
			$mappings
		);
	}//end addCustomMappings()

	/**
	 * Get all default mappings for a type.
	 *
	 * @param string $type The mapping type ('zkn' or 'bg').
	 *
	 * @return array<string, array{property: string, transform: string|null}>
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getDefaultMappings(string $type): array {
		return match ($type) {
			'zkn' => self::DEFAULT_ZKN_MAPPINGS,
			'bg' => self::DEFAULT_BG_MAPPINGS,
			default => [],
		};
	}//end getDefaultMappings()

	/**
	 * Apply mappings to convert StUF data to internal format.
	 *
	 * This is the StUF-to-internal direction; the reverse direction is written
	 * by {@see StufFieldMappingService::getDefaultMappings()} consumers and does
	 * not route through here, so no direction argument is taken.
	 *
	 * @param array<string, string> $data The source data.
	 * @param array<string, array{property: string, transform: string|null}> $mappings The field mappings.
	 *
	 * @return array<string, mixed> The mapped data.
	 */
	private function applyMappings(array $data, array $mappings): array {
		$result = [];

		foreach ($data as $stufField => $value) {
			if (isset($mappings[$stufField]) === false) {
				continue;
			}

			$mapping = $mappings[$stufField];
			$property = $mapping['property'];
			$transform = $mapping['transform'];

			if ($transform !== null && method_exists($this, $transform) === true) {
				$value = $this->$transform($value);
			}

			$result[$property] = $value;
		}

		return $result;
	}//end applyMappings()

	/**
	 * Apply reverse mappings to convert internal data to StUF format.
	 *
	 * @param array<string, mixed> $data The internal data.
	 * @param array<string, array{property: string, transform: string|null}> $mappings The field mappings.
	 *
	 * @return array<string, string> The StUF data.
	 */
	private function applyReverseMappings(array $data, array $mappings): array {
		$result = [];

		// Build reverse lookup: property -> stufField.
		$reverseLookup = [];
		foreach ($mappings as $stufField => $mapping) {
			$reverseLookup[$mapping['property']] = [
				'stufField' => $stufField,
				'transform' => $mapping['transform'],
			];
		}

		foreach ($data as $property => $value) {
			if (isset($reverseLookup[$property]) === false) {
				continue;
			}

			$info = $reverseLookup[$property];
			$stufField = $info['stufField'];

			// Apply reverse transform.
			if ($value !== null && $info['transform'] !== null) {
				$value = match ($info['transform']) {
					'stufDateToIso' => $this->isoToStufDate(isoDate: (string)$value),
					'confidentialityToInternal' => $this->confidentialityToStuf(internalValue: (string)$value),
					default => (string)$value,
				};
			}

			$result[$stufField] = (string)($value ?? '');
		}

		return $result;
	}//end applyReverseMappings()
}//end class
