# Proposal: create-procest-app

## Summary
Create two new Nextcloud apps — **Procest** (case management) and **Pipelinq** (client & request management) — as thin clients on top of OpenRegister. Both follow the softwarecatalog architectural pattern: rich frontend Pinia store, direct OpenRegister API interaction, minimal backend. Procest focuses on cases (the equivalent of "zaken" in zaakafhandelapp). Pipelinq handles clients and "verzoeken" (requests — the pre-state of a case, or a yet-to-be-determined case). Both apps are multilingual from the start using Nextcloud's l10n framework.

## Motivation
The zaakafhandelapp implements Dutch GEMMA-Zaken standards for case management but is tightly coupled to Dutch terminology and follows a "thick client" pattern with 15+ backend controllers and its own entity abstraction layer. By splitting the functionality into two focused apps and rebuilding as thin clients on OpenRegister, we get:
- **Separation of concerns**: cases (Procest) vs. clients & requests (Pipelinq)
- **Simpler codebase**: no own DB entities, leverages OpenRegister's CRUD, search, pagination, and RBAC
- **International reach**: multilingual from day one, English as primary language
- **Consistency**: same architecture as softwarecatalog — familiar patterns for the team

## Affected Projects
- [ ] Project: `procest` (NEW) — Case management app
- [ ] Project: `pipelinq` (NEW) — Client & request management app
- [ ] Project: `openregister` — Register and schema definitions for both apps
- [ ] Project: `zaakafhandelapp` — Functional reference only; no changes

## Scope

### In Scope

**Procest (Case Management)**
- New Nextcloud app scaffolding (appinfo, routes, webpack, Vue 2 + Pinia)
- Register: `case-management` with schemas for cases, tasks, statuses, results, roles, decisions
- Pinia-based object store querying OpenRegister directly
- Core views: Dashboard, Cases (list/detail), Tasks, Search
- Minimal backend: SettingsController + auto-configuration service
- Multilingual: Nextcloud l10n with English as primary, Dutch included

**Pipelinq (Client & Request Management)**
- New Nextcloud app scaffolding (same stack as Procest)
- Register: `client-management` with schemas for clients, requests (verzoeken), contacts
- Same thin-client architecture as Procest
- Core views: Dashboard, Clients (list/detail), Requests (list/detail), Search
- Minimal backend: SettingsController + auto-configuration service
- Multilingual: same approach as Procest

**GitHub Repositories**
- Create `ConductionNL/procest` repository
- Create `ConductionNL/pipelinq` repository

**Shared Patterns**
- NL Design System compatible theming
- Dynamic navigation from available schemas (like softwarecatalog's MainMenu)
- RBAC handled entirely by OpenRegister — no additional access control layer

### Out of Scope
- GEMMA-Zaken API compliance (stays in zaakafhandelapp)
- Own database entities or migrations — all data lives in OpenRegister
- Elasticsearch integration (may be added later)
- Cloud Events / webhooks (may be added later)
- Migration tooling from zaakafhandelapp
- Case-to-request linking between apps (future feature)

## Approach
1. **Scaffold both apps** with standard Nextcloud app structure (info.xml, routes, webpack, Vue 2 + Pinia, l10n)
2. **Define registers/schemas** in OpenRegister — `case-management` for Procest, `client-management` for Pipelinq
3. **Build shared object store pattern** — Pinia store with actions that construct OpenRegister API URLs, handle pagination, search, and CRUD (same pattern for both apps)
4. **Build views** for each entity type — list/detail pages using Nextcloud Vue components
5. **Minimal backend** — SettingsController for register/schema config, auto-config service per app
6. **Navigation** — Dynamic menu from schemas (like softwarecatalog)
7. **Translations** — Set up l10n from day one with English + Dutch

## Cross-Project Dependencies
- **OpenRegister** — Must be installed and active; both apps store all data there
- **NL Design** — Optional but recommended for government-standard theming
- **zaakafhandelapp** — No runtime dependency; functional reference only
- **Procest ↔ Pipelinq** — Independent apps, no direct dependency (future linking possible)

## Rollback Strategy
- Both apps are standalone — disabling either has no impact on other apps
- Data lives in OpenRegister and persists independently
- Simply disable the app in Nextcloud admin to roll back

## Capabilities

### New Capabilities
- `procest-app-scaffold` — Nextcloud app scaffolding, build system, l10n setup for Procest
- `procest-case-management` — Case CRUD, task management, statuses, roles, decisions
- `procest-object-store` — Pinia store pattern for OpenRegister interaction
- `pipelinq-app-scaffold` — Nextcloud app scaffolding, build system, l10n setup for Pipelinq
- `pipelinq-client-management` — Client CRUD, request (verzoek) management, contacts
- `pipelinq-object-store` — Pinia store pattern for OpenRegister interaction

### Modified Capabilities
(none — these are new apps)
