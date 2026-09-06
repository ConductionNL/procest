<?php

/**
 * The class-catching test for local status machinery.
 *
 * THE DIRECTIVE, the third sibling of {@see LocalParaferingRuntimeTest} and
 * {@see LocalDecisionAuthoringTest}. OpenRegister owns the fleet's transition
 * sequencing: flows and their nodes for flow-driven changes, FlowTimers for
 * timer-driven ones, and the declarative `x-openregister-lifecycle` (validated
 * by its LifecycleAnnotationValidator, driven by its TransitionEngine) for
 * static, enum-anchored machines. dossiq keeps exactly two kinds of status
 * code: the single write path for the per-caseType dynamic case machine
 * ({@see \OCA\Dossiq\Service\StatusTransitionService}, until OR supports
 * FK-based status graphs), and domain tables that agree with a declared
 * schema lifecycle while their thinning is staged.
 *
 * The sweep that got here retired a dead second entry point to the engine
 * (WorkflowEngineService, zero production callers) and a dead mini machine
 * (VergaderingCaseService plus its nightly job, writing literal strings into
 * a statusType-reference field). Fixing instances one by one is how the next
 * one ships, so this test asserts the END STATE over every file under lib/.
 *
 * THE RULE, mechanically. Three closed sets:
 *
 * 1. NO RETIRED CLASS RETURNS. None of the retired machinery classes may
 *    exist as a file under lib/ again.
 * 2. THE TRANSITION-TABLE CENSUS IS CLOSED. A file declaring a transition
 *    table constant (`const *TRANSITIONS =` / `const *VALID_STATUSES =`)
 *    must sit in ALLOWED_TABLES with the reason it may. Everything else
 *    rides the engine.
 * 3. A DECLARED LIFECYCLE AGREES WITH ITS SERVICE. The complaint schema's
 *    x-openregister-lifecycle must mirror ComplaintService::TRANSITIONS
 *    edge for edge in BOTH register manifests, so the Dutch-versus-English
 *    drift this change fixed cannot reopen quietly.
 *
 * WHAT THIS CANNOT SEE. The check is per file and lexical. A machine hidden
 * behind indirection passes; the per-surface unit tests carry the finer
 * assertions. This test exists so a NEW local status machine cannot ship
 * quietly.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/case-status-onto-engine-lifecycle/specs/case-status-machinery/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\ComplaintService;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing Structural assertion over source files, not behaviour.
 */
class LocalStatusMachineryTest extends TestCase {

	/**
	 * A transition-table constant declaration. Anchored to `const` so a
	 * docblock mention alone does not flag.
	 *
	 * @var string
	 */
	private const TABLE_PATTERN = '/\bconst\s+[A-Z_]*(TRANSITIONS|VALID_STATUSES)\s*=/';

	/**
	 * The retired machinery classes. None may exist as a file under lib/
	 * again.
	 *
	 * @var array<int, string>
	 */
	private const RETIRED_CLASSES = [
		'WorkflowEngineService',
		'VergaderingCaseService',
		'VergaderingDeadlineJob',
	];

