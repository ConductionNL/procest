# Design: create-procest-app

## Architecture Overview

Both Procest and Pipelinq follow the **softwarecatalog thin-client pattern**: a rich Vue 2 + Pinia frontend that queries OpenRegister directly, with a minimal PHP backend for settings/configuration only.

```
┌─────────────────────────────────────────────────┐
│                  Browser                         │
│  ┌──────────────┐      ┌──────────────┐         │
│  │   Procest     │      │   Pipelinq    │        │
│  │   Vue SPA     │      │   Vue SPA     │        │
│  │  Pinia Store  │      │  Pinia Store  │        │
│  └──────┬───────┘      └──────┬───────┘         │
│         │ fetch()              │ fetch()          │
└─────────┼──────────────────────┼─────────────────┘
          │                      │
          ▼                      ▼
┌─────────────────────────────────────────────────┐
│              OpenRegister API                    │
│  /api/objects/{register}/{schema}               │
│  CRUD, search, pagination, RBAC                 │
│                                                  │
│  ┌────────────────┐  ┌─────────────────┐        │
│  │ case-management │  │ client-management│       │
│  │ register        │  │ register         │       │
│  │                 │  │                  │       │
│  │ - case          │  │ - client         │       │
│  │ - task          │  │ - request        │       │
│  │ - status        │  │ - contact        │       │
│  │ - role          │  │                  │       │
│  │ - result        │  │                  │       │
│  │ - decision      │  │                  │       │
│  └─────────────────┘  └──────────────────┘       │
└─────────────────────────────────────────────────┘
```

Each app has a thin PHP backend for:
- Settings management (register/schema IDs)
- Auto-configuration on install (repair step)
- Admin settings page rendering

No own database tables. No entity CRUD controllers. No backend business logic for domain objects.

## API Design

### Procest Backend Endpoints (minimal)

#### `GET /api/settings`
Returns app configuration (register/schema mappings).

**Response:**
```json
{
  "success": true,
  "config": {
    "register": "5",
    "case_schema": "30",
    "task_schema": "31",
    "status_schema": "32",
    "role_schema": "33",
    "result_schema": "34",
    "decision_schema": "35"
  }
}
```

#### `POST /api/settings`
Saves register/schema configuration. Admin only.

**Request:**
```json
{
  "register": "5",
  "case_schema": "30",
  "task_schema": "31"
}
```

#### `GET /api/settings/status`
Returns app health status (OpenRegister available, schemas configured, object counts).

### Pipelinq Backend Endpoints (minimal)

Same pattern — `GET/POST /api/settings`, `GET /api/settings/status` with client-management register/schema IDs.

### Frontend → OpenRegister API (direct)

All data operations go directly to OpenRegister from the frontend:

```
GET    /apps/openregister/api/objects/{register}/{schema}              → List
GET    /apps/openregister/api/objects/{register}/{schema}/{id}         → Read
POST   /apps/openregister/api/objects/{register}/{schema}              → Create
PUT    /apps/openregister/api/objects/{register}/{schema}/{id}         → Update
DELETE /apps/openregister/api/objects/{register}/{schema}/{id}         → Delete
```

Query parameters: `_limit`, `_offset`, `_order`, `_search`, `_fields`, plus field-level filters.

## Database Changes

**None.** Both apps store all data in OpenRegister. No migrations needed.

Configuration stored via `IAppConfig` (Nextcloud key-value config store).

## OpenRegister Schema Definitions

### case-management register (Procest)

| Schema | Key Fields | Description |
|--------|-----------|-------------|
| `case` | `title`, `description`, `status`, `assignee`, `priority`, `created`, `updated`, `closed` | The core case entity |
| `task` | `title`, `description`, `status`, `assignee`, `case`, `dueDate`, `priority` | Tasks within a case |
| `status` | `name`, `description`, `order`, `isFinal` | Status definitions (configurable workflow) |
| `role` | `name`, `description`, `permissions` | Role definitions for case participants |
| `result` | `name`, `description`, `case` | Case outcome/result |
| `decision` | `title`, `description`, `case`, `decidedBy`, `decidedAt` | Decisions made on a case |

### client-management register (Pipelinq)

| Schema | Key Fields | Description |
|--------|-----------|-------------|
| `client` | `name`, `email`, `phone`, `type` (person/organization), `address`, `notes` | Client entity |
| `request` | `title`, `description`, `client`, `status`, `priority`, `requestedAt`, `category` | Request/verzoek — the pre-state of a case |
| `contact` | `name`, `email`, `phone`, `role`, `client` | Contact person linked to a client |

## Nextcloud Integration

### Controllers (per app)
- `DashboardController` — serves the main Vue SPA page (`templates/index.php`)
- `SettingsController` — register/schema configuration CRUD

### Services (per app)
- `SettingsService` — reads/writes config from `IAppConfig`

### Settings Registration (per app)
- `AdminSettings` — renders the admin settings Vue entry point
- `AdminSection` — registers the section in Nextcloud settings sidebar

### Repair Steps (per app)
- `InitializeSettings` — auto-detects or creates register/schemas on install

### DI Registration (`Application.php`)
```php
class Application extends App implements IBootstrap {
    const APP_ID = 'procest'; // or 'pipelinq'

    public function register(IRegistrationContext $context): void {
        $context->registerService(SettingsService::class, function($c) {
            return new SettingsService(
                $c->get(IAppConfig::class),
                $c->get(LoggerInterface::class)
            );
        });
    }

    public function boot(IBootContext $context): void {
        // Nothing needed at boot for now
    }
}
```

