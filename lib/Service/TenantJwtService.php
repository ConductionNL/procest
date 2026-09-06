<?php

/**
 * Dossiq Tenant JWT Service
 *
 * Self-contained HMAC JWT encode/decode with tenant claim support. Used by
 * the SaaS authentication path to mint and validate tokens carrying
 * `tenant_id` + `tenant_slug` + `roles` claims.
 *
 * HMAC (HS256) is deliberate — it lines up with the existing
 * `ZgwJwtValidator` shape and the OpenRegister Consumer secret model.
 * The signing secret comes from dossiq configuration (never from the
 * request) so a forged signature cannot pass verification.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-05-auth-jwt-tenant-claim/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use InvalidArgumentException;
use RuntimeException;

/**
 * HMAC-based JWT validation with first-class tenant claim support. Minting
 * lives with the external broker that issues the tokens — see the note below.
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-05-auth-jwt-tenant-claim/tasks.md
 */
class TenantJwtService {
	/**
	 * Hash function name passed to hash_hmac.
	 */
	private const HASH_FN = 'sha256';

	/**
	 * Constructor.
	 *
	 * @param string $signingSecret Server-side HMAC signing secret (>= 32 chars).
	 */
	public function __construct(
		private readonly string $signingSecret,
	) {
		if (strlen($this->signingSecret) < 16) {
			throw new InvalidArgumentException('JWT signing secret too short (<16 chars)');
		}
	}//end __construct()

	/**
	 * Encode a tenant-aware JWT.
	 *
	 * @param string $subject Subject (NC user ID).
	 * @param string $tenantId Tenant UUID.
	 * @param string $tenantSlug Tenant slug.
	 * @param array<int, string> $roles Roles inside the tenant.
	 * @param int|null $ttl Override default TTL (seconds).
	 *
	 * @return string Compact JWT string.
	 */
	/*
	 * THIS SERVICE VALIDATES TENANT JWTs. IT DOES NOT MINT THEM.
	 *
	 * `createTokenFromSaml()` built a tenant-scoped JWT out of an eHerkenning
	 * assertion — taking `roles` straight from the assertion and appending an
	 * `eh:level:*` role — and `createToken()` signed it. Neither had a caller:
	 * `createToken()`'s only call site was `createTokenFromSaml()`, and
	 * `createTokenFromSaml()` had none at all. Nothing in `appinfo/routes.php`
	 * issues a token, and the eHerkenning/DigiD SAML adapters in
	 * `lib/Service/Auth/` are log-and-simulator implementations that produce a
	 * `BrokerAssertionResult` nobody consumes.
	 *
	 * Both are removed together, deliberately: deleting only the SAML wrapper
	 * would have left `createToken()` orphaned and reported by this same gate
	 * on the next run. A token minter is the widest surface in this file —
	 * anything that can call it can assert any tenant and any role — so it is
	 * removed rather than wired. The live path is unchanged:
	 * `TenantClaimValidationMiddleware` calls `validate()`, and the tokens it
	 * validates are minted by the external broker.
	 */

	/**
	 * Validate a JWT and return its claims.
	 *
	 * @param string $token Compact JWT.
	 *
	 * @return array<string,mixed> Claim set.
	 *
	 * @throws RuntimeException When the token is malformed, the signature
	 *                          does not match, or the token is expired.
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-05-auth-jwt-tenant-claim/tasks.md
	 */
	public function validate(string $token): array {
		$parts = explode('.', $token);
		if (count($parts) !== 3) {
			throw new RuntimeException('Malformed JWT');
		}

		[$hPart, $cPart, $sPart] = $parts;

		$expected = $this->b64UrlEncode(bytes: $this->signRaw(input: $hPart . '.' . $cPart));
		if (hash_equals($expected, $sPart) === false) {
			throw new RuntimeException('Invalid JWT signature');
		}

		$claims = json_decode($this->b64UrlDecode(encoded: $cPart), true);
		if (is_array($claims) === false) {
			throw new RuntimeException('Malformed JWT claims');
		}

		if (isset($claims['exp']) === true && (int)$claims['exp'] < time()) {
			throw new RuntimeException('Expired JWT');
		}

		return $claims;
	}//end validate()

	/**
	 * Extract the `tenant_id` claim from a (validated) claim set.
	 *
	 * @param array<string,mixed> $claims Validated claim set.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When the claim is missing.
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-05-auth-jwt-tenant-claim/tasks.md
	 */
	public function extractTenantId(array $claims): string {
		$tid = (string)($claims['tenant_id'] ?? '');
		if ($tid === '') {
			throw new RuntimeException('JWT missing tenant_id claim');
		}

		return $tid;
	}//end extractTenantId()

	/**
	 * Raw HMAC of the signing input.
	 *
	 * @param string $input Signing input (header.payload).
	 *
	 * @return string Raw HMAC.
	 */
	private function signRaw(string $input): string {
		return hash_hmac(self::HASH_FN, $input, $this->signingSecret, true);
	}//end signRaw()

	/**
	 * Base64-url encode (no padding).
	 *
	 * @param string $bytes Raw bytes.
	 *
	 * @return string
	 */
	private function b64UrlEncode(string $bytes): string {
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}//end b64UrlEncode()

	/**
	 * Base64-url decode.
	 *
	 * @param string $encoded Encoded string.
	 *
	 * @return string
	 */
	private function b64UrlDecode(string $encoded): string {
		$pad = 4 - (strlen($encoded) % 4);
		if ($pad < 4) {
			$encoded .= str_repeat('=', $pad);
		}

		return (string)base64_decode(strtr($encoded, '-_', '+/'));
	}//end b64UrlDecode()
}//end class