	/**
	 * The CLOSED allowlist of files that may declare a transition table,
	 * each with the reason it may. An entry whose file stops matching fails
	 * the suite, so the list cannot rot silently.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED_TABLES = [
		'lib/Service/StateMachineService.php' => 'the AWB beschikking legal machine with content immutability and immutable stateMachineLog records; termijnbewaking-op-engine-timers 2.2 keeps it explicitly and moves only the cron trigger.',
		'lib/Service/Zaakdossier/InformatieobjectStatusLifecycle.php' => 'the ZGW/DRC-mandated document status canon; also declared on the document schema lifecycle. This file is the single definition.',
		'lib/Service/ZaakdossierService.php' => 're-exports of the InformatieobjectStatusLifecycle canon for existing callers; declares no machine of its own.',
		'lib/Service/ComplaintService.php' => 'agrees edge for edge with the complaint schema x-openregister-lifecycle (asserted below); thinning onto OR TransitionEngine is staged in case-status-onto-engine-lifecycle tasks 5.1.',
		'lib/Service/ConsultationService.php' => 'agrees with the consultation schema x-openregister-lifecycle; thinning onto OR TransitionEngine is staged in case-status-onto-engine-lifecycle tasks 5.1.',
		'lib/Service/AdviceService.php' => 'membership check beside AdviceAuthorizationGuard (domain authz); the declared adviesAanvraag lifecycle agrees; thinning follows termijnbewaking-op-engine-timers 2.5 (the expiry timer) per case-status-onto-engine-lifecycle tasks 5.3.',
		'lib/Service/Subsidie/SubsidieService.php' => 'subsidy process domain machine without a declared schema lifecycle yet; declaring one and thinning is staged in case-status-onto-engine-lifecycle tasks 5.2.',
		'lib/Service/Bezwaar/AdvisoryCommitteeService.php' => 'committee machinery owned end to end by the migrate-committees-to-decidiq change; it leaves dossiq entirely rather than moving onto the engine here.',
		'lib/Service/TenantSaasService.php' => 'tenant lifecycle, not case machinery; owned by the tenancy-onto-openregister-organisation change.',
	];

	/**
	 * 🔴 NO RETIRED MACHINERY CLASS COMES BACK.
	 *
	 * Re-add WorkflowEngineService, VergaderingCaseService or the vergadering
	 * job and this goes red naming it, before it meets a case.
	 */
	public function testNoRetiredMachineryClassExists(): void {
		$files = $this->libFiles();
		$offenders = [];

		foreach (self::RETIRED_CLASSES as $class) {
			foreach (array_keys($files) as $relative) {
				if (basename($relative, '.php') === $class) {
					$offenders[] = $relative;
				}
			}
		}

		self::assertSame(
			[],
			$offenders,
			"These files are retired status-machinery classes. Status sequencing rides the engine: user and flow transitions through StatusTransitionService and its flow-node seam, timers through FlowTimers, static machines through x-openregister-lifecycle. Do not bring them back:\n - "
			. implode("\n - ", $offenders)
		);
	}//end testNoRetiredMachineryClassExists()

	/**
	 * 🔴 NO FILE DECLARES A TRANSITION TABLE OUTSIDE THE CLOSED ALLOWLIST.
	 *
	 * Add a `const FOO_TRANSITIONS = [...]` or `const VALID_STATUSES = [...]`
	 * to a new service and this goes red naming the file, before it ever
	 * sequences a status.
	 */
	public function testNoFileDeclaresATableOutsideTheAllowlist(): void {
		$offenders = [];
		$flagged = [];

		foreach ($this->libFiles() as $relative => $source) {
			if (preg_match(self::TABLE_PATTERN, $source) !== 1) {
				continue;
			}

			$flagged[] = $relative;

			if (array_key_exists($relative, self::ALLOWED_TABLES) === true) {
				continue;
			}

			$offenders[] = $relative;
		}

		self::assertNotSame(
			[],
			$flagged,
			'The sweep found no transition tables at all: the detector is broken, not the tree clean.'
		);

		self::assertSame(
			[],
			$offenders,
			"These files declare a local transition table. Status sequencing belongs to the engine: declare the machine as x-openregister-lifecycle on the schema (OR validates and drives it), or ride StatusTransitionService for the case machine. Only a domain table with a recorded reason may join ALLOWED_TABLES:\n - "
			. implode("\n - ", $offenders)
		);
	}//end testNoFileDeclaresATableOutsideTheAllowlist()

	/**
	 * The allowlist stays honest: every entry names a file that exists AND
	 * still matches the detector, and carries a reason.
	 */
	public function testTheAllowlistCarriesNoStaleEntries(): void {
		$files = $this->libFiles();

		foreach (self::ALLOWED_TABLES as $relative => $reason) {
			self::assertNotSame('', trim($reason), 'An allowlist entry must carry its reason: ' . $relative);
			self::assertArrayHasKey($relative, $files, 'Allowlisted file no longer exists — remove the entry: ' . $relative);

			self::assertSame(
				1,
				preg_match(self::TABLE_PATTERN, $files[$relative]),
				'Allowlisted file no longer declares a transition table — remove the entry: ' . $relative
			);
		}
	}//end testTheAllowlistCarriesNoStaleEntries()

