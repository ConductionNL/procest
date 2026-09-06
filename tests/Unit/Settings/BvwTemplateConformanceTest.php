<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Transitions\TransitionSpecReader;
use PHPUnit\Framework\TestCase;

/**
 * Conformance of the shipped besluitvorming (bvw) template bundles.
 *
 * These bundles are seeded verbatim on every fresh install, so a defect in
 * them is a defect on every fresh install. The "One engine" acceptance proof
 * found three, and each test here encodes one so it cannot ship again:
 *
 * 1. ACTION PLACEMENT. The transition engine dispatches ONLY the actions the
 *    real {@see TransitionSpecReader} extracts from a TRANSITION. The bundles
 *    declared `besluitvormingActivate` / `besluitvormingPublish` on STEPS,
 *    a position nothing reads: "Start parafering" returned 200 with
 *    `dispatchedActions: []` and no parafering was ever raised. The test
 *    drives the real reader over the shipped JSON, so it asserts what the
 *    engine actually sees, not what the file looks like.
 *
 * 2. RESULT-TYPE VOCABULARY. The register's resultType schema constrains
 *    `archivalAction` to a Dutch enum; the bundles wrote English
 *    `keep`/`destroy`, failing 9 objects at enable on every install.
 *
 * 3. INITIAL STATUS. A caseType without an initial status bears cases that
 *    are born statusless when created through the API. Each bundle must name
 *    its initial status, and the name must resolve to a declared statusType.
 *
 * @covers \OCA\Dossiq\Service\Transitions\TransitionSpecReader
 */
class BvwTemplateConformanceTest extends TestCase {

	/**
	 * The shipped bvw template bundle files.
	 *
	 * @return array<string, array<string, mixed>> Decoded bundles keyed by filename.
	 */
	private function shippedBundles(): array {
		$dir = __DIR__ . '/../../../lib/Settings/templates';
		$bundles = [];
		foreach ((array)glob($dir . '/bvw-*.json') as $file) {
			$decoded = json_decode((string)file_get_contents((string)$file), true);
			$this->assertIsArray($decoded, basename((string)$file) . ' must be valid JSON');
			$bundles[basename((string)$file)] = $decoded;
		}

		$this->assertNotEmpty($bundles, 'No shipped bvw template bundles found');

		return $bundles;
	}

	/**
	 * Collect every action type declared anywhere under a node.
	 *
	 * @param mixed $node The JSON node.
	 * @param array<int, string> $types Accumulator of action type names.
	 *
	 * @return void
	 */
	private function collectDeclaredActionTypes(mixed $node, array &$types): void {
		if (is_array($node) === false) {
			return;
		}

		foreach ($node as $key => $value) {
			if (($key === 'automaticActions' || $key === 'actions') && is_array($value) === true) {
				foreach ($value as $action) {
					if (is_array($action) === true && isset($action['type']) === true) {
						$types[] = (string)$action['type'];
					}
				}
			}

			$this->collectDeclaredActionTypes(node: $value, types: $types);
		}
	}

	/**
	 * Every declared automaticAction sits where the engine actually reads it.
	 *
	 * The engine's sole action source is TransitionSpecReader::extractActions()
	 * over a TRANSITION (StatusTransitionService::execute()). So: no step may
	 * carry automaticActions, and every action type the bundle declares must be
	 * extracted by the real reader from at least one transition.
	 *
	 * @return void
	 */
	public function testEveryDeclaredAutomaticActionSitsWhereTheEngineReads(): void {
		$reader = new TransitionSpecReader();

		foreach ($this->shippedBundles() as $file => $bundle) {
			$workflow = (array)($bundle['caseType']['workflowTemplate'] ?? []);
			$this->assertNotEmpty($workflow, $file . ' must ship a workflowTemplate');

			// No step may carry actions: the engine never reads them there.
			foreach ((array)($workflow['steps'] ?? []) as $index => $step) {
				foreach (['automaticActions', 'actions'] as $key) {
					$this->assertArrayNotHasKey(
						$key,
						(array)$step,
						sprintf(
							'%s step[%d] ("%s") declares %s, a position the transition engine never reads — move them to the transition that enters this status',
							$file,
							(int)$index,
							(string)($step['statusName'] ?? ''),
							$key
						)
					);
				}
			}

			// Everything declared must be dispatchable: extracted by the real
			// reader from some transition.
			$declared = [];
			$this->collectDeclaredActionTypes(node: $workflow, types: $declared);
			$this->assertNotEmpty($declared, $file . ' is expected to declare automatic actions');

			$extracted = [];
			foreach ((array)($workflow['transitions'] ?? []) as $transition) {
				foreach ($reader->extractActions(transition: (array)$transition) as $action) {
					$extracted[] = (string)($action['type'] ?? '');
				}
			}

			foreach (array_unique($declared) as $type) {
				$this->assertContains(
					$type,
					$extracted,
					sprintf(
						'%s declares action "%s" at a position TransitionSpecReader never extracts it from — the engine would silently drop it',
						$file,
						$type
					)
				);
			}
		}//end foreach
	}

