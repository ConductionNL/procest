<?php

/**
 * The class-catching test for local decision authoring.
 *
 * THE DIRECTIVE. dossiq owns cases; decidiq owns decisions. A decision is
 * raised in decidiq over the typed event seam ({@see \OCA\Dossiq\Service\ContractDecisionDelegationService},
 * {@see \OCA\Dossiq\Flow\DossiqRequestDecisionNode}) and what a case stores is
 * the outcome decidiq concluded, written as a projection by
 * {@see \OCA\Dossiq\Service\BesluitMaterialisationService}. The migration that
 * got here is spread over five sibling changes, and nothing before this test
 * asserted the END STATE: nothing stopped a new handler from quietly writing
 * a verdict it computed itself. Fixing instances one by one is how the next
 * one ships, so this test asserts the invariant over every file under lib/.
 *
 * THE RULE, mechanically. Two closed sets:
 *
 * 1. AUTHORING. A file that performs storage work (saveObject/updateObject)
 *    AND binds a decision schema (the config keys `*decision_schema` /
 *    `*besluit_schema`, or the literals 'bezwaarDecision', 'appealDecision',
 *    schema: 'decision') must sit in ALLOWED_DECISION_WRITERS with the reason
 *    it may. Everything else raises in decidiq instead.
 * 2. EVALUATION. A file importing OpenRegister's DecisionTableEvaluator must
 *    sit in ALLOWED_EVALUATOR_CONSUMERS. The list distinguishes two kinds of
 *    entry: DEPRECATED STOCK — the case-decision surfaces that shrink to
 *    empty when openregister's flow-decision-tables lands — and SANCTIONED
 *    non-decision consumers, which evaluate rules that were never decidiq's
 *    (kcc-routing-onto-or-decision-tables): consuming the SHARED engine for
 *    those is the fleet-consolidation destination, and refusing it would
 *    push the rule back into a private matcher, the exact thing wave 4
 *    retires. What may never ship is a consumer that computes a case VERDICT
 *    locally — that is decidiq's, whatever engine it borrows.
 *
 * WHAT THIS CANNOT SEE. The check is per file and lexical. A writer that
 * hides the schema binding behind indirection passes; the per-surface unit
 * tests carry the finer assertions. This test exists so a NEW local decision
 * writer or evaluator consumer cannot ship quietly.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/dossiq-decisions-to-decidiq/specs/dossiq-decisions-to-decidiq/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing Structural assertion over source files, not behaviour.
 */
class LocalDecisionAuthoringTest extends TestCase {

	/**
	 * Storage work: calls that reach OpenRegister's ObjectService writers.
	 *
	 * @var string
	 */
	private const STORAGE_CALL_PATTERN = '/->\s*(saveObject|saveObjectAsArray|updateObject)\s*\(/';

	/**
	 * A decision schema binding. `decision_schema` also covers
	 * `case_decision_schema` and `bezwaar_decision_schema`; `besluit_schema`
	 * also covers `mandaterings_besluit_schema`.
	 *
	 * @var string
	 */
	private const DECISION_BINDING_PATTERN = "/decision_schema|besluit_schema|'bezwaarDecision'|'appealDecision'|schema: '(decision|besluit)'/";

	/**
	 * The import line for OpenRegister's shared decision-table evaluator.
	 * Import-line only, so a docblock mention alone does not flag.
	 *
	 * @var string
	 */
	private const EVALUATOR_IMPORT_PATTERN = '/^use OCA\\\\OpenRegister\\\\Service\\\\Dmn\\\\DecisionTableEvaluator;$/m';

