# Design: dossiq-decisions-to-decidiq

The audit below was measured on `development` @ 82d0ee6a (2026-09-02). Classification:

- **A** already delegating to decidiq, or a migration artifact of that delegation: keep.
- **B** local decision logic with a clear decidiq equivalent: retired by this change.
- **C** grey area: recommendation recorded, nothing retired without Ruben's call.
- **BLOCKED** retirement agreed in principle, but decidiq (or openregister) cannot serve the need today; the missing capability is named.

## 1. Category A: already delegating (keep)

| Item | Evidence | Notes |
|---|---|---|
| `lib/Flow/DossiqRequestDecisionNode.php` | Raises via `ContractDecisionDelegationService::raiseDecision()` under `FlowRunAsScope`, suspends, resumes on the decidiq signal; fails closed (lines 275-316) | THE pattern this change holds everything else to |
| `lib/Service/ContractDecisionDelegationService.php` | Dispatches `DecisionRequestedEvent` (both `OCA\Decidiq` and `OCA\Decidesk` spellings), reads `isHandled()`/`getDecisionId()`, fails closed | The `dossiq-delegation-via-events` transport, live on development though that change's tasks are unchecked |
| `lib/Service/BezwaarDecisionDelegationService.php` | Thin sibling; fixes `bezwaar-decision` type | decidiq lists `bezwaar-decision` in `ALLOWED_TYPES` |
| `lib/Service/AdviceDelegationService.php` | Thin sibling; `advice` and `report-adoption` raises | Serves BAC advice, adviesAanvraag, consultatie, voorstel besluit |
| `lib/Service/Bezwaar/DecisionService.php` | `publish()` raises in decidiq and persists only `decisionRef` + notification audit (lines 242-276); "no local" authoring is explicit | Awb record keeping stays; the making is decidiq's |
| `lib/Service/Bezwaar/DecisionValidator.php` | Pure payload inspection; REQ-PDRD-004: Awb rules run BEFORE any raise so no invalid payload reaches decidiq | Validation of what we send, not deciding |
| `lib/Service/Bezwaar/CommitteeDelegationService.php` + `lib/Flow/DossiqEnsureCommitteeNode.php` | `GovernanceBodyRequestedEvent` typed-event command, fails closed, idempotent on (sourceApp, externalReference) | Owned by `migrate-committees-to-decidiq` (6/10 done) |
| `lib/Service/Parafeer/ParaferingDelegationService.php` | Sends the parafeerroute to decidiq's `ApprovalRoute` over the typed event seam | Owned by `parafering-to-decidiq` (8/10 done); runtime deliberately stays, see C-2 |
| `lib/Listener/DecisionConcludedListener.php` + `lib/Service/BesluitMaterialisationService.php` | Consumes decidiq's terminal event; materialises the ZGW Besluit as a projection into the `decision` schema; never authors locally | The sanctioned write door for decision outcomes |
| `lib/Controller/VoorstelBesluitController.php` | Registering a besluit on a voorstel raises a decidiq `report-adoption` decision; fails closed, no local fallback | |
| `lib/Listener/BezwaarDecisionListener.php` | Pure guard: blocks the "Decision on objection" transition when no published decision exists; reverts, never authors | Fail-closed by design; mutation-relevant, covered by existing unit tests |
| `lib/Listener/ParaafResumeListener.php` + `lib/Service/Parafeer/ParaafFlowLinkage.php` | A given paraaf wakes the run that asked for it; stamps linkage, writes no verdict | |
| `lib/Lifecycle/VoorstelSubmitGuard.php` | Read-only precondition on `startParafering` | |
| `src/components/tabs/BesluitvormingLeafTab.vue`, `case-decidesk-decisions` widget, menu removals (`Voorstellen`, `Advice`, `BesluitvormingAgenda`, `BezwaarCommitteesMenu`, `ParafeerroutesMenu`) | `consume-decidesk-besluitvorming-leaf` (12/12) + menu-layout removals with recorded waivers | Pages stay routable per ADR-044 |
| `lib/Repair/MigrateCommitteesToDecidiq.php`, `LinkInFlightContractDecisionsRepair.php`, `LinkInFlightRemainingDecisionsRepair.php` | Idempotent one-time migrations linking existing rows to decidiq | Migration artifacts, keep until sunset |
| `lib/Settings/register.d/72-committees-to-decidiq.json`, `74-parafering-to-decidiq.json` | Fragments ADD the link columns (`governanceBodyId`, `approvalRouteId`) to the local schemas; they are the recorded halves of the two migrations above, not leftovers | What remains after them is the schemas themselves, owned by the two open sibling changes |

## 2. Category B: retired by this change

