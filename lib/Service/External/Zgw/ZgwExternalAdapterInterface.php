<?php

/**
 * Dossiq external-ZGW client port.
 *
 * The Zaakgericht Werken (ZGW) APIs (Zaken-API, Documenten-API,
 * Besluiten-API, Catalogi-API, Autorisaties-API) are the VNG
 * Realisatie reference protocols every Dutch zaaksysteem must
 * speak. Dossiq is itself a ZGW-compliant zaaksysteem, but on
 * certain lifecycles it acts as a *client* talking to a
 * neighbouring municipality's ZGW stack:
 *  - Cross-municipality zaak hand-off (Wet Open Overheid / Wob /
 *    Woo: a complainant's bezwaar travels to the neighbouring
 *    gemeente — dossiq posts the `Zaak` + `Document` envelopes
 *    to the receiving stack via Zaken-API + Documenten-API).
 *  - VTH zaak push to a regional uitvoeringsdienst (omgevingsdienst
 *    or veiligheidsregio) — same client shape, different
 *    endpoint.
 *  - DSO Omgevingsloket landingstroom — already covered by
 *    `dso-omgevingsloket-client`; this adapter is for the rest of
 *    the ZGW family.
 *
 * The port is intentionally narrow — `submitZaak()` + `submitDocument()`
 * returning structured results — so the production binding
 * (openconnector source slug `zgw-external`, per-receiver JWT auth
 * + autorisaties-api scope handshake) can be swapped in via
 * `Application::register()` without touching the zaak hand-off
 * orchestrator.
 *
 * Until that binding is configured, the default binding is dormant:
 * it logs the intent and returns a synthetic `PUSH_DEFERRED`
 * outcome so the surrounding lifecycle stays observable in test +
 * staging environments.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\External\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 * @spec openspec/specs/zgw-autorisaties-api/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\External\Zgw;

/**
 * External-ZGW client port.
 *
 * Implementations MUST be side-effect-free when the dormant flag is
 * set; a dormant adapter records the intent and returns a synthetic
 * PUSH_DEFERRED outcome so the surrounding lifecycle can advance
 * into `pending-zgw-handoff` without contacting a neighbouring ZGW
 * stack.
 *
 * Activation steps for a real external-ZGW binding:
 *  1. Negotiate the per-receiver Autorisaties-API scope handshake
 *     (`zaken.aanmaken`, `zaken.geforceerd-bijwerken`,
 *     `documenten.aanmaken`, etc.).
 *  2. Provision the per-receiver JWT signing key in openconnector
 *     under source slug `zgw-external`, with one Source row per
 *     receiver-endpoint (Zaken-API, Documenten-API,
 *     Besluiten-API).
 *  3. Override the ZgwExternalAdapterInterface DI binding in
 *     `Application::register()` to the openconnector-backed
 *     implementation.
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
interface ZgwExternalAdapterInterface {
	/**
	 * Push a Zaak envelope to a neighbouring ZGW Zaken-API.
	 *
	 * @param array<string,mixed> $caseEnvelope ZGW-shaped payload —
	 *                                          identificatie, bronorganisatie,
	 *                                          omschrijving, zaaktype (URL
	 *                                          to the receiver's
	 *                                          Catalogi-API), startdatum,
	 *                                          rollen[].
	 * @param array<string,mixed> $context Optional context —
	 *                                     receiverSourceSlug (which
	 *                                     openconnector Source row),
	 *                                     handoffReason, correlationId.
	 *
	 * @return ZgwPushResult The dispatch outcome (status +
	 *                       receiver-side zaak URL).
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function submitZaak(array $caseEnvelope, array $context = []): ZgwPushResult;

	/**
	 * Push a Document envelope to a neighbouring ZGW Documenten-API.
	 *
	 * @param array<string,mixed> $documentEnvelope ZGW-shaped payload —
	 *                                              identificatie,
	 *                                              bronorganisatie, titel,
	 *                                              auteur, taal,
	 *                                              informatieobjecttype,
	 *                                              inhoud (base64 or
	 *                                              upload-handle), zaak.
	 * @param array<string,mixed> $context Optional context.
	 *
	 * @return ZgwPushResult The dispatch outcome (status +
	 *                       receiver-side document URL).
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function submitDocument(array $documentEnvelope, array $context = []): ZgwPushResult;

	/**
	 * Whether the adapter is dormant — i.e. wired but not contacting
	 * any external ZGW stack.
	 *
	 * @return bool TRUE when the adapter is a log-only stub.
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function isDormant(): bool;
}//end interface
