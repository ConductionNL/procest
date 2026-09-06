<?php

/**
 * Dossiq eHerkenning SAML Adapter Interface.
 *
 * Dormant external API adapter contract for the eHerkenning broker. The
 * leverancier-portaal session flow obtains a `BrokerAssertionResult`
 * carrying the supplier's `kvkNummer` from an upstream SAML response.
 *
 * Two concrete implementations:
 *
 *   - {@see LogEHerkenningSamlAdapter} — default, ships dormant. Logs the
 *     call and throws a `RuntimeException` so the caller can surface
 *     "broker not configured" to the operator instead of silently
 *     fall-through-authenticating.
 *   - The active implementation (delivered in a follow-up change, paired
 *     with the openconnector eHerkenning broker config + private key + cert)
 *     verifies the SAML response signature, extracts the EH KvK
 *     identifier (`urn:etoegang:1.9:EntityConcernedID:KvKnr`), and returns
 *     a populated `BrokerAssertionResult::forEHerkenning(...)`.
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
 *
 * @spec openspec/changes/leverancier-zaakportaal-02-eherkenning-auth/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Auth;

use RuntimeException;

/**
 * Contract for the eHerkenning broker SAML adapter.
 *
 * Activation requirements (documented for the operator):
 *  1. openconnector eHerkenning broker entry configured (entryPoint URL +
 *     broker EntityID + IdP metadata XML).
 *  2. Dossiq signing private key + X.509 certificate (PEM) loaded into
 *     app-config under `eherkenning.sp.private_key` and
 *     `eherkenning.sp.certificate`.
 *  3. `eherkenning.feature_flag` app-config key flipped from `0` to `1`.
 *  4. DI binding for `EHerkenningSamlAdapterInterface` swapped from
 *     {@see LogEHerkenningSamlAdapter} to the active implementation.
 */
interface EHerkenningSamlAdapterInterface {
	/**
	 * Decode a SAML response from the eHerkenning broker.
	 *
	 * @param string $samlResponse Base64-encoded SAML XML response received from the broker callback.
	 * @param string $relayState Original RelayState string (CSRF / cross-window correlation).
	 *
	 * @return BrokerAssertionResult Decoded assertion containing the supplier KvK number.
	 *
	 * @throws RuntimeException When the broker is not configured, the signature is invalid, or no KvK claim is present.
	 *
	 * @spec openspec/changes/leverancier-zaakportaal-02-eherkenning-auth/tasks.md
	 */
	public function decodeAssertion(string $samlResponse, string $relayState): BrokerAssertionResult;

	/**
	 * Whether the live eHerkenning broker is enabled by the operator.
	 *
	 * @return bool True when `eherkenning.feature_flag` is `1`.
	 *
	 * @spec openspec/changes/leverancier-zaakportaal-02-eherkenning-auth/tasks.md
	 */
	public function isActive(): bool;
}//end interface
