# Proposal: Fix Settings Bug

## Summary

Fix two related UI bugs in the Procest app navigation: (1) the settings button shows "Instellingen" (Dutch) instead of using proper localization; (2) the settings dropdown has two options — "Case Types" and "Configuration" — that both navigate to the same page (AdminRoot). Replace with a single "Settings" button that goes directly to the admin page.

## Problem

1. **Wrong language**: The settings button in the app footer shows "Instellingen" (Dutch) when it should use the app's locale (English by default, or user's language via l10n).
2. **Redundant dropdown**: Clicking the settings button opens a dropdown with "Case Types" and "Configuration". Both options route to the same component (AdminRoot at `/settings` and `/case-types`). Users must click twice to reach settings; one direct button is sufficient.

## Scope — MVP

**In scope:**
- Replace the settings dropdown with a single "Settings" button that navigates directly to the admin page
- Use proper localization: `t('procest', 'Settings')` so the label respects the app's l10n (en.json, nl.json)
- Remove the redundant `/case-types` route or consolidate routing (both currently render AdminRoot)

**Out of scope:**
- New features or requirements
- Changes to the admin page content (Configuration + Case Type Management sections remain as-is)

## Approach

1. In `MainMenu.vue`: Replace `NcAppNavigationSettings` (dropdown with two items) with a single `NcAppNavigationItem` in the footer that links directly to the Settings route
2. Use `t('procest', 'Settings')` for the button label
3. Add "Settings" to `l10n/en.json` and `l10n/nl.json` if not present
4. Remove the redundant `/case-types` route from the router (or keep for backwards compatibility but remove from nav)

## Capabilities

### Modified Capabilities

- **admin-settings**: UI/navigation fix — single settings entry point, proper localization. No spec changes.

## Impact

- **Frontend**: `src/navigation/MainMenu.vue`, `src/router/index.js`, `l10n/en.json`, `l10n/nl.json`
- **Backend**: None
- **Dependencies**: Nextcloud Vue (NcAppNavigationItem), existing l10n

## Dependencies

- admin-settings spec (navigation is part of admin access)
- Nextcloud Vue components
