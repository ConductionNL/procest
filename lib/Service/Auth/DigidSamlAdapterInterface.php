<?php

/**
 * Dossiq DigiD SAML Adapter Interface.
 *
 * Dormant external API adapter contract for the DigiD broker. The
 * zaakportaal session flow obtains a `BrokerAssertionResult` carrying
 * the citizen's `bsn` from an upstream SAML response.
 *
 * Two concrete implementations:
 *
 *   - {@see LogDigidSamlAdapter} — default, ships dormant. Logs the call
 *     and throws a `RuntimeException` so the caller surfaces
 *     "broker not configured" to the operator instead of silently
 *     fall-through-authenticating.
 *   - The active implementation (delivered in a follow-up change, paired
 *     with the openconnector DigiD broker config + private key + cert)
 *     verifies the SAML response signature, extracts the BSN identifier
 *     (`urn:nl-eid-gdi:1.0:LegalSubjectID:BSN`), and returns a populated
 *     `BrokerAssertionResult::forDigid(...)`.
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
 * @spec openspec/specs/zaakportaal-mijngemeente/spec.md#requirement-digid-and-eherkenning-authentication-with-wdo-mandated-trust-levels
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Auth;

use RuntimeException;

/**
 * Contract for the DigiD broker SAML adapter.
 *
 * Activation requirements (documented for the operator):
 *  1. openconnector DigiD broker entry configured (entryPoint URL +
 *     broker EntityID + IdP metadata XML).
 *  2. Dossiq signing private key + X.509 certificate (PEM) loaded into
 *     app-config under `digid.sp.private_key` and `digid.sp.certificate`.
 *  3. `digid.feature_flag` app-config key flipped from `0` to `1`.
 *  4. DI binding for `DigidSamlAdapterInterface` swapped from
 *     {@see LogDigidSamlAdapter} to the active implementation.
 */
interface DigidSamlAdapterInterface {
	/**
	 * Decode a SAML response from the DigiD broker.
	 *
	 * @param string $samlResponse Base64-encoded SAML XML response received from the broker callback.
	 * @param string $relayState Original RelayState string (CSRF / cross-window correlation).
	 *
	 * @return BrokerAssertionResult Decoded assertion containing the citizen BSN.
	 *
	 * @throws RuntimeException When the broker is not configured, the signature is invalid, or no BSN claim is present.
	 *
	 * @spec openspec/specs/zaakportaal-mijngemeente/spec.md#requirement-digid-and-eherkenning-authentication-with-wdo-mandated-trust-levels
	 */
	public function decodeAssertion(string $samlResponse, string $relayState): BrokerAssertionResult;

	/**
	 * Whether the live DigiD broker is enabled by the operator.
	 *
	 * @return bool True when `digid.feature_flag` is `1`.
	 *
	 * @spec openspec/specs/zaakportaal-mijngemeente/spec.md#requirement-digid-and-eherkenning-authentication-with-wdo-mandated-trust-levels
	 */
	public function isActive(): bool;
}//end interface
