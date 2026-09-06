# Tasks

- [x] Add a case-ids reassignment operation, in its own service
- [x] Read `reassignedFrom` per case, not per batch
- [x] Leave a case already assigned to the receiver alone
- [x] Share the write via the `WritesReassignments` trait
- [x] Expose it at `POST /api/reassignments/selection`
- [x] Declare the `reassign` bulk action on the Cases page
- [x] Open the dialog from the function handler, since `open-modal` has no listener
- [x] Retire the Substitutions & reassignment admin entry
- [ ] Close the `open-modal` gap in nextcloud-vue so the modal can be declared
