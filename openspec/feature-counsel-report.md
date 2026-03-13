# Feature Counsel Report: Procest

**Date:** 2026-02-25
**Method:** 8-persona feature advisory analysis against OpenSpec specifications
**Personas:** Henk Bakker, Fatima El-Amrani, Sem de Jong, Noor Yilmaz, Annemarie de Vries, Mark Visser, Priya Ganpat, Jan-Willem van der Berg

---

## Executive Summary

Procest has a strong foundation as an internal case management tool for municipal case handlers, with solid data modeling (CMMN 1.1, Schema.org) and a clean architectural separation via OpenRegister. However, across all 8 personas, the most striking gap is the complete absence of a citizen-facing interface -- 4 of 8 personas independently flagged this as their top priority. The system is built entirely for ambtenaren (civil servants) while ignoring the citizens and businesses whose cases are being managed. Beyond this fundamental gap, three cross-cutting themes emerged: (1) the API layer lacks standards compliance (no OpenAPI spec, no ZGW endpoints, no NLGov API Design Rules adherence), blocking municipal adoption and third-party integration; (2) accessibility and plain language requirements are underspecified despite WCAG AA claims -- no minimum font sizes, touch targets, B1 language level, or dark mode; and (3) security/compliance features are decorative rather than enforceable -- confidentiality levels do not restrict access, retention periods are not enforced, and audit logs cannot be exported.

---

## Consensus Features (suggested by 3+ personas)