	/**
	 * The CLOSED allowlist of files that may write decision-schema objects,
	 * each with the reason it may. An entry here is a claim to re-verify when
	 * the file changes, and an entry whose file stops matching fails the
	 * suite, so the list cannot rot silently.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED_DECISION_WRITERS = [
		'lib/Service/BesluitMaterialisationService.php' => 'THE sanctioned door: materialises the ZGW Besluit as a projection of a decidiq DecisionConcludedEvent outcome; authors nothing of its own.',
		'lib/Service/Bezwaar/DecisionService.php' => 'Awb record keeper: publish() raises the decision in decidiq (fails closed) and persists only the returned decisionRef plus notification audit on the record.',
		'lib/Listener/BezwaarDecisionListener.php' => 'Fail-closed guard: reverts a bezwaar status when no published decision exists; probes decision records, never authors one.',
		'lib/Controller/BrcController.php' => 'ZGW Besluiten API (BRC): external registry writes besluit RECORDS the API mandates; record store compliance, not deliberation.',
		'lib/Repair/LinkInFlightRemainingDecisionsRepair.php' => 'One-time idempotent migration linking in-flight records to their decidiq decisions.',
		'lib/Service/BesluitMigrationService.php' => 'Operator-invoked, idempotent MOVE of besluit records this app already holds onto decidiq\'s Decision (dossiq#1837): it names the decision slug to read the source rows and writes each one back through decidiq, deliberating nothing.',
		'lib/Repair/LoadDefaultZgwMappings.php' => 'Seeds ZGW mapping configuration naming the decision schema; writes mappings, not decisions.',
		'lib/Service/Bezwaar/BeroepService.php' => 'Derives beroep deadlines FROM the contested appealDecision; reads the record, writes the beroep.',
		'lib/Service/Bezwaar/BezwaarCreationHook.php' => 'Resolves the decision schema for a lookup while creating the objection case; writes the objection, not a decision.',
		'lib/Service/Mandaat/MandaatRepository.php' => 'Mandateringsbesluit rows are mandate CONFIGURATION (who may sign), imported and maintained, not deliberated.',
		'lib/Service/MandaatImportService.php' => 'Imports the mandateringsbesluit; same configuration reasoning as MandaatRepository.',
		'lib/Service/WOODecisionService.php' => 'BLOCKED-2 (see the change tasks): authors the Woo besluit locally because decidiq has no woo-decision type yet. The raise moves to the delegation seam when decidiq grows one; the assembly and the Art. 5.1/5.2 guard stay either way.',
		'lib/Service/WooPublicationService.php' => 'Publication stamping of the Woo besluit record; grey area C-3 (publication mechanics), pending the ruling recorded in the change.',
	];

	/**
	 * The CLOSED allowlist of DecisionTableEvaluator consumers. The first two
	 * are deprecated stock (dossiq-decisions-to-decidiq) and shrink to empty
	 * when openregister flow-decision-tables lands; the KCC entry is a
	 * sanctioned non-decision consumer and stays. A new entry needs the same
	 * argument the KCC one made: rules that are not case verdicts, evaluated
	 * on the SHARED engine with the domain dialect kept app-side.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED_EVALUATOR_CONSUMERS = [
		'lib/Service/Transitions/EvaluateDecisionHandler.php' => 'The live evaluateDecision transition action, until flow-decision-tables lands in OpenRegister.',
		'lib/Controller/DecisionTableController.php' => 'The standalone /api/decisions/{id}/evaluate endpoint, until flow-decision-tables lands in OpenRegister.',
		'lib/Service/Kcc/RoutingTableEvaluator.php' => 'SANCTIONED, not deprecated stock: KCC contact-moment routing compiled onto the shared evaluator (kcc-routing-onto-or-decision-tables). Routing a call is triage, not a case verdict; the alternative to this consumer is the private matcher wave 4 retires.',
	];


	/**
	 * 🔴 NO FILE AUTHORS DECISIONS OUTSIDE THE CLOSED ALLOWLIST.
	 *
	 * Add a handler that writes a verdict it computed itself and this goes
	 * red naming the file, before the handler ever meets a case.
	 */
	public function testNoFileAuthorsDecisionsOutsideTheAllowlist(): void {
		$offenders = [];
		$flagged = [];

		foreach ($this->libFiles() as $relative => $source) {
			if (preg_match(self::STORAGE_CALL_PATTERN, $source) !== 1) {
				continue;
			}

			if (preg_match(self::DECISION_BINDING_PATTERN, $source) !== 1) {
				continue;
			}

			$flagged[] = $relative;

			if (array_key_exists($relative, self::ALLOWED_DECISION_WRITERS) === true) {
				continue;
			}

			$offenders[] = $relative;
		}

		self::assertNotSame(
			[],
			$flagged,
			'The sweep found no decision-schema writers at all: the detector is broken, not the tree clean.'
		);

		self::assertSame(
			[],
			$offenders,
			"These files write decision-schema objects locally. dossiq owns cases; decidiq owns decisions. Raise the decision in decidiq via ContractDecisionDelegationService (or the dossiq.requestDecision flow node) and let BesluitMaterialisationService record the outcome. Only when the file verifiably records rather than decides may it join ALLOWED_DECISION_WRITERS, with the reason:\n - "
			. implode("\n - ", $offenders)
		);

	}//end testNoFileAuthorsDecisionsOutsideTheAllowlist()