	/**
	 * The parafering seam arms when parafering STARTS, not when it ends.
	 *
	 * besluitvormingActivate must be extracted from the transition INTO the
	 * Parafering status, and besluitvormingPublish (where declared) from the
	 * transition INTO Bekendmaking.
	 *
	 * @return void
	 */
	public function testSeamActionsFireOnTheTransitionEnteringTheirStatus(): void {
		$reader = new TransitionSpecReader();

		$expectations = [
			'besluitvormingActivate' => 'Parafering',
			'besluitvormingPublish' => 'Bekendmaking',
		];

		foreach ($this->shippedBundles() as $file => $bundle) {
			$workflow = (array)($bundle['caseType']['workflowTemplate'] ?? []);
			$declared = [];
			$this->collectDeclaredActionTypes(node: $workflow, types: $declared);

			foreach ($expectations as $type => $toStatusName) {
				if (in_array($type, $declared, true) === false) {
					continue;
				}

				$carriers = [];
				foreach ((array)($workflow['transitions'] ?? []) as $transition) {
					foreach ($reader->extractActions(transition: (array)$transition) as $action) {
						if ((string)($action['type'] ?? '') === $type) {
							$carriers[] = (string)($transition['toStatusName'] ?? '');
						}
					}
				}

				$this->assertSame(
					[$toStatusName],
					$carriers,
					sprintf(
						'%s must dispatch %s exactly once, on the transition entering "%s"',
						$file,
						$type,
						$toStatusName
					)
				);
			}
		}//end foreach
	}

	/**
	 * Shipped resultTypes speak the register's archivalAction vocabulary.
	 *
	 * The enum is read from the shipped register file rather than restated, so
	 * a vocabulary change there re-checks the bundles automatically.
	 *
	 * @return void
	 */
	public function testResultTypeArchivalActionsMatchTheRegisterEnum(): void {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'),
			true
		);
		$enum = (array)($register['components']['schemas']['resultType']['properties']['archivalAction']['enum'] ?? []);
		$this->assertNotEmpty($enum, 'The resultType schema must constrain archivalAction');

		foreach ($this->shippedBundles() as $file => $bundle) {
			foreach ((array)($bundle['caseType']['resultTypes'] ?? []) as $index => $resultType) {
				$action = (string)(((array)$resultType)['archivalAction'] ?? '');
				if ($action === '') {
					continue;
				}

				$this->assertContains(
					$action,
					$enum,
					sprintf(
						'%s resultTypes[%d] ("%s") writes archivalAction "%s", which the register enum (%s) refuses at enable',
						$file,
						(int)$index,
						(string)(((array)$resultType)['name'] ?? ''),
						$action,
						implode(', ', $enum)
					)
				);
			}
		}
	}

	/**
	 * Every bundle names its initial status, and the name resolves.
	 *
	 * @return void
	 */
	public function testEveryCaseTypeNamesAResolvableInitialStatus(): void {
		foreach ($this->shippedBundles() as $file => $bundle) {
			$caseType = (array)($bundle['caseType'] ?? []);
			$initial = (string)($caseType['initialStatusName'] ?? '');
			$this->assertNotSame(
				'',
				$initial,
				$file . ' caseType declares no initialStatusName: an API-created case would be born statusless'
			);

			$statusNames = [];
			foreach ((array)($caseType['statusTypes'] ?? []) as $statusType) {
				$statusNames[] = (string)(((array)$statusType)['name'] ?? '');
			}

			$this->assertContains(
				$initial,
				$statusNames,
				$file . ' initialStatusName must name a declared statusType'
			);
		}
	}
}//end class
