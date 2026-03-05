# Design: Fix Settings Bug

## Context

The Procest app uses `MainMenu.vue` for navigation. The footer contains `NcAppNavigationSettings`, which renders a button (showing "Instellingen" when Nextcloud is in Dutch) and a dropdown with two items: "Case Types" (→ `/case-types`) and "Configuration" (→ `/settings`). Both routes render `AdminRoot.vue` — the same page with Configuration and Case Type Management sections. The dropdown is redundant and the button label uses Nextcloud's locale instead of the app's.

## Goals / Non-Goals

**Goals:**
- Single "Settings" button in the footer that navigates directly to the admin page (one click, no dropdown)
- Button label uses `t('procest', 'Settings')` for proper app l10n
- Remove redundant navigation options

**Non-Goals:**
- Changes to AdminRoot, Configuration, or Case Type Management content
- Backend changes

## File Map

### Modified Files

| File | Changes |
|------|---------|
| `src/navigation/MainMenu.vue` | Replace NcAppNavigationSettings (dropdown) with single NcAppNavigationItem in footer; use t('procest', 'Settings') |
| `l10n/en.json` | Add "Settings" translation if missing |
| `l10n/nl.json` | Add "Instellingen" (or "Settings") for Dutch if missing |
| `src/router/index.js` | Remove `/case-types` route (optional — keeps URL tidy; or keep for backwards compatibility) |

### Unchanged Files

| File | Reason |
|------|--------|
| `src/views/settings/AdminRoot.vue` | No changes |
| All other views | No changes |

## Design Decisions

### DD-01: Single NcAppNavigationItem in Footer

**Decision**: Replace `NcAppNavigationSettings` with a single `NcAppNavigationItem` in the footer slot.

**Rationale**: NcAppNavigationSettings creates a dropdown. With one destination, a dropdown adds an extra click. A direct NcAppNavigationItem gives one-click access. The NcAppNavigation footer slot accepts arbitrary content; NcAppNavigationItem can be used there.

**Implementation**: Put one NcAppNavigationItem with `:to="{ name: 'Settings' }"` and `:name="t('procest', 'Settings')"` in the footer template.

### DD-02: Keep or Remove /case-types Route

**Decision**: Remove the `/case-types` route from the router; use only `/settings`.

**Rationale**: Both routes render AdminRoot. Having two URLs for the same page is confusing. External links or bookmarks to `/case-types` would break — but this is a small app; such links are unlikely. Simpler to have one canonical URL.

**Alternative**: Keep both routes and redirect `/case-types` → `/settings` for backwards compatibility. Prefer removal for simplicity unless backwards compatibility is required.

### DD-03: Localization

**Decision**: Use `t('procest', 'Settings')` for the button label. Add "Settings" to l10n files.

**Rationale**: Procest has l10n/en.json and l10n/nl.json. The `t()` function uses the app's translation files. "Settings" in English; "Instellingen" in Dutch. Ensures the label matches the app's locale.

## Component Change

```
Before:
  NcAppNavigationSettings (button: "Instellingen" / framework locale)
    ├── NcAppNavigationItem "Case Types" → /case-types (AdminRoot)
    └── NcAppNavigationItem "Configuration" → /settings (AdminRoot)

After:
  NcAppNavigationItem "Settings" → /settings (AdminRoot)
    (single item in footer, no dropdown)
```

## Risks / Trade-offs

- **[Risk] NcAppNavigation footer slot structure** → The footer may expect NcAppNavigationSettings specifically. Mitigation: Check Nextcloud Vue docs; if needed, use NcAppNavigationSettings with one item and pass `:name="t('procest', 'Settings')"` to override the button label.
- **[Trade-off] Removing /case-types** → Any existing links to `/case-types` would 404. Low risk for a settings page.

## Open Questions

None — the fix is straightforward.
