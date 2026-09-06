# Design: KCC routing onto OR decision tables

## D-1 The compilation mapping

One table per evaluation, hit policy FIRST, rules = the enabled routing rules
sorted ascending by priority (PHP's stable sort preserves declaration order on
ties, matching the legacy engine). Outputs: `rule`, `assignedDomain`,
`assignedTeam`, `escalationTeam`, carried verbatim from the rule row.

Per condition type:

| Condition | Column | Type | Cell | Derivation (dossiq's) |
|---|---|---|---|---|
| `channel` | `channel` | string | quoted literal | `contactMoment.channel` |
| `customer_type` | `customerType` | string | quoted literal | KvK rule: 8-digit ref = bedrijf, non-empty = burger, empty = anoniem |
| `day_of_week` | `dayOfWeek` | string | quoted lower-cased literal | `strtolower(now->format('l'))` |
| `time_of_day` | `minutesOfDay` | number | `>= N`, `< N`, or `[L..U)` | minutes since midnight |
| `keyword` | `keyword:<lowered value>` | boolean | `true` | `str_contains(haystack, lowered value)` |
| `regex` | `regex:<pattern>` | boolean | `true` | anchor-free case-insensitive `preg_match` over the haystack |

The haystack is the legacy one: `strtolower(trim(subject . ' ' . summary))`.

**Why quoted literals.** A channel literally named `-` would read as the DMN
wildcard bare, and a value starting with `<` as an operator. Quoting makes
every equality cell inert text; embedded quotes are escaped.

**Why keyword/regex are boolean derive columns.** Substring and regex matching
are the KCC dialect's own; pushing them into the shared unary-test grammar
would widen an engine that is deliberately closed and bounded (it is the
fleet's protection against rule-authoring becoming code injection). humaniq's
`ProvidesTables` derive callables are the precedent: the app computes typed
inputs, the engine decides.

**Why several time conditions fold into one column.** The legacy engine
conjuncts them, so `after_09:00` + `before_17:00` is the window
`[540..1020)` — expressible as one range cell. Multiple `after`s take the max,
multiple `before`s the min; an impossible window compiles to a range that
never matches, the same answer conjunction gave it.

## D-2 Inexpressible rules compile to nothing

A rule with two different `channel` equalities, a malformed `time_of_day`
value, an unknown condition type, or no conditions at all could never match
under the legacy engine (each arm answered false). One cell cannot carry two
literals, so rather than inventing an "impossible" cell the compiler leaves
the whole rule out of the table. Same observable answer, cleaner table; the
parity matrix pins each shape.

## D-3 No-match and failure semantics

`no_rule_matched` from the engine IS the legacy null and maps to it. Every
other `DecisionEvaluationException` is a compiler defect — the compiler owns
both the table and the inputs, so a type mismatch cannot be user data — and
propagates loudly instead of reading as "route to nobody". Absence of the
evaluator class refuses loudly (fail closed): a silent fallback matcher is how
the fleet grew second engines.

## D-4 The parity oracle and its retirement

`RoutingEngine::evaluate()` is not deleted on the strength of an untested
replacement. It stays, deprecated, as the oracle; `KccRoutingParityTest`
sweeps a 40-cell matrix (8 contact moments × 5 clock times, including the
17:00 boundary minute and a Saturday) over a rule set where every
never-matching shape sits at priority 0, so a compiler that wrongly admits
one changes an answer somewhere. Retirement (tasks section 5) deletes the
legacy method and rehomes any remaining assertions once the shared path has
run real data — the humaniq#289 staging, and the same reason openregister
kept dossiq's evaluator alive while #3186 landed.
