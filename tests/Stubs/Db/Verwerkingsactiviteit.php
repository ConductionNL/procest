<?php

/**
 * Test stub for OpenRegister's Verwerkingsactiviteit entity.
 *
 * Minimal surface needed by dossiq unit tests: the catalogue seed repair
 * step (SeedVerwerkingsactiviteiten) sets the descriptive AVG art. 30
 * fields and reads code/status. Mirrors the real OR entity's DECLARED
 * PROPERTIES (English, post Dutch-column rename) and refuses an undeclared
 * attribute exactly like a QBMapper entity's magic __call does — the
 * earlier setter-sink stub accepted setNaam() happily while the real
 * entity threw "naam is not a valid attribute", which is how 7 failing
 * seed rows hid under a green suite.
 *
 * @category Stub
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Stub of OpenRegister's Verwerkingsactiviteit entity for unit tests.
 *
 * @method void setCode(?string $code)
 * @method void setName(?string $name)
 * @method void setDescription(?string $description)
 * @method void setPurpose(?string $purpose)
 * @method void setLegalBasis(?string $legalBasis)
 * @method void setRetentionPeriod(?string $retentionPeriod)
 * @method void setStatus(?string $status)
 * @method void setDataSubjectCategories(?array $categories)
 * @method void setPersonalDataCategories(?array $categories)
 * @method void setRecipients(?array $recipients)
 * @method string|null getCode()
 * @method string|null getName()
 * @method string|null getPurpose()
 * @method string|null getLegalBasis()
 * @method string|null getStatus()
 */
class Verwerkingsactiviteit {
	/**
	 * Article 6 GDPR legal-basis vocabulary, copied from OpenRegister.
	 *
	 * THIS CONSTANT USED TO BE DUTCH, AND THAT IS WHY NOTHING CAUGHT THE BUG.
	 * openregister#2555 internationalised the Verwerkingsactiviteit entity: it
	 * renamed the columns AND this vocabulary, and
	 * `VerwerkingsactiviteitMapper::validate()` refuses anything outside it.
	 * The stub kept the pre-#2555 Dutch spellings, and
	 * `testShippedCatalogueSatisfiesOrValidation()` asserted the shipped
	 * catalogue against the stub — so the test agreed with the catalogue,
	 * agreed with nothing real, and passed on every run while all seven rows
	 * were refused on every fresh install with `Invalid legalBasis
	 * "publieke_taak"; expected ... public_task, legal_obligation ...`.
	 *
	 * Source of truth: openregister `lib/Db/Verwerkingsactiviteit.php`,
	 * `LEGAL_BASIS_VOCABULARY`. Keep the name identical to OR's so a drift is
	 * a rename here rather than a silent divergence.
	 *
	 * @var array<int, string>
	 */
	public const LEGAL_BASIS_VOCABULARY = [
		'consent',
		'contract',
		'legal_obligation',
		'vital_interests',
		'public_task',
		'legitimate_interest',
	];

	/**
	 * Lifecycle status vocabulary, copied from OpenRegister.
	 *
	 * `concept`, not `draft`: the real entity defaults `status` to 'concept'
	 * and `validate()` refuses 'draft' outright, so the stub's old value named
	 * a state no row can hold.
	 *
	 * @var array<int, string>
	 */
	public const STATUS_VOCABULARY = ['concept', 'published', 'archived'];

	/**
	 * Declared properties, mirroring the real OR entity's English columns.
	 */
	protected ?string $uuid = null;

	protected ?string $code = null;

	protected ?string $name = null;

	protected ?string $description = null;

	protected ?string $purpose = null;

	protected ?string $legalBasis = null;

	protected ?array $dataSubjectCategories = null;

	protected ?array $personalDataCategories = null;

	protected ?string $retentionPeriod = null;

	protected ?array $recipients = null;

	protected ?string $status = null;

	/**
	 * Magic setter/getter over DECLARED properties only, like QBMapper's.
	 *
	 * An undeclared attribute throws the same way the real entity does, so a
	 * seeder calling a renamed setter fails here instead of only in
	 * production.
	 *
	 * @param string $name Method name.
	 * @param array<int, mixed> $args Arguments.
	 *
	 * @return mixed
	 */
	public function __call(string $name, array $args) {
		$property = lcfirst(substr($name, 3));

		if (str_starts_with($name, 'set') === true || str_starts_with($name, 'get') === true) {
			if (property_exists($this, $property) === false) {
				throw new \BadFunctionCallException($property . ' is not a valid attribute');
			}

			if (str_starts_with($name, 'set') === true) {
				$this->{$property} = ($args[0] ?? null);
				return null;
			}

			return $this->{$property};
		}

		throw new \BadMethodCallException($name);
	}//end __call()

	/**
	 * All declared fields (test accessor).
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return get_object_vars($this);
	}//end toArray()
}//end class
