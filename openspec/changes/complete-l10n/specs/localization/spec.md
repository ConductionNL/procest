# Spec: Localization

**Scope**: Complete l10n for Procest app (en + nl)

---

## ADDED Requirements

### REQ-L10N-001: Complete Translation Coverage [MVP]

All user-facing strings in the Procest app MUST have entries in both `l10n/en.json` and `l10n/nl.json`.

#### Scenario: English locale
- **WHEN** the user's Nextcloud language is set to English
- **THEN** all strings displayed by the Procest app MUST be in English
- **AND** no fallback keys (untranslated strings) MUST be visible

#### Scenario: Dutch locale
- **WHEN** the user's Nextcloud language is set to Dutch (Nederlands)
- **THEN** all strings displayed by the Procest app MUST be in Dutch
- **AND** no fallback keys (untranslated strings) MUST be visible

#### Scenario: Placeholder preservation
- **WHEN** a translation contains placeholders (e.g., `{field}`, `{count}`, `{days}`)
- **THEN** the placeholder syntax MUST be preserved in both en and nl
- **AND** the translated text around placeholders MAY differ per language

### REQ-L10N-002: Key Extraction [MVP]

All keys added to l10n files MUST correspond to strings already used in the codebase via `t('procest', '...')`.

#### Scenario: No orphan keys
- **WHEN** a key exists in l10n
- **THEN** that key MUST be used in at least one `t('procest', key)` call in `src/`
- **OR** it was present in the original l10n before this change

#### Scenario: Exploration as source
- **WHEN** adding keys
- **THEN** use `exploration.md` as the definitive list of missing keys
- **AND** keys MUST be added in the exact form used in code (including casing, e.g. "New Case" vs "New case")
