<?php

/**
 * EHerkenning SAML SIMULATOR adapter (external-integrations-test-environments).
 *
 * The eHerkenning counterpart of {@see SimulatorDigidSamlAdapter}: models
 * the maykinmedia mock-login pattern with NO real SAML. Accepts a
 * locally-entered KvK number (carried in the `samlResponse` slot as a
 * JSON `{ "kvkNumber": "..." }` blob from the dossiq simulator form) and
 * returns an eHerkenning `BrokerAssertionResult` explicitly marked
 * `simulator: true`. Selected by `integration.digid.mode=simulator`
 * (the DigiD/eHerkenning pair share the tier key).
 *
 * Capped at `beta` for the same honesty reason as the DigiD simulator: it
 * proves the journey, not the SAML koppelvlak.
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
 * Local eHerkenning login simulator — no real SAML (capped at beta).
 *
 * @spec openspec/specs/external-integration-test-wiring/spec.md
 */
final class SimulatorEHerkenningSamlAdapter implements EHerkenningSamlAdapterInterface {
	/**
	 * Decode the simulator "assertion" (a local KvK entry, not SAML).
	 *
	 * @param string $samlResponse JSON `{ "kvkNumber": "..." }` from the simulator form.
	 * @param string $relayState Original RelayState (correlation only).
	 *
	 * @return BrokerAssertionResult An eHerkenning result flagged simulator:true.
	 *
	 * @throws RuntimeException When no usable KvK number is present.
	 *
	 * @spec openspec/specs/external-integration-test-wiring/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) BrokerAssertionResult is intentionally built via its named constructor.
	 */
	public function decodeAssertion(string $samlResponse, string $relayState): BrokerAssertionResult {
		$decoded = json_decode($samlResponse, true);
		$kvkNumber = '';
		if (is_array($decoded) === true) {
			$kvkNumber = (string)($decoded['kvkNumber'] ?? '');
		}

		if (preg_match('/^[0-9]{8}$/', $kvkNumber) !== 1) {
			throw new RuntimeException('eHerkenning simulator requires an 8-digit KvK number from the simulator login form.');
		}

		return BrokerAssertionResult::forEHerkenning(
			kvkNumber: $kvkNumber,
			assertionId: 'simulator-' . $relayState,
			level: 3,
			issuer: 'dossiq-eherkenning-simulator',
			attributes: [
				'simulator' => true,
				'authenticatedBy' => 'simulator',
				'warning' => 'SIMULATED eHerkenning login — not a real SAML assertion. Proves the journey only.',
			]
		);

	}//end decodeAssertion()

	/**
	 * The simulator is an active (non-dormant) tier, but not a live broker.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/external-integration-test-wiring/spec.md
	 */
	public function isActive(): bool {
		return true;
	}//end isActive()
}//end class
