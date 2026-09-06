<?php

/**
 * Sweeps every registered repair step for the system-identity elevation.
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
 * A repair step that writes through OpenRegister without an identity writes nothing.
 *
 * A repair step runs during install and `occ upgrade`, where there is no
 * Nextcloud session. OpenRegister then resolves the actor as 'Anonymous' and
 * refuses every create/update/delete (openregister#1955), and refuses reads too
 * on any schema without an explicit `public` grant. The refusal is reported
 * with `$output->warning()`, which does not fail an upgrade — so the install
 * prints "Update successful" while nothing was written.
 *
 * dossiq#1729 fixed one step. The acceptance proof then found the next one
 * (`LoadDefaultZgwMappings::createDefaultKanalen()`, refused on every fresh
 * install with `User 'Anonymous' does not have permission to 'create' objects
 * in schema 'Notification Channel'`) and, behind it, `SeedDataService` and
 * `ArmTermijnEngineTimers`. Fixing one instance of that class is not fixing the
 * class, which is what this file is for.
 *
 * THE SWEEP IS OVER THE SHIPPED REGISTRATION, NOT A HAND-WRITTEN LIST. It reads
 * `appinfo/info.xml`, resolves each step's WRITE SURFACE (the step's own source
 * plus the dossiq services it constructor-injects, one level deep — which is
 * where every seed step's writes actually live), and requires that a surface
 * containing an OpenRegister object write also references one of the two
 * elevation mechanisms the app ships. A step whose surface writes nothing must
 * be named in {@see self::WRITES_NOTHING_THROUGH_OPENREGISTER} with a reason,
 * and that exemption is verified rather than trusted: an exempt step whose
 * surface acquires a write fails here.
 *
 * @coversNothing
 */
class SeedWriteIdentityTest extends TestCase {

	/**
	 * The two elevation mechanisms the app ships, either of which counts.
	 *
	 * `RunsUnderSystemIdentity::withSystemIdentity()` is the repair-step trait;
	 * `SearchesObjects::runAsSystemIfAvailable()` is the service-side one. Both
	 * funnel into `ObjectService::runAsSystem()` and both fall through when the
	 * deployed OpenRegister predates it.
	 *
	 * MATCHED AS CALL SITES, NOT AS NAMES. A bare `runAsSystemIfAvailable(`
	 * also matches the trait's own DECLARATION, and the trait file is pulled
	 * into any surface whose step imports it — so every `use SearchesObjects`
	 * step passed without ever calling the thing. That is exactly the shape of
	 * defect this file exists to catch, so it must not be the shape of the
	 * check.
	 *
	 * @var array<int, string>
	 */
	private const ELEVATION_MARKERS = [
		'$this->withSystemIdentity(',
		'$this->runAsSystemIfAvailable(',
		'->runAsSystem(',
		'->runElevated(',
	];

	/**
	 * Calls that reach an OpenRegister object, in either direction.
	 *
	 * READS ARE IN HERE TOO, AND DELIBERATELY. Anonymous is fail-closed for
	 * create/update/delete on every schema and for READ on any schema without
	 * an explicit `public` grant — and a refused read is the quieter half:
	 * `ArmTermijnEngineTimers` reported "schemas unconfigured" and
	 * `MigrateAiOversightToHermiq` reported "no audit entries to consider",
	 * both of which read as a healthy empty instance.
	 *
	 * `ConsumerMapper` / `VerwerkingsactiviteitMapper` calls are deliberately
	 * NOT here: those are QBMapper tables of OpenRegister's own, reached
	 * directly rather than through `ObjectService`, and they carry no
	 * per-object RBAC to be refused by.
	 *
	 * @var array<int, string>
	 */
	private const WRITE_MARKERS = [
		'->saveObject(',
		'saveObjectAsArray(',
		'searchObjectsAsArrays(',
		'->searchObjectsPaginated(',
	];

