# A ketenpartner is an organisation, so it should be one

## Why

dossiq keeps its own `partnerOrganization` schema for the external parties it
shares cases with. Every instance therefore has two answers to "which
organisations does this system know about": OpenRegister's Organisation, and
dossiq's private copy.

It was kept for a reason. Case sharing needs three things a generic
organisation did not carry: `defaultPermissionLevel` (what a partner may do
with a shared case by default), `qualityScore` and `qualityStatus`. A partner
projected onto the old Organisation would have lost all three, which is the
same shape of loss the parafering projection turned up: a projection enabled
onto a model that cannot hold what the source held does not fail, it forgets.

Organisation now carries them, along with `oin`, `contactEmail`, `slug`,
`name`, `groups` and `active`. That is every property `partnerOrganization`
declares, so the target is a superset field for field, and the check that had
to come first passes.

## What changes

`occ dossiq:migrate-partners` reads every `partnerOrganization` row and inserts
one Organisation per partner, preserving the partner's uuid so case shares that
reference it keep resolving.

The Organisation is marked `type: partner` and `isLocalTenant: false`. A
ketenpartner is somebody else's organisation that this instance shares cases
WITH, and once partners and tenants live in the same table, that distinction
has to be recorded rather than inferred.

Idempotent by the partner's own id rather than by slug, which is where this
differs from the tenant migration. `partnerOrganization` requires only `name`
and `contactEmail`, so a slug-less partner is ordinary data and keying on the
slug would have failed the migration on exactly the rows it exists to move.
Deriving a slug from the name is worse: two partners sharing a name derive one
slug, and the second is then skipped as already migrated, silently merging two
organisations into one. A row with no id is refused, because without one there
is no key at all.

## What this change does NOT do

It does not retire the `partnerOrganization` schema, the Partners settings page
or `PartnerDetail`. Rows move first. Retiring the surface that writes them in
the same change would land a migration on data still being created behind it.

That retirement is the next change, and it needs the same evidence the other
removals in `menu-layout.json` carry: the count of rows actually moved on a
real instance, not a dev one holding none.