| Item | Evidence it is local decision functionality | Evidence retirement is safe | decidiq equivalent |
|---|---|---|---|
| `src/components/tabs/CaseDecisionsTab.vue` + its `registry.js`/`customComponents.js` entries | Header comment: "Lists decision records ... with create/edit/delete via CnFormDialog" (local authoring of `decision` records) | Zero manifest references (assembled manifest greps clean), zero e2e/vitest references; only the registry import keeps it in the bundle | The besluitvorming leaf authors; the read-only `case-decisions` object-list widget displays outcomes |
| `src/dialogs/VoorstelCreateDialog.vue` | Creates local voorstel records for decision-making | Zero importers anywhere (src, registry, manifests, tests) | The leaf's create-proposal action |
| DMN deprecation markers (schema description in `95-dmn-decision-tables.json`, service/controller docblocks) | Local rule evaluation is decision functionality by the directive's mechanical definition | Markers only; nothing deleted | openregister `flow-decision-tables` (in parallel build), see BLOCKED-1 |
| `tests/Unit/Service/LocalDecisionAuthoringTest.php` (new) | n/a | n/a | Pins the boundary; see section 4 |

## 3. Category C: grey areas, for Ruben (nothing retired)

| # | Item | What it is | Recommendation |
|---|---|---|---|
| C-1 | Decision outcome storage on the case: `decision`, `decisionType`, `decisionDocument`, `bezwaarDecision`, `appealDecision` schemas; `case-decisions` and `case-kpis-decisions` widgets; `BezwaarDecisionDetail` page; `BrcController` (ZGW Besluiten API) | The case carries the outcome decidiq concluded (ZGW Besluit projection) plus the Awb/ZGW record shapes Dutch law and the Besluiten API require | **Case data, keep.** Exactly the distinction the directive predicted: storage of outcomes is not decision functionality. The projection writer is `BesluitMaterialisationService` and the structural test holds that line |
| C-2 | Parafering runtime: `BesluitvormingParafeerService` (520 lines), `BesluitvormingActivateHandler` + Tx node, `DossiqAskParaafNode`, `parafeeractie` schema, `/api/parafeer-actie`, `ParafeerActieDialog`, `SkipStepDialog`, `ParaferingAuditListener`, `ParaferingNotificationService`, `ParaferingAuditExportController` | Who signed what, on whose behalf, under which mandate: an administrative-law record (`onBehalfOf`, `mandate`), per DossiqAskParaafNode's own header | **Keep for now, revisit as its own change.** decidiq DOES run a full approval-route engine today (`ApprovalRouteService`, `ApprovalActionRequestedEvent`), so a runtime handover is implementable in principle. But `parafering-to-decidiq` deliberately kept chain state local, and the record is legally dossiq's case file. If Ruben wants the runtime in decidiq too, that is a coordinated change on both apps, not a leftover |
| C-3 | Besluitvorming publication mechanics: `BesluitvormingPublishHandler` + Tx node, `PublicationService` (DROP/LVBB), `publication#publish` route, `BesluitPublicatiePanel.vue` (unmounted) | Bekendmaking of a concluded besluit to the official-publications channel | **Keep.** decidiq's `DecisionPublicationService` only stamps `isPublished` on its own Decision object, and REQ-DCDH-007 explicitly leaves downstream side effects to the caller. Publishing the case's besluit to DROP/LVBB is case output handling. The unmounted panel is left in place pending this ruling |
| C-4 | `DossiqSetVoorstelStatusNode` | Writes the voorstel's terminal status as the last step of a projected route (`askParaaf x N -> requestDecision -> setVoorstelStatus`) | **Keep.** It records the route's outcome after decidiq concluded; it computes nothing. Allowlisted in the structural test with that reason |
| C-5 | `WOODecisionService` + `wOOAssessment#createDecision` | Assembles the formal Woo besluit from document assessments and authors a `decision` object locally, with no decidiq raise | **Split it.** The assessment aggregation and the Art. 5.1/5.2 completeness guard are case logic and stay. The RAISE should go through decidiq like every other formal decision, but see BLOCKED-2: the hub has no fitting type today |
| C-6 | Beschikking (VTH) generation and signing: `BeschikkingService`, `BeschikkingGenerationService`, `lib/Service/Beschikking/*` | Generates the formal disposition document and routes signing through LibreSign | **Keep.** Document generation and signing of a case output. Signature delegation to filinq is REQ-DCDH-005's model, not decision-making in dossiq |
| C-7 | Mandaat matrix: `MandaatRepository`, `MandaatImportService`, `mandaat#mandaatCheck`, settings tabs | Who may sign what, imported from the mandateringsbesluit | **Keep.** Authorization configuration, not deliberation. Flag only: decidiq's governance model could hold mandates one day, but nothing asks for that now |
| C-8 | Advice records: `adviceRequest`, `adviesAanvraag`, `adviceResponse` schemas, `AdviceService`, `AdvisoryBodyService`, advice views (off-menu, routable) | Local record of advice requested and received | **Keep records; the raise already delegates** (`AdviceDelegationService`, type `advice`). Same projection logic as C-1 |
| C-9 | `BesluitvormingController#activateTemplate`, `BesluitvormingTemplateService`, `TemplateBundleSeeder`, `bvw-*.json` templates (still seed `besluitvormingActivate`/`besluitvormingPublish` actions and `parafering` steps) | Seeds the College/Raads/Mandaatbesluit case-type bundles including the legacy chain drivers | **Follows C-2 and C-3.** Whatever Ruben rules there decides what these templates seed. Until then they stay, or freshly seeded case types would break |
| C-10 | `bezwaaradviescommissie`, `bacAdviceRequest` schemas + `AdvisoryCommitteeService` | Committee record and referral lifecycle | **Owned by `migrate-committees-to-decidiq` (6/10 done).** Not duplicated here; its remaining tasks finish the retirement |

