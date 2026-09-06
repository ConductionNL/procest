# Namespace the case supplier invoice

## Why

`supplierInvoice` was claimed by this app and by shillinq. A schema slug is
global per organisation and `SchemaMapper::find()` matches `LOWER(slug)`, so
whichever row the lookup reached first answered for both.

shillinq owns the accounts-payable invoice: 27 fields against this app's 11.
This one is the case-side view, the document as it arrives in a dossier.

## Renamed apart

The two share `invoiceDate` and `dueDate`. No invoice number, nothing that
identifies the document. That is two records that reached for the same word,
not one invoice seen twice, so this side is renamed rather than folded.

No decoys: every quoted occurrence in the app was a slug reference, because a
camelCase compound does not collide with ordinary prose the way `contract` (a
GDPR lawful basis) and `resource` (a log-context key) do.

## Also fixes a stale stub

`StubApiDriftTest` was failing before this change on
`Service/Flow/FlowNodeRegistry`: openregister's constructor gained an optional
third argument and this app's stub was one short.

Worth stating how that was confirmed, because the test compares against a
**sibling openregister checkout** and so reports drift that depends on which
revision happens to sit next door. The remote's default branch still has two
arguments; `development` has three. The drift is real against `development`,
which is what the fleet builds on, so the stub gains the argument.
