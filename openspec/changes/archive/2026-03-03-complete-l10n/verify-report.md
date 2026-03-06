# Verification Report: Complete l10n

**Date**: 2026-03-03  
**Change**: complete-l10n (archived)  
**Status**: PARTIAL — automated passed, manual Dutch verification failed

---

## Automated Checks

| Check | Result |
|-------|--------|
| en.json valid JSON | OK |
| nl.json valid JSON | OK |
| en.json and nl.json identical keys | OK (302 total) |
| Placeholders preserved in nl.json | OK (36 keys with placeholders) |
| All keys used in code exist in l10n | OK (275 keys) |

---

## Fixes Applied During Verify

18 keys were found in code but missing from l10n. Added to both en.json and nl.json:

- `{days} days`
- `No tasks yet`
- `Case Type Management`
- `Manage case types and their configurations`
- `Case type schema`
- `Status type schema`
- `Initiator action`
- `Handler action`
- `e.g., P56D (56 days)`
- `e.g., P42D (42 days)`
- `e.g., P28D (28 days)`
- `Extension allowed`
- `Publication required`
- `Publication text`
- `Reference process`
- `Keywords`
- `Comma-separated keywords`
- `Valid from`

---

## Manual Verification (Failed)

Dutch texts are not displayed in the app when Nextcloud language is set to Nederlands. All manual verification tasks failed. A follow-up change will be created to fix Dutch display.

- [x] V01: Set Nextcloud language to Dutch, verify dashboard labels in Dutch — **FAILED**
- [x] V02: Verify case detail, case type admin, and task forms show Dutch labels — **FAILED**
- [x] V03: Verify error messages and validation text appear in Dutch — **FAILED**
- [x] V04: Verify Overdue panel, My Work, and relative time strings in Dutch — **FAILED**

---

## Verification Script

Run `node openspec/verify-l10n.js` from the procest app root to re-run automated checks.