## 4. BLOCKED retirements (capability named)

| # | Retirement | Blocked on | Missing capability, precisely |
|---|---|---|---|
| BLOCKED-1 | The DMN stack: `decisionTable` schema (`95-dmn-decision-tables.json`), `DecisionTableService`, `DecisionTableController` (5 routes under `/api/decisions`), `EvaluateDecisionHandler` + `DossiqTxEvaluateDecisionNode`, `DecisionTablesTab.vue`, `MigrateLhsToDecisionTablesCommand`, `LhsMatrixDecisionTableMigrator` | openregister `flow-decision-tables` (in parallel build) | A flow-native decision-table home in OpenRegister: table storage plus the evaluate node, so dossiq's transition action, admin tab and `/api/decisions` CRUD have a destination. Evaluation itself already consumes the shared `DecisionTableEvaluator` (`dossiq-consumes-shared-dmn`, 10/10); what remains local is storage, CRUD and the transition hook |
| BLOCKED-2 | The WOO besluit raise in `WOODecisionService` | decidiq | No `woo-decision` entry in `DecisionIntegrationService::ALLOWED_TYPES` (nor in the schema enum homes it mirrors; the test `DecisionIntegrationServiceTest::testAllowedTypesMatchSchemaEnum` pins them together). `advice` and `bezwaar-decision` are semantically wrong for a beslissing op een Woo-verzoek |

## 5. The structural test

`tests/Unit/Service/LocalDecisionAuthoringTest.php`, modelled on `FlowStorageRunsAsTheRunsIdentityTest` (same defect-class reasoning: fixing instances one by one is how the next one ships).

**Mechanical definition of "decision-shaped", two invariants:**

1. **Authoring.** A file under `lib/` that performs storage work (`saveObject`/`updateObject`) AND references a decision schema binding (`decision_schema`, `besluit_schema`, `bezwaar_decision_schema`, `case_decision_schema`, `mandaterings_besluit_schema`, `'bezwaarDecision'`, `'appealDecision'`, `schema: 'decision'`) must sit in the closed allowlist, each entry carrying the reason it may. Today's allowlist is the 12 files in section 1/3 (projection writer, record keepers, migrations, ZGW API, mandate config, plus WOODecisionService explicitly marked BLOCKED-2). A NEW file matching the signature fails with the instruction: raise the decision in decidiq via the delegation services or `dossiq.requestDecision`; only `BesluitMaterialisationService` records outcomes.
2. **Evaluation.** A file under `lib/` referencing `DecisionTableEvaluator` must sit in the closed DMN allowlist (`EvaluateDecisionHandler`, `DecisionTableService`, `DecisionTableController`), which shrinks to empty when BLOCKED-1 lands and must never grow.

**What it cannot see** (stated in the test header, as the runAs test does): the check is per file and lexical. A file that hides the schema literal behind indirection passes; the per-surface unit tests carry the finer assertions. The test exists so a NEW local decision writer cannot ship quietly.

**Fail-closed note.** The two retired components are UI; neither gates authorization, so no auth door opens by their removal. The guards that DO gate decisions (`BezwaarDecisionListener`, `VoorstelSubmitGuard`, the fail-closed delegation raises) are untouched, and their existing tests keep asserting the closed failure mode.

## 6. decidiq capability matrix (measured on decidiq-ro @ development, 2026-09-02)

| Capability | State | Evidence |
|---|---|---|
| Decision raise/conclude events | YES | `lib/Event/DecisionRequestedEvent.php`, `DecisionConcludedEvent.php` |
| Types incl. `advice`, `bezwaar-decision`, `contract`, `report-adoption` | YES | `DecisionIntegrationService::ALLOWED_TYPES` |
| `woo-decision` type | NO | Same list; hence BLOCKED-2 |
| Governance bodies (committees) | YES | `GovernanceBodyRequestedEvent`, `GovernanceBodyCommandService` |
| Approval routes incl. runtime actions | YES | `ApprovalRouteRequestedEvent`, `ApprovalActionRequestedEvent`, `ApprovalRouteService` |
| Votes, tallies, rounds | YES | 20+ Voting* services |
| Decision publication (own object) | PARTIAL | `DecisionPublicationService` stamps `isPublished`; no DROP/LVBB channel, and REQ-DCDH-007 leaves side effects to the caller |
| DMN / business-rule tables | NO | No decision-table service; ruled to openregister anyway |
