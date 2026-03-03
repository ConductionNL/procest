# Tasks: Fix Settings Bug

## 1. Navigation Fix [MVP]

- [ ] 1.1 Replace NcAppNavigationSettings with single NcAppNavigationItem in MainMenu.vue footer — use `:name="t('procest', 'Settings')"` and `:to="{ name: 'Settings' }"`, include Cog icon
- [ ] 1.2 Add "Settings" to l10n/en.json and "Instellingen" to l10n/nl.json (if not already present)

## 2. Route Cleanup

- [ ] 2.1 Remove `/case-types` route from router (or add redirect to `/settings` for backwards compatibility)

## Verification Tasks

- [ ] V01 Manual test: Settings button appears in footer with correct label (English: "Settings", Dutch: "Instellingen")
- [ ] V02 Manual test: Single click on Settings navigates to admin page (no dropdown)
- [ ] V03 Manual test: Admin page shows both Configuration and Case Type Management sections
