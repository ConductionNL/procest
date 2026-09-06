<?php

/**
 * Dossiq Staatssteun Classifier.
 *
 * EU state-aid classification helpers (REQ-SUB-008): de-minimis threshold
 * checking against the €300.000-per-three-years ceiling (Verordening
 * 1407/2013, geactualiseerd 2024), AGVV eligibility (Verordening 651/2014),
 * and DAEB detection (Besluit 2012/21/EU). All classification logic is pure
 * and unit-tested; prior-aid lookup is delegated to a supplied history
 * provider so this class stays free of persistence coupling.
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
 * Pure EU state-aid classification helpers.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/subsidieverlening-keten/specs.md
 */
class StaatssteunClassifier {
	/**
	 * De-minimis ceiling per onderneming over three years, in EUR
	 * (Verordening 1407/2013 as updated in 2024).
	 */
	public const DE_MINIMIS_PLAFOND = 300000.0;

	/**
	 * De-minimis lookback window, in years.
	 */
	public const DE_MINIMIS_LOOKBACK_JAREN = 3;

	/**
	 * AGVV articles supported for classification (subset relevant to
	 * municipal grant-making).
	 *
	 * @var array<int, string>
	 */
	public const AGVV_ARTIKELEN = ['art14', 'art17', 'art25', 'art31', 'art53', 'art55'];

	/**
	 * Whether a new grant fits under the de-minimis ceiling given the
	 * cumulative prior de-minimis aid to the same onderneming (REQ-SUB-008).
	 *
	 * @param float $newAmount The proposed grant amount.
	 * @param float $eerdereDeMinimis The cumulative prior de-minimis aid in the window.
	 *
	 * @return bool True when the cumulative total stays within the ceiling.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function fitsDeMinimis(float $newAmount, float $eerdereDeMinimis): bool {
		return ($eerdereDeMinimis + $newAmount) <= self::DE_MINIMIS_PLAFOND;
	}//end fitsDeMinimis()

	/**
	 * Remaining de-minimis headroom for an onderneming (REQ-SUB-008).
	 *
	 * @param float $eerdereDeMinimis The cumulative prior de-minimis aid in the window.
	 *
	 * @return float The remaining headroom in EUR (never negative).
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function deMinimisHeadroom(float $eerdereDeMinimis): float {
		return max(0.0, (self::DE_MINIMIS_PLAFOND - $eerdereDeMinimis));
	}//end deMinimisHeadroom()

	/**
	 * Whether a state-aid ground is required for an amount: any aid above
	 * the de-minimis ceiling needs an explicit ground (REQ-SUB-008).
	 *
	 * @param float $amount The proposed grant amount.
	 * @param float $eerdereDeMinimis The cumulative prior de-minimis aid.
	 *
	 * @return bool True when a state-aid ground must be recorded.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function requiresStaatssteunGrondslag(float $amount, float $eerdereDeMinimis): bool {
		return $this->fitsDeMinimis(newAmount: $amount, eerdereDeMinimis: $eerdereDeMinimis) === false;
	}//end requiresStaatssteunGrondslag()

	/**
	 * Whether an AGVV article is one of the supported classifications.
	 *
	 * @param string $artikel The AGVV article token.
	 *
	 * @return bool True when supported.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function isAgvvArtikel(string $artikel): bool {
		return in_array($artikel, self::AGVV_ARTIKELEN, true);
	}//end isAgvvArtikel()

	/**
	 * Classify a grant into a state-aid category (REQ-SUB-008).
	 *
	 * @param float $amount The proposed grant amount.
	 * @param float $eerdereDeMinimis The cumulative prior de-minimis aid.
	 * @param string|null $agvvArtikel An AGVV article, when the handler asserts AGVV cover.
	 * @param bool $isDaeb Whether the activity is a DAEB.
	 *
	 * @return string One of geen|de_minimis|agvv|daeb|notificatieplicht.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) — $isDaeb is a classification
	 * input (the activity either is or is not a DAEB), not a behaviour switch.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function classify(float $amount, float $eerdereDeMinimis, ?string $agvvArtikel = null, bool $isDaeb = false): string {
		if ($isDaeb === true) {
			return 'daeb';
		}

		if ($this->fitsDeMinimis(newAmount: $amount, eerdereDeMinimis: $eerdereDeMinimis) === true) {
			if ($amount <= 0.0) {
				return 'none';
			}

			return 'de_minimis';
		}

		if ($agvvArtikel !== null && $this->isAgvvArtikel(artikel: $agvvArtikel) === true) {
			return 'agvv';
		}

		return 'notificatieplicht';
	}//end classify()

	/**
	 * Build a TAM-melding payload for an AGVV-classified grant (REQ-SUB-008).
	 *
	 * @param string $beschikkingnummer The decision number.
	 * @param string $agvvArtikel The AGVV article.
	 * @param float $amount The granted amount.
	 *
	 * @return array<string, mixed> The melding payload for async transmission.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function buildTamMelding(string $beschikkingnummer, string $agvvArtikel, float $amount): array {
		return [
			'register' => 'TAM',
			'beschikkingnummer' => $beschikkingnummer,
			'rechtsgrond' => 'AGVV 651/2014 ' . $agvvArtikel,
			'amount' => round($amount, 2),
		];
	}//end buildTamMelding()
}//end class
