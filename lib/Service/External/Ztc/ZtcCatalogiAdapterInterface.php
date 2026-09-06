<?php

/**
 * Dossiq ZTC / Catalogi-API client port.
 *
 * The Zaaktypecatalogus (ZTC) is the VNG Realisatie reference
 * Catalogi-API: it stores per-tenant `ZaakType`, `BesluitType`,
 * `InformatieObjectType`, `RolType` and `StatusType` definitions
 * with cross-references. Dossiq is itself ZTC-capable but on two
 * lifecycles it acts as a *client* of a neighbouring municipality's
 * Catalogi-API:
 *  1. Cross-municipality hand-off — the receiver-side Zaken-API
 *     push (see `ZgwExternalAdapterInterface::submitZaak()`)
 *     requires the `zaaktype` URL to be a stable URL in the
 *     receiver's Catalogi-API; this adapter resolves it before
 *     the hand-off.
 *  2. ZaakType import — the
 *     `case-types-01-seed-and-stores` flow can import zaaktypen
 *     from a regional shared Catalogi-API (e.g.
 *     Dimpact / Common Ground sandbox) into the tenant's own
 *     ZTC, preserving the cross-references.
 *
 * The port is intentionally narrow — `resolveZaakType()` +
 * `importZaakType()` returning structured results — so the
 * production binding (openconnector source slug `ztc-catalogi`,
 * per-receiver JWT auth) can be swapped in via
 * `Application::register()` without touching consumers.
 *
 * Until that binding is configured, the default binding is
 * dormant: it logs the intent and returns a synthetic
 * `LOOKUP_DEFERRED` / `IMPORT_DEFERRED` outcome so the surrounding
 * lifecycle stays observable in test + staging environments.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\External\Ztc
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/catalogi/
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 * @spec openspec/changes/case-types-01-seed-and-stores/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\External\Ztc;

/**
 * ZTC / Catalogi-API client port.
 *
 * Implementations MUST be side-effect-free when the dormant flag is
 * set; a dormant adapter records the intent and returns a synthetic
 * LOOKUP_DEFERRED / IMPORT_DEFERRED outcome so the surrounding
 * lifecycle can advance into `awaiting-ztc-resolution` /
 * `awaiting-ztc-import` without contacting an external Catalogi-API.
 *
 * Activation steps for a real ZTC binding:
 *  1. Provision per-receiver JWT signing key + Autorisaties-API
 *     scope (`catalogi.lezen`, `catalogi.aanmaken` for the import
 *     flow) in openconnector under source slug `ztc-catalogi`.
 *  2. Configure the per-receiver Catalogi-API base URL.
 *  3. Override the ZtcCatalogiAdapterInterface DI binding in
 *     `Application::register()` to the openconnector-backed
 *     implementation.
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
interface ZtcCatalogiAdapterInterface {
	/**
	 * Resolve a `zaaktypeIdentificatie` to a canonical Catalogi-API
	 * URL on the named receiver.
	 *
	 * @param string $caseTypeId The receiver-side
	 *                           zaaktypeIdentificatie
	 *                           (e.g. `ZAAK-2026-WOO`).
	 * @param string $receiverSourceSlug Which openconnector
	 *                                   Source row to use
	 *                                   for the lookup.
	 * @param array<string,mixed> $context Optional context —
	 *                                     correlationId.
	 *
	 * @return ZtcResult The lookup outcome (status + canonical URL).
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function resolveZaakType(string $caseTypeId, string $receiverSourceSlug, array $context = []): ZtcResult;

	/**
	 * Import a `ZaakType` envelope from a neighbouring Catalogi-API
	 * into the tenant's own ZTC.
	 *
	 * @param string $caseTypeUrl Canonical receiver-side
	 *                            URL (output of
	 *                            resolveZaakType() or
	 *                            operator paste).
	 * @param array<string,mixed> $context Optional context
	 *                                     —
	 *                                     targetCatalogusUrl
	 *                                     (the tenant's own
	 *                                     catalogus the
	 *                                     import targets),
	 *                                     correlationId.
	 *
	 * @return ZtcResult The import outcome (status +
	 *                   `localZaakTypeUrl`).
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function importZaakType(string $caseTypeUrl, array $context = []): ZtcResult;

	/**
	 * Whether the adapter is dormant — i.e. wired but not contacting
	 * an external Catalogi-API.
	 *
	 * @return bool TRUE when the adapter is a log-only stub.
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function isDormant(): bool;
}//end interface
