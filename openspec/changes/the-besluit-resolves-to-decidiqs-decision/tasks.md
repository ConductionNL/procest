# Tasks

## 1. Resolution

- [x] 1.1 `decision_schema` falls back to decidiq's `decision`.
- [x] 1.2 The lookup uses the `(application, slug)` pair, not the slug alone.
- [x] 1.3 It resolves LAST, so a configured instance keeps its own schema.
- [x] 1.4 It fails to '' when decidiq is absent or carries no such schema.
- [x] 1.5 It applies to `decision_schema` alone, not to sibling keys whose names
      contain `decision`.

## 2. Verification

- [x] 2.1 The same lookup with and without a local value — the only pair that
      tells "resolves last" from "resolves at all".
- [x] 2.2 Absent peer, and a peer with no such schema.
- [x] 2.3 The four sibling keys are asserted unaffected, so "the fallback works"
      cannot be confused with "the fallback fires on anything decision-shaped".
- [x] 2.4 Verified by disabling the fallback: exactly one of the six goes red.
- [x] 2.5 3,016 tests green.

## 3. Fixed while here

- [x] 3.1 Three assertions in `RequestDecisionHeartbeatRecoveryTest` expected
      `STATUS_STOPPED` from a run its own step killed. openregister#3425 drew
      that distinction deliberately — `stopped` is an operator halting a run,
      `failed` is the run dying of its own step — and left this app's tests red
      on development. Only the name changed; all three cases are still the kind
      no heartbeat fixes.

## 4. Not in this change

- [ ] 4.1 Retiring this app's own `decision` schema, and migrating its besluiten.
      On every existing instance the fallback is inert because the key is set.
      Moving the rows carries a decision — `case` was deliberately not added to
      decidiq's Decision — and belongs in its own change with its own command.
