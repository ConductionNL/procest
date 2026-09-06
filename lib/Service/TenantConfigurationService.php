<?php

/**
 * Dossiq Tenant Configuration Service
 *
 * Per-tenant configuration: branding, locale, feature flags, custom CSS.
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
 * @spec openspec/changes/tenant-zaaksysteem-saas-08-configuration-branding/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use InvalidArgumentException;
use OCA\Dossiq\Service\Tenant\TenantBrandingSanitiser;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Per-tenant configuration with sanitised branding inputs.
 *
 * Branding validation is owned by {@see TenantBrandingSanitiser}; this service
 * owns configuration storage — read, merge, persist — plus locale and feature
 * flags.
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-08-configuration-branding/tasks.md
 */
class TenantConfigurationService {
	/**
	 * Allowed locale identifiers (ISO + Dutch defaults).
	 *
	 * @var array<int, string>
	 */
	public const ALLOWED_LOCALES = ['nl_NL', 'nl_BE', 'en_GB', 'en_US', 'fr_FR', 'de_DE'];

	/**
	 * Allowed timezone identifiers (subset of IANA — extend as needed).
	 *
	 * @var array<int, string>
	 */
	public const ALLOWED_TIMEZONES = ['Europe/Amsterdam', 'Europe/Brussels', 'Europe/Berlin', 'Europe/Paris', 'UTC'];

	/**
	 * Maximum logo size in bytes (5MB).
	 *
	 * Canonically owned by {@see TenantBrandingSanitiser}; aliased here so
	 * existing callers keep working.
	 *
	 * @var int
	 */
	public const LOGO_MAX_BYTES = TenantBrandingSanitiser::LOGO_MAX_BYTES;

	/**
	 * Allowed logo MIME types.
	 *
	 * Canonically owned by {@see TenantBrandingSanitiser}.
	 *
	 * @var array<int, string>
	 */
	public const LOGO_ALLOWED_MIME = TenantBrandingSanitiser::LOGO_ALLOWED_MIME;

	/**
	 * Custom-CSS property whitelist (sanitiser).
	 *
	 * Canonically owned by {@see TenantBrandingSanitiser}.
	 *
	 * @var array<int, string>
	 */
	public const CSS_PROPERTY_WHITELIST = TenantBrandingSanitiser::CSS_PROPERTY_WHITELIST;

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager App manager.
	 * @param ContainerInterface $container Service container.
	 * @param TenantBrandingSanitiser $sanitiser Fail-closed branding input validation.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly TenantBrandingSanitiser $sanitiser,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the full configuration row for a tenant.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-08-configuration-branding/tasks.md
	 */
	public function getConfig(string $tenantId): ?array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			// ObjectService::findAll() takes a single $config array — the previous
			// named-argument form threw "Unknown named parameter $register" and
			// was swallowed by the catch below. Register/schema live inside
			// `filters`; limit/offset are top-level config keys.
			$rows = $objectService->findAll(
				[
					'filters' => [
						'register' => TenantSaasService::REGISTER,
						'schema' => 'tenantConfiguration',
						'tenantRef' => $tenantId,
					],
					'limit' => 1,
					'offset' => 0,
				]
			);
			if (is_array($rows) === true && count($rows) > 0) {
				return $rows[0];
			}

