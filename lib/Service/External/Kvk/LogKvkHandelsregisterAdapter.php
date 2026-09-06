<?php

/**
 * Dormant default Dossiq KvK Handelsregister adapter.
 *
 * Records the would-be Handelsregister lookup to the structured
 * logger and returns a synthetic LOOKUP_DEFERRED result so the
 * surrounding lifecycle (leverancier-onboarding eHerkenning hand-off,
 * bedrijfszaak intake, BRP/KvK register-set seed) stays observable
 * until an openconnector-backed binding to the KvK Handelsregister
 * API is wired in via `Application::register()`. Mirrors the
 * `LogEHerkenningSamlAdapter` / `LogDigidSamlAdapter`
 * dormant-default pattern used across the Dossiq external surface.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\External\Kvk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/leverancier-zaakportaal-02-eherkenning-auth/tasks.md
 * @spec openspec/changes/brp-kvk-register-sets/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\External\Kvk;

use Psr\Log\LoggerInterface;

/**
 * Dormant log-backed Dossiq KvK Handelsregister adapter.
 *
 * @spec openspec/changes/leverancier-zaakportaal-02-eherkenning-auth/tasks.md
 */
class LogKvkHandelsregisterAdapter implements KvkHandelsregisterAdapterInterface {
	/**
	 * Construct the log-backed KvK adapter.
	 *
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Log the intent + synthesise a LOOKUP_DEFERRED result.
	 *
	 * The KvK number itself is not PII (it is publicly searchable in
	 * the Handelsregister), but the `context.caseId` /
	 * `context.correlationId` may carry a tenant-scoped zaak
	 * identifier, so the call is logged at INFO for the audit trail.
	 *
	 * @param string $kvkNumber 8-digit KvK number.
	 * @param array<string,mixed> $context Lookup context.
	 *
	 * @return KvkLookupResult The dispatch outcome.
	 *
	 * @spec openspec/specs/kvk-register/spec.md#requirement-kvk-company-register-schema-exists-in-openregister
	 */
	public function lookup(string $kvkNumber, array $context = []): KvkLookupResult {
		$this->logger->info(
			'Dossiq KvK Handelsregister lookup deferred (no outbound connector bound)',
			[
				'kvkNumber' => $kvkNumber,
				'context' => $context,
			]
		);

		return new KvkLookupResult(
			lookupStatus: 'LOOKUP_DEFERRED',
			kvkNumber: $kvkNumber,
			entity: [],
			dormant: true,
			extras: [
				'reason' => 'no-outbound-connector-bound',
				'note' => 'Bind openconnector source slug `kvk-handelsregister` (KvK Handelsregister API v1, '
					. 'per-tenant API key) and override KvkHandelsregisterAdapterInterface in '
					. 'Application::register() to enable real lookup.',
			],
		);
	}//end lookup()

	/**
	 * Report whether this adapter is dormant.
	 *
	 * @inheritDoc
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/kvk-register/spec.md#requirement-kvk-company-register-schema-exists-in-openregister
	 */
	public function isDormant(): bool {
		return true;
	}//end isDormant()
}//end class
