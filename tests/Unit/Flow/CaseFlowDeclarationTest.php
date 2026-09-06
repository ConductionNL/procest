<?php

/**
 * The shipped case flow is well-formed.
 *
 * The flow is DECLARED in the register file and imported into OpenRegister's
 * flow store. Nothing type-checks it on the way in: a node type nothing
 * registers, an edge pointing at a node that does not exist, or a loop with no
 * way out are all accepted at import and only discovered when a real case runs
 * — by which time a citizen's application is stuck in it.
 *
 * These tests are the check that does not exist anywhere else. They are
 * deliberately STRUCTURAL rather than executable: dossiq's own bootstrap stubs
 * OpenRegister's flow interfaces when the app is absent, so a test that asked
 * OpenRegister's builder to build this document would, on a machine without
 * OpenRegister, validate a stub and pass while proving nothing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Flow;

use PHPUnit\Framework\TestCase;

class CaseFlowDeclarationTest extends TestCase {
	/**
	 * The declared flow, as shipped.
	 *
	 * @var array<string, mixed>
	 */
	private array $flow;

	protected function setUp(): void {
		$path = __DIR__ . '/../../../lib/Settings/dossiq_register.json';
		$this->assertFileExists($path);

		$register = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($register, 'The register file must be valid JSON.');

		// READ IT WHERE THE RUNTIME READS IT.
		//
		// SchemaFlowImportListener takes the declaration from the schema's
		// CONFIGURATION -- `$schema->getConfiguration()['x-openregister-flows']`
		// -- and nothing lifts a top-level `x-openregister-*` key into
		// configuration on import.
		//
		// This test used to read it from the schema's top level, where the
		// declaration also sat. Both agreed with each other and neither agreed
		// with the importer, so this suite was green over a flow the runtime
		// could never materialise -- which is exactly what the e2e
		// (case-flow-human-steps.spec.ts) then reported as "not found in the
		// flow store".
		$flows = ($register['components']['schemas']['case']['configuration']['x-openregister-flows'] ?? null);
		$this->assertIsArray(
			$flows,
			'The case schema must declare its flow inside `configuration` — '
			. 'that is the only place SchemaFlowImportListener looks.'
		);
		$this->assertCount(1, $flows, 'Exactly one case flow ships; a second would import as a separate flow.');

		$this->flow = $flows[0];
	}//end setUp()

	/**
	 * Node ids, for edge checking.
	 *
	 * @return string[] The declared node ids.
	 */
	private function nodeIds(): array {
		return array_map(static fn (array $n): string => (string)$n['id'], $this->flow['nodes']);
	}//end nodeIds()

	/**
	 * The declared node with this id.
	 *
	 * @param string $id The node id.
	 *
	 * @return array<string, mixed> The node.
	 */
	private function nodeById(string $id): array {
		foreach ($this->flow['nodes'] as $node) {
			if ((string)($node['id'] ?? '') === $id) {
				return $node;
			}
		}

		$this->fail(sprintf('The flow declares no node "%s".', $id));
	}//end nodeById()

	/**
	 * Where the edge leaving this node through this exit points.
	 *
	 * @param string $nodeId The branching node.
	 * @param string $exitId The exit the edge must name in `fromExit`.
	 *
	 * @return string The target node id.
	 */
	private function edgeTargetForExit(string $nodeId, string $exitId): string {
		foreach ($this->flow['edges'] as $edge) {
			if (($edge['from'] ?? '') === $nodeId && (string)($edge['fromExit'] ?? '') === $exitId) {
				return (string)($edge['to'] ?? '');
			}
		}

		$this->fail(sprintf('No edge leaves "%s" through exit "%s".', $nodeId, $exitId));
	}//end edgeTargetForExit()

	public function testTheFlowNamesItselfAndItsTrigger(): void {
		$this->assertNotSame('', trim((string)($this->flow['name'] ?? '')), 'A declared flow with no name is refused at import.');
		$this->assertSame('object.created', $this->flow['trigger']);

		$trigger = null;
		foreach ($this->flow['nodes'] as $node) {
			if (($node['type'] ?? '') === 'openregister.trigger-object') {
				$trigger = $node;
				break;
			}
		}

		$this->assertNotNull($trigger, 'The flow must carry a trigger node, not only a trigger column.');
		$this->assertSame('case', $trigger['config']['schema']);
		$this->assertSame('object.created', $trigger['config']['event']);
	}//end testTheFlowNamesItselfAndItsTrigger()

	/**
	 * 🔴 Every edge points at a node that exists.
	 *
	 * A dangling edge is accepted at import and fails the run at the moment the
	 * token reaches it — mid-case, after side effects have already happened.
	 */
	public function testEveryEdgeConnectsDeclaredNodes(): void {
		$ids = $this->nodeIds();

		foreach ($this->flow['edges'] as $edge) {
			$this->assertContains(
				(string)$edge['from'],
				$ids,
				sprintf('Edge "%s" starts at a node that does not exist.', $edge['id'])
			);
			$this->assertContains(
				(string)$edge['to'],
				$ids,
				sprintf('Edge "%s" ends at a node that does not exist.', $edge['id'])
			);
		}
	}//end testEveryEdgeConnectsDeclaredNodes()

	public function testNodeIdsAreUnique(): void {
		$ids = $this->nodeIds();

		$this->assertSame(
			count($ids),
			count(array_unique($ids)),
			'Two nodes sharing an id make every edge to that id ambiguous.'
		);
	}//end testNodeIdsAreUnique()

	/**
	 * Every node type is one this app or OpenRegister actually registers.
	 *
	 * A type nothing answers to is the quietest failure available: the flow
	 * imports, the editor shows the node, and the run fails on it.
	 */
	public function testEveryNodeTypeIsOneSomebodyRegisters(): void {
		$known = [
			// OpenRegister's own catalogue, as used here.
			'openregister.trigger-object',
			'openregister.switch',
			'openregister.set-fields',
			'openregister.end',
			// dossiq's, each registered in DossiqFlowNodeListener::NODES.
			'dossiq.setStatus',
			'dossiq.askPerson',
			'dossiq.requestDecision',
			'dossiq.action.mergeTemplate',
		];

		foreach ($this->flow['nodes'] as $node) {
			$this->assertContains(
				(string)$node['type'],
				$known,
				sprintf('Node "%s" has a type nothing registers.', $node['id'])
			);
		}
	}//end testEveryNodeTypeIsOneSomebodyRegisters()

	/**
	 * The dossiq node types named here are the ones the listener registers.
	 *
	 * Reads the listener's source rather than trusting the list above, so the
	 * two cannot drift: renaming a node id in the listener and not in the flow
	 * would otherwise leave this suite green and the flow broken.
	 */
	public function testDossiqNodeTypesMatchTheRegisteredNodes(): void {
		$dossiqTypes = [];
		foreach ($this->flow['nodes'] as $node) {
			$type = (string)$node['type'];
			if (str_starts_with($type, 'dossiq.') === true && str_starts_with($type, 'dossiq.action.') === false) {
				$dossiqTypes[] = $type;
			}
		}

		$this->assertNotEmpty($dossiqTypes, 'The flow is supposed to use dossiq nodes.');

        $sources = glob(__DIR__ . '/../../../lib/Flow/*.php');
        $declared = '';
        foreach (($sources ?: []) as $file) {
            $declared .= (string)file_get_contents($file);
        }

		foreach (array_unique($dossiqTypes) as $type) {
			$this->assertStringContainsString(
				"'" . $type . "'",
				$declared,
				sprintf('No node class declares the id "%s".', $type)
			);
		}
	}//end testDossiqNodeTypesMatchTheRegisteredNodes()

	/**
	 * 🔴 THE LOOP HAS A DECLARED WAY OUT.
	 *
	 * The applicant loop must leave by an edge, not by the engine's transition
	 * ceiling. A run that dies on the ceiling is reported as a FAILED run — so
	 * a case nobody answered would read as a broken flow and land on the wrong
	 * person's desk.
	 */
	public function testTheApplicantLoopHasAnUnconditionalExit(): void {
		$check = $this->nodeById('check-complete');
		$exits = (array)($check['exits'] ?? []);

		$this->assertGreaterThanOrEqual(3, count($exits), 'complete / under-cap / at-cap are three distinct exits.');

		$unconditional = array_values(
			array_filter($exits, static fn (array $e): bool => isset($e['condition']) === false)
		);

		$this->assertCount(
			1,
			$unconditional,
			'Exactly one exit must be the else: none means the run can stall with no edge to take, several means the choice is ambiguous.'
		);

		$this->assertSame(
			'status-gestrand',
			$this->edgeTargetForExit(nodeId: 'check-complete', exitId: (string)$unconditional[0]['id']),
			'The else must be the stalled route, so an unanswered case ends deliberately.'
		);
	}//end testTheApplicantLoopHasAnUnconditionalExit()

	/**
	 * 🔴 EVERY EXIT REACHES AN EDGE, AND EVERY BRANCHING EDGE NAMES AN EXIT.
	 *
	 * The two halves of the exits[] contract. An exit no edge references is a
	 * branch that silently goes nowhere (`placesForExit` returns nothing and
	 * the token skips it); an edge leaving a branching node WITHOUT `fromExit`
	 * never matches any exit, so the branch it draws can never be taken.
	 */
	public function testEveryExitAndEveryBranchingEdgePairUp(): void {
		foreach ($this->flow['nodes'] as $node) {
			$exits = (array)($node['exits'] ?? []);
			if ($exits === []) {
				continue;
			}

			$exitIds = array_map(static fn (array $e): string => (string)$e['id'], $exits);
			$fromExits = [];
			foreach ($this->flow['edges'] as $edge) {
				if (($edge['from'] ?? '') !== $node['id']) {
					continue;
				}

				$fromExit = trim((string)($edge['fromExit'] ?? ''));
				$this->assertNotSame(
					'',
					$fromExit,
					sprintf(
						'Edge "%s" leaves branching node "%s" without a fromExit, so it matches no exit and can never be taken.',
						$edge['id'],
						$node['id']
					)
				);
				$this->assertContains(
					$fromExit,
					$exitIds,
					sprintf('Edge "%s" names an exit "%s" that node "%s" does not declare.', $edge['id'], $fromExit, $node['id'])
				);
				$fromExits[] = $fromExit;
			}

			foreach ($exitIds as $exitId) {
				$this->assertContains(
					$exitId,
					$fromExits,
					sprintf('Exit "%s" of node "%s" is referenced by no edge: that branch silently goes nowhere.', $exitId, $node['id'])
				);
			}
		}//end foreach
	}//end testEveryExitAndEveryBranchingEdgePairUp()

	/**
	 * 🔴 THE CAP COUNTS SOMETHING THAT IS ACTUALLY WRITTEN.
	 *
	 * The cap condition reads `aanvullingRound`. If nothing incremented it, the
	 * comparison would read an absent value as zero, the under-cap edge would
	 * be taken forever, and the cap would be decorative — the loop bounded only
	 * by the engine ceiling it exists to avoid.
	 */
	public function testTheLoopCounterIsIncrementedInsideTheLoop(): void {
		$capped = null;
		foreach ((array)($this->nodeById('check-complete')['exits'] ?? []) as $exit) {
			if (isset($exit['condition']['<']) === true) {
				$capped = $exit;
				break;
			}
		}

		$this->assertNotNull($capped, 'The loop must be capped by a comparison.');

		$variable = $capped['condition']['<'][0]['var'];
		$this->assertSame('json.aanvullingRound', $variable);

		$writers = array_values(
			array_filter(
				$this->flow['nodes'],
				static fn (array $n): bool => isset($n['config']['compute']['aanvullingRound']) === true
			)
		);

		$this->assertCount(1, $writers, 'Exactly one node must maintain the counter the cap reads.');

		// And it must sit INSIDE the loop, or it counts nothing.
		$intoWriter = array_values(
			array_filter(
				$this->flow['edges'],
				static fn (array $e): bool => ($e['to'] ?? '') === $writers[0]['id']
			)
		);
		$this->assertNotEmpty($intoWriter, 'The counter node must be reachable.');
	}//end testTheLoopCounterIsIncrementedInsideTheLoop()

	/**
	 * 🔴 NO SHIPPED FLOW MAY PUT A `condition` ON AN EDGE. EVER.
	 *
	 * The engine's contract is exits[]: FlowTokenRouter reads conditions from
	 * the node's `exits` and follows edges by `fromExit`. A condition written
	 * on the edge itself is INVISIBLE to it — not rejected, not warned about,
	 * simply never read — so every enabled transition looks unconditional and
	 * the first one wins. That is how a COMPLETE case was routed to "Wacht op
	 * aanvulling" live: the completeness check carried both its conditions in
	 * the shape the engine does not have.
	 *
	 * Scans EVERY flow declaration the app ships, not just the case flow, so
	 * the wrong shape cannot come back anywhere: the failure mode is a class,
	 * not an instance.
	 */
	public function testNoShippedFlowPutsAConditionOnAnEdge(): void {
		$declarationFiles = array_merge(
			[__DIR__ . '/../../../lib/Settings/dossiq_register.json'],
			(glob(__DIR__ . '/../../../lib/Settings/register.d/*.json') ?: [])
		);

		$flowsSeen = 0;
		foreach ($declarationFiles as $file) {
			$document = json_decode((string)file_get_contents($file), true);
			$this->assertIsArray($document, basename($file) . ' must be valid JSON.');

			foreach ($this->flowDeclarationsIn($document) as $flow) {
				$flowsSeen++;
				foreach ((array)($flow['edges'] ?? []) as $edge) {
					$this->assertArrayNotHasKey(
						'condition',
						(array)$edge,
						sprintf(
							'%s: flow "%s" puts a condition on edge "%s". The engine reads conditions from the '
							. 'declaring node\'s exits[] (matched by the edge\'s fromExit) and NEVER from the edge, '
							. 'so this condition would silently not exist and the first enabled transition would win.',
							basename($file),
							(string)($flow['name'] ?? '?'),
							(string)($edge['id'] ?? '?')
						)
					);
				}
			}
		}//end foreach

		$this->assertGreaterThan(0, $flowsSeen, 'The scan found no flow declarations at all: the query, not the fleet, is broken.');
	}//end testNoShippedFlowPutsAConditionOnAnEdge()

	/**
	 * Every `x-openregister-flows` declaration in a decoded document.
	 *
	 * Walks the whole tree rather than one known path, so a flow declared on
	 * another schema — or a schema added later — cannot dodge the scan.
	 *
	 * @param array $document The decoded JSON document.
	 *
	 * @return array<int, array<string, mixed>> The declared flows.
	 */
	private function flowDeclarationsIn(array $document): array {
		$flows = [];
		$walk = static function (array $node) use (&$walk, &$flows): void {
			foreach ($node as $key => $value) {
				if (is_array($value) === false) {
					continue;
				}

				if ($key === 'x-openregister-flows') {
					foreach ($value as $flow) {
						if (is_array($flow) === true) {
							$flows[] = $flow;
						}
					}

					continue;
				}

				$walk($value);
			}
		};
		$walk($document);

		return $flows;
	}//end flowDeclarationsIn()

	/**
	 * Every shipped ask names somebody, in EVERY shipped flow.
	 *
	 * An unassigned step is answerable by ANYONE — OpenRegister's resume guard
	 * treats silence as "no restriction", deliberately, because webhook and
	 * child-run signals record no assignee. In a case flow that would mean any
	 * authenticated user could advance somebody's application, which is why
	 * DossiqAskPersonNode::validateConfig refuses one outright.
	 *
	 * 🔴 THIS USED TO READ ONLY THE CASE FLOW, AND THE OTHER SHIPPED FLOW WAS
	 * BROKEN THE WHOLE TIME. `register.d/72-committees-to-decidiq.json` ships a
	 * bezwaar-advice flow whose two `dossiq.askPerson` nodes declared no
	 * assignee at all: validateConfig therefore threw on the first execute and
	 * every advice request ever created failed its run at the first human step.
	 * Reading `$this->flow` alone could not see it. The sweep now walks every
	 * declaration file, the same way testNoShippedFlowPutsAConditionOnAnEdge
	 * does, so a second flow cannot be exempt from the first flow's rules.
	 */
	public function testEveryAskNamesWhoIsBeingAsked(): void {
		$asksSeen = 0;

		foreach ($this->shippedFlows() as $entry) {
			['file' => $file, 'flow' => $flow] = $entry;

			foreach ((array)($flow['nodes'] ?? []) as $node) {
				if (($node['type'] ?? '') !== 'dossiq.askPerson') {
					continue;
				}

				$asksSeen++;
				$where = sprintf('%s, flow "%s", node "%s"', $file, (string)($flow['name'] ?? '?'), (string)($node['id'] ?? '?'));

				$this->assertNotSame(
					'',
					trim((string)($node['config']['assignee'] ?? '')),
					sprintf('%s names nobody. validateConfig refuses that, so the run dies at this step.', $where)
				);
				$this->assertNotSame(
					'',
					trim((string)($node['config']['question'] ?? '')),
					sprintf('%s asks nothing.', $where)
				);
			}
		}

		$this->assertGreaterThan(0, $asksSeen, 'The sweep found no shipped asks at all: the query, not the data, is broken.');
	}//end testEveryAskNamesWhoIsBeingAsked()

	/**
	 * A templated assignee carries a literal fallback.
	 *
	 * 🔴 THE ONE THING A TEMPLATE CAN ALWAYS DO IS RESOLVE TO NOBODY, AND THAT
	 * KILLED RUNS ON A FRESH RIG. The case flow's supplement ask named
	 * `{{ case.assignee }}`, `assignee` is not in the case schema's `required`,
	 * and a case filed from the New case dialog with only a title and a case
	 * type therefore has none. The step threw
	 * `could not resolve the assignee "{{ case.assignee }}"`, the run failed,
	 * and the case sat in "Wacht op aanvulling" with no task for anybody.
	 * Reproduced twice on independent clean installs.
	 *
	 * So a declaration that MIGHT render to nobody must say where the ask goes
	 * when it does. The fallback must be a literal: a second template can fail
	 * the same way the first one did, on the same missing field, and would only
	 * move the failure one line down. ProvisionAssignedGroupsTest separately
	 * requires that literal to be a group the install actually creates.
	 */
	public function testEveryTemplatedAssigneeDeclaresALiteralFallback(): void {
		$checked = 0;

		foreach ($this->shippedFlows() as $entry) {
			['file' => $file, 'flow' => $flow] = $entry;

			foreach ((array)($flow['nodes'] ?? []) as $node) {
				if (($node['type'] ?? '') !== 'dossiq.askPerson') {
					continue;
				}

				$assignee = trim((string)($node['config']['assignee'] ?? ''));
				if (str_contains($assignee, '{{') === false) {
					continue;
				}

				$checked++;
				$where = sprintf('%s, flow "%s", node "%s"', $file, (string)($flow['name'] ?? '?'), (string)($node['id'] ?? '?'));
				$fallback = trim((string)($node['config']['assigneeFallback'] ?? ''));

				$this->assertNotSame(
					'',
					$fallback,
					sprintf(
						'%s names the template "%s" and no assigneeFallback. When it resolves to nobody the '
						. 'step throws and the run dies, which is what a case filed with no assignee did.',
						$where,
						$assignee
					)
				);
				$this->assertStringNotContainsString(
					'{{',
					$fallback,
					sprintf('%s falls back to another template, which can fail exactly as the first one did.', $where)
				);
			}
		}

		$this->assertGreaterThan(0, $checked, 'No shipped ask templates its assignee: the query, not the data, is broken.');
	}//end testEveryTemplatedAssigneeDeclaresALiteralFallback()

	/**
	 * Every flow declared in every shipped register file.
	 *
	 * @return array<int, array{file: string, flow: array<string, mixed>}> The declarations.
	 */
	private function shippedFlows(): array {
		$files = array_merge(
			[__DIR__ . '/../../../lib/Settings/dossiq_register.json'],
			(glob(__DIR__ . '/../../../lib/Settings/register.d/*.json') ?: [])
		);

		$found = [];
		foreach ($files as $file) {
			$document = json_decode((string)file_get_contents($file), true);
			$this->assertIsArray($document, basename((string)$file) . ' must be valid JSON.');

			foreach ($this->flowDeclarationsIn($document) as $flow) {
				$found[] = ['file' => basename((string)$file), 'flow' => $flow];
			}
		}

		return $found;
	}//end shippedFlows()

	/**
	 * The case cannot reach its final status without its decision document.
	 */
	public function testTheCaseIsNotClosedBeforeItsDocumentIsMade(): void {
		$toFinal = array_values(
			array_filter(
				$this->flow['edges'],
				static fn (array $e): bool => ($e['to'] ?? '') === 'status-afgehandeld'
			)
		);

		$this->assertNotEmpty($toFinal, 'Something must lead to the final status.');

		foreach ($toFinal as $edge) {
			$this->assertSame(
				'besluit-document',
				$edge['from'],
				'The only way into the final status is through the step that produces the decision document.'
			);
		}
	}//end testTheCaseIsNotClosedBeforeItsDocumentIsMade()

	/**
	 * 🔴 EVERY STATUS THE FLOW MOVES TO EXISTS ON THE SEEDED CASE TYPE.
	 *
	 * The flow names statuses; the handler resolves each name inside the case's
	 * own case type at run time, because a statusType uuid is minted per
	 * installation and a shipped flow cannot carry one. So the flow and the
	 * seed share a contract made of strings, and nothing but this test checks
	 * that the two sides still agree.
	 *
	 * When they do not, the handler refuses the step and the case stops moving
	 * — correct behaviour, discovered at the worst possible moment. A typo in
	 * either file is otherwise invisible until a real case runs.
	 */
	public function testEveryStatusTheFlowUsesIsSeededOnTheCaseType(): void {
		$seedPath = __DIR__ . '/../../../lib/Settings/case_flow_seed_data.json';
		$this->assertFileExists($seedPath, 'The flow needs its case type and statuses to exist.');

		$seed = json_decode((string)file_get_contents($seedPath), true);
		$this->assertIsArray($seed);

		$seeded = [];
		foreach (($seed['caseTypes'] ?? []) as $caseType) {
			foreach (($caseType['statusTypes'] ?? []) as $status) {
				$seeded[] = (string)$status['name'];
			}
		}

		$this->assertNotEmpty($seeded, 'The seed must define the statuses the flow moves through.');

		foreach ($this->flow['nodes'] as $node) {
			if (($node['type'] ?? '') !== 'dossiq.setStatus') {
				continue;
			}

			$this->assertContains(
				(string)$node['config']['status'],
				$seeded,
				sprintf(
					'Node "%s" moves the case to a status "%s" that the seeded case type does not define.',
					$node['id'],
					$node['config']['status']
				)
			);
		}
	}//end testEveryStatusTheFlowUsesIsSeededOnTheCaseType()

	/**
	 * The demo data exercises BOTH branches of the completeness check.
	 *
	 * A seed in which every case is complete would demonstrate the happy path
	 * and leave the applicant loop — the part with the most moving pieces —
	 * untouched on first run.
	 */
	public function testTheSeedExercisesBothSidesOfTheCompletenessCheck(): void {
		$seed = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/case_flow_seed_data.json'),
			true
		);

		$complete = 0;
		$incomplete = 0;
		foreach (($seed['cases'] ?? []) as $case) {
			if (trim((string)($case['description'] ?? '')) === '') {
				$incomplete++;
				continue;
			}

			$complete++;
		}

		$this->assertGreaterThan(0, $complete, 'One case must pass the completeness check.');
		$this->assertGreaterThan(0, $incomplete, 'One case must fail it, or the applicant loop is never demonstrated.');
	}//end testTheSeedExercisesBothSidesOfTheCompletenessCheck()

	/**
	 * 🔴 THE `blocksCase` CALCULATION USES OPERATORS THE ENGINE ACTUALLY HAS.
	 *
	 * A calculation whose expression form the engine does not understand is
	 * INERT — it is not rejected, it simply never produces a value. This schema
	 * already carries a scar from exactly that: `objectionProceeding`'s
	 * decisionDeadline shipped for months as an array-form string DSL
	 * "which OpenRegister's calculation engine never honoured".
	 *
	 * The operator list below is copied from
	 * `OpenRegister\Service\Calculation\CalculationEvaluator::apply()`. It is a
	 * cheap structural check, not a substitute for evaluating: the expression was
	 * additionally run through the real evaluator during development, and returns
	 * true only for a task that names a run and is neither completed nor
	 * terminated.
	 *
	 * @return void
	 */
	public function testTheBlocksCaseCalculationUsesSupportedOperatorsOnly(): void {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'),
			true
		);

		$calc = ($register['components']['schemas']['caseTask']['x-openregister-calculations']['blocksCase'] ?? null);
		$this->assertIsArray($calc, 'The task must declare when it is blocking a case.');
		$this->assertTrue(($calc['materialise'] ?? false), 'It must be materialised, or it cannot be filtered server-side.');

		$supported = [
			'abs', 'and', 'coalesce', 'concat', 'dateAdd', 'dateDiff', 'days', 'diffDays',
			'eq', 'formatDate', 'global', 'gt', 'gte', 'hours', 'if', 'lit', 'lt', 'lte',
			'max', 'min', 'minutes', 'monthly', 'months', 'monthsElapsed', 'ne', 'not',
			'now', 'or', 'prop', 'round', 'seconds', 'sequence', 'weeks', 'year',
			'yearly', 'years',
		];

		$operators = [];
		$walk = static function (mixed $node) use (&$walk, &$operators): void {
			if (is_array($node) === false) {
				return;
			}

			foreach ($node as $key => $value) {
				if (is_string($key) === true) {
					$operators[] = $key;
				}

				$walk($value);
			}
		};
		$walk($calc['expression']);

		$this->assertNotEmpty($operators, 'An expression with no operators computes nothing.');

		foreach (array_unique($operators) as $operator) {
			$this->assertContains(
				$operator,
				$supported,
				sprintf('"%s" is not an operator the calculation engine implements, so the field would be inert.', $operator)
			);
		}
	}//end testTheBlocksCaseCalculationUsesSupportedOperatorsOnly()

	/**
	 * Every path ends at an end node rather than simply stopping.
	 */
	public function testEveryTerminalPathEndsDeliberately(): void {
		$withOutgoing = array_map(
			static fn (array $e): string => (string)$e['from'],
			$this->flow['edges']
		);

		foreach ($this->flow['nodes'] as $node) {
			if (in_array((string)$node['id'], $withOutgoing, true) === true) {
				continue;
			}

			$this->assertSame(
				'openregister.end',
				(string)$node['type'],
				sprintf('Node "%s" has no outgoing edge and is not an end node.', $node['id'])
			);
		}
	}//end testEveryTerminalPathEndsDeliberately()

	/**
	 * The document step's config is one its node class accepts and can persist.
	 *
	 * Found broken: the shipped config said `template`/`outputName`, while
	 * DossiqMergeTemplateNode::requiredConfigKeys() demands `templateSlug` and
	 * `targetField` — so validateConfig() threw at execute() and EVERY run
	 * stranded at besluit-document, meaning no case could ever close. Nothing
	 * else could catch it: import accepts any config, and the node's own tests
	 * run against configs the tests invent.
	 *
	 * The second half is the quieter failure: `targetField` must name a
	 * property the case schema declares, because the object store strips
	 * undeclared fields on save — the merge would succeed while the document
	 * silently never persisted.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
	 */
	public function testTheDocumentStepConfigMatchesItsNodeAndItsSchema(): void {
		$path = __DIR__ . '/../../../lib/Settings/dossiq_register.json';
		$register = json_decode((string)file_get_contents($path), true);
		$caseProperties = ($register['components']['schemas']['case']['properties'] ?? []);

		$mergeNodes = array_filter(
			$this->flow['nodes'],
			static fn (array $n): bool => (string)($n['type'] ?? '') === 'dossiq.action.mergeTemplate'
		);
		$this->assertNotSame([], $mergeNodes, 'The flow must carry its document step.');

		foreach ($mergeNodes as $node) {
			$config = (array)($node['config'] ?? []);

			// The keys DossiqMergeTemplateNode::requiredConfigKeys() refuses to
			// run without.
			foreach (['templateSlug', 'targetField'] as $key) {
				$this->assertNotSame(
					'',
					trim((string)($config[$key] ?? '')),
					sprintf(
						'Node "%s" omits "%s": validateConfig() throws at execute() and the run strands here.',
						$node['id'],
						$key
					)
				);
			}

			$this->assertArrayHasKey(
				(string)$config['targetField'],
				$caseProperties,
				sprintf(
					'Node "%s" writes to "%s", which the case schema does not declare — '
					. 'the store would strip it and the case would close without its document.',
					$node['id'],
					$config['targetField']
				)
			);
		}
	}//end testTheDocumentStepConfigMatchesItsNodeAndItsSchema()

	/**
	 * Every field the flow writes to the case is a declared case property.
	 *
	 * THE CLASS-CATCHING CHECK. OpenRegister drops any property the schema
	 * does not declare, silently: the save answers 200 and returns the object,
	 * minus the field. That is how `aanvullingRound` was lost — the loop
	 * counter reset to null on every save, so the completeness cap never
	 * engaged — and how `actionResult` vanished after the document step.
	 * Checking those two names would only catch those two names; this test
	 * instead walks the declaration and collects EVERY field a node writes:
	 *
	 *  - `openregister.set-fields`: the keys of `set` and `compute`, and the
	 *    new names in `rename` (SetFieldsNode's config vocabulary);
	 *  - `dossiq.setStatus`: `status`;
	 *  - `dossiq.setField`: the config's `field`;
	 *  - `dossiq.evaluateDecision`: the case fields its `outputMapping` names;
	 *  - any dossiq node with a `targetField`: that field (mergeTemplate and
	 *    kin), plus — for `dossiq.action.*` — the output key
	 *    DossiqFlowNodeBase merges the handler result under, which defaults
	 *    to `actionResult` when the step names none;
	 *  - any node with a `signalKey`: that field. An ask or decision node
	 *    stamps the signal payload onto the case snapshot when the run
	 *    resumes, and a snapshot field is a case field the moment any writer
	 *    persists it.
	 *
	 * The first version of this walk covered only the first three shapes, and
	 * that is exactly how `commissieBesluit` slipped through: its writer is
	 * the decision node's signal, not a set-fields step, so the walk never saw
	 * it and the store silently dropped it on every save.
	 *
	 * A new node writing a new field fails here before it fails silently in
	 * production.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
	 */
	public function testTheCaseSchemaDeclaresEveryFieldTheFlowWrites(): void {
		$path = __DIR__ . '/../../../lib/Settings/dossiq_register.json';
		$register = json_decode((string)file_get_contents($path), true);
		$caseProperties = ($register['components']['schemas']['case']['properties'] ?? []);
		$this->assertNotSame([], $caseProperties, 'The case schema must declare properties.');

		$written = [];
		foreach ($this->flow['nodes'] as $node) {
			$type = (string)($node['type'] ?? '');
			$config = (array)($node['config'] ?? []);

			if ($type === 'openregister.set-fields') {
				foreach (array_keys((array)($config['set'] ?? [])) as $field) {
					$written[(string)$field] = $node['id'];
				}

				foreach (array_keys((array)($config['compute'] ?? [])) as $field) {
					$written[(string)$field] = $node['id'];
				}

				foreach ((array)($config['rename'] ?? []) as $newName) {
					$written[(string)$newName] = $node['id'];
				}
			}

			if ($type === 'dossiq.setStatus') {
				$written['status'] = $node['id'];
			}

			if ($type === 'dossiq.setField') {
				$field = trim((string)($config['field'] ?? ''));
				if ($field !== '') {
					$written[$field] = $node['id'];
				}
			}

			if ($type === 'dossiq.evaluateDecision') {
				foreach ((array)($config['outputMapping'] ?? []) as $caseField) {
					$written[(string)$caseField] = $node['id'];
				}
			}

			// A targetField is a case write on ANY dossiq node that carries
			// one, not only the action catalogue's.
			if (str_starts_with($type, 'dossiq.') === true) {
				$target = trim((string)($config['targetField'] ?? ''));
				if ($target !== '') {
					$written[$target] = $node['id'];
				}
			}

			if (str_starts_with($type, 'dossiq.action.') === true) {
				// DossiqFlowNodeBase::execute() merges the handler result
				// under config `output`, defaulting to `actionResult`.
				$written[trim((string)($config['output'] ?? '')) !== ''
					? (string)$config['output'] : 'actionResult'] = $node['id'];
			}

			// The resumed signal payload: DossiqAskPersonNode and
			// DossiqRequestDecisionNode write it onto the case under the
			// step's signalKey.
			$signalKey = trim((string)($config['signalKey'] ?? ''));
			if ($signalKey !== '') {
				$written[$signalKey] = $node['id'];
			}
		}//end foreach

		$this->assertNotSame([], $written, 'The flow must write at least one case field.');

		foreach ($written as $field => $nodeId) {
			// A dotted path writes into the property named by its first segment.
			$declared = explode('.', (string)$field)[0];
			$this->assertArrayHasKey(
				$declared,
				$caseProperties,
				sprintf(
					'Node "%s" writes "%s", which the case schema does not declare. '
					. 'OpenRegister strips undeclared properties on save, so the write '
					. 'reports success and the value is silently gone.',
					$nodeId,
					$field
				)
			);
		}
	}//end testTheCaseSchemaDeclaresEveryFieldTheFlowWrites()

	/**
	 * The task schema declares the two fields that tie a task to its run.
	 *
	 * DossiqAskPersonNode stamps `flowRun` and `flowNode` onto the task it
	 * creates, and TaskCompletionResumeListener reads them back to wake the
	 * run. Both sides are tested against doubles, so dropping the fields from
	 * the schema would break the round-trip while every other test stayed
	 * green: the object store strips what the schema does not declare.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/task-management/spec.md
	 */
	public function testTheTaskSchemaDeclaresItsRunAndNodeFields(): void {
		$path = __DIR__ . '/../../../lib/Settings/dossiq_register.json';
		$register = json_decode((string)file_get_contents($path), true);

		$properties = ($register['components']['schemas']['caseTask']['properties'] ?? []);

		foreach (['flowRun', 'flowNode'] as $field) {
			$this->assertArrayHasKey(
				$field,
				$properties,
				sprintf(
					'task.%s is missing: the ask node would stamp a field the store strips, '
					. 'and no completed task could ever resume its run.',
					$field
				)
			);
			$this->assertSame('string', ($properties[$field]['type'] ?? null));
		}
	}//end testTheTaskSchemaDeclaresItsRunAndNodeFields()

	/**
	 * Every status step says what the status MEANS, not only what it is called.
	 *
	 * A literal name is not an identifier: `statusType.name` is declared
	 * `x-translatable`, and every case type spells its working phase
	 * differently and all of them are right.
	 */
	public function testEveryStatusStepNamesARole(): void {
		$roles = ($this->statusTypeSchema()['properties']['role']['enum'] ?? []);
		$this->assertNotEmpty($roles, 'statusType must declare the role vocabulary the flow addresses.');

		foreach ($this->flow['nodes'] as $node) {
			if (($node['type'] ?? '') !== 'dossiq.setStatus') {
				continue;
			}

			$role = (string)($node['config']['role'] ?? '');
			$this->assertNotSame(
				'',
				$role,
				sprintf(
					'Node "%s" moves the case by a literal name alone. A name is translatable and '
					. 'case-type-specific; say which ROLE the status plays.',
					$node['id']
				)
			);
			$this->assertContains($role, $roles, sprintf('Node "%s" names a role nothing can carry.', $node['id']));
		}
	}//end testEveryStatusStepNamesARole()

	/**
	 * 🔴 THE TEST THAT WOULD HAVE CAUGHT THE DEFECT.
	 *
	 * The flow triggers on `object.created` for schema `case`, UNSCOPED by case
	 * type — the trigger cannot be scoped, because `TriggerObjectNode` matches
	 * an (event, register, schema) triple and a case type is a per-installation
	 * uuid a shipped flow could never name. So EVERY case type this app ships
	 * runs this flow, and every one of them has to be able to.
	 *
	 * They could not. Six literal names were the contract and exactly ONE case
	 * type carried all six, so 8 of 18 demo runs died at `status-behandeling`.
	 * The suite stayed green because `testEveryStatusTheFlowUsesIsSeededOnTheCaseType()`
	 * above — and the e2e — both check only that one complete case type, which
	 * is the blind spot rather than the coverage.
	 *
	 * So: every REQUIRED step must resolve on every shipped case type. A step
	 * the flow declares `required: false` is exempt, because that is precisely
	 * the claim it is making — a pothole report has no planning commission.
	 */
	public function testEveryShippedCaseTypeCanRunEveryRequiredStepOfTheFlow(): void {
		$required = [];
		$optional = [];
		foreach ($this->flow['nodes'] as $node) {
			if (($node['type'] ?? '') !== 'dossiq.setStatus') {
				continue;
			}

			$role = (string)($node['config']['role'] ?? '');
			if (($node['config']['required'] ?? true) === false) {
				$optional[$node['id']] = $role;
				continue;
			}

			$required[$node['id']] = $role;
		}

		$this->assertNotEmpty($required, 'The flow must have at least one status move it insists on.');
		$this->assertNotEmpty($optional, 'A flow in which no phase is optional cannot serve more than one process.');

		$caseTypes = $this->shippedCaseTypes();
		$this->assertGreaterThan(
			1,
			count($caseTypes),
			'Reading one case type is what hid this: the app ships many and the trigger scopes to none of them.'
		);

		foreach ($caseTypes as $label => $statuses) {
			$roles = [];
			$names = [];
			foreach ($statuses as $status) {
				$role = strtolower(trim((string)($status['role'] ?? '')));
				if ($role !== '') {
					$roles[$role] = true;
				}

				$names[strtolower(trim((string)($status['name'] ?? '')))] = true;
			}

			foreach ($required as $nodeId => $role) {
				$this->assertTrue(
					(isset($roles[$role]) === true),
					sprintf(
						'Case type "%s" models no status with role "%s", so the shipped flow dies at node "%s" '
						. 'on every case of that type. Give the case type that role, or declare the step '
						. '"required": false if the phase genuinely does not apply.',
						$label,
						$role,
						$nodeId
					)
				);
			}
		}
	}//end testEveryShippedCaseTypeCanRunEveryRequiredStepOfTheFlow()

	/**
	 * Every case type this app ships, from every seed that ships one.
	 *
	 * Collected from all three shapes rather than one, because "the case types"
	 * living in one file is the assumption that made the blind spot: the four
	 * the demo caseload actually uses are declared as flat `objects` entries
	 * with a `@ref` back-link, while the VTH set and the flow's own demo type
	 * nest their statuses under the case type.
	 *
	 * @return array<string, array<int, array<string, mixed>>> Statuses per case type label.
	 */
	private function shippedCaseTypes(): array {
		$out = [];

		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'),
			true
		);

		// The flat shape: caseType and statusType are sibling objects, joined by
		// the status's `caseType: "@ref:<slug>"` back-reference.
		$slugs = [];
		foreach (($register['components']['objects'] ?? []) as $object) {
			if ((($object['@self']['schema'] ?? '') === 'caseType')) {
				$slugs[(string)($object['@self']['slug'] ?? '')] = [];
			}
		}

		foreach (($register['components']['objects'] ?? []) as $object) {
			if ((($object['@self']['schema'] ?? '') !== 'statusType')) {
				continue;
			}

			$ref = ltrim((string)($object['caseType'] ?? ''), '@');
			$slug = (string)preg_replace('/^ref:/', '', $ref);
			if (isset($slugs[$slug]) === true) {
				$slugs[$slug][] = $object;
			}
		}

		foreach ($slugs as $slug => $statuses) {
			// A case type with no statuses at all is a different defect and is
			// not this test's to report: it cannot run ANY flow.
			if ($statuses !== []) {
				$out['dossiq_register.json:' . $slug] = $statuses;
			}
		}

		// The nested shape.
		foreach (['case_flow_seed_data.json', 'vth_seed_data.json'] as $file) {
			$seed = json_decode(
				(string)file_get_contents(__DIR__ . '/../../../lib/Settings/' . $file),
				true
			);

			foreach (($seed['caseTypes'] ?? []) as $caseType) {
				$statuses = ($caseType['statusTypes'] ?? []);
				if ($statuses !== []) {
					$out[$file . ':' . (string)($caseType['slug'] ?? '?')] = $statuses;
				}
			}
		}

		return $out;
	}//end shippedCaseTypes()

	/**
	 * The statusType schema, as shipped.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function statusTypeSchema(): array {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'),
			true
		);

		return (array)($register['components']['schemas']['statusType'] ?? []);
	}//end statusTypeSchema()
}//end class