	/**
	 * 🔴 THE COMPLAINT DECLARATION MIRRORS THE SERVICE TABLE, IN BOTH
	 * MANIFESTS.
	 *
	 * The complaint x-openregister-lifecycle once carried Dutch state names
	 * against an English enum: a declared machine naming states the enum
	 * forbids, dead as written. This pins the fix: the declared edge set
	 * equals ComplaintService::TRANSITIONS, and every declared state is an
	 * enum member, in dossiq_register.json AND dossiq_mock_register.json
	 * (the mock duplicates every slug).
	 */
	public function testTheComplaintLifecycleMirrorsTheServiceTable(): void {
		$constant = new \ReflectionClassConstant(ComplaintService::class, 'TRANSITIONS');
		$table = $constant->getValue();
		self::assertIsArray($table);

		$serviceEdges = [];
		foreach ($table as $from => $tos) {
			foreach ($tos as $to) {
				$serviceEdges[] = $from . ' -> ' . $to;
			}
		}

		sort($serviceEdges);

		$root = dirname(__DIR__, 3);
		$manifests = [
			'lib/Settings/dossiq_register.json',
			'lib/Settings/dossiq_mock_register.json',
		];

		foreach ($manifests as $manifest) {
			$complaint = $this->findComplaintSchema(path: $root . '/' . $manifest);
			self::assertNotNull($complaint, 'No complaint schema found in ' . $manifest);

			$enum = ($complaint['properties']['status']['enum'] ?? []);
			$lifecycle = ($complaint['configuration']['x-openregister-lifecycle'] ?? []);
			self::assertSame('status', ($lifecycle['field'] ?? null), 'Complaint lifecycle must drive the status field: ' . $manifest);

			$declaredEdges = [];
			$declaredStates = [(string)($lifecycle['initial'] ?? '')];
			foreach (($lifecycle['final'] ?? []) as $finalState) {
				$declaredStates[] = (string)$finalState;
			}

			foreach (($lifecycle['transitions'] ?? []) as $transition) {
				$to = (string)($transition['to'] ?? '');
				$declaredStates[] = $to;
				foreach (($transition['from'] ?? []) as $from) {
					$declaredEdges[] = $from . ' -> ' . $to;
					$declaredStates[] = (string)$from;
				}
			}

			sort($declaredEdges);

			self::assertSame(
				$serviceEdges,
				$declaredEdges,
				'The declared complaint lifecycle and ComplaintService::TRANSITIONS drifted apart in ' . $manifest
				. '. They must agree edge for edge until the table is thinned onto OR TransitionEngine (staged tasks 5.1).'
			);

			foreach (array_unique($declaredStates) as $state) {
				self::assertContains(
					$state,
					$enum,
					'Declared lifecycle state "' . $state . '" is not in the complaint status enum in ' . $manifest
					. ' — that is the Dutch-versus-English drift this test exists to block.'
				);
			}
		}//end foreach
	}//end testTheComplaintLifecycleMirrorsTheServiceTable()

	/**
	 * Locate the complaint schema node in a register manifest.
	 *
	 * @param string $path Absolute path to the manifest.
	 *
	 * @return array<string, mixed>|null The complaint schema, or null.
	 */
	private function findComplaintSchema(string $path): ?array {
		$raw = file_get_contents($path);
		self::assertIsString($raw, 'Could not read ' . $path);
		$decoded = json_decode($raw, associative: true);
		self::assertIsArray($decoded, 'Manifest is not valid JSON: ' . $path);

		$stack = [$decoded];
		while ($stack !== []) {
			$node = array_pop($stack);
			if (is_array($node) === false) {
				continue;
			}

			if (($node['slug'] ?? null) === 'complaint' && isset($node['properties']) === true) {
				return $node;
			}

			foreach ($node as $child) {
				if (is_array($child) === true) {
					$stack[] = $child;
				}
			}
		}

		return null;
	}//end findComplaintSchema()

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
