<?php

/**
 * Access-Control Group Provisioning Sweep
 *
 * Every Nextcloud group this app gates access on must be a group this app
 * creates.
 *
 * A group that does not exist and a group with no members are INDISTINGUISHABLE
 * at the point of decision: `IGroupManager::isInGroup()` answers false for both,
 * with no log line and no error. OpenRegister's `GroupProvisioner` records the
 * same property for its own blocks — "a typo in an authorization block reads
 * exactly like a working access control". So a gate naming a group nothing
 * provisions is not a gate: it is a permanent, silent denial for every
 * non-admin, and it presents to the user as a feature that does nothing.
 *
 * `ProvisionAssignedGroupsTest` already sweeps the group literals the shipped
 * FLOWS assign work to. This sweeps the ones the CODE gates access on, which is
 * the other half and was unswept: measured 2026-09-05, seven of the nine groups
 * dossiq gates on were provisioned by nothing.
 *
 * @category Test
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

use OCA\Dossiq\Repair\ProvisionAssignedGroups;
use PHPUnit\Framework\TestCase;

/**
 * Asserts no access gate names a group nothing creates.
 *
 * @coversNothing
 */
final class AccessControlGroupsAreProvisionedTest extends TestCase {

	/**
	 * Groups Nextcloud itself guarantees, so this app must not create them.
	 *
	 * `admin` exists on every instance by definition.
	 *
	 * @var array<int, string>
	 */
	private const RESERVED = ['admin'];

	/**
	 * Every group literal reached by an `isInGroup()` gate, by source file.
	 *
	 * @return array<string, array<int, string>> Group ids by relative path.
	 */
	private function gatedGroups(): array {
		$found = [];

		foreach ($this->phpFiles() as $path) {
			// 🔴 COMMENTS ARE NOT CODE. Scanning the raw source reported
			// CaseAccessGuard as gating on `procest-gebruikers` — a group named
			// only in a docblock that documents the check as HISTORICAL. A
			// sweep that cannot tell a gate from a description of one produces
			// findings nobody can act on, so the tokenizer removes them first.
			$source = $this->strippedSource(path: $path);
			if (str_contains($source, 'isInGroup(') === false) {
				continue;
			}

			$relative = substr($path, (strlen($this->libDir()) - 3));
			$groups = array_merge(
				$this->literalArgumentsOf(source: $source),
				$this->groupConstantsIn(source: $source)
			);

			$groups = array_values(array_unique(array_filter($groups)));
			if ($groups !== []) {
				$found[$relative] = $groups;
			}
		}

		return $found;
	}

	/**
	 * No gate names a group this app does not provision.
	 *
	 * @return void
	 */
	public function testEveryGatedGroupIsProvisioned(): void {
		$gated = $this->gatedGroups();

		self::assertNotSame(
			[],
			$gated,
			'The sweep found no group gates at all — the scan is broken, not the app ungated'
		);

		$provisioned = array_merge(ProvisionAssignedGroups::ASSIGNED_GROUPS, self::RESERVED);

		$findings = [];
		foreach ($gated as $file => $groups) {
			foreach ($groups as $group) {
				if (in_array($group, $provisioned, true) === true) {
					continue;
				}

				$findings[] = $file . ' gates on "' . $group . '", which nothing creates';
			}
		}

		self::assertSame(
			[],
			$findings,
			"`isInGroup()` cannot tell a missing group from an empty one: both answer false,\n"
			. "silently. Each gate below therefore denies every non-admin forever, and the\n"
			. "feature behind it looks broken rather than restricted. Add the group to\n"
			. "ProvisionAssignedGroups::ASSIGNED_GROUPS, or stop gating on it:\n"
			. implode("\n", $findings)
		);

	}//end testEveryGatedGroupIsProvisioned()

	/**
	 * The sweep reports a gate that is really there.
	 *
	 * Without this, "no findings" cannot be told from "no longer scanning".
	 *
	 * @return void
	 */
	public function testTheSweepSeesTheKnownGates(): void {
		$all = [];
		foreach ($this->gatedGroups() as $groups) {
			$all = array_merge($all, $groups);
		}

		// Three gates whose group ids are written three different ways: a bare
		// literal, a scalar constant, and a member of an array constant. If the
		// scan stops resolving any one of those, this fails rather than the
		// sweep above quietly narrowing.
		self::assertContains('admin', $all, 'the bare-literal form must be seen');
		self::assertContains('procest-admin', $all, 'the scalar-constant form must be seen');
		self::assertContains('kcc', $all, 'the array-constant form must be seen');

	}//end testTheSweepSeesTheKnownGates()

	/**
	 * The app's lib directory.
	 *
	 * @return string Absolute path.
	 */
	private function libDir(): string {
		return __DIR__ . '/../../../lib';
	}

	/**
	 * Every PHP file under lib/.
	 *
	 * @return array<int, string> Absolute paths.
	 */
	private function phpFiles(): array {
		$files = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($this->libDir(), \FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file) {
			if ($file->isFile() === true && $file->getExtension() === 'php') {
				$files[] = $file->getPathname();
			}
		}

		sort($files);

		return $files;
	}

	/**
	 * One file's source with every comment removed.
	 *
	 * @param string $path Absolute path to the PHP file.
	 *
	 * @return string The source, comments replaced by a single space.
	 */
	private function strippedSource(string $path): string {
		$stripped = '';
		foreach (token_get_all((string)file_get_contents($path)) as $token) {
			if (is_array($token) === false) {
				$stripped .= $token;
				continue;
			}

			if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
				$stripped .= ' ';
				continue;
			}

			$stripped .= $token[1];
		}

		return $stripped;
	}

	/**
	 * Group ids written as bare string literals inside an `isInGroup()` call.
	 *
	 * @param string $source The file's source.
	 *
	 * @return array<int, string> Group ids.
	 */
	private function literalArgumentsOf(string $source): array {
		preg_match_all("/isInGroup\\(\\s*[^,()]+,\\s*'([^']+)'\\s*\\)/", $source, $matches);

		return (array)($matches[1] ?? []);
	}

	/**
	 * Group ids held by a constant whose name says it holds groups.
	 *
	 * Covers both shapes the app uses: a scalar (`ADMIN_GROUP_ID = 'x'`) and an
	 * array (`ALLOWED_GROUPS = ['a', 'b']`) iterated into the gate.
	 *
	 * @param string $source The file's source.
	 *
	 * @return array<int, string> Group ids.
	 */
	private function groupConstantsIn(string $source): array {
		$groups = [];

		preg_match_all("/const\\s+\\w*GROUP\\w*\\s*=\\s*'([^']+)'\\s*;/", $source, $scalars);
		$groups = array_merge($groups, (array)($scalars[1] ?? []));

		preg_match_all("/const\\s+\\w*GROUP\\w*\\s*=\\s*\\[([^\\]]*)\\]\\s*;/", $source, $arrays);
		foreach ((array)($arrays[1] ?? []) as $body) {
			preg_match_all("/'([^']+)'/", (string)$body, $members);
			$groups = array_merge($groups, (array)($members[1] ?? []));
		}

		return $groups;
	}
}//end class
