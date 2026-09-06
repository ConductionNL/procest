<?php

/**
 * A stub's public API may not drift from the class it doubles.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Support;

use OCA\Dossiq\Tests\Support\StubDrift\ClassApi;
use PHPUnit\Framework\TestCase;

/**
 * Every `tests/Stubs` double must agree with the OpenRegister class it stands in for.
 *
 * ## Why this exists
 *
 * dossiq's unit suite runs without OpenRegister, so `tests/Stubs/` supplies the
 * whole `OCA\OpenRegister\` namespace. That works right up to the moment a stub
 * says something the real class does not, because a stub is the one witness the
 * suite has: when it agrees with the caller, the caller cannot be wrong here and
 * is wrong live. Running the suite against a real OpenRegister once (dossiq#1756,
 * `DOSSIQ_REAL_FLOW_ENGINE=1`) surfaced 50 such disagreements at 11 distinct
 * points of drift, every one of them invisible to a green run.
 *
 * A one-off measurement does not stay true, so this pins it mechanically. It
 * compares the DECLARED public API of each stub against its real counterpart in
 * a sibling `openregister` checkout, and reports:
 *
 *  - a public method the real class does not have (`setSchemaId()`, which cost
 *    23 assertions);
 *  - a differing arity or required-argument count (`FlowNodeResumeState`'s
 *    constructor, which cost 21);
 *  - differing parameter NAMES, because a named-argument call site is a caller
 *    like any other;
 *  - a public constant that is absent, or whose value differs
 *    (`FlowNodeResumeState::CONTEXT_KEY`, which said `resumeState` — the
 *    RUN-level key — where the engine says `resume`).
 *
 * ## Why it reads source instead of reflecting
 *
 * A stub and its subject share one fully-qualified name. Only one can be loaded
 * in a process, so reflection can only ever see the one that won, and there is
 * then nothing to compare it with. {@see ClassApi} parses both files instead,
 * which also means neither has to be loadable.
 *
 * ## Why it may skip
 *
 * The sibling checkout is not always there. On a developer machine without it
 * this test skips, and it must: a check that cannot read the real class has
 * nothing to say, and pretending otherwise is the "skip cannot tell absent from
 * broken" failure this whole file exists to prevent. In CI the checkout IS
 * present — the shared PHPUnit job clones openregister next to this app — so
 * there the comparison always runs.
 */
final class StubApiDriftTest extends TestCase {

	/**
	 * Where a sibling OpenRegister checkout would be.
	 *
	 * The SAME place `tests/bootstrap.php` looks for the real flow engine —
	 * `<app>/../openregister` — so the check and the bootstrap cannot end up
	 * disagreeing about which checkout is "the real one". The shared PHPUnit
	 * job clones it to `server/apps/openregister` beside `server/apps/dossiq`
	 * and composer-installs it (`additional-apps` in code-quality.yml), which
	 * is why this comparison runs rather than skips in CI.
	 *
	 * @var string
	 */
	private const OPENREGISTER_LIB = __DIR__ . '/../../../../openregister/lib';

	/**
	 * Stubs whose drift is a KNOWN, OWNED defect rather than a stub to repair.
	 *
	 * A waiver is not a way to quieten this check. Each entry names a real
	 * disagreement, the fix that owns it, and nothing else — and each one makes
	 * this test weaker until it is removed, which is the point of listing them
	 * here rather than deleting the comparison.
	 *
	 * @var array<string, string>
	 */
	private const WAIVED = [
		// The stub carries OR's AVG art. 6 vocabulary in DUTCH
		// (`RECHTSGROND_VOCABULARY` = toestemming, publieke_taak, …) where the
		// real entity declares it in English (`LEGAL_BASIS_VOCABULARY` =
		// consent, public_task, …), and a lifecycle status of `draft` where the
		// real default is `concept`. That is not stub drift to tidy away: the
		// SHIPPED catalogue writes the Dutch values, OR's mapper throws
		// InvalidArgumentException on every one of them, and the seed step
		// swallows it per row. The fix owns the stub, the catalogue and the
		// assertions together; splitting it across two changes would leave the
		// tree half-migrated. Tracked as dossiq#1763.
		'Db\\Verwerkingsactiviteit' => 'dossiq#1763: the catalogue writes Dutch legal bases OR rejects; '
			. 'stub, catalogue and assertions move together',
	];

