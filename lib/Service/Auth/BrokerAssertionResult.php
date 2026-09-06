<?php

/**
 * Dossiq Broker Assertion Result.
 *
 * Value object returned by both `EHerkenningSamlAdapterInterface` and
 * `DigidSamlAdapterInterface` after a SAML response from the OpenConnector
 * broker has been decoded. Carries either a `kvkNummer` (eHerkenning,
 * business identifier) or a `bsn` (DigiD, citizen identifier), the
 * authentication assurance level, the issuer EntityID, and the raw
 * underlying assertion id + decoded attribute map for audit.
 *
 * Immutable. Constructed only via the static `forEHerkenning()` /
 * `forDigid()` named constructors so callers can't accidentally cross-wire
 * a citizen identifier into a business-portal session.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Auth
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

namespace OCA\Dossiq\Service\Auth;

use InvalidArgumentException;

/**
 * Decoded SAML-broker assertion result for dossiq auth flows.
 *
 * @spec openspec/specs/zaakportaal-mijngemeente/spec.md
 */
final class BrokerAssertionResult {
	/**
	 * Broker dialect: 'eherkenning' or 'digid'.
	 */
	public const DIALECT_EHERKENNING = 'eherkenning';

	/**
	 * DigiD broker dialect.
	 */
	public const DIALECT_DIGID = 'digid';

	/**
	 * Constructor — use the named constructors instead of `new` directly.
	 *
	 * @param string $dialect Broker dialect (`eherkenning`/`digid`).
	 * @param string|null $kvkNumber KvK identifier for eHerkenning, null for DigiD.
	 * @param string|null $bsn BSN identifier for DigiD, null for eHerkenning.
	 * @param string $assertionId Underlying SAML assertion id (for audit + replay-guard).
	 * @param string|null $issuer EntityID of the issuing broker.
	 * @param int $level Assurance level: eHerkenning EH1..EH4 maps to 1..4; DigiD basis=1, midden=2, substantieel=3, hoog=4.
	 * @param array<string,mixed> $attributes Raw decoded attribute map (audit only).
	 */
	private function __construct(
		public readonly string $dialect,
		public readonly ?string $kvkNumber,
		public readonly ?string $bsn,
		public readonly string $assertionId,
		public readonly ?string $issuer,
		public readonly int $level,
		public readonly array $attributes,
	) {
	}//end __construct()

	/**
	 * Build an eHerkenning result. Requires a non-empty KvK number.
	 *
	 * @param string $kvkNumber KvK identifier (digits only — caller validates format).
	 * @param string $assertionId Assertion id for audit.
	 * @param int $level Assurance level 1..4.
	 * @param string|null $issuer EntityID of the broker.
	 * @param array<string,mixed> $attributes Raw attributes.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/zaakportaal-mijngemeente/spec.md
	 */
	public static function forEHerkenning(
		string $kvkNumber,
		string $assertionId,
		int $level = 3,
		?string $issuer = null,
		array $attributes = [],
	): self {
		if ($kvkNumber === '') {
			throw new InvalidArgumentException('forEHerkenning requires a non-empty kvkNummer');
		}

		return new self(
			dialect: self::DIALECT_EHERKENNING,
			kvkNumber: $kvkNumber,
			bsn: null,
			assertionId: $assertionId,
			issuer: $issuer,
			level: $level,
			attributes: $attributes
		);
	}//end forEHerkenning()

	/**
	 * Build a DigiD result. Requires a non-empty BSN.
	 *
	 * @param string $bsn BSN identifier (digits only — caller validates format).
	 * @param string $assertionId Assertion id for audit.
	 * @param int $level Assurance level 1..4.
	 * @param string|null $issuer EntityID of the broker.
	 * @param array<string,mixed> $attributes Raw attributes.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/zaakportaal-mijngemeente/spec.md
	 */
	public static function forDigid(
		string $bsn,
		string $assertionId,
		int $level = 2,
		?string $issuer = null,
		array $attributes = [],
	): self {
		if ($bsn === '') {
			throw new InvalidArgumentException('forDigid requires a non-empty BSN');
		}

		return new self(
			dialect: self::DIALECT_DIGID,
			kvkNumber: null,
			bsn: $bsn,
			assertionId: $assertionId,
			issuer: $issuer,
			level: $level,
			attributes: $attributes
		);
	}//end forDigid()

	/**
	 * Serialise to a JSON-safe array (audit logs, session bootstrap).
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/zaakportaal-mijngemeente/spec.md
	 */
	public function toArray(): array {
		return [
			'dialect' => $this->dialect,
			'kvkNumber' => $this->kvkNumber,
			'bsn' => $this->bsn,
			'assertionId' => $this->assertionId,
			'issuer' => $this->issuer,
			'level' => $this->level,
			'attributes' => $this->attributes,
		];
	}//end toArray()
}//end class
