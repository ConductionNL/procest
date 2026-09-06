<?php

/**
 * Dossiq DigiD SAML Adapter — dormant logging implementation.
 *
 * Ships as the default DI binding for {@see DigidSamlAdapterInterface}.
 * Logs every call at warning level and throws a `RuntimeException` so the
 * caller (zaakportaal AuthController) surfaces "broker not configured" to
 * the operator instead of silently authenticating with stub data.
 *
 * Activation: see {@see DigidSamlAdapterInterface} class docblock —
 * requires openconnector DigiD broker config + private key + certificate,
 * plus flipping the `digid.feature_flag` app-config key and swapping the
 * DI binding to the active implementation.
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

use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Default DigiD adapter — logs + refuses.
 *
 * @spec openspec/specs/zaakportaal-mijngemeente/spec.md#requirement-digid-and-eherkenning-authentication-with-wdo-mandated-trust-levels
 */
final class LogDigidSamlAdapter implements DigidSamlAdapterInterface {
	/**
	 * App id for IAppConfig look-ups.
	 */
	public const APP_ID = 'dossiq';

	/**
	 * Feature-flag key.
	 */
	public const FLAG_KEY = 'digid.feature_flag';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config App-config service (feature-flag check).
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Always throws — the dormant adapter refuses to fabricate an assertion.
	 *
	 * @param string $samlResponse Base64-encoded SAML response.
	 * @param string $relayState Original RelayState.
	 *
	 * @return BrokerAssertionResult
	 *
	 * @throws RuntimeException Always.
	 *
	 * @spec openspec/specs/zaakportaal-mijngemeente/spec.md#requirement-digid-and-eherkenning-authentication-with-wdo-mandated-trust-levels
	 */
	public function decodeAssertion(string $samlResponse, string $relayState): BrokerAssertionResult {
		$this->logger->warning(
			'digid.broker.dormant',
			[
				'adapter' => self::class,
				'flag_key' => self::FLAG_KEY,
				'active' => $this->isActive(),
				'response_len' => strlen($samlResponse),
				'relay_state' => $relayState,
				'activation' => 'configure openconnector DigiD broker + private key + cert; '
					. 'occ config:app:set dossiq digid.feature_flag --value 1; '
					. 'swap DI binding to the active SamlAdapter implementation.',
			]
		);

		throw new RuntimeException(
			'DigiD broker not configured — wire openconnector + flip digid.feature_flag.'
		);
	}//end decodeAssertion()

	/**
	 * Whether the live broker is enabled.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/zaakportaal-mijngemeente/spec.md#requirement-digid-and-eherkenning-authentication-with-wdo-mandated-trust-levels
	 */
	public function isActive(): bool {
		$raw = $this->config->getValueString(self::APP_ID, self::FLAG_KEY, '0');
		return ($raw === '1' || strtolower($raw) === 'true');
	}//end isActive()
}//end class