	/**
	 * Registered steps whose write surface reaches no OpenRegister object write.
	 *
	 * Each reason says what the step does INSTEAD, so a step that later grows a
	 * write cannot hide behind an entry written when it had none. The assertion
	 * below re-derives that from the source, so a stale entry is a failure.
	 *
	 * @var array<string, string>
	 */
	private const WRITES_NOTHING_THROUGH_OPENREGISTER = [
		'MigrateAppConfigKeys' => 'moves oc_appconfig rows',
		'MigrateUserPreferences' => 'moves oc_preferences rows',
		'MigrateRegisterSlug' => 'renames one column on the register row',
		'MigrateSchemaApplicationId' => 'moves openregister_schemas.application',
		'MigrateRegisterApplicationId' => 'delegates to OpenRegister SchemaApplicationMigrator',
		'RenameDutchSchemaSlugs' => 'renames schema slugs on existing rows',
		'InitializeSettings' => 'triggers the configuration import; writes no objects',
		'ProvisionAssignedGroups' => 'creates Nextcloud groups through IGroupManager',
		'SeedBezwaarWorkflowDefinition' => 'elevates in run(); see the marker assertion',
		'SeedBesluitvormingTemplates' => 'delegates to BesluitvormingTemplateService, which elevates',
		'SeedVthWorkflowTemplates' => 'writes through VthSeedLookup::runElevated()',
		'RetireOriRegister' => 'deletes a register row through the register mapper',
		'RenameDutchColumns' => 'renames magic-table columns in SQL',
		'RenameDutchDeadlineColumns' => 'renames magic-table columns in SQL',
		'RenameDutchDirectionValues' => 'rewrites column values in SQL',
		'RenameDutchValues' => 'rewrites column values in SQL',
		'MigrateCommitteesToDecidiq' => 'dispatches to the decision app over the delegation seam',
		'SeedVerwerkingsactiviteiten' => 'writes through OpenRegister VerwerkingsactiviteitMapper (a QBMapper, no object RBAC)',
	];

	/**
	 * Every step class name declared in either info.xml block.
	 *
	 * @return array<int, string> Short class names.
	 */
	private function registeredSteps(): array {
		$xml = new SimpleXMLElement((string)file_get_contents(__DIR__ . '/../../../appinfo/info.xml'));
		$names = [];
		foreach ($xml->xpath('//repair-steps//step') ?: [] as $step) {
			$parts = explode('\\', trim((string)$step));
			$names[] = end($parts);
		}

		return array_values(array_unique($names));
	}//end registeredSteps()

	/**
	 * The source files a step's writes can reach: itself plus its injected dossiq services.
	 *
	 * One level deep on purpose. Every seed step in this app is a thin wrapper
	 * over one service, and that service is where the writes are — going deeper
	 * would pull in the whole graph and make the sweep meaningless.
	 *
	 * @param string $step Short class name of the repair step.
	 *
	 * @return array<string, string> Path => source.
	 */
	private function writeSurface(string $step): array {
		$root = __DIR__ . '/../../../lib';
		$stepPath = $root . '/Repair/' . $step . '.php';
		if (file_exists($stepPath) === false) {
			return [];
		}

		$surface = [$stepPath => (string)file_get_contents($stepPath)];

		// Constructor-injected dossiq collaborators, resolved from the `use`
		// statements — the same names the promoted constructor properties are
		// typed with.
		preg_match_all('/^use (OCA\\\\Dossiq\\\\(?:Service|Repair)\\\\[A-Za-z0-9_\\\\]+);$/m', $surface[$stepPath], $matches);
		foreach (($matches[1] ?? []) as $fqcn) {
			$relative = str_replace(['OCA\\Dossiq\\', '\\'], ['', '/'], $fqcn);

			// The MECHANISMS are not part of the surface. `SearchesObjects`
			// and `RunsUnderSystemIdentity` are traits whose own bodies carry
			// both an object call and an elevation call, so leaving them in
			// made every step that merely IMPORTS one look both writing and
			// elevated. A check satisfied by importing the fix is no check.
			if (str_starts_with($relative, 'Service/Support/') === true
				|| str_starts_with($relative, 'Repair/Support/') === true
			) {
				continue;
			}

			$path = $root . '/' . $relative . '.php';
			if (file_exists($path) === true && isset($surface[$path]) === false) {
				$surface[$path] = (string)file_get_contents($path);
			}
		}

		return $surface;
	}//end writeSurface()

