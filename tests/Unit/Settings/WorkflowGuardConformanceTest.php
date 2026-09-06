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

use OCA\Dossiq\Service\MandaatValidationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\ChecklistGuard;
use OCA\Dossiq\Service\Transitions\GuardRegistry;
use OCA\Dossiq\Service\Transitions\MandaatGuard;
use OCA\Dossiq\Service\Transitions\RequiredDocumentGuard;
use OCA\Dossiq\Service\Transitions\RequiredFieldGuard;
use OCA\Dossiq\Service\Transitions\RoleGuard;
use OCA\Dossiq\Service\Transitions\TransitionSpecReader;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Conformance of every guard the app ships, against the engine that reads it.
 *
 * The acceptance proof found that every guard in the shipped besluitvorming
 * bundles was dead. They were written as `{"type": "requiredField", "config":
 * {"field": "..."}}`, and each evaluator reads its parameters from the TOP
 * LEVEL of the guard entry, so every guard resolved an empty configuration and
 * answered with its unconfigured-failure. A case could not leave Parafering on
 * any fresh install, and the failure read as "the case is not ready" rather
 * than "this guard was never wired up".
 *
 * A test that reads the JSON and checks its shape would have missed it just as
 * the file review did, because the file looked deliberate. So this test drives
 * the REAL {@see TransitionSpecReader} and the REAL {@see GuardRegistry} over
 * the shipped JSON, and for every guard asserts both halves of being alive:
 *
 * - it RESOLVES its parameters (an unconfigured guard is a defect, not a
 *   stricter guard), and
 * - it can actually PASS on a case that satisfies it and FAIL on one that does
 *   not (a guard that only ever fails closed is indistinguishable from a dead
 *   one from the outside, and a guard that only ever passes cannot fail).
 *
 * Every sweep asserts it FOUND guards before asserting none of them is broken,
 * so an empty query cannot read as clean.
 *
 * @covers \OCA\Dossiq\Service\Transitions\TransitionSpecReader
 * @covers \OCA\Dossiq\Service\Transitions\GuardRegistry
 * @covers \OCA\Dossiq\Service\Transitions\RequiredFieldGuard
 * @covers \OCA\Dossiq\Service\Transitions\RequiredDocumentGuard
 * @covers \OCA\Dossiq\Service\Transitions\RoleGuard
 * @covers \OCA\Dossiq\Service\Transitions\ChecklistGuard
 *
 * @uses \OCA\Dossiq\Service\Transitions\GuardResult
 * @uses \OCA\Dossiq\Service\Transitions\MandaatGuard
 */
class WorkflowGuardConformanceTest extends TestCase {

	/**
	 * Fixture: the tasks the mocked task store answers with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $tasks = [];

	/**
	 * Fixture: the group memberships the mocked group manager reports.
	 *
	 * @var array<int, string>
	 */
	private array $groups = [];

	/**
	 * Every workflow JSON the app ships, keyed by repository-relative path.
	 *
	 * @return array<string, array<string, mixed>> Decoded documents.
	 */
	private function shippedWorkflowDocuments(): array {
		$root = __DIR__ . '/../../../lib/Settings';
		$paths = array_merge(
			(array)glob($root . '/templates/*.json'),
			(array)glob($root . '/vth-templates/*.json'),
			[$root . '/bezwaar_seed_data.json'],
		);

		$documents = [];
		foreach ($paths as $path) {
			$decoded = json_decode((string)file_get_contents((string)$path), true);
			$this->assertIsArray($decoded, basename((string)$path) . ' must be valid JSON');
			$documents[basename((string)$path)] = $decoded;
		}

		$this->assertNotEmpty($documents, 'No shipped workflow JSON found to sweep');

		return $documents;
	}

	/**
	 * Collect every guard entry declared anywhere under a node.
	 *
	 * @param mixed $node The JSON node.
	 * @param string $trail The path walked so far.
	 * @param array<int, array{path: string, guard: array<string, mixed>}> $found Accumulator.
	 *
	 * @return void
	 */
	private function collectGuards(mixed $node, string $trail, array &$found): void {
		if (is_array($node) === false) {
			return;
		}

		foreach ($node as $key => $value) {
			if ($key === 'guards' && is_array($value) === true) {
				foreach ($value as $index => $guard) {
					if (is_array($guard) === true) {
						$found[] = ['path' => $trail . '/guards/' . $index, 'guard' => $guard];
					}
				}
			}

			$this->collectGuards(node: $value, trail: $trail . '/' . $key, found: $found);
		}
	}

