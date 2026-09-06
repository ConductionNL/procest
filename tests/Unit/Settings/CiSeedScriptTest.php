<?php

/**
 * Sweeps the e2e CI seed script for silently-failing steps.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
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

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * A seed step whose failure is swallowed makes the suite green on luck.
 *
 * `tests/e2e/ci-seed.sh` builds the preconditions every Playwright spec then
 * asserts against. A step in it that fails silently does not fail CI; it
 * removes a precondition, and the specs pass or fail on whatever else happened
 * to touch the instance first.
 *
 * MEASURED. `dossiq:workflows:migrate-to-flows` was invoked twice, by two PRs
 * that landed minutes apart. #1557's copy names `--user`, which the command
 * REQUIRES and refuses without, and `exit 1`s on failure. #1556's copy named no
 * `--user` and sent both streams to `/dev/null`, so it returned INVALID on
 * every run and reported a `::warning`. CI was green because the projected
 * flows arrived incidentally — the block's own comment said as much. On a rig,
 * running it correctly creates four disabled projected flows and
 * `changed-surfaces.spec.ts` passes 7/7.
 *
 * So: no `occ` invocation in this script may discard its output, and the one
 * command with a required option must name it.
 *
 * @coversNothing
 */
class CiSeedScriptTest extends TestCase {

	/**
	 * The seed script's source.
	 *
	 * @return string The script text.
	 */
	private function script(): string {
		$path = __DIR__ . '/../../../tests/e2e/ci-seed.sh';
		self::assertFileExists($path);

		return (string)file_get_contents($path);
	}//end script()

	/**
	 * No `occ` invocation discards its output.
	 *
	 * @return void
	 */
	public function testNoOccInvocationDiscardsItsOutput(): void {
		$lines = explode("\n", $this->script());

		$occLines = [];
		$swallowed = [];
		foreach ($lines as $number => $line) {
			if (str_contains($line, 'php occ ') === false || str_starts_with(ltrim($line), '#') === true) {
				continue;
			}

			$occLines[] = $line;
			if (str_contains($line, '/dev/null') === true) {
				$swallowed[] = sprintf('line %d: %s', ($number + 1), trim($line));
			}
		}

		// The positive control: a script with no `occ` calls at all would make
		// the assertion below trivially true.
		self::assertNotSame([], $occLines, 'The sweep found no occ invocations, so it cannot have checked any.');

		self::assertSame(
			[],
			$swallowed,
			sprintf(
				"These occ invocations send their output to /dev/null, so a failure reaches CI as nothing at all:\n%s",
				implode("\n", $swallowed)
			)
		);
	}//end testNoOccInvocationDiscardsItsOutput()

	/**
	 * The flow projection is invoked exactly once, with the user it requires.
	 *
	 * @return void
	 */
	public function testTheFlowProjectionIsInvokedOnceAndNamesAUser(): void {
		$invocations = [];
		foreach (explode("\n", $this->script()) as $line) {
			if (str_contains($line, 'dossiq:workflows:migrate-to-flows') === false
				|| str_starts_with(ltrim($line), '#') === true
				|| str_contains($line, 'echo ') === true
			) {
				continue;
			}

			$invocations[] = trim($line);
		}

		self::assertCount(
			1,
			$invocations,
			sprintf(
				"The projection must be invoked exactly once; a second copy is how the swallowed one shipped:\n%s",
				implode("\n", $invocations)
			)
		);

		self::assertStringContainsString(
			'--user',
			$invocations[0],
			'`dossiq:workflows:migrate-to-flows` refuses with "--user is required", so an invocation without it '
			. 'projects nothing on every run.'
		);
	}//end testTheFlowProjectionIsInvokedOnceAndNamesAUser()

	/**
	 * A seed step that fails stops the seed, rather than warning past it.
	 *
	 * `::error` plus `exit 1` is the shape the script's other gates use;
	 * `::warning` is what the swallowed copy used, and a warning does not fail
	 * a workflow.
	 *
	 * @return void
	 */
	public function testTheFlowProjectionFailsTheSeedRatherThanWarning(): void {
		$script = $this->script();

		$position = strpos($script, 'dossiq:workflows:migrate-to-flows --user');
		self::assertIsInt($position, 'The projection invocation was not found.');

		$tail = substr($script, $position, 400);
		self::assertStringContainsString('::error', $tail, 'A failed projection must be reported as an error.');
		self::assertStringContainsString('exit 1', $tail, 'A failed projection must stop the seed.');
	}//end testTheFlowProjectionFailsTheSeedRatherThanWarning()
}//end class