	/**
	 * Whether any file in a surface carries one of the given markers.
	 *
	 * @param array<string, string> $surface Path => source.
	 * @param array<int, string> $markers Substrings to look for.
	 *
	 * @return bool True when at least one file carries at least one marker.
	 */
	private function surfaceHas(array $surface, array $markers): bool {
		foreach ($surface as $source) {
			foreach ($markers as $marker) {
				if (str_contains($source, $marker) === true) {
					return true;
				}
			}
		}

		return false;
	}//end surfaceHas()

	/**
	 * Every registered step that writes objects must elevate first.
	 *
	 * @return void
	 */
	public function testEveryWritingRepairStepRunsUnderASystemIdentity(): void {
		$steps = $this->registeredSteps();
		self::assertGreaterThan(30, count($steps), 'The registration sweep read no steps; it cannot have checked any.');

		$writing = [];
		$unelevated = [];
		foreach ($steps as $step) {
			$surface = $this->writeSurface($step);
			self::assertNotSame([], $surface, sprintf('Repair step %s is registered but has no source file.', $step));

			if ($this->surfaceHas($surface, self::WRITE_MARKERS) === false) {
				continue;
			}

			$writing[] = $step;
			if ($this->surfaceHas($surface, self::ELEVATION_MARKERS) === false) {
				$unelevated[] = $step;
			}
		}

		// The positive control: an empty query must not read as clean.
		self::assertGreaterThan(
			10,
			count($writing),
			'The sweep found almost no writing steps, so it cannot have proven anything about them.'
		);

		self::assertSame(
			[],
			$unelevated,
			sprintf(
				'These repair steps write objects through OpenRegister with no system identity, so every write is '
				. "refused as 'Anonymous' and the install still reports success: %s",
				implode(', ', $unelevated)
			)
		);
	}//end testEveryWritingRepairStepRunsUnderASystemIdentity()

	/**
	 * No exemption may be stale: an exempt step must still write nothing.
	 *
	 * @return void
	 */
	public function testNoExemptStepHasAcquiredAnObjectWrite(): void {
		$stale = [];
		foreach (self::WRITES_NOTHING_THROUGH_OPENREGISTER as $step => $reason) {
			$surface = $this->writeSurface($step);
			self::assertNotSame([], $surface, sprintf('Exempt step %s no longer exists; remove the entry.', $step));
			self::assertNotSame('', $reason, sprintf('Exemption for %s carries no reason.', $step));

			if ($this->surfaceHas($surface, self::WRITE_MARKERS) === true
				&& $this->surfaceHas($surface, self::ELEVATION_MARKERS) === false
			) {
				$stale[] = $step;
			}
		}

		self::assertSame(
			[],
			$stale,
			sprintf(
				'These steps are exempted as writing nothing through OpenRegister, but their write surface now '
				. 'carries an unelevated object write: %s',
				implode(', ', $stale)
			)
		);
	}//end testNoExemptStepHasAcquiredAnObjectWrite()

	/**
	 * Every exemption must name a step that is actually registered.
	 *
	 * @return void
	 */
	public function testEveryExemptionNamesARegisteredStep(): void {
		$registered = $this->registeredSteps();
		foreach (array_keys(self::WRITES_NOTHING_THROUGH_OPENREGISTER) as $step) {
			self::assertContains(
				$step,
				$registered,
				sprintf('%s is exempted here but is not registered in appinfo/info.xml.', $step)
			);
		}
	}//end testEveryExemptionNamesARegisteredStep()
}//end class