| # | Feature | Suggested by | Priority | Impact |
|---|---------|-------------|----------|--------|
| 1 | **Citizen portal / "Mijn Zaken"** | Henk, Fatima, Mark, Jan-Willem | MUST | Citizens and businesses have no way to track their case status. Legally required under Wmebv/Awb. 4/8 personas flagged this as their #1 need. |
| 2 | **Plain language B1 Dutch** | Henk, Fatima, Jan-Willem | MUST | All citizen-facing text must be at B1 reading level. Specs use jargon like "Besluitvorming", "CasePlanModel", "P56D" that excludes 2.5M low-literate Dutch adults. |
| 3 | **Bulk operations on list views** | Mark, Sem, Annemarie | SHOULD | No spec defines bulk reassign, bulk status change, or bulk delete. Essential for real-world case volumes. |
| 4 | **Proper URL-based routing (not hash)** | Sem, Mark, Annemarie | MUST | Hash-based routing (#/cases/123) breaks deep-linking, bookmarking, sharing, and browser back/forward. 3 personas independently flagged this. |
| 5 | **Email/SMS notifications** | Henk, Mark, Jan-Willem | SHOULD | Only Nextcloud internal notifications are specified. Citizens and external users need email/SMS to know when their case status changes. |
| 6 | **Data retention enforcement** | Noor, Mark, Annemarie | SHOULD | Retention periods are defined on result types but never enforced. Violates Archiefwet. Need automated retention checks and destruction workflows. |
| 7 | **CSV/Excel export** | Mark, Noor | MUST | No export capability anywhere in the specs. Needed for management reporting, WBSO, ENSIA compliance evidence, and data portability (AVG Art. 20). |
| 8 | **OpenAPI 3.0 specification** | Priya, Annemarie | MUST | No machine-readable API documentation. Table stakes for any government API in 2026. Blocks all third-party integration. |
| 9 | **ZGW API compatibility layer** | Priya, Annemarie | MUST | ZGW mapping exists only in documentation tables, not as actual endpoints. Municipalities cannot integrate without real ZGW Zaken/Catalogi/Besluiten API endpoints. |
| 10 | **NLGov API Design Rules v2** | Priya, Annemarie | MUST | Error format, pagination, versioning, and URL patterns do not comply. Mandatory for Dutch government APIs per Forum Standaardisatie. |

---

## Per-Persona Highlights

### Henk Bakker (Elderly Citizen, 78)
- **Top need**: Citizen portal with simple status tracking ("Stap 2 van 4")
- **Key missing feature**: Minimum font sizes (16px+), touch targets (44x44px), zoom support (200%)
- **Quote**: "Ik wil gewoon weten hoe het met mijn vergunning staat, zonder dat ik hoef te bellen."

### Fatima El-Amrani (Low-Literate Migrant, 52)
- **Top need**: Visual progress indicators with icons, not text labels
- **Key missing feature**: Multi-language support (Arabic, Turkish) and RTL CSS
- **Quote**: "I recognize apps by their icons, not by their names. Give me a folder icon for cases, a checkmark for tasks."

### Sem de Jong (Young Digital Native, 22)
- **Top need**: Dark mode, keyboard shortcuts, command palette (Cmd+K)
- **Key missing feature**: Global search across all entity types
- **Quote**: "Hash URLs in 2026? I can't share a filtered view with my colleague. Use proper URL paths."

### Noor Yilmaz (Municipal CISO, 36)
- **Top need**: Exportable audit logs with IP/session data for ENSIA compliance
- **Key missing feature**: Enforceable confidentiality levels (not just decorative metadata)
- **Quote**: "A 'geheim' case visible to all users with generic RBAC is a data breach waiting to happen."

### Annemarie de Vries (VNG Standards Architect, 38)
- **Top need**: ZGW API endpoints and publiccode.yml for GEMMA Softwarecatalogus listing
- **Key missing feature**: Pre-built case type templates for small municipalities
- **Quote**: "Without ZGW endpoints and publiccode.yml, I cannot recommend Procest to any municipality."

### Mark Visser (MKB Software Vendor, 48)
- **Top need**: CSV/Excel export on every list view for business reporting
- **Key missing feature**: External portal for tracking submitted cases as a vendor/initiator
- **Quote**: "I cannot run a business on a system I cannot get data out of. Export is a non-starter blocker."

### Priya Ganpat (ZZP Developer, 34)
- **Top need**: Published OpenAPI spec and webhook/event system for integrations
- **Key missing feature**: RFC 7807 error format and rate limit documentation
- **Quote**: "I opened DevTools and I can see the requests, but there is no Swagger UI, no try-it-out. I have to read source code."

### Jan-Willem van der Berg (Small Business Owner, 55)
- **Top need**: Contact information (phone/email) accessible from every screen
- **Key missing feature**: Simple case status tracker modeled after PostNL package tracking
- **Quote**: "Dit is een systeem voor ambtenaren. Waar ben IK in dit verhaal?"

---

## Feature Suggestions by Category

### Accessibility & Inclusivity

| # | Feature | Personas | Priority | Notes |
|---|---------|----------|----------|-------|
| 1 | Minimum 16px base font, 44x44px touch targets | Henk, Fatima | MUST | No concrete sizing specs despite WCAG AA claims |
| 2 | B1 Dutch language level for all user-facing text | Henk, Fatima, Jan-Willem | MUST | Specs use compound words like "vertrouwelijkheidaanduiding" (9 syllables) |
| 3 | Icons alongside ALL text labels | Henk, Fatima | SHOULD | Navigation, status indicators, buttons, filter options need paired icons |
| 4 | Dark mode / prefers-color-scheme support | Sem | SHOULD | No dark mode variants specified for any color references |
| 5 | RTL (right-to-left) CSS support | Fatima | SHOULD | Prerequisite for Arabic language support; use CSS logical properties |
| 6 | Multi-language: Arabic, Turkish, Frisian | Fatima, Annemarie | SHOULD | Only English/Dutch specified; excludes 600K+ residents |
| 7 | prefers-reduced-motion support | Sem | SHOULD | Kanban drag, status transitions, loading animations need motion query respect |
| 8 | 200% zoom support verified | Henk | MUST | No spec mentions zoom behavior |
| 9 | Focus management for SPA navigation | Sem | SHOULD | After view transitions, focus must move to new content, not stay on invisible elements |
| 10 | Visible "Terug" (back) button on all detail pages | Henk | SHOULD | Users fear using browser back button; need explicit in-app navigation |

### Security & Compliance

| # | Feature | Personas | Priority | Standard |
|---|---------|----------|----------|----------|
| 1 | Audit log export (CSV/PDF) | Noor, Mark | MUST | BIO2 12.4.1, ENSIA evidence |
| 2 | IP/session/user-agent in audit trail | Noor | MUST | BIO2 12.4.1 source identification |
| 3 | Enforceable confidentiality levels | Noor | MUST | ISO 27002:2022 clause 5.10 |
| 4 | Soft-delete with retention-aware destruction | Noor, Priya | SHOULD | Archiefwet, data integrity |
| 5 | Data retention enforcement automation | Noor, Annemarie, Mark | SHOULD | Archiefwet 1995 |
| 6 | Permission overview / access matrix | Noor | SHOULD | ISO 27002:2022 clause 8.3 |
| 7 | Admin action audit logging | Noor | SHOULD | Config changes need same audit rigor as case operations |
| 8 | DPIA documentation / privacy-by-design section | Noor, Annemarie | SHOULD | AVG article 35 |
| 9 | Failed authentication logging | Noor | SHOULD | BIO2 9.4.2 |
| 10 | Four-eyes principle for critical transitions | Noor | COULD | ISO 27002:2022 clause 5.4 |

### API & Developer Experience

| # | Feature | Personas | Priority | Notes |
|---|---------|----------|----------|-------|
| 1 | Published OpenAPI 3.0 specification | Priya, Annemarie | MUST | No machine-readable API docs exist |
| 2 | ZGW API compatibility layer | Priya, Annemarie | MUST | Mapping exists in docs only, not as endpoints |
| 3 | RFC 7807 Problem Details error format | Priya, Annemarie | MUST | NLGov API Design Rules mandate |
| 4 | Webhook/event notification system | Priya | MUST | Real-time integration; no polling |
| 5 | API versioning strategy | Priya, Annemarie | MUST | No version segment in current URL pattern |
| 6 | Rate limit documentation + headers | Priya | SHOULD | Rate limiting exists but is undocumented |
| 7 | Allowed transitions in API responses | Priya | SHOULD | Expose `_allowedTransitions` to avoid client-side state machine |
| 8 | `_expand` query parameter for eager loading | Priya | SHOULD | Solve N+1 problem server-side, not just frontend caching |
| 9 | Cursor-based pagination option | Priya | SHOULD | Offset pagination breaks during concurrent modifications |
| 10 | Health check endpoint | Priya | SHOULD | `/api/health` for monitoring and CI pipelines |
| 11 | Bulk/batch API endpoint | Priya, Mark | SHOULD | Migration and batch operations need multi-record support |
| 12 | JSON-LD `@context` in responses | Priya, Annemarie | COULD | Schema.org types declared but not in API output |

### UX & Performance

| # | Feature | Personas | Priority | Notes |
|---|---------|----------|----------|-------|
| 1 | URL-based routing (replace hash routing) | Sem, Mark, Annemarie | MUST | Bookmarking, sharing, browser back/forward |
| 2 | Global search / command palette (Cmd+K) | Sem | SHOULD | Unified search across cases, tasks, decisions |
| 3 | Keyboard shortcuts for power users | Sem | SHOULD | N=new case, T=new task, G+D=go dashboard |
| 4 | Undo-via-toast instead of confirmation dialogs | Sem | SHOULD | Modern UX pattern; less flow interruption |
| 5 | Skeleton/shimmer loading on all views | Sem | SHOULD | Only dashboard specifies loading states |
| 6 | Inline editing on list views | Sem, Mark | SHOULD | Avoid opening detail page for single-field changes |
| 7 | Optimistic UI for all mutations | Sem | SHOULD | Only kanban drag specifies optimistic updates |
| 8 | Auto-refresh dashboard with staleness indicator | Sem | SHOULD | Manual refresh is outdated UX |
| 9 | Saved filters / bookmarkable filtered views | Mark, Sem | SHOULD | Persist filter state in URL and localStorage |
| 10 | Configurable page size (20/50/100) | Mark, Sem | COULD | 20 items too few for power users |
| 11 | Print-friendly case overview | Henk, Mark | COULD | For physical records and meetings |
| 12 | Empty state illustrations | Sem | COULD | Friendly, not just text |

### Standards & Interoperability

| # | Feature | Personas | Priority | Standard |
|---|---------|----------|----------|----------|
| 1 | publiccode.yml | Annemarie | MUST | Standard for Public Code; GEMMA listing prerequisite |
| 2 | NLGov API Design Rules v2 compliance | Annemarie, Priya | MUST | Forum Standaardisatie mandatory standard |
| 3 | GEMMA reference component mapping | Annemarie | SHOULD | Position in municipal application landscape |
| 4 | Common Ground 5-layer documentation | Annemarie | SHOULD | Document which layer each component belongs to |
| 5 | FSC (Federated Service Connectivity) readiness | Annemarie | SHOULD | Inter-organizational API communication |
| 6 | EUPL-1.2 license consideration | Annemarie | SHOULD | EC recommended; many procurement frameworks require it |
| 7 | Notificatiecomponent / Abonnementen API | Annemarie | COULD | ZGW event-driven integration |
| 8 | DigiD / eHerkenning integration path | Jan-Willem, Annemarie | COULD | Required if citizen portal is built |
| 9 | e-Depot / archiving integration | Annemarie | SHOULD | Archiefwet compliance for closed cases |
| 10 | ZGW enum values for confidentiality levels | Annemarie | SHOULD | Use `zaakvertrouwelijk` not `case_sensitive` |

### Business & Workflow

| # | Feature | Personas | Priority | Notes |
|---|---------|----------|----------|-------|
| 1 | Citizen portal / "Mijn Zaken" | Henk, Fatima, Mark, Jan-Willem | MUST | 4 personas; legally required (Wmebv) |
| 2 | CSV/Excel export on all list views | Mark, Noor | MUST | Reporting, compliance evidence, data portability |
| 3 | Bulk operations (reassign, status change, delete) | Mark, Sem, Annemarie | SHOULD | Essential for real-world case volumes |
| 4 | Email/SMS notifications for external users | Mark, Henk, Jan-Willem | SHOULD | Nextcloud-only notifications exclude non-Nextcloud users |
| 5 | Contact info (phone/email) on every page | Henk, Jan-Willem | SHOULD | Government accessibility fundamental |
| 6 | Pre-built case type templates | Annemarie, Jan-Willem | SHOULD | Lower barrier for small municipalities |
| 7 | Organisation-scoped dashboard | Mark | SHOULD | Business owners need company-wide view |
| 8 | Configurable case identifier format per type | Mark, Annemarie | SHOULD | Prefix like "VRG-2026-042" for recognition |
| 9 | Help/FAQ system with contextual guidance | Henk, Jan-Willem | SHOULD | No help system specified anywhere |
| 10 | Data import/migration from existing zaaksystemen | Annemarie | SHOULD | Adoption impossible without migration path |

---

## Recommended Actions

### MUST (blocking for key user groups)

1. **Build a citizen portal ("Mijn Zaken")** — 4 of 8 personas identified this as their #1 need. Citizens and businesses have no way to track case status. This is not just a UX gap; under the Wmebv and Awb, citizens have a legal right to follow their case progress digitally. Model it after PostNL package tracking: simple steps, visual progress, plain language, and a phone number.

2. **Publish OpenAPI 3.0 specification and adopt NLGov API Design Rules v2** — Without a machine-readable API contract, no developer can build integrations without reading source code. Without NLGov compliance (RFC 7807 errors, standard pagination, versioning), the API fails procurement evaluation at every municipality. This is table stakes for Dutch government APIs.

3. **Implement ZGW API compatibility layer** — The specs claim ZGW mapping but no actual ZGW endpoint exists. At minimum, provide read-only Zaken, Catalogi, and Besluiten API endpoints. Without this, Procest cannot participate in the municipal zaaksysteem-keten and Annemarie cannot recommend it.

4. **Add publiccode.yml** — Required for GEMMA Softwarecatalogus listing, developer.overheid.nl, and Standard for Public Code compliance. A 30-minute task with massive visibility impact.

5. **Replace hash-based routing with proper URL-based routing** — 3 personas independently flagged this as a fundamental limitation. Hash URLs break bookmarking, sharing, deep-linking from other systems, and browser back/forward. Migrate to vue-router with history mode.

6. **Add CSV/Excel export on all list views** — No export capability exists anywhere. Blocks business reporting, ENSIA compliance evidence, data portability (AVG Art. 20), and management oversight.

### SHOULD (significant improvement for multiple personas)

1. **Mandate B1 Dutch language level for all citizen-facing text** — Specs use jargon ("Besluitvorming", "vertrouwelijkheidaanduiding", "CasePlanModel") that excludes 2.5M low-literate Dutch adults. Add a cross-cutting NFR for B1 plain language.

2. **Make confidentiality levels enforceable, not decorative** — Currently, setting a case to "geheim" does not restrict access. Link confidentiality levels to RBAC so cases at "zaakvertrouwelijk" or higher are invisible without explicit role assignment.

3. **Implement audit log export with source identification** — Add IP address, session ID, and user agent to every audit entry. Provide export (CSV/PDF) for ENSIA self-evaluation. Without this, municipalities cannot produce BIO2 compliance evidence.

4. **Implement soft-delete with retention-aware destruction** — Hard deletion of cases with Archiefwet retention obligations is a compliance violation. All deletion must be soft-delete with configurable destruction after retention period.

5. **Add bulk operations on all list views** — Bulk reassign, bulk status change, and bulk delete are essential for real-world case volumes. 3 personas flagged this independently.

6. **Implement webhook/event notification system** — Real-time integration capability. The audit trail already captures events; expose them as configurable outbound webhooks with documented payloads.

7. **Add email/SMS notifications for case status changes** — Only Nextcloud internal notifications are specified. Citizens and external users (who do not have Nextcloud accounts) need email/SMS.

8. **Provide pre-built case type templates** — Ship with Omgevingsvergunning, Subsidieaanvraag, and Klacht templates. Most small municipalities lack the expertise to configure CMMN-based case types from scratch.

9. **Specify minimum font sizes (16px+) and touch targets (44x44px)** — No concrete sizing specs exist despite WCAG AA claims. Essential for elderly users and mobile users.

10. **Add icons alongside all text labels** — Navigation items, status indicators, buttons, and filter options all need paired icons for low-literate users.

### COULD (nice-to-have, improves specific persona experience)

1. **Add dark mode / prefers-color-scheme support** — Important for young digital natives and evening use. All color references need dark mode variants with verified contrast.

2. **Add global search / command palette (Cmd+K)** — Power user productivity; unified search across cases, tasks, and decisions.

3. **Add keyboard shortcuts for common actions** — N=new case, T=new task, /=focus search, ?=show shortcut map.

4. **Multi-language support (Arabic, Turkish, Frisian)** — Expands accessibility to 600K+ additional residents. Requires RTL CSS support.

5. **DigiD / eHerkenning integration path** — Required if citizen portal is built; standard Dutch government authentication.

6. **SIEM integration for audit events** — Syslog forwarding or webhook triggers for Security Operations Center monitoring.

7. **Print-friendly case overview** — For physical records, meetings, and citizens who prefer paper.

8. **Undo-via-toast pattern instead of confirmation dialogs** — Modern UX pattern reducing flow interruption.

---

## Potential OpenSpec Changes

These features could be turned into OpenSpec changes using `/opsx:new`:

| Change Name | Description | Related Personas | Estimated Complexity |
|-------------|-------------|-----------------|---------------------|
| `citizen-portal` | Citizen-facing "Mijn Zaken" case status tracker with visual progress, plain language, and contact info | Henk, Fatima, Mark, Jan-Willem | XL |
| `openapi-spec` | Published OpenAPI 3.0 specification at well-known endpoint with Swagger UI | Priya, Annemarie | M |
| `zgw-api-layer` | Read-only ZGW Zaken, Catalogi, and Besluiten API compatibility endpoints | Priya, Annemarie | XL |
| `nlgov-api-compliance` | RFC 7807 errors, standard pagination, API versioning, HAL links | Priya, Annemarie | L |
| `publiccode-yml` | Add publiccode.yml to repository root | Annemarie | S |
| `url-routing` | Replace hash-based routing with vue-router history mode, URL state for filters | Sem, Mark, Annemarie | L |
| `data-export` | CSV/Excel export on case list, task list, decision list, and audit log | Mark, Noor | M |
| `bulk-operations` | Bulk reassign, bulk status change, bulk delete on all list views | Mark, Sem, Annemarie | L |
| `audit-compliance` | Audit log export, IP/session logging, admin action auditing, SIEM integration | Noor | L |
| `confidentiality-enforcement` | Link confidentiality levels to access control; restrict visibility based on classification | Noor | L |
| `soft-delete-retention` | Soft-delete for all entities with retention-aware physical destruction workflow | Noor, Priya | M |
| `plain-language-b1` | B1 language level requirement, status notification text guidelines, jargon glossary | Henk, Fatima, Jan-Willem | M |
| `accessibility-sizing` | Minimum font sizes, touch targets, zoom support, focus management specifications | Henk, Fatima | M |
| `dark-mode` | Dark mode token variants, prefers-color-scheme detection, dark contrast verification | Sem | M |
| `webhook-events` | Outbound webhook system with configurable event subscriptions and documented payloads | Priya | L |
| `email-sms-notifications` | Email and SMS notification channels for case status changes | Mark, Henk, Jan-Willem | L |
| `keyboard-shortcuts` | Global command palette, keyboard shortcut map, power user navigation | Sem | M |
| `case-type-templates` | Pre-built Omgevingsvergunning, Subsidieaanvraag, Klacht templates with first-run wizard | Annemarie, Jan-Willem | M |
| `multi-language` | Arabic, Turkish, Frisian language support with RTL CSS | Fatima, Annemarie | XL |
| `icons-navigation` | Icon-paired labels throughout UI, bottom navigation on mobile | Henk, Fatima | M |
