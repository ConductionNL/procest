<?php

/**
 * Dossiq KvK Handelsregister lookup port.
 *
 * The Kamer van Koophandel Handelsregister-API is the canonical
 * source-of-truth for Dutch legal entities. Dossiq consumes it on
 * three case-management lifecycles:
 *  1. Leverancier-zaakportaal supplier onboarding — the eHerkenning
 *     SAML assertion carries a `kvkNummer`; the adapter resolves it
 *     to the supplier's statutaire naam, vestigingsadres, RSIN and
 *     primary contact so the tenant doesn't retype data already in
 *     the register (REQ-LZP-S01 to REQ-LZP-S05).
 *  2. Zaakportaal-mijngemeente kvk lookup — when a citizen-facing
 *     intake form (`bedrijfszaak`) collects a KvK number, the
 *     adapter pre-fills the legal-entity envelope so the
 *     zaakbehandelaar receives a complete dossier.
 *  3. BRP/KvK register-set seeding — the `brp-kvk-register-sets`
 *     change materialises a per-tenant register-set; the seed flow
 *     calls this adapter to bootstrap example records.
 *
 * The port is intentionally narrow — one method returning a
 * structured result — so the production binding (openconnector
 * source slug `kvk-handelsregister`, OAuth2 + per-tenant API key)
 * can be swapped in via `Application::register()` without touching
 * any orchestrator. Until that binding is configured, the default
 * binding is dormant: it logs the intent + returns a synthetic
 * `LOOKUP_DEFERRED` outcome so the surrounding lifecycle stays
 * observable in test + staging environments.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\External\Kvk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 * @link https://developers.kvk.nl/apis/handelsregister
 *
 * @spec openspec/changes/leverancier-zaakportaal-02-eherkenning-auth/tasks.md
 * @spec openspec/changes/brp-kvk-register-sets/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\External\Kvk;

/**
 * KvK Handelsregister lookup port.
 *
 * Implementations MUST be side-effect-free when the dormant flag is set;
 * a dormant adapter records the intent (logger, audit trail) and returns
 * a synthetic LOOKUP_DEFERRED outcome so the surrounding lifecycle can
 * advance into `awaiting-kvk-enrichment` without contacting the KvK.
 *
 * Activation steps for a real KvK binding:
 *  1. Provision a KvK Handelsregister API key (production tier).
 *  2. Create an openconnector source with slug `kvk-handelsregister`,
 *     pointing at Handelsregister-Profile API v1
 *     (`api.kvk.nl/api/v1/handelsregister`).
 *  3. Override the KvkHandelsregisterAdapterInterface DI binding in
 *     `Application::register()` to the openconnector-backed
 *     implementation.
 *
 * @spec openspec/changes/leverancier-zaakportaal-02-eherkenning-auth/tasks.md
 * @spec openspec/changes/brp-kvk-register-sets/proposal.md
 */
interface KvkHandelsregisterAdapterInterface {
	/**
	 * Look up a legal entity by KvK number.
	 *
	 * @param string $kvkNumber 8-digit KvK number — leading
	 *                          zeros preserved.
	 * @param array<string,mixed> $context Optional context — caseId,
	 *                                     lookupReason
	 *                                     (`leverancier-onboarding` |
	 *                                     `bedrijfszaak-intake` |
	 *                                     `register-set-seed`),
	 *                                     correlationId.
	 *
	 * @return KvkLookupResult The lookup outcome (status + entity
	 *                         envelope + optional vestiging list).
	 *
	 * @spec openspec/changes/brp-kvk-register-sets/proposal.md
	 */
	public function lookup(string $kvkNumber, array $context = []): KvkLookupResult;

	/**
	 * Whether the adapter is dormant — i.e. wired but not contacting
	 * the KvK Handelsregister.
	 *
	 * @return bool TRUE when the adapter is a log-only stub.
	 *
	 * @spec openspec/changes/brp-kvk-register-sets/proposal.md
	 */
	public function isDormant(): bool;
}//end interface
