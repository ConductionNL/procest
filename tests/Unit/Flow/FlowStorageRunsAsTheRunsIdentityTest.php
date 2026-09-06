<?php

/**
 * The class-catching test for the runAs defect family — inverted.
 *
 * THE DEFECT CLASS, THEN. OpenRegister's permission gate reads the AMBIENT
 * SESSION user, and under FlowRunWorker that session carries nobody, so every
 * flow handler or node that touched ObjectService storage bare was refused as
 * "User 'Anonymous' does not have permission". dossiq answered with a local
 * `FlowRunAsScope` and this test asserted that every storage-performing flow
 * file wrapped its work in it — because the defect shipped four separate times
 * before the invariant existed.
 *
 * THE DEFECT CLASS, NOW. openregister#3332 moved that duty into the engine:
 * `RegistryStepDispatcher` executes every CONTRIBUTED node inside
 * `ObjectService::runAs()` as the run's validated acting identity, so dossiq's
 * nodes — and the transition/action handlers they delegate to — are scoped
 * before their first line runs. The local wrapper is deleted. The invariant
 * therefore INVERTS: a flow file that reintroduces a manual runAs wrap is now
 * the defect, because it re-creates the per-consumer copy of an engine rule
 * (the copy that drifts is the one nobody looks at), and because the deleted
 * class it would reach for no longer exists to fail loudly at review time.
 *
 * A test that encodes an old requirement goes red when the requirement moves;
 * this is that test, inverted rather than deleted, so the HISTORY of the
 * defect class keeps a guard pointing in the direction that is now correct.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/adopt-flow-engine-consumer-seams/specs/case-flow-human-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Flow;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing Structural assertion over source files, not behaviour.
 */
class FlowStorageRunsAsTheRunsIdentityTest extends TestCase {

	/**
	 * The directories whose classes execute under FlowRunWorker.
	 *
	 * lib/Flow holds the nodes the engine runs; lib/Service/Transitions and
	 * lib/Service/Actions hold the handlers those nodes are thin wrappers
	 * around (see DossiqFlowNodeBase: the flow context is handed to them as
	 * `$transitionContext`).
	 *
	 * @var string[]
	 */
	private const FLOW_DIRECTORIES = [
		'lib/Flow',
		'lib/Service/Transitions',
		'lib/Service/Actions',
	];

	/**
	 * Direct storage work: calls that reach OpenRegister's ObjectService.
	 * Used only as the detector's self-check — proof the sweep still scans
	 * a tree where the invariant has something to protect.
	 *
	 * @var string
	 */
	private const STORAGE_CALL_PATTERN = '/->\s*(saveObject|updateObject|deleteObject|find|findAll|searchObjectsPaginated|buildSearchQuery|getObjectService)\s*\(/';

	/**
	 * The manual-scoping fingerprints a flow file must NOT carry any more.
	 *
	 * `FlowRunAsScope` is the deleted local wrapper; `runAsScope->call(` is
	 * how every wrap invoked it. A direct `ObjectService::runAs()` call is
	 * deliberately NOT forbidden: the occ-driven migrators under these
	 * directories scope their own writes legitimately, because the dispatcher
	 * never executes them.
	 *
	 * @var string[]
	 */
	private const FORBIDDEN_WRAP_FINGERPRINTS = [
		'FlowRunAsScope',
		'runAsScope->call(',
	];

	/**
	 * 🔴 NO FLOW FILE WRAPS ITS STORAGE WORK IN A LOCAL runAs SCOPE.
	 *
	 * The engine's dispatcher scopes every contributed node natively
	 * (openregister#3332); a manual wrap here is the defect now. Add one and
	 * this goes red naming the file.
	 */
	public function testNoFlowFileWrapsRunAsManually(): void {
		$wrapped = [];
		$storagePerforming = 0;

		foreach ($this->flowFiles() as $relative => $source) {
			if (preg_match(self::STORAGE_CALL_PATTERN, $source) === 1) {
				$storagePerforming++;
			}

			foreach (self::FORBIDDEN_WRAP_FINGERPRINTS as $fingerprint) {
				if (str_contains($source, $fingerprint) === true) {
					$wrapped[] = $relative . ' (' . $fingerprint . ')';
				}
			}
		}

		self::assertGreaterThan(
			0,
			$storagePerforming,
			'The sweep found no storage-performing flow files at all: the detector is scanning the wrong tree, not a clean one.'
		);

		self::assertSame(
			[],
			$wrapped,
			"These flow files scope their storage work manually. The engine's RegistryStepDispatcher already executes every contributed node inside ObjectService::runAs() as the run's acting identity (openregister#3332), so a local wrap re-creates the per-consumer copy of an engine rule and nests a second scope inside the dispatcher's. Delete the wrap:\n - "
			. implode("\n - ", $wrapped)
		);
	}//end testNoFlowFileWrapsRunAsManually()

	/**
	 * The deleted wrapper stays deleted.
	 *
	 * `lib/Service/FlowRunAsScope.php` was the local copy of the engine's
	 * scoping rule. Resurrecting the file is the first step of resurrecting
	 * the pattern, so its absence is asserted by name.
	 */
	public function testTheLocalRunAsScopeStaysDeleted(): void {
		$root = dirname(__DIR__, 3);

		self::assertFileDoesNotExist(
			$root . '/lib/Service/FlowRunAsScope.php',
			'lib/Service/FlowRunAsScope.php is back. The engine scopes contributed nodes natively (openregister#3332); dossiq must not keep a second implementation of that rule.'
		);
	}//end testTheLocalRunAsScopeStaysDeleted()

	/**
	 * Every PHP source file in the flow-facing directories.
	 *
	 * @return array<string, string> Relative path => source.
	 */
	private function flowFiles(): array {
		$root = dirname(__DIR__, 3);
		$files = [];

		foreach (self::FLOW_DIRECTORIES as $directory) {
			$paths = glob($root . '/' . $directory . '/*.php');
			self::assertNotFalse($paths, 'Could not list ' . $directory);
			self::assertNotSame([], $paths, 'No PHP files under ' . $directory . ': the sweep is scanning the wrong tree.');

			foreach ($paths as $path) {
				$source = file_get_contents($path);
				self::assertIsString($source, 'Could not read ' . $path);
				$files[substr($path, (strlen($root) + 1))] = $source;
			}
		}

		return $files;
	}//end flowFiles()
}//end class
