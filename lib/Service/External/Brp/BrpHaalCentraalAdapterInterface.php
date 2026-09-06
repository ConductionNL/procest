<?php

/**
 * Dossiq BRP / Haal Centraal lookup port.
 *
 * The Basisregistratie Personen (BRP) — accessed via the
 * Logius/RvIG Haal Centraal Personen-API — is the canonical
 * source-of-truth for Dutch natural persons. Dossiq consumes it
 * on three case-management lifecycles:
 *  1. Citizen zaak intake — a DigiD-authenticated burger lands on
 *     the `zaakportaal-mijngemeente` intake flow; the adapter
 *     resolves the BSN (extracted from the DigiD SAML assertion)
 *     into a Persoon-envelope (naam, adres, geboortedatum,
 *     verblijfplaats) so the zaakbehandelaar receives a complete
 *     dossier without manual data entry.
 *  2. Brieflocatie resolution — a paper inkomende post case
 *     captures a BSN through the briefcode/OCR pipeline; the
 *     adapter enriches the zaak with the current adres for
 *     correct correspondence routing.
 *  3. BRP/KvK register-set seeding — the `brp-kvk-register-sets`
 *     change materialises a per-tenant register-set;
 *     example records seeded via this adapter.
 *
 * Per AVG / WBP article 9, BSN values MUST NEVER appear in
 * structured logs. The dormant default redacts them.
 *
 * The port is intentionally narrow — one method returning a
 * structured result — so the production binding (openconnector
 * source slug `brp-haalcentraal`, PKIoverheid Services-server
 * cert + per-tenant autorisatieprofiel) can be swapped in via
 * `Application::register()` without touching any orchestrator.
 *
 * Until that binding is configured, the default binding is
 * dormant: it logs the (BSN-redacted) intent + returns a
 * synthetic `LOOKUP_DEFERRED` outcome so the surrounding
 * lifecycle stays observable in test + staging environments.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\External\Brp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 * @link https://www.rvig.nl/brp/haal-centraal
 *
 * @spec openspec/changes/brp-kvk-register-sets/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\External\Brp;

/**
 * BRP / Haal Centraal lookup port.
 *
 * Implementations MUST be side-effect-free when the dormant flag is
 * set; a dormant adapter records the (BSN-redacted) intent and
 * returns a synthetic LOOKUP_DEFERRED outcome so the surrounding
 * lifecycle can advance into `awaiting-brp-enrichment` without
 * contacting RvIG.
 *
 * Activation steps for a real Haal Centraal binding:
 *  1. Provision a PKIoverheid Services-server certificate (RSA 4096
 *     + OIN) registered with the Logius/RvIG autorisatieproces.
 *  2. Obtain a per-tenant `autorisatieprofiel` (the set of fields
 *     the tenant is allowed to read — at minimum
 *     `burgerservicenummer`, `naam`, `geboorte`, `verblijfplaats`).
 *  3. Create an openconnector source with slug `brp-haalcentraal`,
 *     pointing at the Haal Centraal BRP Personen API endpoint
 *     (`api.haalcentraal.nl/haalcentraal/api/brp/personen`).
 *  4. Override the BrpHaalCentraalAdapterInterface DI binding in
 *     `Application::register()` to the openconnector-backed
 *     implementation.
 *
 * @spec openspec/changes/brp-kvk-register-sets/proposal.md
 */
interface BrpHaalCentraalAdapterInterface {
	/**
	 * Look up a natural person by BSN.
	 *
	 * @param string $bsn 9-digit Burgerservicenummer.
	 * @param array<string,mixed> $context Optional context — caseId,
	 *                                     lookupReason
	 *                                     (`citizen-intake` |
	 *                                     `briefcode-resolution` |
	 *                                     `register-set-seed`),
	 *                                     correlationId,
	 *                                     autorisatieprofielId
	 *                                     (openconnector-side ref).
	 *
	 * @return BrpLookupResult The lookup outcome (status + persoon
	 *                         envelope minus BSN).
	 *
	 * @spec openspec/changes/brp-kvk-register-sets/proposal.md
	 */
	public function lookup(string $bsn, array $context = []): BrpLookupResult;

	/**
	 * Whether the adapter is dormant — i.e. wired but not contacting
	 * Haal Centraal.
	 *
	 * @return bool TRUE when the adapter is a log-only stub.
	 *
	 * @spec openspec/changes/brp-kvk-register-sets/proposal.md
	 */
	public function isDormant(): bool;
}//end interface
