# Tasks

- [x] Confirm Organisation carries every `partnerOrganization` property
- [x] `PartnerMigrationService`, idempotent by the partner's own id, preserving it
- [x] Mark migrated partners `type: partner` / `isLocalTenant: false`
- [x] Migrate a slug-less partner (slug is optional); refuse only a row with no id
- [x] `occ dossiq:migrate-partners`, registered in info.xml
- [x] Verify the superset claim against a running instance rather than the entity source
- [x] Run the migration on an instance and record the count
- [x] Move the rows on UPGRADE, not only on request: `MigratePartnersToOrganisations`
- [x] Point the share picker at Organisation, filtered to `type: partner`
- [x] Retire the Partners page, its detail page and its menu entry

## What the verification actually showed

The superset claim was checked against a live OpenRegister rather than the
entity class, because a property that exists on the entity and is dropped by
`jsonSerialize()` is invisible to the picker either way. `GET
/api/organisations` returns `type`, `isLocalTenant`, `defaultPermissionLevel`,
`qualityScore`, `qualityStatus`, `oin` and `contactEmail`, so the three fields
this migration existed to protect all survive the wire.

The instance carried no `partnerOrganization` rows, so the recorded count is
zero. That is a real result and not a passing one: it means the migration has
not been exercised against production-shaped data, and the PHPUnit suite is
what stands behind it until it has been.

## Why the surface could go in the same change

The original task list kept the page until the rows had moved, which was right.
What made retiring it safe here is that the move no longer depends on somebody
running a command: `MigratePartnersToOrganisations` runs it on upgrade. The
migration writes through the Organisation mapper and needs no acting user, so
unlike the workflow and LHS projections it does not have to be an occ command.

It runs under a system identity. An upgrade has no signed-in user and
OpenRegister is fail-closed for anonymous reads, so without the elevation the
migration would have found zero rows and reported "nothing to migrate" while
the rows sat there.

## Not done here

`partnerOrganization` stays in the register descriptor and in
`SchemaSlugMap`. Nothing in dossiq authors it any more, but dropping the schema
deletes the source of a migration that has only ever run against an empty
instance. It goes once the migration has been run somewhere with real partners
and the count has been confirmed.