	/**
	 * 🔴 NO NEW LOCAL DECISION EVALUATION.
	 *
	 * The evaluator consumer list is deprecated stock and never grows.
	 */
	public function testNoNewDecisionTableEvaluatorConsumers(): void {
		$offenders = [];
		$flagged = [];

		foreach ($this->libFiles() as $relative => $source) {
			if (preg_match(self::EVALUATOR_IMPORT_PATTERN, $source) !== 1) {
				continue;
			}

			$flagged[] = $relative;

			if (array_key_exists($relative, self::ALLOWED_EVALUATOR_CONSUMERS) === true) {
				continue;
			}

			$offenders[] = $relative;
		}

		self::assertNotSame(
			[],
			$flagged,
			'The sweep found no evaluator consumers at all: the detector is broken, not the tree clean (or flow-decision-tables landed; then empty BOTH lists and delete this guard of the detector).'
		);

		self::assertSame(
			[],
			$offenders,
			"These files import DecisionTableEvaluator. dossiq's decision-table stack is deprecated: rule evaluation moves to OpenRegister's flow-decision-tables. Do not add consumers; put the rule where it is going, not where it is leaving:\n - "
			. implode("\n - ", $offenders)
		);

	}//end testNoNewDecisionTableEvaluatorConsumers()


	/**
	 * The allowlists stay honest: every entry names a file that exists AND
	 * still matches its detector, and carries a reason. A stale entry is a
	 * claim nobody checks any more, so it fails rather than lingers.
	 */
	public function testTheAllowlistsCarryNoStaleEntries(): void {
		$files = $this->libFiles();

		foreach (self::ALLOWED_DECISION_WRITERS as $relative => $reason) {
			self::assertNotSame('', trim($reason), 'An allowlist entry must carry its reason: ' . $relative);
			self::assertArrayHasKey($relative, $files, 'Allowlisted file no longer exists — remove the entry: ' . $relative);
			self::assertSame(
				1,
				preg_match(self::STORAGE_CALL_PATTERN, $files[$relative]),
				'Allowlisted file no longer performs storage — remove the entry: ' . $relative
			);
			self::assertSame(
				1,
				preg_match(self::DECISION_BINDING_PATTERN, $files[$relative]),
				'Allowlisted file no longer binds a decision schema — remove the entry: ' . $relative
			);
		}

		foreach (self::ALLOWED_EVALUATOR_CONSUMERS as $relative => $reason) {
			self::assertNotSame('', trim($reason), 'An allowlist entry must carry its reason: ' . $relative);
			self::assertArrayHasKey($relative, $files, 'Allowlisted file no longer exists — remove the entry: ' . $relative);
			self::assertSame(
				1,
				preg_match(self::EVALUATOR_IMPORT_PATTERN, $files[$relative]),
				'Allowlisted file no longer imports the evaluator — remove the entry: ' . $relative
			);
		}

	}//end testTheAllowlistsCarryNoStaleEntries()


	/**
	 * Every PHP source file under lib/, recursively.
	 *
	 * @return array<string, string> Relative path => source.
	 */
	private function libFiles(): array {
		$root = dirname(__DIR__, 3);
		$files = [];

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($root . '/lib', \FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator as $info) {
			if ($info->isFile() === false || $info->getExtension() !== 'php') {
				continue;
			}

			$source = file_get_contents($info->getPathname());
			self::assertIsString($source, 'Could not read ' . $info->getPathname());
			$files[substr($info->getPathname(), (strlen($root) + 1))] = $source;
		}

		self::assertNotSame([], $files, 'No PHP files under lib/: the sweep is scanning the wrong tree.');

		return $files;

	}//end libFiles()


}//end class
