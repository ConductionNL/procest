<?php

/**
 * DigiD SAML SIMULATOR adapter (external-integrations-test-environments).
 *
 * Models the maykinmedia `django-digid-eherkenning` mock-login pattern:
 * NO real SAML. Instead of decoding a broker assertion, the simulator
 * accepts a locally-entered BSN (carried in the `samlResponse` slot as a
 * JSON `{ "bsn": "..." }` blob produced by the dossiq simulator login
 * form) and returns a DigiD `BrokerAssertionResult` explicitly marked
 * `simulator: true` in its attributes. Selected by
 * `integration.digid.mode=simulator`.
 *
 * HONESTY RULE (the reason this is permanently capped at `beta`): a
 * simulator proves the login JOURNEY and the downstream session wiring,
 * NOT the SAML koppelvlak. Real signature/artifact validation is only
 * provable against Logius preproductie (a separate certificate-bound
 * adapter and lane).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Auth
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 * @link https://github.com/maykinmedia/django-digid-eherkenning
 *
 * @spec openspec/specs/external-integration-test-wiring/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Auth;

use RuntimeException;

/**
 * Local DigiD login simulator — no real SAML (capped at beta).
 *
 * @spec openspec/specs/external-integration-test-wiring/spec.md
 */
final class SimulatorDigidSamlAdapter implements DigidSamlAdapterInterface {
	/**
	 * Decode the simulator "assertion" (a local BSN entry, not SAML).
	 *
	 * @param string $samlResponse JSON `{ "bsn": "..." }` from the simulator form.
	 * @param string $relayState Original RelayState (correlation only).
	 *
	 * @return BrokerAssertionResult A DigiD result flagged simulator:true.
	 *
	 * @throws RuntimeException When no usable BSN is present in the simulator payload.
	 *
	 * @spec openspec/specs/external-integration-test-wiring/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) BrokerAssertionResult is intentionally built via its named constructor.
	 */
	public function decodeAssertion(string $samlResponse, string $relayState): BrokerAssertionResult {
		$decoded = json_decode($samlResponse, true);
		$bsn = '';
		if (is_array($decoded) === true) {
			$bsn = (string)($decoded['bsn'] ?? '');
		}

		if (preg_match('/^[0-9]{9}$/', $bsn) !== 1) {
			throw new RuntimeException('DigiD simulator requires a 9-digit BSN from the simulator login form.');
		}

		return BrokerAssertionResult::forDigid(
			bsn: $bsn,
			assertionId: 'simulator-' . $relayState,
			level: 2,
			issuer: 'dossiq-digid-simulator',
			attributes: [
				'simulator' => true,
				'authenticatedBy' => 'simulator',
				'warning' => 'SIMULATED DigiD login — not a real SAML assertion. Proves the journey only.',
			]
		);

	}//end decodeAssertion()

	/**
	 * The simulator is an active (non-dormant) tier, but it is NOT a live
	 * broker — callers surface the simulation label.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/external-integration-test-wiring/spec.md
	 */
	public function isActive(): bool {
		return true;
	}//end isActive()
}//end class
