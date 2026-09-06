# Tasks: add-work-queue

- [x] 1.1 Add the `Queue` page (`/queue`), a `type: index` over `case` filtered to
  `assignee: "IS NULL"` and `isFinalStatus: false`, with the caseType folder sidebar
  and an empty state.
- [x] 1.2 Add its menu entry (order 15) and relocate it into the My work group above
  Assigned to me.
- [x] 1.3 Extend the work-navigation e2e spec from four surfaces to five, and add
  `/queue` to the navigation href assertions.
- [x] 1.4 Add e2e coverage asserting the queue is a strict subset of the case index
  and that an empty result renders the empty state.
- [x] 1.5 Record the queue in the My Work spec so the two surfaces describe each
  other.
