# Delta Spec: Admin Settings — Settings Navigation Bug Fix

**Source**: `openspec/specs/admin-settings/spec.md`
**Scope**: UI/navigation fix — single settings button, proper localization. No spec changes to admin-settings requirements.

---

## ADDED Requirements (implicit from fix)

### REQ-NAV-001: Settings Navigation [MVP]

The app MUST provide a single, clearly labeled entry point to the admin settings page from the main navigation.

#### Scenario: Settings button in navigation footer
- **WHEN** the user views the Procest app
- **THEN** a "Settings" (or localized equivalent) button MUST appear in the navigation footer
- **AND** clicking it MUST navigate directly to the admin settings page (one click, no dropdown)

#### Scenario: Settings label uses app locale
- **WHEN** the app locale is English
- **THEN** the settings button MUST display "Settings"
- **WHEN** the app locale is Dutch
- **THEN** the settings button MUST display "Instellingen" (or the Dutch translation)

#### Scenario: No redundant navigation options
- **WHEN** the user clicks the settings button
- **THEN** the system MUST NOT show a dropdown with multiple options that lead to the same page
- **AND** the user MUST reach the admin page with a single click

---

## EXCLUDED Requirements

| Req | Description | Reason |
|-----|-------------|--------|
| REQ-ADMIN-001 through REQ-ADMIN-016 | Admin settings content and behavior | This change only fixes navigation; admin page content unchanged |
