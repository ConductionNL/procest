<?php

/**
 * Sweeps the repair-step registration in appinfo/info.xml.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * A repair step that is not in `<install>` does not run on a fresh install.
 *
 * Nextcloud's `Installer::installAppLastSteps()` guards BOTH the pre- and
 * post-migration blocks with `if ($previousVersion !== '')`. On a first
 * install `installed_version` is empty, so neither block fires and only
 * `repair-steps/install` runs. A step declared post-migration only is
 * therefore INVISIBLE to every new instance — and invisibly so: nothing errors,
 * the step simply never runs and whatever it provisions is absent.
 *
 * That is how `ProvisionAssignedGroups` shipped (dossiq#1729). Its own unit
 * test drove the step's BEHAVIOUR and swept the shipped flow assignees, and
 * passed the whole time, because nothing asserted the step was REGISTERED for
 * the path that needs it. On a fresh rig the group `behandelaars` was absent,
 * the employee-task completion signal was refused fail-closed, and the
 * live-journey suite red at `task-behandelaar`.
 *
 * So this file sweeps rather than asserts one step: every post-migration step
 * must either appear in `<install>` too, or be named in
 * {@see self::INSTALL_EXEMPT} with the reason it operates on data a fresh
 * install does not have. A new step cannot ship without one of the two, which
 * is the property the single assertion would not have had.
 *
 * @coversNothing
 */
class RepairStepRegistrationTest extends TestCase {

	/**
	 * Steps that legitimately do not belong in `<install>`, and why.
	 *
	 * Every entry operates on data a brand-new instance does not have: a
	 * migration of existing rows, a rename over them, a backfill, or a
	 * handover of in-flight work. The reasons are duplicated in the info.xml
	 * comment above the `<install>` block; this table is what a test can fail
	 * on.
	 *
	 * @var array<string, string>
	 */
	private const INSTALL_EXEMPT = [
		'RenameDutchDeadlineColumns' => 'renames columns over existing rows',
		'RenameDutchDirectionValues' => 'rewrites values in existing rows',
		'RenameDutchColumns' => 'renames columns over existing rows',
		'RenameDutchValues' => 'rewrites values in existing rows',
		'RealignLhsActorTypeVocabulary' => 'repairs rows an earlier rename touched',
		'RealignStatutoryVocabulary' => 'repairs rows an earlier rename touched; a fresh install never acquires them',
		'MigrateArchivalToOpenRegister' => 'one-way migration of existing archival rows',
		'MigratePartnersToOrganisations' => 'moves existing ketenpartner rows onto Organisation',
		'MigrateSubsidieRegelingToCaseType' => 'moves existing subsidieRegeling rows onto case types',
		'MigrateAiOversightToHermiq' => 'replays existing audit history into hermiq',
		'MigrateCommitteesToDecidiq' => 'raises existing committees; a fresh install seeds none',
		'ArmTermijnEngineTimers' => 'arms existing TermijnInstances; none exist yet',
		'RetireOriRegister' => 'retires a register a fresh install never had',
		'FoldCasePropertiesOntoCase' => 'backfill over existing cases',
		'BackfillInformatieobjectMetadata' => 'backfill over existing documents',
		'BackfillAdviceRequestObjection' => 'backfill over existing bacAdviceRequests',
		'LinkInFlightContractDecisionsRepair' => 'links in-flight contract decisions; none exist yet',
		'LinkInFlightRemainingDecisionsRepair' => 'links in-flight decisions; none exist yet',
		'RepairDemoDataSchemaFork' => 'retires a schema fork only an already-enabled app could have made',
	];

	/**
	 * The repair-steps blocks as declared, keyed by block name.
	 *
	 * @return array<string, array<int, string>> Fully-qualified class names per block.
	 */
	private function blocks(): array {
		$path = __DIR__ . '/../../../appinfo/info.xml';
		$xml = simplexml_load_string((string)file_get_contents($path));
		self::assertInstanceOf(SimpleXMLElement::class, $xml, 'appinfo/info.xml must parse.');

		$blocks = [];
		foreach (['pre-migration', 'post-migration', 'install', 'uninstall'] as $block) {
			$steps = [];
			foreach (($xml->{'repair-steps'}->{$block}->step ?? []) as $step) {
				$steps[] = trim((string)$step);
			}

			$blocks[$block] = $steps;
		}

		return $blocks;
	}//end blocks()

