# Procest — Case Management for Nextcloud

## Overview

Procest is a lightweight case management (zaakgericht werken) app for Nextcloud, built as a thin client on top of OpenRegister. It manages cases, tasks, statuses, roles, results, and decisions — the internal processing side of case management. Customer-facing concerns (clients, communication, intake) are handled by the companion app Pipelinq.

## Architecture

- **Type**: Nextcloud App (PHP backend + Vue 2 frontend)
- **Data layer**: OpenRegister (all data stored as register objects)
- **Pattern**: Thin client — Procest provides UI/UX, OpenRegister handles persistence
- **License**: AGPL-3.0-or-later

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for detailed architecture and data model decisions.

## Standards

**Principle: international standards for data storage, Dutch standards as API mapping layer.**

| Layer | Standard | Purpose |
|-------|----------|---------|
| **Primary** | CMMN 1.1 + Schema.org | International case management model |
| **Semantic** | Schema.org JSON-LD | Linked data interoperability |
| **API mapping** | ZGW APIs (Zaken, Besluiten, Catalogi) | Dutch government compatibility |
| **Supplementary** | BPMN 2.0, DMN | Process and decision modeling |
| **Nextcloud** | Deck, Calendar, Contacts | Native reuse |

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 8.1+, Nextcloud App Framework |
| Frontend | Vue 2.7, Pinia, @nextcloud/vue |
| Data | OpenRegister (JSON object storage) |
| Build | Webpack 5, @nextcloud/webpack-vue-config |
| i18n | English, Dutch |

## Data Model

| Object | Description | CMMN / Schema.org | ZGW Mapping |
|--------|-------------|-------------------|-------------|
| Case | Formal process with lifecycle | CasePlanModel / `Project` | Zaak |
| Task | Work item within a case | HumanTask / `Action` | — |
| Status | Lifecycle phase | Milestone / `ActionStatusType` | Status |
| Role | Participant relationship | — / `Role` | Rol |
| Result | Case outcome | Case outcome / `Action.result` | Resultaat |
| Decision | Formal decision | — / `ChooseAction` | Besluit |

## Features

### Implemented (MVP)

| Feature | Description | Status |
|---------|-------------|--------|
| Case Types | Configurable case types with status workflows | Done |
| Case Management | Create, view, edit cases with status timeline, deadlines, activity | Done |
| Task Management | BPMN lifecycle tasks (available/active/completed) within cases | Done |
| Dashboard | KPI cards, status chart, overdue panel, activity feed, my work preview | Done |
| Unified Search Deep Links | Cases and tasks appear in Nextcloud search with links to Procest detail views | Done |

### Planned

Features derived from zaakafhandelapp analysis and feature counsel.

| Feature | Description | Priority | Source |
|---------|-------------|----------|--------|
| OpenRegister Integration | Store all data in OpenRegister instead of local state | MUST | Architecture |
| Werkvooraad (Work Queue) | Dashboard section showing unassigned cases needing a handler | SHOULD | ZAA Dashboard |
| Snelle Start Sidebar | Quick-start sidebar with tabs: work instructions, your cases, your tasks | SHOULD | ZAA Dashboard |
| Roles & Decisions | Participant roles on cases, formal decisions (besluiten) | SHOULD | Spec (roles-decisions) |
| Citizen Portal ("Mijn Zaken") | Public case status tracker for citizens (legally required under Wmebv) | MUST | Feature Counsel |
| CSV/Excel Export | Export on all list views for reporting and compliance | MUST | Feature Counsel |
| ZGW API Compatibility | Read-only Zaken, Catalogi, Besluiten API endpoints | MUST | Feature Counsel |
| Bulk Operations | Bulk reassign, status change, delete on list views | SHOULD | Feature Counsel |
| Pre-built Case Type Templates | Omgevingsvergunning, Subsidieaanvraag, Klacht | SHOULD | Feature Counsel |
| Email/SMS Notifications | External notification channels for case status changes | SHOULD | Feature Counsel |

### Shared with OpenRegister

These features are implemented at the OpenRegister level, benefiting all consumer apps:

| Feature | Description |
|---------|-------------|
| Nextcloud Unified Search | Search provider with deep link registry (apps register URL patterns per schema) |
| Audit Trail | Comprehensive audit logging with export capability |
| Business Rules Engine | Server-side validation, status transitions, event hooks |

### Boundary with Pipelinq

Procest focuses on **internal case processing** (what happens after intake). Pipelinq handles the **customer-facing/CRM side** (who the case is about, communication with them).

| Concern | Procest | Pipelinq |
|---------|---------|----------|
| Cases (Zaken) | Owns | Links to (as context for requests) |
| Tasks (Taken) | Owns | — |
| Roles (Rollen) | Owns | — |
| Clients (Klanten) | References | Owns |
| Contact Moments | — | Owns |
| Messages (Berichten) | — | Owns |
| Employees (Medewerkers) | Via Nextcloud Users | Via Nextcloud Users |
| Search | Via OpenRegister unified search | Via OpenRegister unified search |

## Key Directories

```
procest/
├── appinfo/          # App manifest and routes
├── lib/              # PHP backend (controllers, services, repair)
├── src/              # Vue frontend source
├── docs/             # Architecture and documentation
├── openspec/         # OpenSpec specs and changes
├── l10n/             # Translations
└── templates/        # PHP templates
```

## Development

- **Local URL**: http://localhost:8080/apps/procest/
- **Requires**: OpenRegister app installed and enabled
- **Docker**: Part of openregister/docker-compose.yml