	/**
	 * Every guard entry in every shipped workflow document.
	 *
	 * @return array<int, array{path: string, guard: array<string, mixed>}>
	 */
	private function allShippedGuards(): array {
		$found = [];
		foreach ($this->shippedWorkflowDocuments() as $name => $document) {
			$this->collectGuards(node: $document, trail: $name, found: $found);
		}

		return $found;
	}

	/**
	 * The guard entries in the bundles a PHP loader reads.
	 *
	 * `lib/Settings/vth-templates/` is deliberately excluded: no PHP reads that
	 * directory, so its guards never reach an evaluator.
	 * {@see testTheVthTemplatesStillHaveNoLoader()} verifies that exclusion
	 * instead of assuming it, so wiring a loader up makes this test speak.
	 *
	 * @return array<int, array{path: string, guard: array<string, mixed>}>
	 */
	private function loadedGuards(): array {
		return array_values(
			array_filter(
				$this->allShippedGuards(),
				static fn (array $entry): bool => str_starts_with($entry['path'], 'bvw-') === true
					|| str_starts_with($entry['path'], 'bezwaar_seed_data.json') === true,
			)
		);
	}

	/**
	 * The engine's guard pipeline, wired with the real evaluators.
	 *
	 * @return GuardRegistry
	 */
	private function realRegistry(): GuardRegistry {
		$objectService = new class ($this) {
			/**
			 * @param WorkflowGuardConformanceTest $test The owning test.
			 */
			public function __construct(private readonly WorkflowGuardConformanceTest $test) {
			}

			/**
			 * @param string $register The register slug.
			 * @param string $schema The schema slug.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				return $this->test->rowsFor(schema: $schema);
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'task_schema' => 'caseTask',
				default => 'other',
			}
		);

		$user = $this->createMock(IUser::class);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturnCallback(
			fn (string $uid, string $gid): bool => in_array($gid, $this->groups, true)
		);

		return new GuardRegistry(
			new ChecklistGuard($settings, new NullLogger()),
			new RequiredFieldGuard(),
			new RequiredDocumentGuard(),
			new RoleGuard($groupManager, $userManager, new NullLogger()),
			new MandaatGuard($this->createMock(MandaatValidationService::class)),
			new NullLogger(),
		);
	}

	/**
	 * The rows the mocked object store answers for one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function rowsFor(string $schema): array {
		return match ($schema) {
			'caseTask' => $this->tasks,
			default => [],
		};
	}

	/**
	 * Run one shipped guard through the real reader and the real registry.
	 *
	 * @param array<string, mixed> $guard The guard entry as shipped.
	 * @param array<string, mixed> $case The case to evaluate against.
	 *
	 * @return array{type: string, passed: bool, failureMessage: ?string, details: array<string, mixed>}
	 */
	private function evaluateShipped(array $guard, array $case): array {
		$normalised = (new TransitionSpecReader())->extractGuards(transition: ['guards' => [$guard]]);
		$results = $this->realRegistry()->evaluateAll(guards: $normalised, case: $case, userId: 'behandelaar');
		$this->assertCount(1, $results, 'One guard in must be one result out');

		return $results[0];
	}

	/**
	 * Every shipped guard declares its parameters where the engine reads them.
	 *
	 * @return void
	 */
	public function testEveryShippedGuardDeclaresItsParametersAtTheTopLevel(): void {
		$guards = $this->allShippedGuards();
		$this->assertGreaterThanOrEqual(40, count($guards), 'Expected the shipped workflow JSON to declare guards');

		$nested = [];
		foreach ($guards as $entry) {
			if (array_key_exists('config', $entry['guard']) === true) {
				$nested[] = $entry['path'];
			}
		}

		$this->assertSame(
			[],
			$nested,
			"Guards declaring parameters under `config` are read by nothing:\n" . implode("\n", $nested)
		);
	}

	/**
	 * Every shipped guard names a type the registry can evaluate.
	 *
	 * @return void
	 */
	public function testEveryShippedGuardTypeIsRegistered(): void {
		$guards = $this->allShippedGuards();
		$this->assertNotEmpty($guards, 'Expected the shipped workflow JSON to declare guards');

		$unknown = [];
		foreach ($guards as $entry) {
			$result = $this->evaluateShipped(guard: $entry['guard'], case: ['id' => 'case-1']);
			if (($result['details']['unknown'] ?? false) === true) {
				$unknown[] = $entry['path'] . ' (' . $result['type'] . ')';
			}
		}

		$this->assertSame([], $unknown, "Guard types no evaluator is registered for:\n" . implode("\n", $unknown));
	}

	/**
	 * No seeded guard evaluates as unconfigured.
	 *
	 * This is the assertion the shipped bundles failed. Every evaluator answers
	 * an empty configuration with a distinct message, and that message is the
	 * fingerprint of a guard the engine cannot read.
	 *
	 * @return void
	 */
	public function testNoSeededGuardEvaluatesAsUnconfigured(): void {
		$guards = $this->loadedGuards();
		$this->assertGreaterThanOrEqual(16, count($guards), 'Expected the seeded bundles to declare guards');

		$unconfigured = [];
		foreach ($guards as $entry) {
			$result = $this->evaluateShipped(guard: $entry['guard'], case: ['id' => 'case-1']);
			$message = (string)($result['failureMessage'] ?? '');
			if (str_contains($message, 'missing') === true || str_contains($message, 'Onbekende') === true) {
				$unconfigured[] = $entry['path'] . ': ' . $message;
			}
		}

		$this->assertSame(
			[],
			$unconfigured,
			"Guards the engine reads as unconfigured:\n" . implode("\n", $unconfigured)
		);
	}

	/**
	 * Every seeded requiredField guard names a field a case can answer.
	 *
	 * A guard on a field no schema declares and nothing writes can never pass,
	 * which is a dead end dressed as a precondition. `paraferingCompleet` was
	 * exactly that in all three besluitvorming bundles.
	 *
	 * @return void
	 */
	public function testEveryRequiredFieldGuardNamesAFieldACaseCanAnswer(): void {
		$caseProperties = $this->shippedCaseSchemaProperties();
		$this->assertGreaterThan(20, count($caseProperties), 'Expected the shipped case schema to declare properties');

		$declaredByCaseType = $this->shippedPropertyDefinitionNames();

		// Scoped to the besluitvorming bundles, the ones a fresh install seeds.
		// The bezwaar bundle guards on fields that live on its OWN objection,
		// appealDecision and hearingSession objects rather than on the case,
		// which is the same defect on a bundle nobody seeds;
		// {@see testTheBezwaarBundleIsStillParked()} keeps that from going
		// quiet.
		$guards = array_values(
			array_filter(
				$this->loadedGuards(),
				static fn (array $entry): bool => ($entry['guard']['type'] ?? '') === 'requiredField'
					&& str_starts_with($entry['path'], 'bvw-') === true
			)
		);
		$this->assertNotEmpty($guards, 'Expected the seeded bundles to declare requiredField guards');

		$orphans = [];
		foreach ($guards as $entry) {
			$field = (string)($entry['guard']['field'] ?? ($entry['guard']['fieldName'] ?? ''));
			if (in_array($field, $caseProperties, true) === false
				&& in_array($field, $declaredByCaseType, true) === false
			) {
				$orphans[] = $entry['path'] . ': ' . $field;
			}
		}

		$this->assertSame(
			[],
			$orphans,
			"requiredField guards on a field neither the case schema nor a case type declares:\n"
			. implode("\n", $orphans)
		);
	}

	/**
	 * The property names the shipped case schema declares.
	 *
	 * @return array<int, string>
	 */
	private function shippedCaseSchemaProperties(): array {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'),
			true
		);
		$this->assertIsArray($register, 'dossiq_register.json must be valid JSON');

		$case = $register['components']['schemas']['case']['properties'] ?? null;
		$this->assertIsArray($case, 'The shipped register must declare a case schema with properties');

		return array_keys($case);
	}

	/**
	 * The propertyDefinition names the shipped bundles declare on their case types.
	 *
	 * A case answers these in its `properties[]` array rather than as a column,
	 * which is why {@see RequiredFieldGuard} looks in both places.
	 *
	 * @return array<int, string>
	 */
	private function shippedPropertyDefinitionNames(): array {
		$names = [];
		foreach ($this->shippedWorkflowDocuments() as $document) {
			$this->collectPropertyDefinitionNames(node: $document, names: $names);
		}

		return $names;
	}

	/**
	 * Collect propertyDefinition names anywhere under a node.
	 *
	 * @param mixed $node The JSON node.
	 * @param array<int, string> $names Accumulator.
	 *
	 * @return void
	 */
	private function collectPropertyDefinitionNames(mixed $node, array &$names): void {
		if (is_array($node) === false) {
			return;
		}

		foreach ($node as $key => $value) {
			if ($key === 'propertyDefinitions' && is_array($value) === true) {
				foreach ($value as $definition) {
					if (is_array($definition) === true && isset($definition['name']) === true) {
						$names[] = (string)$definition['name'];
					}
				}
			}

			$this->collectPropertyDefinitionNames(node: $value, names: $names);
		}
	}

	/**
	 * Each shipped requiredField guard passes on an answering case and fails otherwise.
	 *
	 * @return void
	 */
	public function testRequiredFieldGuardsPassAndFail(): void {
		$guards = $this->guardsOfType(type: 'requiredField');

		foreach ($guards as $entry) {
			$field = (string)$entry['guard']['field'];

			$answered = $this->evaluateShipped(
				guard: $entry['guard'],
				case: ['id' => 'case-1', 'properties' => [['name' => $field, 'value' => 'ingevuld']]],
			);
			$this->assertTrue($answered['passed'], $entry['path'] . ' must pass when the case answers ' . $field);

			$blank = $this->evaluateShipped(guard: $entry['guard'], case: ['id' => 'case-1', 'properties' => []]);
			$this->assertFalse($blank['passed'], $entry['path'] . ' must fail when the case does not answer ' . $field);
		}
	}

	/**
	 * Each shipped requiredDocument guard passes with the document and fails without.
	 *
	 * @return void
	 */
	public function testRequiredDocumentGuardsPassAndFail(): void {
		$guards = $this->guardsOfType(type: 'requiredDocument');

		foreach ($guards as $entry) {
			$type = (string)$entry['guard']['documentType'];

			$withDoc = $this->evaluateShipped(
				guard: $entry['guard'],
				case: ['id' => 'case-1', 'documents' => [['documentType' => $type]]],
			);
			$this->assertTrue($withDoc['passed'], $entry['path'] . ' must pass when ' . $type . ' is attached');

			$without = $this->evaluateShipped(guard: $entry['guard'], case: ['id' => 'case-1', 'documents' => []]);
			$this->assertFalse($without['passed'], $entry['path'] . ' must fail without ' . $type);
		}
	}

	/**
	 * Each shipped roleGuard passes for a holder of the role and hides otherwise.
	 *
	 * @return void
	 */
	public function testRoleGuardsPassAndFail(): void {
		$guards = $this->guardsOfType(type: 'roleGuard');

		foreach ($guards as $entry) {
			$roles = (array)$entry['guard']['allowedRoles'];
			$role = (string)$roles[0];

			$this->groups = [strtolower($role)];
			$member = $this->evaluateShipped(guard: $entry['guard'], case: ['id' => 'case-1']);
			$this->assertTrue($member['passed'], $entry['path'] . ' must pass for a holder of ' . $role);

			$this->groups = [];
			$outsider = $this->evaluateShipped(guard: $entry['guard'], case: ['id' => 'case-1']);
			$this->assertFalse($outsider['passed'], $entry['path'] . ' must fail for a user without ' . $role);
			$this->assertTrue(
				($outsider['details']['silent'] ?? false),
				$entry['path'] . ' must hide the transition rather than explain the refusal'
			);
		}
	}

	/**
	 * Each shipped checklist guard passes on a ticked checklist and fails otherwise.
	 *
	 * @return void
	 */
	public function testChecklistGuardsPassAndFail(): void {
		$guards = $this->guardsOfType(type: 'checklist');

		foreach ($guards as $entry) {
			$labels = array_map(
				static fn (mixed $item): string => (string)$item,
				(array)($entry['guard']['requiredItems'] ?? ['Alles gecontroleerd'])
			);

			$this->tasks = [['checklist' => json_encode(
				array_map(static fn (string $l): array => ['label' => $l, 'checked' => true], $labels)
			)]];
			$done = $this->evaluateShipped(guard: $entry['guard'], case: ['id' => 'case-1']);
			$this->assertTrue($done['passed'], $entry['path'] . ' must pass when every item is ticked');

			$this->tasks = [['checklist' => json_encode(
				array_map(static fn (string $l): array => ['label' => $l, 'checked' => false], $labels)
			)]];
			$open = $this->evaluateShipped(guard: $entry['guard'], case: ['id' => 'case-1']);
			$this->assertFalse($open['passed'], $entry['path'] . ' must fail while an item is unticked');
		}

		$this->tasks = [];
	}

	/**
	 * The seeded guards of one type, asserted to exist before being swept.
	 *
	 * @param string $type The guard type.
	 *
	 * @return array<int, array{path: string, guard: array<string, mixed>}>
	 */
	private function guardsOfType(string $type): array {
		$guards = array_values(
			array_filter(
				$this->loadedGuards(),
				static fn (array $entry): bool => ($entry['guard']['type'] ?? '') === $type
			)
		);

		$this->assertNotEmpty($guards, 'No shipped ' . $type . ' guard found to exercise');

		return $guards;
	}

	/**
	 * The bezwaar bundle is still parked, so its orphan guard fields are inert.
	 *
	 * `SeedDataService` reads the `caseTypes` key; the Dutch bezwaar, beroep
	 * and subsidie case types sit under `_caseTypes_disabled` and are seeded by
	 * nothing. Eleven of their requiredField guards name fields the case does
	 * not carry — `isTimely`, `dispositionType`, `dispositionDetails`,
	 * `hearingWaived`, `minutesSummary`, `advisoryReport`, `withdrawalReason`,
	 * `rulingOutcome`, `settlementDetails`, `beschiktBedrag` — which live on
	 * the objection, appealDecision and hearingSession objects instead. Those
	 * guards can never pass, exactly as `paraferingCompleet` could not.
	 * Re-enabling the bundle means declaring them first.
	 *
	 * @return void
	 */
	public function testTheBezwaarBundleIsStillParked(): void {
		$seed = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/bezwaar_seed_data.json'),
			true
		);
		$this->assertIsArray($seed, 'bezwaar_seed_data.json must be valid JSON');
		$this->assertArrayHasKey('_caseTypes_disabled', $seed, 'The parked bezwaar case types must still be present');
		$this->assertArrayNotHasKey(
			'caseTypes',
			$seed,
			'The bezwaar bundle is being seeded again. Eleven of its requiredField guards name fields the case '
			. 'does not carry, so those transitions cannot be taken. Declare them on the case type before '
			. 'enabling the bundle, and fold the bundle into the requiredField sweep.'
		);
	}

	/**
	 * The VTH templates are still inert, so excluding them from the pass/fail sweep is safe.
	 *
	 * Their checklist guards name no condition beyond a message, which the
	 * engine cannot evaluate. That is harmless only while nothing loads them.
	 *
	 * @return void
	 */
	public function testTheVthTemplatesStillHaveNoLoader(): void {
		$hits = [];
		$directory = new \RecursiveDirectoryIterator(__DIR__ . '/../../../lib');
		foreach (new \RecursiveIteratorIterator($directory) as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			if (str_contains((string)file_get_contents($file->getPathname()), 'vth-templates') === true) {
				$hits[] = $file->getPathname();
			}
		}

		$this->assertSame(
			[],
			$hits,
			"lib/Settings/vth-templates/ is now loaded by PHP, so its guards reach the engine. "
			. "Give them evaluable conditions and fold them into the seeded sweep:\n" . implode("\n", $hits)
		);
	}
}