	/**
	 * The class basename of a fully-qualified step name.
	 *
	 * @param string $class The fully-qualified class name.
	 *
	 * @return string The basename.
	 */
	private function shortName(string $class): string {
		$parts = explode('\\', $class);

		return (string)end($parts);
	}//end shortName()

	/**
	 * THE SWEEP: no post-migration step may be unregistered and unexplained.
	 *
	 * @return void
	 */
	public function testEveryPostMigrationStepIsEitherInstalledOrExplicitlyExempt(): void {
		$blocks = $this->blocks();
		self::assertNotSame([], $blocks['post-migration'], 'The post-migration block must declare steps, or this sweep is vacuous.');
		self::assertNotSame([], $blocks['install'], 'The install block must declare steps, or this sweep is vacuous.');

		$installed = array_map([$this, 'shortName'], $blocks['install']);

		$unregistered = [];
		foreach ($blocks['post-migration'] as $step) {
			$name = $this->shortName($step);
			if (in_array($name, $installed, true) === true) {
				continue;
			}

			if (array_key_exists($name, self::INSTALL_EXEMPT) === true) {
				continue;
			}

			$unregistered[] = $name;
		}

		self::assertSame(
			[],
			$unregistered,
			"These repair steps run on upgrade but NOT on a fresh install, and nothing says why.\n"
			. "Nextcloud skips the post-migration block entirely when installed_version is empty, so a\n"
			. "brand-new instance never runs them and whatever they provision is simply absent.\n"
			. "Either add each to <install> in appinfo/info.xml, or add it to INSTALL_EXEMPT with the\n"
			. "reason it operates on data a fresh install does not have:\n - "
			. implode("\n - ", $unregistered)
		);
	}//end testEveryPostMigrationStepIsEitherInstalledOrExplicitlyExempt()

	/**
	 * A step must not run before the step that provisions what it reads.
	 *
	 * 🔴 THE ORDER IS NOT DOCUMENTATION, IT IS THE CONTRACT. `info.xml` runs
	 * the steps in the order they are written, and a step registered ahead of
	 * its own precondition cannot succeed even once. Both blocks shipped
	 * `SeedVthWorkflowTemplates` ahead of `VthSeedDataRepairStep`, which
	 * provisions the case types five of the six templates resolve. Every
	 * fresh install therefore reported "0 seeded, 5 skipped", in an
	 * info-level line, and carried on green: the VTH catalogue was absent on
	 * every new instance and nothing ever failed.
	 *
	 * The pairs are asserted rather than the whole order, because only the pairs
	 * with a real data dependency are worth freezing. Add a pair here when you
	 * find a step whose own output tells the operator to run something else
	 * first, which is the tell that found the first one.
	 *
	 * 🔴 MigrateWorkflowDefinitions IS THE SECOND ONE, AND ITS TELL WAS A
	 * COUNT RATHER THAN A MESSAGE. It projects a basis workflow onto every case
	 * type that has none, so it can only project the case types that already
	 * exist. It ran between SeedBezwaarBeroepData and SeedBesluitvormingTemplates,
	 * ahead of the besluitvorming, VTH and case-flow seeds, and said "migrated 0"
	 * as success. Measured on a clean rig on 2026-09-04: 6 templates after a
	 * fresh install and 15 after a repair pass nobody was told to run.
	 *
	 * @return void
	 */
	public function testAStepNeverRunsBeforeWhatItDependsOn(): void {
		$dependencies = [
			// consumer => providers.
			'SeedVthWorkflowTemplates' => ['VthSeedDataRepairStep'],
			'SeedBezwaarWorkflowDefinition' => ['SeedBezwaarBeroepData'],
			'MigrateWorkflowDefinitions' => [
				'SeedBezwaarBeroepData',
				'SeedBesluitvormingTemplates',
				'VthSeedDataRepairStep',
				'SeedVthWorkflowTemplates',
				'CaseFlowSeedDataRepairStep',
			],
		];

		foreach ($this->blocks() as $block => $steps) {
			$order = array_map([$this, 'shortName'], $steps);

			foreach ($dependencies as $consumer => $providers) {
				foreach ($providers as $provider) {
					$consumerAt = array_search($consumer, $order, true);
					$providerAt = array_search($provider, $order, true);

					if ($consumerAt === false || $providerAt === false) {
						continue;
					}

					self::assertLessThan(
						$consumerAt,
						$providerAt,
						sprintf(
							'In the <%s> block, %s runs before %s, which provisions the data it reads. '
							. 'That step can never succeed on a fresh install, and it reports the failure '
							. 'as a skip rather than an error.',
							$block,
							$consumer,
							$provider
						)
					);
				}
			}
		}
	}//end testAStepNeverRunsBeforeWhatItDependsOn()

