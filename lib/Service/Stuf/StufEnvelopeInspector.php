<?php

/**
 * Dossiq StUF envelope inspector.
 *
 * The best-effort reads the async-confirmation webhook performs on a raw
 * inbound envelope before anything is persisted: which endpoint sent it, whether
 * its WSSE UsernameToken matches that endpoint's stored credentials, and the
 * bericht-soort / crossRefnummer / functie needed to match it back to an
 * outbound StufMessage row.
 *
 * Regex rather than DOM on purpose: the webhook accepts envelopes from third-party
 * zaaksystemen whose namespace prefixes vary, and a parse failure must not stop
 * the caller from being identified and rejected.
 *
 * Split out of {@see \OCA\Dossiq\Controller\StufController}.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Stuf
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-async-confirmation
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Stuf;

/**
 * Reads endpoint identity, WSSE credentials and routing hints off a raw envelope.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-async-confirmation
 */
class StufEnvelopeInspector {
	/**
	 * Constructor.
	 *
	 * @param StufRegisterAccess $register The register access helper.
	 * @param StufVaultService $vault The vault adapter.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly StufRegisterAccess $register,
		private readonly StufVaultService $vault,
	) {
	}//end __construct()

	/**
	 * Resolve the StufEndpoint from the envelope's zender (best-effort).
	 *
	 * @param string $envelopeXml The inbound envelope.
	 * @param string $headerEndpointId Fallback endpoint id from the
	 *                                 X-Dossiq-Endpoint-Id header (used by
	 *                                 callers we control); empty when absent.
	 *
	 * @return array|null The endpoint or null.
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-async-confirmation
	 */
	public function resolveEndpoint(string $envelopeXml, string $headerEndpointId = ''): ?array {
		$zenderPattern = '#<stuf:zender>.*?<stuf:applicatie>([^<]+)</stuf:applicatie>.*?</stuf:zender>#s';
		$applicatie = $this->firstMatch(pattern: $zenderPattern, subject: $envelopeXml);
		if ($applicatie !== '') {
			$endpoint = $this->register->findOne(
				schema: StufRegisterAccess::SCHEMA_ENDPOINT,
				filters: ['recipientApplication' => $applicatie]
			);
			if ($endpoint !== null) {
				return $endpoint;
			}
		}

		if ($headerEndpointId !== '') {
			return $this->register->findById(
				schema: StufRegisterAccess::SCHEMA_ENDPOINT,
				id: $headerEndpointId
			);
		}

		return null;
	}//end resolveEndpoint()

	/**
	 * Verify the inbound WSSE UsernameToken matches the endpoint's stored credentials.
	 *
	 * @param string $envelopeXml The envelope XML.
	 * @param array $endpoint The endpoint.
	 *
	 * @return bool True when both the username and the password match.
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-async-confirmation
	 */
	public function verifyWsse(string $envelopeXml, array $endpoint): bool {
		$auth = ($endpoint['authentication'] ?? []);
		$expectedUser = (string)($auth['username'] ?? '');
		$expectedPasswordRef = (string)($auth['passwordVaultRef'] ?? '');
		$expectedPassword = $this->vault->resolveSecret(reference: $expectedPasswordRef);

		if ($expectedUser === '' || $expectedPassword === '') {
			return false;
		}

		$username = $this->firstMatch(pattern: '#<wsse:Username>([^<]+)</wsse:Username>#', subject: $envelopeXml);
		$password = $this->firstMatch(pattern: '#<wsse:Password[^>]*>([^<]+)</wsse:Password>#', subject: $envelopeXml);

		return hash_equals(known_string: $expectedUser, user_string: $username)
			&& hash_equals(known_string: $expectedPassword, user_string: $password);
	}//end verifyWsse()

	/**
	 * Detect the bericht-soort (Bv01, Lk02, ...) from the envelope.
	 *
	 * @param string $envelopeXml The envelope.
	 *
	 * @return string The bericht-soort; falls back to Lk02.
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-async-confirmation
	 */
	public function detectBerichtSoort(string $envelopeXml): string {
		$declared = $this->firstMatch(
			pattern: '#<stuf:berichtcode>([A-Za-z0-9]+)</stuf:berichtcode>#',
			subject: $envelopeXml
		);
		if ($declared !== '') {
			return $declared;
		}

		$needles = [
			'zakLk02' => 'Lk02',
			'zakLk01' => 'Lk01',
			'Bv01' => 'Bv01',
			'Fo02' => 'Fo02',
		];
		foreach ($needles as $needle => $kind) {
			if (str_contains(haystack: $envelopeXml, needle: $needle) === true) {
				return $kind;
			}
		}

		return 'Lk02';
	}//end detectBerichtSoort()

	/**
	 * Extract the crossRefnummer from an inbound envelope (best-effort).
	 *
	 * Falls back to the envelope's own referentienummer, which is what a peer
	 * that omits the cross-reference uses to identify the message.
	 *
	 * @param string $envelopeXml The envelope.
	 *
	 * @return string The cross-reference, or the empty string.
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-async-confirmation
	 */
	public function extractCrossRefnummer(string $envelopeXml): string {
		$crossRef = $this->firstMatch(
			pattern: '#<stuf:crossRefnummer>([^<]+)</stuf:crossRefnummer>#',
			subject: $envelopeXml
		);
		if ($crossRef !== '') {
			return $crossRef;
		}

		return $this->firstMatch(
			pattern: '#<stuf:referentienummer>([^<]+)</stuf:referentienummer>#',
			subject: $envelopeXml
		);
	}//end extractCrossRefnummer()

	/**
	 * Extract the functie from an inbound envelope (best-effort).
	 *
	 * @param string $envelopeXml The envelope.
	 *
	 * @return string The functie, or the empty string.
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-async-confirmation
	 */
	public function extractFunctie(string $envelopeXml): string {
		return $this->firstMatch(
			pattern: '#<stuf:functie>([^<]+)</stuf:functie>#',
			subject: $envelopeXml
		);
	}//end extractFunctie()

	/**
	 * Return the first capture group of a pattern, trimmed, or the empty string.
	 *
	 * @param string $pattern The regex with exactly one capture group.
	 * @param string $subject The envelope XML.
	 *
	 * @return string The trimmed capture, or the empty string when no match.
	 *
	 * @SuppressWarnings(PHPMD.UndefinedVariable) $matches is a preg_match() by-reference
	 * out-parameter, which PHPMD does not model.
	 */
	private function firstMatch(string $pattern, string $subject): string {
		if (preg_match(pattern: $pattern, subject: $subject, matches: $matches) !== 1) {
			return '';
		}

		return trim(string: $matches[1]);
	}//end firstMatch()
}//end class