	/**
	 * Public methods an OCP Entity subclass inherits without declaring.
	 *
	 * Property-derived accessors are resolved separately, from the real class's
	 * declared properties; these are the fixed ones.
	 *
	 * @var array<int, string>
	 */
	private const ENTITY_INHERITED = [
		'__call',
		'getId',
		'setId',
		'columnToProperty',
		'propertyToColumn',
		'getUpdatedFields',
		'resetUpdatedFields',
		'getFieldTypes',
	];

	/**
	 * Every stub declaring a class in the OpenRegister namespace.
	 *
	 * @return array<string, array{0:string, 1:string}> Case name to [file, relative class path].
	 */
	public static function stubProvider(): array {
		$root = realpath(__DIR__ . '/../../Stubs');
		self::assertIsString($root);

		$cases = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
		foreach ($files as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			$source = file_get_contents($file->getPathname());
			if ($source === false || preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1) {
				continue;
			}

			if (str_starts_with(trim($namespace[1]), 'OCA\\OpenRegister') === false) {
				continue;
			}

			$found = preg_match_all(
				'/^\s*(?:final\s+|abstract\s+)?(?:class|interface|trait)\s+(\w+)/m',
				$source,
				$declarations,
				PREG_SET_ORDER
			);
			if ($found === 0) {
				continue;
			}

			foreach ($declarations as $declaration) {
				$relative = str_replace(
					'\\',
					'/',
					substr(trim($namespace[1]), strlen('OCA\\OpenRegister\\')) . '\\' . $declaration[1]
				);
				$relative = ltrim($relative, '/');
				$cases[str_replace('/', '\\', $relative)] = [$file->getPathname(), $relative];
			}
		}//end foreach

		ksort($cases);

		return $cases;
	}//end stubProvider()

	/**
	 * A stub's declared public API must match the real class's.
	 *
	 * @param string $stubFile     Path to the stub file.
	 * @param string $relativePath The class's path below `OCA\OpenRegister\`.
	 *
	 * @return void
	 *
	 * @dataProvider stubProvider
	 */
	public function testTheStubAgreesWithTheRealClass(string $stubFile, string $relativePath): void {
		$realLib = realpath(self::OPENREGISTER_LIB);
		if ($realLib === false || is_dir($realLib) === false) {
			self::markTestSkipped(
				'No sibling openregister checkout at ' . self::OPENREGISTER_LIB
				. ' — there is nothing to compare against. CI clones it, so this runs there.'
			);
		}

		$realFile = $realLib . '/' . $relativePath . '.php';
		if (file_exists($realFile) === false) {
			// A stub with no counterpart is a contract dossiq invented, which
			// this check has no opinion about.
			self::assertFileDoesNotExist($realFile);

			return;
		}

		$shortName = basename($relativePath);
		$stub = ClassApi::fromFile($stubFile, $shortName);
		$real = ClassApi::fromFile($realFile, $shortName);

		self::assertNotNull($stub, 'The stub file must declare ' . $shortName . '.');
		self::assertNotNull($real, 'The real file must declare ' . $shortName . '.');

		$drift = $this->drift($stub, $real);
		$waiver = (self::WAIVED[str_replace('/', '\\', $relativePath)] ?? null);
		if ($waiver !== null) {
			self::assertNotSame(
				[],
				$drift,
				'The waiver for ' . $relativePath . ' (' . $waiver . ') describes drift that is no longer there. '
				. 'Delete the waiver rather than leaving a rule that guards nothing.'
			);

			return;
		}

		self::assertSame(
			[],
			$drift,
			"The stub for OCA\\OpenRegister\\" . str_replace('/', '\\', $relativePath)
			. " no longer matches the class it doubles.\n"
			. "A stub that says something the real class does not is the one witness this suite has, so\n"
			. "anything written against it is green here and fatal against a real OpenRegister.\n\n  - "
			. implode("\n  - ", $drift) . "\n"
		);
	}//end testTheStubAgreesWithTheRealClass()