	/**
	 * The exemption table may not outlive the steps it excuses.
	 *
	 * A stale entry is how an exemption silently starts covering a step that
	 * has since become baseline-creating.
	 *
	 * @return void
	 */
	public function testNoExemptionIsStale(): void {
		$blocks = $this->blocks();
		$declared = array_map([$this, 'shortName'], array_merge($blocks['post-migration'], $blocks['install']));

		$stale = [];
		foreach (array_keys(self::INSTALL_EXEMPT) as $name) {
			if (in_array($name, $declared, true) === false) {
				$stale[] = $name;
			}
		}

		self::assertSame([], $stale, 'These INSTALL_EXEMPT entries name steps info.xml no longer declares: ' . implode(', ', $stale));
	}//end testNoExemptionIsStale()

	/**
	 * A step that is exempt from `<install>` must not also be listed there.
	 *
	 * @return void
	 */
	public function testNoExemptStepIsAlsoInstalled(): void {
		$installed = array_map([$this, 'shortName'], $this->blocks()['install']);

		$contradictory = array_values(array_intersect($installed, array_keys(self::INSTALL_EXEMPT)));

		self::assertSame([], $contradictory, 'These steps are both installed and marked exempt: ' . implode(', ', $contradictory));
	}//end testNoExemptStepIsAlsoInstalled()

	/**
	 * Every declared step names a class that actually exists.
	 *
	 * A typo in info.xml is a step that never runs, and Nextcloud logs the
	 * missing class rather than failing the install.
	 *
	 * @return void
	 */
	public function testEveryDeclaredStepClassExists(): void {
		$blocks = $this->blocks();

		$missing = [];
		foreach (array_merge($blocks['pre-migration'], $blocks['post-migration'], $blocks['install'], $blocks['uninstall']) as $step) {
			$relative = str_replace('OCA\\Dossiq\\', '', $step);
			$path = __DIR__ . '/../../../lib/' . str_replace('\\', '/', $relative) . '.php';
			if (file_exists($path) === false) {
				$missing[] = $step;
			}
		}

		self::assertSame([], $missing, 'These repair steps are declared in info.xml but have no class file: ' . implode(', ', $missing));
	}//end testEveryDeclaredStepClassExists()

	/**
	 * Group provisioning precedes every step that seeds work assigned to one.
	 *
	 * Ordering is load-bearing in both blocks: the shipped flow's completion
	 * gate resolves group membership, and a group created after the flow data
	 * lands leaves the seeded work uncompletable until the next repair run.
	 *
	 * @return void
	 */
	public function testGroupProvisioningPrecedesTheSeedsInEveryBlock(): void {
		foreach (['post-migration', 'install'] as $block) {
			$steps = array_map([$this, 'shortName'], $this->blocks()[$block]);

			$provision = array_search('ProvisionAssignedGroups', $steps, true);
			$caseFlow = array_search('CaseFlowSeedDataRepairStep', $steps, true);

			self::assertIsInt($provision, 'ProvisionAssignedGroups must be declared in the ' . $block . ' block.');
			self::assertIsInt($caseFlow, 'CaseFlowSeedDataRepairStep must be declared in the ' . $block . ' block.');
			self::assertLessThan(
				$caseFlow,
				$provision,
				'ProvisionAssignedGroups must run before the case-flow seed in the ' . $block . ' block: '
				. 'the completion gate fails closed on a group that does not exist yet.'
			);
		}
	}//end testGroupProvisioningPrecedesTheSeedsInEveryBlock()

}//end class
