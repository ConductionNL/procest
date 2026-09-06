<?php

/**
 * Dossiq StufVaultService.
 *
 * Resolves `vault://...` references to their plaintext secret. The current
 * implementation looks up the secret from IAppConfig under the
 * `stuf.vault.<sha256(reference)>` key (app=dossiq) — this keeps the actual
 * passwords/cert blobs out of git, while the JSON schemas only carry the
 * reference URL. A production install would plug a real vault driver via
 * the same interface (KMS, HashiCorp, NC encrypted credentials, etc.).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Stuf
 *
 * @author    Conduction <info@conduction.nl>
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
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-secure-credential-handling
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Stuf;

use OCA\Dossiq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Resolves vault references to plaintext secrets at send time.
 *
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-secure-credential-handling
 */
class StufVaultService {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config used as backing store.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve a vault reference to its plaintext secret.
	 *
	 * Empty or missing references resolve to an empty string and emit an ERROR
	 * log line. The empty case lets the envelope builder still produce a
	 * well-formed XML document while the HTTP client refuses to send.
	 *
	 * @param string $reference The vault reference (vault://...).
	 *
	 * @return string The secret value (empty if unresolved).
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-secure-credential-handling
	 */
	public function resolveSecret(string $reference): string {
		if ($reference === '') {
			$this->logger->error(message: 'StUF vault: empty reference, no credential available');
			return '';
		}

		$key = $this->vaultKey(reference: $reference);
		$value = $this->appConfig->getValueString(app: Application::APP_ID, key: $key, default: '');

		if ($value === '') {
			$this->logger->error(
				message: 'StUF vault: reference {ref} not present in app config',
				context: ['ref' => $this->maskReference(reference: $reference)]
			);
		}

		return $value;
	}//end resolveSecret()

	/*
	 * NO storeSecret() HERE, AND THAT IS THE POINT.
	 *
	 * It wrote a plaintext secret into app config behind a `vault://`
	 * reference, described as "admin tooling / install seed". It had no
	 * caller: all three consumers of this vault (`StufEnvelopeInspector`,
	 * `StufHttpClient`, `StufMessageBuilder`) only ever call
	 * `resolveSecret()`. This app therefore READS StUF credentials and never
	 * writes them — they are placed by an administrator through Nextcloud's
	 * own config surface. Wiring a writer would have added the first
	 * credential-writing path in the app, which is the widest kind of
	 * surface this gate exists to stop being opened by accident.
	 */

	/**
	 * Build the IAppConfig key for a vault reference.
	 *
	 * Nextcloud's appconfig enforces a 64-character key limit, so the full
	 * sha256 is truncated to 40 hex chars — `stuf.v.<40hex>` = 47 chars,
	 * comfortably under the limit while keeping a collision-safe digest.
	 *
	 * @param string $reference The vault reference (vault://...).
	 *
	 * @return string The bounded app-config key.
	 */
	private function vaultKey(string $reference): string {
		return 'stuf.v.' . substr(string: hash(algo: 'sha256', data: $reference), offset: 0, length: 40);
	}//end vaultKey()

	/**
	 * Mask a vault reference for safe logging (keep scheme + first 16 chars).
	 *
	 * @param string $reference The reference to mask.
	 *
	 * @return string The masked reference.
	 */
	private function maskReference(string $reference): string {
		if (strlen(string: $reference) <= 16) {
			return $reference;
		}

		return substr(string: $reference, offset: 0, length: 16) . '…';
	}//end maskReference()
}//end class
