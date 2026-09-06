# Tasks

- [x] `StoreDescriptor` for `case-type-template`, and a thin `search()` over the engine client
- [x] `install()` writing through `ConfiguredRegistryService`, refusing record schemas
- [x] Registry settings with a write-only token
- [x] The Store page and the footer menu entry
- [x] Analysis stubs, so the injected engine types resolve without OpenRegister
- [x] Tests: the allowlist refusal, the write-only token, and the offline fallback
- [x] Verify the offline contract against a running instance, not just a mock

## Verified against a running instance

`GET /api/store/items` answers `{"outcome":"not_configured","cards":[]}` as an
administrator and `401` anonymously, which proves the engine's client resolved
rather than the analysis stub: the stub is never autoloaded at runtime.

The e2e watches the network while the page loads and asserts the request list
is empty, so "no registry means no network call" is proven by absence rather
than assumed from the outcome string. The first version of that assertion
compared each request's host against `page.url()`, which is `about:blank` while
the early requests fire, so it counted the app's own traffic as external. It
compares against the configured base URL now.

## Two findings the gates produced, both real

- `StoreOutline` was not registered in `src/icons.js`. An unregistered name
  renders NO icon in the navigation rather than a fallback glyph, so the entry
  would have shipped blank.
- The Store page needed a visual baseline. Adding it turned up that the
  neighbouring `cases list` baseline resolved the label `Cases`, renamed in
  dossiq#1646, and `shootByNav` guards its click behind `isVisible` — so that
  baseline had been a screenshot of the DASHBOARD, under the name `cases.png`,
  ever since.

## Deep links are paths, not hashes

dossiq runs on `createWebHistory`. `#/store` navigates nowhere and throws
nothing, so the first version of the deep-link test rendered the Dashboard and
would have passed against any page-shell locator. The same defect was sitting in
`work-navigation`'s "cases page stays reachable by direct link", which is fixed
here too.

## Follow-ups this exposes, not fixed here

- hermiq and integriq both label a local card grid "Store" without injecting
  `GenericStoreService`, which is the ADR-080 Decision 4 violation the ADR was
  written about.
- ADR-080 records no placement decision. Store sits in the main section in those
  apps and in the footer here, so one of the two conventions needs amending.