## File Structure

Both apps share the same structure:

```
procest/                          pipelinq/
├── appinfo/                      ├── appinfo/
│   ├── info.xml                  │   ├── info.xml
│   └── routes.php                │   └── routes.php
├── lib/                          ├── lib/
│   ├── AppInfo/                  │   ├── AppInfo/
│   │   └── Application.php       │   │   └── Application.php
│   ├── Controller/               │   ├── Controller/
│   │   ├── DashboardController.php │  │   ├── DashboardController.php
│   │   └── SettingsController.php │   │   └── SettingsController.php
│   ├── Service/                  │   ├── Service/
│   │   └── SettingsService.php   │   │   └── SettingsService.php
│   ├── Repair/                   │   ├── Repair/
│   │   └── InitializeSettings.php│   │   └── InitializeSettings.php
│   ├── Settings/                 │   ├── Settings/
│   │   ├── AdminSettings.php     │   │   ├── AdminSettings.php
│   │   └── AdminSection.php      │   │   └── AdminSection.php
│   └── Sections/                 │   └── Sections/
│       └── SettingsSection.php   │       └── SettingsSection.php
├── src/                          ├── src/
│   ├── main.js                   │   ├── main.js
│   ├── settings.js               │   ├── settings.js
│   ├── pinia.js                  │   ├── pinia.js
│   ├── App.vue                   │   ├── App.vue
│   ├── store/                    │   ├── store/
│   │   ├── store.js              │   │   ├── store.js
│   │   └── modules/              │   │   └── modules/
│   │       ├── object.js         │   │       ├── object.js
│   │       ├── navigation.js     │   │       ├── navigation.js
│   │       └── settings.js       │   │       └── settings.js
│   ├── views/                    │   ├── views/
│   │   ├── Dashboard.vue         │   │   ├── Dashboard.vue
│   │   ├── cases/                │   │   ├── clients/
│   │   │   ├── CaseList.vue      │   │   │   ├── ClientList.vue
│   │   │   └── CaseDetail.vue    │   │   │   └── ClientDetail.vue
│   │   └── settings/             │   │   ├── requests/
│   │       └── Settings.vue      │   │   │   ├── RequestList.vue
│   │                             │   │   │   └── RequestDetail.vue
│   │                             │   │   └── settings/
│   │                             │   │       └── Settings.vue
│   ├── navigation/               │   ├── navigation/
│   │   └── MainMenu.vue          │   │   └── MainMenu.vue
│   └── components/               │   └── components/
│       └── (shared UI)           │       └── (shared UI)
├── templates/                    ├── templates/
│   └── index.php                 │   └── index.php
├── img/                          ├── img/
│   └── app.svg                   │   └── app.svg
├── l10n/                         ├── l10n/
│   ├── en.json                   │   ├── en.json
│   └── nl.json                   │   └── nl.json
├── webpack.config.js             ├── webpack.config.js
├── package.json                  ├── package.json
├── composer.json                 ├── composer.json
└── .github/                      └── .github/
    └── workflows/                    └── workflows/
```

## Translation / l10n

Both apps are multilingual from day one:
- Use `t('procest', 'key')` in Vue templates and `$this->l->t('key')` in PHP
- Provide base translations in `l10n/en.json` (English primary) and `l10n/nl.json` (Dutch)
- All user-facing strings wrapped in translation functions — no hardcoded text
- Nextcloud's Transifex integration handles additional languages

## Security Considerations

- **Authentication**: Nextcloud session auth (automatic for logged-in users)
- **CSRF**: Nextcloud `requesttoken` header on all API calls (automatic via `@nextcloud/axios` or manual with `OC.requestToken`)
- **RBAC**: Handled entirely by OpenRegister — no additional access control layer
- **Input validation**: Delegated to OpenRegister schema validation
- **CORS**: Not needed — same-origin requests only (Nextcloud app)

## NL Design System

- Use `@nextcloud/vue` components as the base (NcButton, NcSelect, NcModal, etc.)
- Compatible with nldesign app for government-standard theming
- Avoid hardcoded colors — use CSS variables
- Ensure WCAG AA contrast and accessibility

## Trade-offs

### Thin client vs. thick client
**Chosen: Thin client (like softwarecatalog)**
- Pro: Much less code, no DB migrations, leverages OpenRegister fully
- Pro: Frontend drives the experience — faster iteration
- Con: Complex business logic harder to implement without backend
- Con: Multiple API calls from frontend (no backend aggregation)
- Mitigation: If business logic grows, add targeted backend services later

### Two separate apps vs. one combined app
**Chosen: Two separate apps**
- Pro: Clear separation of concerns (cases vs. clients/requests)
- Pro: Can install independently — not everyone needs both
- Pro: Smaller, more focused codebases
- Con: Some code duplication (object store, settings pattern)
- Mitigation: Shared patterns are small and well-understood; copy is fine

### Vue 2 vs. Vue 3
**Chosen: Vue 2**
- Nextcloud ecosystem is standardized on Vue 2
- All `@nextcloud/vue` components are Vue 2
- Vue 3 migration can follow Nextcloud's timeline

### Native fetch vs. @nextcloud/axios
**Chosen: Native fetch (following softwarecatalog pattern)**
- Simpler, no extra dependency for API calls
- Manual `requesttoken` header required but straightforward
- Consistent with the existing pattern in the codebase