			return null;
		} catch (Throwable $e) {
			return null;
		}//end try
	}//end getConfig()

	/*
	 * NO updateBranding() / updateLocale() HERE.
	 *
	 * Both merged a delta into a tenant's stored configuration, and neither
	 * had a caller — nothing in `lib/` constructs
	 * `TenantConfigurationService` at all, and `appinfo/routes.php` has no
	 * tenant-configuration route. They were per-tenant configuration writers
	 * with no authenticated surface in front of them and no per-tenant guard
	 * of their own; wiring either one meant designing that surface first.
	 *
	 * The read and validation halves stay: `getConfig()`, `getThemingTokens()`,
	 * `sanitiseBranding()`, `sanitiseCustomCss()`, `validateLogoUpload()` and
	 * `isHexColor()` are the pieces `Tenant\TenantBrandingSanitiser` was split
	 * out around, and the locale/timezone/currency allow-lists remain
	 * available to whatever writer eventually arrives.
	 */

	/**
	 * Set or unset a feature flag.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $flag Flag name.
	 * @param bool $enabled True to add, false to remove.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-08-configuration-branding/tasks.md
	 */
	public function setFeatureFlag(string $tenantId, string $flag, bool $enabled): array {
		$current = $this->getConfig(tenantId: $tenantId) ?? ['tenantRef' => $tenantId, 'features' => []];
		$features = (array)($current['features'] ?? []);
		$features = array_values(array_unique(array_filter($features, fn ($f) => is_string($f) && $f !== '')));
		if ($enabled === true && in_array($flag, $features, true) === false) {
			$features[] = $flag;
		} elseif ($enabled === false) {
			$features = array_values(array_filter($features, fn ($f) => $f !== $flag));
		}

		return $this->mergeConfig(tenantId: $tenantId, delta: ['features' => $features]);
	}//end setFeatureFlag()

	/**
	 * Build the theming-tokens CSS-variable map from the tenant branding.
	 *
	 * @param array<string,mixed> $config Configuration row.
	 *
	 * @return array<string,string> CSS-variable map.
	 *
	 * @spec openspec/specs/tenant-configuration/spec.md#requirement-branding-configuration-and-theming-tokens-req-004-a
	 */
	public function getThemingTokens(array $config): array {
		$branding = (array)($config['branding'] ?? []);
		$tokens = [];
		if (isset($branding['primaryColor']) === true && $this->isHexColor(val: (string)$branding['primaryColor']) === true) {
			$tokens['--nc-color-primary'] = (string)$branding['primaryColor'];
			$tokens['--nc-color-primary-element'] = (string)$branding['primaryColor'];
		}

		if (isset($branding['secondaryColor']) === true && $this->isHexColor(val: (string)$branding['secondaryColor']) === true) {
			$tokens['--dossiq-color-secondary'] = (string)$branding['secondaryColor'];
		}

		if (isset($branding['fontFamily']) === true) {
			$fontFamily = (string)$branding['fontFamily'];
			// Drop quotes and dangerous chars.
			$fontFamily = preg_replace('/[^a-zA-Z0-9_\\- ,]/', '', $fontFamily) ?? '';
			$tokens['--dossiq-font-family'] = $fontFamily;
		}

		return $tokens;
	}//end getThemingTokens()

	/**
	 * Sanitise a branding payload — hex-color check, whitelist custom CSS.
	 *
	 * @param array<string,mixed> $branding Input.
	 *
	 * @return array<string,mixed> Sanitised.
	 *
	 * @throws InvalidArgumentException When a hex color is invalid.
	 *
	 * @spec openspec/specs/security-hardening/spec.md
	 */
	public function sanitiseBranding(array $branding): array {
		return $this->sanitiser->sanitiseBranding(branding: $branding);
	}//end sanitiseBranding()

	/**
	 * Whitelist-based CSS sanitiser. Strips any rule with a property not in
	 * the whitelist or a value containing `url(`, `@import`, `expression`.
	 *
	 * @param string $css Raw CSS.
	 *
	 * @return string Sanitised CSS.
	 *
	 * @spec openspec/specs/security-hardening/spec.md
	 */
	public function sanitiseCustomCss(string $css): string {
		return $this->sanitiser->sanitiseCustomCss(css: $css);
	}//end sanitiseCustomCss()

	/**
	 * Validate that an uploaded logo passes the MIME + size guard.
	 *
	 * @param string $mimeType Uploaded MIME.
	 * @param int $bytes Size in bytes.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException
	 *
	 * @spec openspec/specs/security-hardening/spec.md
	 */
	public function validateLogoUpload(string $mimeType, int $bytes): void {
		$this->sanitiser->validateLogoUpload(mimeType: $mimeType, bytes: $bytes);
	}//end validateLogoUpload()

	/**
	 * Check whether a string is a 6-digit hex color.
	 *
	 * @param string $val 6-digit hex (with leading #).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/security-hardening/spec.md
	 */
	public function isHexColor(string $val): bool {
		return $this->sanitiser->isHexColor(val: $val);
	}//end isHexColor()

	/**
	 * Merge a delta into the tenant configuration row and persist it.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param array<string,mixed> $delta Fields to merge.
	 *
	 * @return array<string,mixed>
	 */
	private function mergeConfig(string $tenantId, array $delta): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return ['tenantRef' => $tenantId] + $delta;
		}

		$current = ($this->getConfig(tenantId: $tenantId) ?? ['tenantRef' => $tenantId]);
		$next = array_merge($current, $delta);
		try {
			$uuidArg = null;
			$uuid = (string)($current['uuid'] ?? $current['id'] ?? '');
			if ($uuid !== '') {
				$uuidArg = $uuid;
			}

			return $objectService->saveObject(
				object: $next,
				register: TenantSaasService::REGISTER,
				schema: 'tenantConfiguration',
				uuid: $uuidArg
			);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: tenantConfiguration save failed', ['exception' => $e->getMessage()]);
			return $next;
		}
	}//end mergeConfig()

	/**
	 * Resolve the OpenRegister ObjectService when available.
	 *
	 * @return mixed|null
	 */
	private function getObjectService() {
		// IAppManager::getInstalledApps() declares its array return in PHPDoc
		// only, so normalise defensively before the membership test.
		$installed = (array)$this->appManager->getInstalledApps();
		if (in_array('openregister', $installed, true) === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			return null;
		}
	}//end getObjectService()
}//end class
