# Supplier portal

## ADDED Requirements

### Requirement: The case supplier invoice is namespaced (REQ-SP-030)

The supplier invoice schema SHALL be `caseSupplierInvoice` and SHALL NOT be
`supplierInvoice`.

A schema slug is global per organisation and `SchemaMapper::find()` matches
`LOWER(slug)`, so a bare `supplierInvoice` was answered for by shillinq's
accounts-payable record as readily as by this app's case-side view. shillinq
owns the invoice and keeps the bare slug.

They SHALL be renamed apart and SHALL NOT be folded. The two share only
`invoiceDate` and `dueDate`; there is no invoice number and nothing else that
identifies the document.

A repair step SHALL rename the row IN PLACE before the register import, scoped
to this app's own rows, and SHALL be registered in both the post-migration and
the install block. Enabling the app is a fresh install as far as Nextcloud is
concerned, so an instance holding the old slug reaches the import through
either path.

#### Scenario: The slug is renamed in place

- **GIVEN** an install carrying a dossiq-owned `supplierInvoice` schema
- **WHEN** the repair step runs
- **THEN** the row keeps its schema id, and so its shard table and objects.

#### Scenario: The register declares the namespaced slug

- **WHEN** the register JSON is read
- **THEN** `caseSupplierInvoice` is declared and `supplierInvoice` is not, so the
  import cannot create a second schema behind the renamed row.
