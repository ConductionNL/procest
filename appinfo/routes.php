<?php

/**
 * Dossiq Route Configuration
 *
 * Defines all HTTP routes for the Dossiq application.
 *
 * @category Routes
 * @package  OCA\Dossiq
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

// The mechanical boilerplate routes — dashboard#page (`/`), the SPA catch-all
// (`/{path}`), settings#index/create/load, preferences#getPreference/setPreference,
// metrics#index and health#index — are now provided by the OpenRegister AppHost
// canonical route table (ADR-040). URLs, verbs and route names are unchanged.
// The dossiq-bespoke dashboard PWA assets (dashboard#serviceWorker /
// dashboard#webManifest) and every domain route below are passed through as
// `$extra`; they are inserted before the catch-all so they keep priority.
//
// ⚠️ The AppHost builder is invoked through a `class_exists()` guard. Nextcloud
// `include`s this file for EVERY dossiq request, so an unguarded static call
// to a class in another app makes every route in the app fatal with HTTP 500
// when openregister is absent — not just the AppHost ones. Dossiq does not
// declare `<app>openregister</app>`, so an admin can create exactly that
// configuration. Fixing only the controllers MOVES the fatal here rather than
// removing it. The fallback branch below reproduces `Routes::standard()`'s
// output locally so dossiq still routes without openregister.
// See decidesk#377 / #388.
$extra = [
        // Backend manifest delta — case-type navigation (case-type-navigation).
        // Consumed by useAppManifest('dossiq', bundled, { mergeStrategy: 'delta' }).
    ['name' => 'manifest#manifest',  'url' => '/api/manifest',           'verb' => 'GET'],

        // AI-Assisted Processing (specific endpoints precede wildcard routes).
    ['name' => 'ai#classify',        'url' => '/api/ai/classify',        'verb' => 'POST'],
    ['name' => 'ai#extract',         'url' => '/api/ai/extract',         'verb' => 'POST'],
    ['name' => 'ai#ask',             'url' => '/api/ai/ask',             'verb' => 'POST'],
    ['name' => 'ai#summarize',       'url' => '/api/ai/summarize',       'verb' => 'POST'],
    ['name' => 'ai#suggestRouting',  'url' => '/api/ai/suggest-routing', 'verb' => 'POST'],
    ['name' => 'ai#suggestNext',     'url' => '/api/ai/suggest-next',    'verb' => 'POST'],
        // First-time setup wizard (ADR-042)
    ['name' => 'setup#status',       'url' => '/api/setup/status',              'verb' => 'GET'],
    ['name' => 'setup#saveConfig',   'url' => '/api/setup/config',              'verb' => 'POST'],
    ['name' => 'setup#runAction',    'url' => '/api/setup/action/{actionId}',   'verb' => 'POST'],
    ['name' => 'ai#recordAction',    'url' => '/api/ai/record-action',   'verb' => 'POST'],
    ['name' => 'ai#auditIndex',      'url' => '/api/ai/audit',           'verb' => 'GET'],
    ['name' => 'aiAuditExport#export', 'url' => '/api/ai/audit/export',  'verb' => 'GET'],
    ['name' => 'aiSettings#getSettings',    'url' => '/api/ai/settings',  'verb' => 'GET'],
    ['name' => 'aiSettings#updateSettings', 'url' => '/api/ai/settings', 'verb' => 'POST'],
    ['name' => 'aiSettings#healthCheck',   'url' => '/api/ai/health',    'verb' => 'POST'],

        // Case assistant via Hermiq (case-assistant-via-hermiq): thin consumer
        // surface — conversational assistance is delegated to Hermiq's
        // case-assistant-surface; this app only enriches with case context.
    ['name' => 'assistant#availability', 'url' => '/api/assistant/availability', 'verb' => 'GET'],
    ['name' => 'assistant#converse',     'url' => '/api/assistant/converse',     'verb' => 'POST'],

        // KCC Klantcontact (kcc-klantcontact-integratie).
        // Static/verb routes precede the {id} wildcard routes.
    ['name' => 'kccRouting#evaluate', 'url' => '/api/kcc/routing/evaluate', 'verb' => 'POST'],
    ['name' => 'kccRouting#index',    'url' => '/api/kcc/routing-rules', 'verb' => 'GET'],
    ['name' => 'kccRouting#create',   'url' => '/api/kcc/routing-rules', 'verb' => 'POST'],
    ['name' => 'kccRouting#update',   'url' => '/api/kcc/routing-rules/{id}', 'verb' => 'PUT'],
    ['name' => 'kccRouting#destroy',  'url' => '/api/kcc/routing-rules/{id}', 'verb' => 'DELETE'],

        // DMN decision tables (dmn-decision-tables spec).
    ['name' => 'decisionTable#index',    'url' => '/api/decisions',              'verb' => 'GET'],
    ['name' => 'decisionTable#create',   'url' => '/api/decisions',              'verb' => 'POST'],
    ['name' => 'decisionTable#evaluate', 'url' => '/api/decisions/{id}/evaluate', 'verb' => 'POST'],
    ['name' => 'decisionTable#update',   'url' => '/api/decisions/{id}',          'verb' => 'PUT'],
    ['name' => 'decisionTable#destroy',  'url' => '/api/decisions/{id}',          'verb' => 'DELETE'],

    ['name' => 'kccContact#indexCallbacks',  'url' => '/api/kcc/callback-requests', 'verb' => 'GET'],
    ['name' => 'kccContact#scheduleCallback', 'url' => '/api/kcc/callback-requests', 'verb' => 'POST'],
    ['name' => 'kccContact#cancelCallback',  'url' => '/api/kcc/callback-requests/{id}/cancel', 'verb' => 'POST'],

    ['name' => 'kccContact#index',   'url' => '/api/kcc/contact-moments', 'verb' => 'GET'],
    ['name' => 'kccContact#create',  'url' => '/api/kcc/contact-moments', 'verb' => 'POST'],
    ['name' => 'kccContact#related', 'url' => '/api/kcc/contact-moments/{id}/related', 'verb' => 'GET'],
    ['name' => 'kccContact#show',    'url' => '/api/kcc/contact-moments/{id}', 'verb' => 'GET'],
    ['name' => 'kccContact#update',  'url' => '/api/kcc/contact-moments/{id}', 'verb' => 'PUT'],

        // Subsidieverlening-keten (subsidieverlening-keten spec) — AWB titel 4.2.
        // Static/verb routes precede the {id} wildcard routes; the public
        // subsidieregister feed precedes the authenticated /api/subsidies list.
    ['name' => 'subsidieRegister#export', 'url' => '/api/subsidies/register/export', 'verb' => 'GET'],
    ['name' => 'subsidie#index',  'url' => '/api/subsidies', 'verb' => 'GET'],
    ['name' => 'subsidie#create', 'url' => '/api/subsidies', 'verb' => 'POST'],
    ['name' => 'subsidie#createTussenrapportage', 'url' => '/api/subsidies/uitvoeringen/{uitvoeringId}/tussenrapportages', 'verb' => 'POST'],
    ['name' => 'subsidie#approveTussenrapportage', 'url' => '/api/subsidies/tussenrapportages/{reportId}/beoordelen', 'verb' => 'POST'],
        // The placeholder names bind BY NAME to the method parameters, not by
        // position, so a placeholder that does not match the method's parameter
        // name resolves to null and the Dispatcher answers HTTP 400. These three were
        // {vaststellingId}/{beschikkingId} against $determinationId/$decisionId
        // and answered HTTP 400 on every call.
    ['name' => 'subsidie#finalizeVaststelling', 'url' => '/api/subsidies/vaststellingen/{determinationId}/vast', 'verb' => 'POST'],
    ['name' => 'subsidie#signBeschikking', 'url' => '/api/subsidies/beschikkingen/{decisionId}/sign', 'verb' => 'POST'],
    ['name' => 'subsidie#publishBeschikking', 'url' => '/api/subsidies/beschikkingen/{decisionId}/publish', 'verb' => 'POST'],
    ['name' => 'subsidie#transition', 'url' => '/api/subsidies/{id}/transition', 'verb' => 'POST'],
    ['name' => 'subsidie#createBeschikking', 'url' => '/api/subsidies/{id}/beschikking', 'verb' => 'POST'],

        // ZGW Mapping Management.
    ['name' => 'zgwMapping#index', 'url' => '/api/zgw-mappings', 'verb' => 'GET'],
    ['name' => 'zgwMapping#show', 'url' => '/api/zgw-mappings/{resourceKey}', 'verb' => 'GET'],
    ['name' => 'zgwMapping#update', 'url' => '/api/zgw-mappings/{resourceKey}', 'verb' => 'PUT'],
    ['name' => 'zgwMapping#destroy', 'url' => '/api/zgw-mappings/{resourceKey}', 'verb' => 'DELETE'],
    ['name' => 'zgwMapping#reset', 'url' => '/api/zgw-mappings/{resourceKey}/reset', 'verb' => 'POST'],

        // Case Definition Portability (export/import zaaktype packages).
    ['name' => 'caseDefinition#export', 'url' => '/api/case-definitions/export', 'verb' => 'POST'],
    ['name' => 'caseDefinition#validate', 'url' => '/api/case-definitions/validate', 'verb' => 'POST'],
    ['name' => 'caseDefinition#import', 'url' => '/api/case-definitions/import', 'verb' => 'POST'],

        // Case type duplicate (zaaktype-copy) + draft-only guarded delete.
    ['name' => 'caseDefinition#copy',   'url' => '/api/case-definitions/{id}/copy', 'verb' => 'POST'],
    ['name' => 'caseDefinition#delete', 'url' => '/api/case-definitions/{id}',      'verb' => 'DELETE'],

        // ── ZGW OpenAPI Discovery (zgw-openapi-publication) ─────────────
        // Literal routes registered before the ZGW {resource}-wildcard
        // blocks below so `openapi`/`openapi.yaml` segments are never
        // swallowed by a parameterized ZGW route.
    ['name' => 'zgwOpenApi#index', 'url' => '/api/zgw/openapi', 'verb' => 'GET'],
    ['name' => 'zgwOpenApi#spec', 'url' => '/api/zgw/{api}/openapi.yaml', 'verb' => 'GET'],

        // ── DRC (Documenten) ────────────────────────────────────────────
        // Special endpoints (must precede wildcard routes).
    ['name' => 'drc#download', 'url' => '/api/zgw/documenten/v1/enkelvoudiginformatieobjecten/{uuid}/download', 'verb' => 'GET'],
    ['name' => 'drc#lock', 'url' => '/api/zgw/documenten/v1/enkelvoudiginformatieobjecten/{uuid}/lock', 'verb' => 'POST'],
    ['name' => 'drc#unlock', 'url' => '/api/zgw/documenten/v1/enkelvoudiginformatieobjecten/{uuid}/unlock', 'verb' => 'POST'],
        // Bestandsdelen (chunked upload).
    ['name' => 'drc#uploadChunk', 'url' => '/api/zgw/documenten/v1/bestandsdelen/{uuid}', 'verb' => 'PUT'],
        // Audit trail.
    ['name' => 'drc#audittrailIndex', 'url' => '/api/zgw/documenten/v1/{resource}/{uuid}/audittrail', 'verb' => 'GET'],
    ['name' => 'drc#audittrailShow', 'url' => '/api/zgw/documenten/v1/{resource}/{uuid}/audittrail/{auditUuid}', 'verb' => 'GET'],
        // CRUD.
    ['name' => 'drc#index', 'url' => '/api/zgw/documenten/v1/{resource}', 'verb' => 'GET'],
    ['name' => 'drc#create', 'url' => '/api/zgw/documenten/v1/{resource}', 'verb' => 'POST'],
    ['name' => 'drc#show', 'url' => '/api/zgw/documenten/v1/{resource}/{uuid}', 'verb' => 'GET'],
    ['name' => 'drc#update', 'url' => '/api/zgw/documenten/v1/{resource}/{uuid}', 'verb' => 'PUT'],
    ['name' => 'drc#patch', 'url' => '/api/zgw/documenten/v1/{resource}/{uuid}', 'verb' => 'PATCH'],
    ['name' => 'drc#destroy', 'url' => '/api/zgw/documenten/v1/{resource}/{uuid}', 'verb' => 'DELETE'],

        // ── ZRC (Zaken) ─────────────────────────────────────────────────
        // Nested sub-resource routes (must precede wildcard routes).
    ['name' => 'zrc#zaakeigenschappenIndex', 'url' => '/api/zgw/zaken/v1/zaken/{zaakUuid}/zaakeigenschappen', 'verb' => 'GET'],
    ['name' => 'zrc#zaakeigenschappenCreate', 'url' => '/api/zgw/zaken/v1/zaken/{zaakUuid}/zaakeigenschappen', 'verb' => 'POST'],
    ['name' => 'zrc#zaakeigenschappenShow', 'url' => '/api/zgw/zaken/v1/zaken/{zaakUuid}/zaakeigenschappen/{uuid}', 'verb' => 'GET'],
    ['name' => 'zrc#zaakeigenschappenUpdate', 'url' => '/api/zgw/zaken/v1/zaken/{zaakUuid}/zaakeigenschappen/{uuid}', 'verb' => 'PUT'],
    ['name' => 'zrc#zaakeigenschappenPatch', 'url' => '/api/zgw/zaken/v1/zaken/{zaakUuid}/zaakeigenschappen/{uuid}', 'verb' => 'PATCH'],
    ['name' => 'zrc#zaakeigenschappenDestroy', 'url' => '/api/zgw/zaken/v1/zaken/{zaakUuid}/zaakeigenschappen/{uuid}', 'verb' => 'DELETE'],
        // Zaakbesluiten sub-resource.
    ['name' => 'zrc#zaakbesluitenIndex', 'url' => '/api/zgw/zaken/v1/zaken/{zaakUuid}/besluiten', 'verb' => 'GET'],
        // Zoek endpoint.
    ['name' => 'zrc#zoek', 'url' => '/api/zgw/zaken/v1/zaken/_zoek', 'verb' => 'POST'],
        // Audit trail.
    ['name' => 'zrc#audittrailIndex', 'url' => '/api/zgw/zaken/v1/{resource}/{uuid}/audittrail', 'verb' => 'GET'],
    ['name' => 'zrc#audittrailShow', 'url' => '/api/zgw/zaken/v1/{resource}/{uuid}/audittrail/{auditUuid}', 'verb' => 'GET'],
        // CRUD.
    ['name' => 'zrc#index', 'url' => '/api/zgw/zaken/v1/{resource}', 'verb' => 'GET'],
    ['name' => 'zrc#create', 'url' => '/api/zgw/zaken/v1/{resource}', 'verb' => 'POST'],
    ['name' => 'zrc#show', 'url' => '/api/zgw/zaken/v1/{resource}/{uuid}', 'verb' => 'GET'],
    ['name' => 'zrc#update', 'url' => '/api/zgw/zaken/v1/{resource}/{uuid}', 'verb' => 'PUT'],
    ['name' => 'zrc#patch', 'url' => '/api/zgw/zaken/v1/{resource}/{uuid}', 'verb' => 'PATCH'],
    ['name' => 'zrc#destroy', 'url' => '/api/zgw/zaken/v1/{resource}/{uuid}', 'verb' => 'DELETE'],

        // ── ZTC (Catalogi) ──────────────────────────────────────────────
        // Publish endpoints (must precede wildcard routes).
    ['name' => 'ztc#publishZaaktype', 'url' => '/api/zgw/catalogi/v1/zaaktypen/{uuid}/publish', 'verb' => 'POST'],
    ['name' => 'ztc#publishBesluittype', 'url' => '/api/zgw/catalogi/v1/besluittypen/{uuid}/publish', 'verb' => 'POST'],
    ['name' => 'ztc#publishInformatieobjecttype', 'url' => '/api/zgw/catalogi/v1/informatieobjecttypen/{uuid}/publish', 'verb' => 'POST'],
        // Audit trail.
    ['name' => 'ztc#audittrailIndex', 'url' => '/api/zgw/catalogi/v1/{resource}/{uuid}/audittrail', 'verb' => 'GET'],
    ['name' => 'ztc#audittrailShow', 'url' => '/api/zgw/catalogi/v1/{resource}/{uuid}/audittrail/{auditUuid}', 'verb' => 'GET'],
        // CRUD.
    ['name' => 'ztc#index', 'url' => '/api/zgw/catalogi/v1/{resource}', 'verb' => 'GET'],
    ['name' => 'ztc#create', 'url' => '/api/zgw/catalogi/v1/{resource}', 'verb' => 'POST'],
    ['name' => 'ztc#show', 'url' => '/api/zgw/catalogi/v1/{resource}/{uuid}', 'verb' => 'GET'],
    ['name' => 'ztc#update', 'url' => '/api/zgw/catalogi/v1/{resource}/{uuid}', 'verb' => 'PUT'],
    ['name' => 'ztc#patch', 'url' => '/api/zgw/catalogi/v1/{resource}/{uuid}', 'verb' => 'PATCH'],
    ['name' => 'ztc#destroy', 'url' => '/api/zgw/catalogi/v1/{resource}/{uuid}', 'verb' => 'DELETE'],

        // ── BRC (Besluiten) ─────────────────────────────────────────────
        // Audit trail.
    ['name' => 'brc#audittrailIndex', 'url' => '/api/zgw/besluiten/v1/{resource}/{uuid}/audittrail', 'verb' => 'GET'],
    ['name' => 'brc#audittrailShow', 'url' => '/api/zgw/besluiten/v1/{resource}/{uuid}/audittrail/{auditUuid}', 'verb' => 'GET'],
        // CRUD.
    ['name' => 'brc#index', 'url' => '/api/zgw/besluiten/v1/{resource}', 'verb' => 'GET'],
    ['name' => 'brc#create', 'url' => '/api/zgw/besluiten/v1/{resource}', 'verb' => 'POST'],
    ['name' => 'brc#show', 'url' => '/api/zgw/besluiten/v1/{resource}/{uuid}', 'verb' => 'GET'],
    ['name' => 'brc#update', 'url' => '/api/zgw/besluiten/v1/{resource}/{uuid}', 'verb' => 'PUT'],
    ['name' => 'brc#patch', 'url' => '/api/zgw/besluiten/v1/{resource}/{uuid}', 'verb' => 'PATCH'],
    ['name' => 'brc#destroy', 'url' => '/api/zgw/besluiten/v1/{resource}/{uuid}', 'verb' => 'DELETE'],

        // ── AC (Autorisaties) ───────────────────────────────────────────
    ['name' => 'ac#index', 'url' => '/api/zgw/autorisaties/v1/applicaties', 'verb' => 'GET'],
    ['name' => 'ac#create', 'url' => '/api/zgw/autorisaties/v1/applicaties', 'verb' => 'POST'],
    ['name' => 'ac#show', 'url' => '/api/zgw/autorisaties/v1/applicaties/{uuid}', 'verb' => 'GET'],
    ['name' => 'ac#update', 'url' => '/api/zgw/autorisaties/v1/applicaties/{uuid}', 'verb' => 'PUT'],
    ['name' => 'ac#patch', 'url' => '/api/zgw/autorisaties/v1/applicaties/{uuid}', 'verb' => 'PATCH'],
    ['name' => 'ac#destroy', 'url' => '/api/zgw/autorisaties/v1/applicaties/{uuid}', 'verb' => 'DELETE'],

        // ── NRC (Notificaties) ──────────────────────────────────────────
        // Notificaties webhook endpoint.
    ['name' => 'nrc#notificatieCreate', 'url' => '/api/zgw/notificaties/v1/notificaties', 'verb' => 'POST'],
        // Audit trail.
    ['name' => 'nrc#audittrailIndex', 'url' => '/api/zgw/notificaties/v1/{resource}/{uuid}/audittrail', 'verb' => 'GET'],
    ['name' => 'nrc#audittrailShow', 'url' => '/api/zgw/notificaties/v1/{resource}/{uuid}/audittrail/{auditUuid}', 'verb' => 'GET'],
        // CRUD.
    ['name' => 'nrc#index', 'url' => '/api/zgw/notificaties/v1/{resource}', 'verb' => 'GET'],
    ['name' => 'nrc#create', 'url' => '/api/zgw/notificaties/v1/{resource}', 'verb' => 'POST'],
    ['name' => 'nrc#show', 'url' => '/api/zgw/notificaties/v1/{resource}/{uuid}', 'verb' => 'GET'],
    ['name' => 'nrc#update', 'url' => '/api/zgw/notificaties/v1/{resource}/{uuid}', 'verb' => 'PUT'],
    ['name' => 'nrc#patch', 'url' => '/api/zgw/notificaties/v1/{resource}/{uuid}', 'verb' => 'PATCH'],
    ['name' => 'nrc#destroy', 'url' => '/api/zgw/notificaties/v1/{resource}/{uuid}', 'verb' => 'DELETE'],

        // GIS / cases-on-map: the multi-object overview is served by
        // OpenRegister's page-level maps-overview surface (OR #154) — RBAC-scoped
        // marker points at /apps/openregister/api/integrations/maps/overviews/...
        // Dossiq's bespoke GIS-proxy / WMS-WFS-proxy / WFS-export / WFS-XML /
        // map-layer-CRUD / cases-geo routes were removed with that migration
        // (issue #112, ADR-022). PDOK address resolution is owned separately by
        // the migrate-pdok-to-openconnector change.
        // ── BAG (Basisregistratie Adressen en Gebouwen) lookup (bag-register-adapter) ──
        // Authoritative address + pand/verblijfsobject lookup, dormant by default
        // (integration.bag.mode). Distinct from PDOK's free/open BAG WFS mirror
        // (PdokBagService) — see openspec/changes/bag-register-adapter/design.md.
    ['name' => 'bag#address', 'url' => '/api/external/bag/address', 'verb' => 'GET'],
    ['name' => 'bag#pand', 'url' => '/api/external/bag/pand/{id}', 'verb' => 'GET'],
    ['name' => 'bag#verblijfsobject', 'url' => '/api/external/bag/verblijfsobject/{id}', 'verb' => 'GET'],

        // ── BRK (Basisregistratie Kadaster) lookup (brk-woz-register-adapters) ──
        // Authoritative parcel/ownership-reference lookup, dormant by default
        // (integration.brk.mode) — see
        // openspec/changes/brk-woz-register-adapters/design.md.
    ['name' => 'brk#parcel', 'url' => '/api/external/brk/parcel', 'verb' => 'GET'],
    ['name' => 'brk#object', 'url' => '/api/external/brk/parcel/{id}', 'verb' => 'GET'],

        // ── WOZ (Waardering Onroerende Zaken) lookup (brk-woz-register-adapters) ──
        // Authoritative property-valuation lookup, dormant by default
        // (integration.woz.mode). Deliberately NOT bound to the public
        // WOZ-waardeloket, which has no programmatic API — see
        // openspec/changes/brk-woz-register-adapters/design.md Decision 2.
    ['name' => 'woz#value', 'url' => '/api/external/woz/value', 'verb' => 'GET'],
    ['name' => 'woz#object', 'url' => '/api/external/woz/value/{wozobjectnummer}', 'verb' => 'GET'],

        // ── StUF (Standaard Uitwisselings Formaat) ──────────────────────
        // Inbound SOAP endpoints accept raw XML POST.
    ['name' => 'stuf#cases',   'url' => '/api/stuf/cases',   'verb' => 'POST'],
    ['name' => 'stuf#persons', 'url' => '/api/stuf/persons', 'verb' => 'POST'],
        // DEPRECATED ALIASES — same reasoning as /api/stuf/inkomend below: the
        // URL lives in the SENDING zaaksysteem's configuration, so renaming it
        // alone turns a working SOAP receiver into a silent 404 on somebody
        // else's schedule. Each needs its OWN method name because AppHost
        // Routes::standard() rejects duplicates by `name` and ignores `postfix`.
        // Remove once every configured sender posts to the English paths.
    ['name' => 'stuf#casesLegacyPath',   'url' => '/api/stuf/zaken',    'verb' => 'POST'],
    ['name' => 'stuf#personsLegacyPath', 'url' => '/api/stuf/personen', 'verb' => 'POST'],
        // Outbound StUF-ZKN gateway (admin REST) + async confirmation receiver.
    ['name' => 'stuf#endpoints', 'url' => '/api/stuf/endpoints', 'verb' => 'GET'],
    ['name' => 'stuf#messages',  'url' => '/api/stuf/messages',  'verb' => 'GET'],
    ['name' => 'stuf#outbound',  'url' => '/api/stuf/outbound',  'verb' => 'POST'],
    ['name' => 'stuf#inbound',   'url' => '/api/stuf/inbound',   'verb' => 'POST'],
        // DEPRECATED ALIAS — remove once every configured zaaksysteem posts to
        // /api/stuf/inbound. This URL is a WIRE CONTRACT: it is written into the
        // upstream system's configuration, not into ours, so renaming it alone
        // turns a working webhook into a silent 404 on somebody else's schedule.
        //
        // It routes to its OWN method rather than reusing 'stuf#inbound' with a
        // postfix: openregister's AppHost Routes::standard() rejects duplicate
        // names by `name` alone and never looks at `postfix`, so the two-entries-
        // one-name form throws "Duplicate route name" at boot and takes the whole
        // app's routing down with it.
    ['name' => 'stuf#inboundLegacyPath', 'url' => '/api/stuf/inkomend', 'verb' => 'POST'],

        // Doorlooptijd (throughput-time) dashboard metrics.
    ['name' => 'doorlooptijd#metrics', 'url' => '/api/doorlooptijd/metrics', 'verb' => 'GET'],

        // Process mining bottleneck report — dwell-time, bottleneck ranking,
        // transition matrix + rework detection, throughput trend.
    ['name' => 'processMining#report', 'url' => '/api/reports/process-mining', 'verb' => 'GET'],

        // Deelzaak (sub-case) parent-child relations.
    ['name' => 'deelzaak#counts',   'url' => '/api/deelzaken/counts',                'verb' => 'GET'],
    ['name' => 'deelzaak#validate', 'url' => '/api/deelzaken/validate',              'verb' => 'POST'],
    ['name' => 'deelzaak#list',     'url' => '/api/deelzaken/{caseId}/children',     'verb' => 'GET'],
    ['name' => 'deelzaak#parent',   'url' => '/api/deelzaken/{caseId}/parent',       'verb' => 'GET'],
    ['name' => 'deelzaak#unlink',   'url' => '/api/deelzaken/{caseId}/unlink',       'verb' => 'POST'],

        // Related-case linking — typed peer relations (relevanteAndereZaken).
    ['name' => 'caseRelation#list',    'url' => '/api/cases/{caseId}/relations',                          'verb' => 'GET'],
    ['name' => 'caseRelation#create',  'url' => '/api/cases/{caseId}/relations',                          'verb' => 'POST'],
        // {aardRelatie} bound nothing: the method signs $natureRelationship. HTTP 400.
    ['name' => 'caseRelation#destroy', 'url' => '/api/cases/{caseId}/relations/{targetId}/{natureRelationship}', 'verb' => 'DELETE'],
        // Dashboard KPI aggregation endpoint.
    ['name' => 'kpi#index', 'url' => '/api/dashboard/kpis', 'verb' => 'GET'],

        // Intelligent work-queue: urgency-scored personal queue + coordinator workload.
    ['name' => 'workQueue#index',    'url' => '/api/work-queue',          'verb' => 'GET'],
    ['name' => 'workQueue#workload', 'url' => '/api/work-queue/workload', 'verb' => 'GET'],

        // PWA assets (must precede the catch-all /{path} shell route below).
    ['name' => 'dashboard#serviceWorker', 'url' => '/service-worker.js',           'verb' => 'GET'],
    ['name' => 'dashboard#webManifest',   'url' => '/manifest.webmanifest',        'verb' => 'GET'],


        // ── Advice Management (adviesAanvraag) ──────────────────────────
        // CRUD is handled by the manifest renderer via OpenRegister. Only
        // workflow operations live on this controller.
    ['name' => 'advice#transitionStatus',  'url' => '/api/advice/{id}/transition', 'verb' => 'POST'],
    ['name' => 'advice#dispatchReminder',  'url' => '/api/advice/{id}/remind',     'verb' => 'POST'],

        // ── Notes @mention notifications (ncvue-w2-leaves-adoption) ─────
        // Note storage/CRUD is owned entirely by the OpenRegister notes
        // integration leaf (nc-vue CnNotesTab). This is the only
        // dossiq-side side-effect: turning a saved note's @mention
        // tokens into real Nextcloud notifications.
    ['name' => 'notes#mention', 'url' => '/api/notes/mention', 'verb' => 'POST'],

        // ── Workflow Definitions (workflowTemplate) ─────────────────────
        // CRUD on workflowTemplate is served by the manifest renderer +
        // OpenRegister auto-routing (/api/objects/<register>/<schema>).
        // Only the lifecycle transitions and the read-only consumer
        // contract live on this controller.
    ['name' => 'workflowDefinition#publish',           'url' => '/api/workflow-definitions/{id}/publish',         'verb' => 'POST'],
    ['name' => 'workflowDefinition#deprecate',         'url' => '/api/workflow-definitions/{id}/deprecate',       'verb' => 'POST'],
    ['name' => 'workflowDefinition#cloneDefinition',   'url' => '/api/workflow-definitions/{id}/clone',           'verb' => 'POST'],
    ['name' => 'workflowDefinition#active',            'url' => '/api/workflow-definitions/active/{caseTypeId}',  'verb' => 'GET'],
    ['name' => 'workflowDefinition#forCase',           'url' => '/api/workflow-definitions/for-case/{caseId}',    'verb' => 'GET'],

        // ── Status Transition Engine ────────────────────────────────────
        // Single write-path for case.status. CRUD on statusRecord is rendered
        // by the manifest (/settings/status-records). Action-style endpoints
        // live here because guard evaluation + side-effect dispatch are
        // non-CRUD engine logic.
    ['name' => 'statusTransition#available', 'url' => '/api/case/{caseId}/available-transitions', 'verb' => 'GET'],
    ['name' => 'statusTransition#execute',   'url' => '/api/case/{caseId}/transition',            'verb' => 'POST'],
    ['name' => 'statusTransition#freeform',  'url' => '/api/case/{caseId}/transition-freeform',   'verb' => 'POST'],
    ['name' => 'statusTransition#history',   'url' => '/api/case/{caseId}/transition-history',    'verb' => 'GET'],

        // Bulk transitions (case-bulk-status-transition) — plural `/api/cases/`
        // prefix with literal `bulk-transition` segments, distinct from the
        // singular `/api/case/{caseId}/...` engine routes above and from every
        // other `/api/cases/{id}/...` parameterised route (none of which use a
        // single literal `bulk-transition` first segment), so no collision.
    ['name' => 'statusTransition#bulkPreview', 'url' => '/api/cases/bulk-transition/preview', 'verb' => 'POST'],
    ['name' => 'statusTransition#bulkExecute', 'url' => '/api/cases/bulk-transition/execute', 'verb' => 'POST'],

        // ── CMMN Adaptive Case Engine (cmmn-adaptive-case) ──────────────
        // Sibling to the Status Transition Engine above: single write-path
        // for case.casePlanState on CMMN-managed caseTypes (handlingModel =
        // 'cmmn'). BPMN-managed caseTypes never reach these routes — the
        // engine itself refuses to operate on them (case_not_cmmn_managed).
    ['name' => 'cmmnCase#plan',      'url' => '/api/case/{caseId}/cmmn-plan',           'verb' => 'GET'],
    ['name' => 'cmmnCase#enable',    'url' => '/api/case/{caseId}/cmmn-plan/enable',    'verb' => 'POST'],
    ['name' => 'cmmnCase#complete',  'url' => '/api/case/{caseId}/cmmn-plan/complete',  'verb' => 'POST'],
    ['name' => 'cmmnCase#terminate', 'url' => '/api/case/{caseId}/cmmn-plan/terminate', 'verb' => 'POST'],
    ['name' => 'cmmnCase#signal',    'url' => '/api/case/{caseId}/cmmn-plan/signal',    'verb' => 'POST'],

        // Multi-Tenant SaaS — domain endpoints only. Generic tenant CRUD
        // (list/create/update/destroy) is rendered by the manifest pages
        // at /settings/tenants and proxied directly to OpenRegister; this
        // controller keeps provisioning, usage aggregation, and current-
        // tenant resolution — the parts that are not declarative CRUD.
    ['name' => 'tenant#current',   'url' => '/api/tenants/current',                'verb' => 'GET'],
    ['name' => 'tenant#memberships',  'url' => '/api/tenants/memberships',         'verb' => 'GET'],
    ['name' => 'tenant#switchTenant', 'url' => '/api/tenants/switch',              'verb' => 'POST'],
    ['name' => 'tenant#provision', 'url' => '/api/tenants/{tenantId}/provision',   'verb' => 'POST'],
    ['name' => 'tenant#usage',     'url' => '/api/tenants/{tenantId}/usage',       'verb' => 'GET'],

        // SaaS Tenant CRUD + lifecycle — backed by the `tenant` register schema
        // (chain member tenant-zaaksysteem-saas-01). Admin-only via the
        // SecurityMiddleware default; #[AuthorizedAdminSetting] on each method.
    ['name' => 'tenantSaas#index',   'url' => '/api/saas/tenants',                  'verb' => 'GET'],
    ['name' => 'tenantSaas#create',  'url' => '/api/saas/tenants',                  'verb' => 'POST'],
    ['name' => 'tenantSaas#show',    'url' => '/api/saas/tenants/{tenantId}',       'verb' => 'GET'],
    ['name' => 'tenantSaas#update',  'url' => '/api/saas/tenants/{tenantId}',       'verb' => 'PATCH'],
    ['name' => 'tenantSaas#destroy', 'url' => '/api/saas/tenants/{tenantId}',       'verb' => 'DELETE'],

        // SaaS metered billing (chain member 10) — aggregate usage + run Shillinq invoicing.
    ['name' => 'tenantSaas#billingSummary', 'url' => '/api/saas/tenants/{tenantId}/billing/{month}',     'verb' => 'GET'],
    ['name' => 'tenantSaas#runBilling',     'url' => '/api/saas/tenants/{tenantId}/billing/{month}/run', 'verb' => 'POST'],

        // SaaS onboarding (chain member 07) — checklist init/progress/complete + go-live activation.
    ['name' => 'tenantOnboarding#initialise', 'url' => '/api/saas/tenants/{tenantId}/onboarding/initialise',     'verb' => 'POST'],
    ['name' => 'tenantOnboarding#progress',   'url' => '/api/saas/tenants/{tenantId}/onboarding/progress',       'verb' => 'GET'],
    ['name' => 'tenantOnboarding#complete',   'url' => '/api/saas/tenants/{tenantId}/onboarding/{step}/complete', 'verb' => 'POST'],
    ['name' => 'tenantOnboarding#activate',   'url' => '/api/saas/tenants/{tenantId}/onboarding/activate',       'verb' => 'POST'],

        // ── Appointment Scheduling (afsprakenbeheer) ────────────────────
        // Specific endpoints (must precede wildcard {appointmentId} routes).
    ['name' => 'appointment#timeslots', 'url' => '/api/appointments/timeslots',                'verb' => 'GET'],
    ['name' => 'appointment#noShow',    'url' => '/api/appointments/{appointmentId}/no-show',  'verb' => 'POST'],
        // CRUD.
    ['name' => 'appointment#index',     'url' => '/api/appointments',                          'verb' => 'GET'],
    ['name' => 'appointment#create',    'url' => '/api/appointments',                          'verb' => 'POST'],
    ['name' => 'appointment#cancel',    'url' => '/api/appointments/{appointmentId}',          'verb' => 'DELETE'],
        // Public (citizen self-service via token).
    ['name' => 'publicAppointment#view',   'url' => '/api/public/appointment/{token}',         'verb' => 'GET'],
    ['name' => 'publicAppointment#cancel', 'url' => '/api/public/appointment/{token}/cancel',  'verb' => 'POST'],

        // Case sharing & collaboration — domain endpoints only.
        // Public "track your case" token links are minted/revoked through
        // OpenRegister's shares integration leaf (ADR-022): createShare
        // delegates to the leaf's case-token surface; revokeShare delegates
        // to the leaf revoke. Partner-organisation handover + case transfer
        // stay in-app (zaak-domain). CRUD over the partner/transfer schemas
        // is served by the OpenRegister manifest renderer.
        //
        // The bespoke dossiq public-share controller + its token routes
        // (/api/public/share/*, /api/public/status/*) were REMOVED: the
        // citizen-facing public case-status page now resolves anonymously
        // through OR's `#[PublicPage]` endpoint
        // `GET /apps/openregister/api/public/case-tokens/{token}` — an
        // audited, RBAC-respecting surface (only public-group-readable
        // fields), not a hand-maintained dossiq auth surface.
    ['name' => 'caseSharing#createShare',      'url' => '/api/shares',                   'verb' => 'POST'],
    ['name' => 'caseSharing#revokeShare',      'url' => '/api/shares/{shareId}',         'verb' => 'DELETE'],
    ['name' => 'caseSharing#initiateTransfer', 'url' => '/api/transfers',                'verb' => 'POST'],
    ['name' => 'caseSharing#handleTransfer',   'url' => '/api/transfers/{transferId}',   'verb' => 'PUT'],

        // Federated (cross-instance) case collaboration (federated-case-collaboration).
        // Local session endpoints — case-access RBAC enforced in the controller.
    ['name' => 'caseFederation#createFederatedShare', 'url' => '/api/federation/shares',                            'verb' => 'POST'],
    ['name' => 'caseFederation#revokeFederatedShare', 'url' => '/api/federation/shares/{shareId}',                  'verb' => 'DELETE'],
    ['name' => 'caseFederation#postActivity',         'url' => '/api/federation/activity/{federatedShareId}',       'verb' => 'POST'],
    ['name' => 'caseFederation#listActivity',         'url' => '/api/federation/activity/{federatedShareId}',       'verb' => 'GET'],
        // Public (remote-instance) endpoints — authenticated via the OR-minted
        // scoped bearer token, NOT a local session (the caller is another
        // Nextcloud instance). See design.md §1/§4.
    ['name' => 'caseFederation#handleFederatedTransfer', 'url' => '/api/public/federation/transfers/{shareToken}/{transferId}',      'verb' => 'PUT'],
    ['name' => 'caseFederation#postRemoteActivity',      'url' => '/api/public/federation/activity/{shareToken}/{federatedShareId}', 'verb' => 'POST'],
    ['name' => 'caseFederation#listRemoteActivity',      'url' => '/api/public/federation/activity/{shareToken}/{federatedShareId}', 'verb' => 'GET'],

        // Role-based routing engine action — manual recompute of step assignees.
        // CRUD of routing rules themselves lives on workflowTemplate (manifest).
    ['name' => 'routing#reroute', 'url' => '/api/cases/{id}/reroute', 'verb' => 'POST'],

        // ── VTH Module: DSO intake, checklist results, advice, LHS lookup ─
        // @spec openspec/changes/vth-module/tasks.md#task-3
    ['name' => 'dSOIntake#intake', 'url' => '/api/vth/dso/intake', 'verb' => 'POST'],
        // @spec openspec/changes/vth-module/tasks.md#task-8
    ['name' => 'lhs#lookup',          'url' => '/api/vth/lhs/lookup', 'verb' => 'GET'],

        // LHS engine actions — matrix lookup + inspector override.
        // CRUD of matrices and recommendations lives on lhsMatrix/lhsRecommendation (manifest).
    ['name' => 'lhs#recommend', 'url' => '/api/lhs/recommend', 'verb' => 'POST'],
    ['name' => 'lhs#override',  'url' => '/api/lhs/override',  'verb' => 'POST'],

        // ── VTH Module ─────────────────────────────────────────────────────
        // VTH zaaktype template management (admin only).
    ['name' => 'vTHTemplate#index',    'url' => '/api/vth/templates',             'verb' => 'GET'],
    ['name' => 'vTHTemplate#activate', 'url' => '/api/vth/templates/{slug}/activate', 'verb' => 'POST'],
        // Inspection checklist CRUD (admin).
    ['name' => 'inspectionChecklist#index',   'url' => '/api/vth/checklists',       'verb' => 'GET'],
    ['name' => 'inspectionChecklist#create',  'url' => '/api/vth/checklists',       'verb' => 'POST'],
    ['name' => 'inspectionChecklist#update',  'url' => '/api/vth/checklists/{id}',  'verb' => 'PUT'],
    ['name' => 'inspectionChecklist#destroy', 'url' => '/api/vth/checklists/{id}',  'verb' => 'DELETE'],
        // Per-case inspection result submission and retrieval.
    ['name' => 'inspectionChecklist#submitResult', 'url' => '/api/vth/cases/{id}/inspection-result', 'verb' => 'POST'],
    ['name' => 'inspectionChecklist#getResults',   'url' => '/api/vth/cases/{id}/inspection-results', 'verb' => 'GET'],
        // Per-case advice request creation.
    ['name' => 'advice#createForCase', 'url' => '/api/vth/cases/{id}/advice-requests', 'verb' => 'POST'],
    ['name' => 'advice#getForCase',    'url' => '/api/vth/cases/{id}/advice-requests', 'verb' => 'GET'],

        // ── Berichtenbox (government inbox integration) ─────────────────
    ['name' => 'berichtenbox#send',     'url' => '/api/berichtenbox/send',                 'verb' => 'POST'],
    ['name' => 'berichtenbox#messages', 'url' => '/api/berichtenbox/messages',             'verb' => 'GET'],
    ['name' => 'berichtenbox#poll',     'url' => '/api/berichtenbox/messages/{messageId}', 'verb' => 'GET'],

        // ── Beschikking (compose -> onderteken -> Berichtenbox -> archief) ──
        // Specific verb/suffix routes precede the generic PATCH /{id} wildcard.
    ['name' => 'beschikking#create',      'url' => '/api/beschikkingen',                       'verb' => 'POST'],
    ['name' => 'beschikking#show',        'url' => '/api/beschikkingen/{id}',                  'verb' => 'GET'],
    ['name' => 'beschikking#auditPakket', 'url' => '/api/beschikkingen/{id}/audit-pakket',     'verb' => 'GET'],
    ['name' => 'beschikking#akkoord',     'url' => '/api/beschikkingen/{id}/akkoord',          'verb' => 'PATCH'],
    ['name' => 'beschikking#onderteken',  'url' => '/api/beschikkingen/{id}/onderteken',       'verb' => 'PATCH'],
    ['name' => 'beschikking#verzend',     'url' => '/api/beschikkingen/{id}/verzend',          'verb' => 'PATCH'],
    ['name' => 'beschikking#update',      'url' => '/api/beschikkingen/{id}',                  'verb' => 'PATCH'],

        // ── Consultation (advice requests and responses) ─────────────────
    ['name' => 'consultation#index',               'url' => '/api/consultations/case/{caseId}',                'verb' => 'GET'],
    ['name' => 'consultation#create',              'url' => '/api/consultations',                              'verb' => 'POST'],
    ['name' => 'consultation#show',                'url' => '/api/consultations/{id}',                         'verb' => 'GET'],
    ['name' => 'consultation#delete',              'url' => '/api/consultations/{id}',                         'verb' => 'DELETE'],
    ['name' => 'consultation#updateStatus',        'url' => '/api/consultations/{id}/status',                  'verb' => 'POST'],
    ['name' => 'consultation#submitResponse',      'url' => '/api/consultations/{id}/response',                'verb' => 'POST'],
    ['name' => 'consultation#requestExtension',    'url' => '/api/consultations/{id}/extension',               'verb' => 'POST'],
    ['name' => 'consultation#approveExtension',    'url' => '/api/consultations/{id}/extension/approve',       'verb' => 'POST'],
    ['name' => 'consultation#overdue',             'url' => '/api/consultations/overdue',                      'verb' => 'GET'],
    ['name' => 'advisoryBody#listAdvisoryBodies',   'url' => '/api/advisory-bodies',                            'verb' => 'GET'],
    ['name' => 'advisoryBody#searchAdvisoryBodies', 'url' => '/api/advisory-bodies/search',                     'verb' => 'GET'],
    ['name' => 'consultationPublic#publicResponseGet',  'url' => '/api/public/consultations/{token}',           'verb' => 'GET'],
    ['name' => 'consultationPublic#publicResponsePost', 'url' => '/api/public/consultations/{token}',           'verb' => 'POST'],

        // ── Email (outbound case communication) ─────────────────────────
    ['name' => 'email#send',             'url' => '/api/email/{caseId}/send',            'verb' => 'POST'],
    ['name' => 'email#sendFromTemplate', 'url' => '/api/email/{caseId}/send-template',   'verb' => 'POST'],
    ['name' => 'email#preview',          'url' => '/api/email/{caseId}/preview',         'verb' => 'POST'],
    ['name' => 'email#templates',        'url' => '/api/email/templates/{caseTypeId}',   'verb' => 'GET'],

        // Email template versioning + IMAP settings (leaf-first: NC Mail still owns send/list/link).
    ['name' => 'emailTemplate#listTemplates',  'url' => '/api/casetypes/{caseTypeId}/email-templates',                  'verb' => 'GET'],
    ['name' => 'emailTemplate#createTemplate', 'url' => '/api/casetypes/{caseTypeId}/email-templates',                  'verb' => 'POST'],
    ['name' => 'emailTemplate#variables',      'url' => '/api/casetypes/{caseTypeId}/email-templates/variables',         'verb' => 'GET'],
    ['name' => 'emailTemplate#seedDefaults',   'url' => '/api/casetypes/{caseTypeId}/email-templates/seed-defaults',    'verb' => 'POST'],
    ['name' => 'emailTemplate#updateTemplate', 'url' => '/api/email-templates/{templateId}',                            'verb' => 'PUT'],
    ['name' => 'emailTemplate#prefillDraft',   'url' => '/api/cases/{caseId}/email-templates/{templateId}/draft',       'verb' => 'POST'],
    ['name' => 'emailTemplate#getSettings',    'url' => '/api/settings/email',                                          'verb' => 'GET'],
    ['name' => 'emailTemplate#saveSettings',   'url' => '/api/settings/email',                                          'verb' => 'PUT'],
    ['name' => 'emailTemplate#testImap',       'url' => '/api/settings/email/test-imap',                                 'verb' => 'POST'],

        // ── Template (workflow step templates) ──────────────────────────
    ['name' => 'template#index',    'url' => '/api/templates',           'verb' => 'GET'],
    ['name' => 'template#show',     'url' => '/api/templates/{id}',      'verb' => 'GET'],
    ['name' => 'template#activate', 'url' => '/api/templates/{id}/activate', 'verb' => 'POST'],

        // ── WOO (Wet open overheid) operations ──────────────────────────
    ['name' => 'wOOAssessment#bulkAssess',      'url' => '/api/cases/{id}/woo/assessment',     'verb' => 'POST'],
    ['name' => 'wOOAssessment#extendDeadline',  'url' => '/api/cases/{id}/woo/extend-deadline','verb' => 'POST'],
    ['name' => 'wOOAssessment#createDecision',  'url' => '/api/cases/{id}/woo/decision',       'verb' => 'POST'],
    ['name' => 'wOOAssessment#publishDecision', 'url' => '/api/cases/{id}/woo/publish',        'verb' => 'POST'],
    ['name' => 'wOOAssessment#withdrawPublication', 'url' => '/api/cases/{id}/woo/withdraw',   'verb' => 'POST'],

        // LLM-assisted redaction-span proposal (woo-llm-anonymisation): an ASSIST
        // to the existing WOORedactionService, never a replacement — proposals are
        // always human-reviewed (proposeRedaction → reviewRedactionProposal) before
        // any hand-off to the unchanged Docudesk/manual redaction pipeline.
    [
        'name'         => 'wOOAssessment#proposeRedaction',
        'url'          => '/api/cases/{id}/woo/documents/{documentRef}/redaction-proposal',
        'verb'         => 'POST',
        'requirements' => ['documentRef' => '[^/]+'],
    ],
    [
        'name'         => 'wOOAssessment#reviewRedactionProposal',
        'url'          => '/api/cases/{id}/woo/documents/{documentRef}/redaction-proposal/review',
        'verb'         => 'POST',
        'requirements' => ['documentRef' => '[^/]+'],
    ],

        // ── Milestone tracking ───────────────────────────────────────────
    ['name' => 'milestone#progress', 'url' => '/api/cases/{caseId}/milestones/progress/{caseTypeId}', 'verb' => 'GET'],
    ['name' => 'milestone#caseProgress', 'url' => '/api/cases/{caseId}/milestones/progress', 'verb' => 'GET'],
    ['name' => 'milestone#mark',     'url' => '/api/cases/{caseId}/milestones/{milestoneId}/mark',    'verb' => 'POST'],
    ['name' => 'milestone#reverse',  'url' => '/api/cases/{caseId}/milestones/{milestoneId}/reverse', 'verb' => 'POST'],

        // ── Besluitvorming workflow ──────────────────────────────────────
    ['name' => 'besluitvorming#activateTemplate', 'url' => '/api/besluitvorming/templates/{slug}/activate', 'verb' => 'POST'],
        // The two `agenda#` routes were removed with the agenda compiler: decidiq
        // owns agenda-building and meetings, and it surfaces them here through the
        // `decidesk-decisions` integration leaf. They served only the compiler view
        // and became unreachable the moment it went, which is the dead-endpoint
        // shape this programme already retired once on the AVG page.
    ['name' => 'publication#publish',             'url' => '/api/besluitvorming/cases/{id}/publish',        'verb' => 'POST'],
    ['name' => 'mandaat#mandaatCheck',            'url' => '/api/besluitvorming/cases/{id}/mandaat-check',  'verb' => 'GET'],

        // GIS map-layer CRUD removed (issue #112): base-layer config is now
        // declarative on OpenRegister's maps-overview surface (PDOK WMTS default,
        // overridable) — dossiq no longer manages WMS/WFS overlay layers.
        // ── DSO / Omgevingsloket (DSO controller endpoints) ──────────────
    ['name' => 'dso#dashboard',            'url' => '/api/dso/dashboard',                                'verb' => 'GET'],
    ['name' => 'dso#transitionStatus',     'url' => '/api/dso/cases/{caseId}/transition',                'verb' => 'POST'],
    ['name' => 'dso#generateBeschikking',  'url' => '/api/dso/cases/{caseId}/beschikking',               'verb' => 'POST'],
    ['name' => 'dso#initiateSamenwerking', 'url' => '/api/dso/cases/{caseId}/samenwerking',              'verb' => 'POST'],
    ['name' => 'dso#respondSamenwerking',  'url' => '/api/dso/samenwerking/{samenwerkId}/respond',       'verb' => 'POST'],
    ['name' => 'dso#doorsturen',           'url' => '/api/dso/cases/{caseId}/doorsturen',                'verb' => 'POST'],
    ['name' => 'dossierExport#export',     'url' => '/api/dossier/{caseId}/export',                      'verb' => 'GET'],

        // ── KCC-werkplek bridge (kcc-werkplek-zaaksysteem-bridge) ───────
    ['name' => 'contactMoment#create',            'url' => '/api/contactmomenten',                     'verb' => 'POST'],
    ['name' => 'contactMoment#index',             'url' => '/api/contactmomenten',                     'verb' => 'GET'],
    ['name' => 'contactMoment#voorblad',          'url' => '/api/kcc/voorblad',                        'verb' => 'GET'],
    ['name' => 'contactMoment#statusGeven',       'url' => '/api/kcc/quick-actions/status-geven',      'verb' => 'POST'],
    ['name' => 'contactMoment#nieuweZaak',        'url' => '/api/kcc/quick-actions/nieuwe-zaak',       'verb' => 'POST'],
    ['name' => 'contactMoment#klachtRegistreren', 'url' => '/api/kcc/quick-actions/klacht-registreren', 'verb' => 'POST'],
    ['name' => 'contactMoment#doorverbinden',     'url' => '/api/kcc/quick-actions/doorverbinden',     'verb' => 'POST'],
    ['name' => 'contactMoment#acceptDoorverbinding', 'url' => '/api/kcc/doorverbindingen/{id}/accept', 'verb' => 'POST'],
    ['name' => 'contactMoment#rejectDoorverbinding', 'url' => '/api/kcc/doorverbindingen/{id}/reject', 'verb' => 'POST'],
    ['name' => 'belplan#route',                   'url' => '/api/kcc/belplannen/route',                'verb' => 'POST'],
    ['name' => 'belplan#index',                   'url' => '/api/kcc/belplannen',                      'verb' => 'GET'],
    ['name' => 'belplan#create',                  'url' => '/api/kcc/belplannen',                      'verb' => 'POST'],
    ['name' => 'belplan#update',                  'url' => '/api/kcc/belplannen/{id}',                 'verb' => 'PUT'],
    ['name' => 'specialistBeschikbaarheid#index', 'url' => '/api/kcc/specialist-beschikbaarheid',      'verb' => 'GET'],

        // ── Complaints (klachtafhandeling) — Awb chapter 9 ─────────────────
    ['name' => 'complaint#index',              'url' => '/api/complaints',                             'verb' => 'GET'],
    ['name' => 'complaint#create',             'url' => '/api/complaints',                             'verb' => 'POST'],
        // Literal GET sub-paths MUST be registered before the `/{id}` wildcard,
        // otherwise `complaint#show` captures `/complaints/deadline-alerts`,
        // `/complaints/analytics` and `/complaints/kpi` as an `{id}` lookup.
        // The analytics pair now lives on ComplaintAnalyticsController, but the
        // ordering constraint is unchanged: they still precede `complaint#show`.
    ['name' => 'complaint#deadlineAlerts',              'url' => '/api/complaints/deadline-alerts',    'verb' => 'GET'],
    ['name' => 'complaintAnalytics#analytics',          'url' => '/api/complaints/analytics',          'verb' => 'GET'],
    ['name' => 'complaintAnalytics#kpi',                'url' => '/api/complaints/kpi',                'verb' => 'GET'],
    ['name' => 'complaint#show',               'url' => '/api/complaints/{id}',                        'verb' => 'GET'],
    ['name' => 'complaint#update',             'url' => '/api/complaints/{id}',                        'verb' => 'PUT'],
    ['name' => 'complaint#transition',         'url' => '/api/complaints/{id}/transition',             'verb' => 'POST'],
    ['name' => 'complaint#verdaging',          'url' => '/api/complaints/{id}/verdaging',              'verb' => 'POST'],
    ['name' => 'complaint#escalate',           'url' => '/api/complaints/{id}/escalate',               'verb' => 'POST'],
        // Hearings.
    ['name' => 'complaintHearing#hearings',             'url' => '/api/complaints/{id}/hearings',                  'verb' => 'GET'],
    ['name' => 'complaintHearing#scheduleHearing',      'url' => '/api/complaints/{id}/hearings',                  'verb' => 'POST'],
    ['name' => 'complaintHearing#recordHearingOutcome', 'url' => '/api/complaints/{id}/hearings/{hearingId}',      'verb' => 'PUT'],
        // Dispositions.
    ['name' => 'complaintDisposition#getDisposition',     'url' => '/api/complaints/{id}/disposition',           'verb' => 'GET'],
    ['name' => 'complaintDisposition#submitDisposition',  'url' => '/api/complaints/{id}/disposition',           'verb' => 'POST'],
    ['name' => 'complaintDisposition#approveDisposition', 'url' => '/api/complaints/{id}/disposition/approve',   'verb' => 'POST'],
    ['name' => 'complaintDisposition#generateLetter',     'url' => '/api/complaints/{id}/disposition/letter',    'verb' => 'POST'],
        // Categories (admin).
    ['name' => 'complaintCategory#categories',     'url' => '/api/complaint-categories',              'verb' => 'GET'],
    ['name' => 'complaintCategory#createCategory', 'url' => '/api/complaint-categories',              'verb' => 'POST'],
    ['name' => 'complaintCategory#updateCategory', 'url' => '/api/complaint-categories/{id}',         'verb' => 'PUT'],

        // ── Bezwaar hoorzitting (Awb art. 7:2) ────────────────────────────
        // Attendance is append-only with a one-hour grace window and an
        // awb-art-7:7 audit entry on late corrections; the `hearingSession`
        // schema has no manifest page, so this is the only way in.
    ['name' => 'bezwaarHearing#recordAttendance', 'url' => '/api/bezwaar/hearings/{sessionId}/attendance', 'verb' => 'POST'],

        // Archief / e-Depot handover is owned by OpenRegister (migrate-archival-to-or,
        // ADR-022): retention, transfer, proof and destruction run through OR's
        // /api/archival, /api/transfers, /api/settings/edepot surfaces. Dossiq
        // contributes retention config declaratively (x-openregister-archival on the
        // case schema) and places legal holds via BezwaarLegalHoldListener; it exposes
        // no archief endpoints of its own.
        // ── Mandaat-matrix authorization engine ────────────────────────────
    ['name' => 'mandaatMatrix#probe',           'url' => '/api/mandate/authorize',                    'verb' => 'POST'],
    ['name' => 'mandaatMatrix#importPreview',   'url' => '/api/mandate/import',                       'verb' => 'POST'],
    ['name' => 'mandaatMatrix#importApprove',   'url' => '/api/mandate/import/{importId}/approve',    'verb' => 'POST'],
    ['name' => 'mandaatMatrix#escalateCreate',  'url' => '/api/mandate/escalations',                  'verb' => 'POST'],
    ['name' => 'mandaatMatrix#escalateApprove', 'url' => '/api/mandate/escalations/{id}/approve',     'verb' => 'POST'],
    ['name' => 'mandaatMatrix#escalateReject',  'url' => '/api/mandate/escalations/{id}/reject',      'verb' => 'POST'],
    ['name' => 'mandaatMatrix#auditTrail',      'url' => '/api/mandate/cases/{caseId}/audit-trail',   'verb' => 'GET'],
    ['name' => 'mandaatMatrix#applicable',      'url' => '/api/mandate/cases/{caseId}/applicable',    'verb' => 'GET'],

        // Mandate-matrix ADMIN registries (procest#794). The settings panel has
        // always called these four collections; nothing declared them, so every
        // request was answered by Nextcloud's own HTML page under HTTP 200 and
        // four admin tabs rendered silently empty. Every target method carries
        // #[AuthorizedAdminSetting] — repointing the frontend at OpenRegister's
        // generic object route also resolves and also removes the symptom, and
        // would bypass that guard (the retracted first fix for procest#784).
    ['name' => 'mandaatRegistry#besluiten',          'url' => '/api/mandate/besluiten',           'verb' => 'GET'],
    ['name' => 'mandaatRegistry#mandatenCreate',     'url' => '/api/mandate/mandaten',            'verb' => 'POST'],
    ['name' => 'mandaatRegistry#mandatenUpdate',     'url' => '/api/mandate/mandaten/{id}',       'verb' => 'PATCH'],
    ['name' => 'organisatieRol#rollenIndex',         'url' => '/api/mandate/rollen',              'verb' => 'GET'],
    ['name' => 'organisatieRol#rollenCreate',        'url' => '/api/mandate/rollen',              'verb' => 'POST'],
    ['name' => 'organisatieRol#rollenUpdate',        'url' => '/api/mandate/rollen/{id}',         'verb' => 'PATCH'],
    ['name' => 'organisatieRol#rollenDestroy',       'url' => '/api/mandate/rollen/{id}',         'verb' => 'DELETE'],
    ['name' => 'organisatieRol#toewijzingenIndex',   'url' => '/api/mandate/toewijzingen',        'verb' => 'GET'],
    ['name' => 'organisatieRol#toewijzingenCreate',  'url' => '/api/mandate/toewijzingen',        'verb' => 'POST'],
    ['name' => 'organisatieRol#toewijzingenUpdate',  'url' => '/api/mandate/toewijzingen/{id}',   'verb' => 'PATCH'],

        // ── Handler vervanging/waarneming + bulk reassignment (handler-vervanging-waarneming) ──
    ['name' => 'substitution#index',           'url' => '/api/substitutions',                 'verb' => 'GET'],
    ['name' => 'substitution#create',          'url' => '/api/substitutions',                 'verb' => 'POST'],
    ['name' => 'substitution#substitutedWork', 'url' => '/api/substitutions/work',            'verb' => 'GET'],
    ['name' => 'substitution#actions',         'url' => '/api/substitutions/{id}/actions',    'verb' => 'GET'],
    ['name' => 'substitution#revoke',          'url' => '/api/substitutions/{id}/revoke',     'verb' => 'POST'],
    ['name' => 'caseReassignment#reassignPreview', 'url' => '/api/reassignments/preview',      'verb' => 'POST'],
    ['name' => 'caseReassignment#reassignExecute', 'url' => '/api/reassignments/execute',      'verb' => 'POST'],
    ['name' => 'caseReassignment#reassignSelection', 'url' => '/api/reassignments/selection',  'verb' => 'POST'],

        // ── Termijnbewaking + dwangsom engine (AWB 4:13/4:14/4:17) ─────────
        // Public webhook for openconnector/ERP payment confirmation callbacks.
    ['name' => 'dwangsomPaymentCallback#callback', 'url' => '/api/dossiq/openconnector/dwangsom-payment-callback', 'verb' => 'POST'],
        // TermijnInstance lifecycle (caseworker / handler).
    ['name' => 'termijn#create',     'url' => '/api/termijn/instances',                  'verb' => 'POST'],
    ['name' => 'termijn#show',       'url' => '/api/termijn/instances/{id}',             'verb' => 'GET'],
    ['name' => 'termijn#pauze',      'url' => '/api/termijn/instances/{id}/pauze',       'verb' => 'POST'],
    ['name' => 'termijn#hervat',     'url' => '/api/termijn/instances/{id}/hervat',      'verb' => 'POST'],
    ['name' => 'termijn#verleng',    'url' => '/api/termijn/instances/{id}/verleng',     'verb' => 'POST'],
    ['name' => 'termijn#voltooi',    'url' => '/api/termijn/instances/{id}/voltooi',     'verb' => 'POST'],
        // TermijnDefinitie ADMIN registry (REQ-TERM-ADMIN-001, procest#794).
        // TermijnDefinitiesTab.vue has always called this collection; only
        // /api/termijn/instances* was declared, so the tab rendered empty.
    ['name' => 'termijnDefinitie#index',  'url' => '/api/termijn/definities',      'verb' => 'GET'],
    ['name' => 'termijnDefinitie#create', 'url' => '/api/termijn/definities',      'verb' => 'POST'],
    ['name' => 'termijnDefinitie#update', 'url' => '/api/termijn/definities/{id}', 'verb' => 'PATCH'],
        // Notice-of-default registration.
        // Route NAMES follow the renamed controller — a route name resolves to
        // its class, so it must move with it or the router 500s. The URLs
        // deliberately do NOT move: /api/termijn/ingebrekestellingen is the
        // published contract and changing it is a breaking change for every
        // consumer, which is a separate decision from this rename.
    ['name' => 'noticeOfDefault#register', 'url' => '/api/termijn/ingebrekestellingen',       'verb' => 'POST'],
    ['name' => 'noticeOfDefault#show',     'url' => '/api/termijn/ingebrekestellingen/{id}',  'verb' => 'GET'],
        // Dwangsom state + bezwaar.
    ['name' => 'dwangsom#show',         'url' => '/api/termijn/dwangsom/{id}',             'verb' => 'GET'],
    ['name' => 'dwangsom#beschikking',  'url' => '/api/termijn/dwangsom/{id}/beschikking', 'verb' => 'POST'],
    ['name' => 'dwangsom#bezwaar',      'url' => '/api/termijn/dwangsom/{id}/bezwaar',     'verb' => 'POST'],
    ['name' => 'dwangsom#bezwaarHeroverweging', 'url' => '/api/termijn/dwangsom/{id}/bezwaar/heroverweging', 'verb' => 'POST'],
        // Reporting (manager / accountant).
        // The route NAMES follow the renamed controller and its methods; the
        // URLs deliberately do NOT. /api/termijn/* is the published contract and
        // moving it is a breaking change for every consumer, so it is a separate
        // decision from this rename. Nothing references these route names — the
        // frontend calls the URLs directly.
    ['name' => 'deadlineReporting#dashboard',        'url' => '/api/termijn/dashboard/kpi',            'verb' => 'GET'],
    ['name' => 'deadlineReporting#quarterlyReport',  'url' => '/api/termijn/reports/kwartaal',         'verb' => 'GET'],
    ['name' => 'deadlineReporting#annualStatement',  'url' => '/api/termijn/reports/jaarrekening',     'verb' => 'GET'],
        // IV3/BBV taakveld reference list, for the case-type classification
        // picker. The quarterly IV3 cost report that used to sit alongside it
        // is gone under ADR-081 — Shillinq is the only statutory reporter. The
        // URL is unchanged because the settings picker calls it directly.
    ['name' => 'iv3Taakveld#taakvelden',             'url' => '/api/reports/iv3/taakvelden',           'verb' => 'GET'],

        // ── ZGW DRC Case Dossier (document-zaakdossier spec) ────────────
        // Specific endpoints precede the {infoObjectId} wildcards so bulk/status routes resolve first.
    ['name' => 'zaakdossier#listDossier',          'url' => '/api/cases/{caseId}/dossier',                     'verb' => 'GET'],
    ['name' => 'zaakdossier#uploadDocument',       'url' => '/api/cases/{caseId}/dossier',                     'verb' => 'POST'],
    ['name' => 'zaakdossierDownload#downloadZip',  'url' => '/api/cases/{caseId}/dossier/zip',                 'verb' => 'POST'],
    ['name' => 'zaakdossier#linkExisting',         'url' => '/api/cases/{caseId}/dossier/{infoObjectId}/link', 'verb' => 'POST'],
    ['name' => 'zaakdossier#unlinkDocument',       'url' => '/api/cases/{caseId}/dossier/{infoObjectId}/link', 'verb' => 'DELETE'],
    ['name' => 'zaakdossier#bulkTransitionStatus', 'url' => '/api/informatieobjecten/bulk/status',            'verb' => 'POST'],
    ['name' => 'zaakdossier#bulkUpdateMetadata',   'url' => '/api/informatieobjecten/bulk/metadata',          'verb' => 'POST'],
    ['name' => 'zaakdossier#transitionStatus',     'url' => '/api/informatieobjecten/{infoObjectId}/status',   'verb' => 'PATCH'],
    ['name' => 'zaakdossier#updateMetadata',       'url' => '/api/informatieobjecten/{infoObjectId}',          'verb' => 'PATCH'],
    ['name' => 'zaakdossierDownload#downloadFile',         'url' => '/api/objects/{register}/{schema}/{objectId}/files/{fileId}/download', 'verb' => 'GET'],
    ['name' => 'zaakdossierDownload#downloadZgwDocumenten','url' => '/api/zgw/documenten/v1/enkelvoudiginformatieobjecten/{uuid}/download', 'verb' => 'GET'],

        // ORI Atom feeds REMOVED (ori-removal). Raadsinformatie is decidiq's
        // domain; these three routes moved to /apps/decidiq/feed/ori/*.
        // Deliberately no proxy and no redirect — a feed that silently answers
        // from a different app is worse than one that stops, because a
        // subscriber cannot tell it has moved.

        // Case-configuration store (ADR-080). Consume-only: dossiq browses a
        // remote OpenRegister registry through AppHost's GenericStoreService and
        // installs locally. It never publishes, and it builds no registry URL of
        // its own — discovery belongs to the engine.
    ['name' => 'store#search',       'url' => '/api/store/items',                 'verb' => 'GET'],
    ['name' => 'store#install',      'url' => '/api/store/items/{slug}/install',  'verb' => 'POST', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
    ['name' => 'store#getSettings',  'url' => '/api/store/settings',              'verb' => 'GET'],
    ['name' => 'store#saveSettings', 'url' => '/api/store/settings',              'verb' => 'PUT'],

        // NOTE: dashboard#page (`/`) and the SPA catch-all (`/{path}`,
        // dashboard#catchAll) are supplied by Routes::standard(); both resolve to
        // dossiq's DashboardController, which implements them locally.
];

// Preferred path: the OpenRegister AppHost owns the canonical route table.
// `class_exists()` autoloads without fatalling when the class is unavailable.
if (class_exists('OCA\OpenRegister\AppHost\Routes') === true) {
    return \OCA\OpenRegister\AppHost\Routes::standard($extra);
}

// Fallback: openregister is not installed. Reproduce `Routes::standard()`
// locally — canonical routes first (minus any name `$extra` overrides), then
// `$extra`, then the SPA catch-all LAST so it never shadows a real route.
$canonicalRoutes = [
    ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
    ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
    ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
    ['name' => 'settings#update', 'url' => '/api/settings', 'verb' => 'PUT'],
    ['name' => 'settings#load', 'url' => '/api/settings/load', 'verb' => 'POST'],
    ['name' => 'preferences#getPreference', 'url' => '/api/preferences/{key}', 'verb' => 'GET'],
    ['name' => 'preferences#setPreference', 'url' => '/api/preferences/{key}', 'verb' => 'PUT'],
    ['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
    ['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET'],
];

$catchAllRoute = [
    'name'         => 'dashboard#catchAll',
    'url'          => '/{path}',
    'verb'         => 'GET',
    'requirements' => ['path' => '.+'],
    'defaults'     => ['path' => ''],
];

$extraNames = [];
foreach ($extra as $extraRoute) {
    if (isset($extraRoute['name']) === true) {
        $extraNames[(string) $extraRoute['name']] = true;
    }
}

$mergedRoutes = [];
foreach ($canonicalRoutes as $canonicalRoute) {
    if (isset($extraNames[$canonicalRoute['name']]) === true) {
        continue;
    }

    $mergedRoutes[] = $canonicalRoute;
}

$mergedRoutes   = array_merge($mergedRoutes, $extra);
$mergedRoutes[] = $catchAllRoute;

return ['routes' => $mergedRoutes];