	/**
	 * Every way the stub disagrees with the real class.
	 *
	 * @param ClassApi $stub The stub's API.
	 * @param ClassApi $real The real class's API.
	 *
	 * @return list<string> The disagreements, empty when there are none.
	 */
	private function drift(ClassApi $stub, ClassApi $real): array {
		$drift = array_merge(
			$this->methodDrift($stub, $real),
			$this->constantDrift($stub, $real)
		);
		sort($drift);

		return $drift;
	}//end drift()

	/**
	 * Method-level disagreements.
	 *
	 * @param ClassApi $stub The stub's API.
	 * @param ClassApi $real The real class's API.
	 *
	 * @return list<string> The disagreements.
	 */
	private function methodDrift(ClassApi $stub, ClassApi $real): array {
		$magic = $this->magicAccessors($real);
		$drift = [];

		foreach ($stub->methods as $name => $signature) {
			if (isset($real->methods[$name]) === false) {
				if (in_array($name, $magic, true) === true) {
					continue;
				}

				$drift[] = sprintf(
					'%s() is public on the stub and does not exist on the real class', $name
				);
				continue;
			}

			$realSignature = $real->methods[$name];
			if ($realSignature['total'] !== $signature['total']
				|| $realSignature['required'] !== $signature['required']
			) {
				$drift[] = sprintf(
					'%s() takes %d argument(s), %d required, where the real class takes %d, %d required',
					$name,
					$signature['total'],
					$signature['required'],
					$realSignature['total'],
					$realSignature['required']
				);
				continue;
			}

			if ($realSignature['params'] !== $signature['params']) {
				$drift[] = sprintf(
					'%s() names its arguments (%s) where the real class names them (%s), '
					. 'so a named-argument call site cannot work against both',
					$name,
					implode(', ', $signature['params']),
					implode(', ', $realSignature['params'])
				);
			}
		}//end foreach

		return $drift;
	}//end methodDrift()

	/**
	 * Constant-level disagreements.
	 *
	 * @param ClassApi $stub The stub's API.
	 * @param ClassApi $real The real class's API.
	 *
	 * @return list<string> The disagreements.
	 */
	private function constantDrift(ClassApi $stub, ClassApi $real): array {
		$drift = [];
		foreach ($stub->constants as $name => $value) {
			if (array_key_exists($name, $real->constants) === false) {
				$drift[] = sprintf(
					'%s is a public constant on the stub and does not exist on the real class', $name
				);
				continue;
			}

			if ($real->constants[$name] !== $value) {
				$drift[] = sprintf(
					'%s is %s on the stub and %s on the real class',
					$name,
					$value,
					$real->constants[$name]
				);
			}
		}

		return $drift;
	}//end constantDrift()

	/**
	 * The public methods the real class serves without declaring them.
	 *
	 * An `OCP\AppFramework\Db\Entity` subclass answers `getFoo()`, `setFoo()`
	 * and `isFoo()` for every DECLARED property through `__call`, plus a fixed
	 * handful of its own. A stub is entitled to spell those out.
	 *
	 * @param ClassApi $real The real class's API.
	 *
	 * @return list<string> Method names the stub may declare freely.
	 */
	private function magicAccessors(ClassApi $real): array {
		if ($real->parent === null) {
			return [];
		}

		$names = self::ENTITY_INHERITED;
		foreach ($real->properties as $property) {
			$suffix = ucfirst($property);
			$names[] = 'get' . $suffix;
			$names[] = 'set' . $suffix;
			$names[] = 'is' . $suffix;
		}

		return array_values($names);
	}//end magicAccessors()
}//end class
