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

use PHPUnit\Framework\TestCase;

/**
 * Every status a shipped VTH workflow template names must exist on the case
 * type it names.
 *
 * 🔴 THIS IS THE CONTROL THAT WAS MISSING, AND IT COST THE WHOLE ENTRY.
 * `toezichtbezoek` named the status `Inspectie` while its case type
 * `toezichtzaak-bouw` ships `Inspectie fase 1` to `fase 3`. Nothing compared
 * the two: the seeder resolved names against whatever the instance held,
 * failed, and reported the entry inside a count of "5 skipped (already present
 * or unresolved)". The template was dropped on every install since the
 * catalogue shipped, and no run ever said which status was missing.
 *
 * `Inspectie` is a real status name, which is why it reads as correct. It
 * belongs to `toezichtzaak-milieu`. A name that exists somewhere else on the
 * instance is exactly the mistake a human review does not catch.
 *
 * @coversNothing
 */
class ShippedVthTemplateStatusesTest extends TestCase {

	/**
	 * Catalogue entries whose case type this app deliberately does not ship.
	 *
	 * An entry listed here is seeded on an instance that creates the case type
	 * itself, and skipped with a named reason everywhere else. Adding one is a
	 * decision, which is the point of writing it down.
	 *
	 * @var array<string, string>
	 */
	private const CASE_TYPE_NOT_SHIPPED = [
		'klacht-toezicht' => 'a complaint about an inspector is not the generic klacht-behandeling case type, and dossiq ships no case type of its own for it yet',
	];

	/**
	 * The catalogue files, by template slug.
	 *
	 * @return array<string, array<string, mixed>> Slug to decoded entry.
	 */
	private function catalogue(): array {
		$entries = [];
		foreach ((array)glob(__DIR__ . '/../../../lib/Settings/seed/vth-workflow-templates/*.json') as $file) {
			$data = json_decode((string)file_get_contents((string)$file), true);
			self::assertIsArray($data, 'Catalogue file must be valid JSON: ' . basename((string)$file));
			$entries[(string)($data['slug'] ?? basename((string)$file))] = $data;
		}

		self::assertNotSame([], $entries, 'The catalogue must hold entries, or this sweep is vacuous.');

		return $entries;
	}

	/**
	 * The statuses this app ships per case-type slug.
	 *
	 * Two sources, because the case types arrive by two routes: the VTH seed
	 * file carries its statuses inline, and the register JSONs carry them as
	 * separate `statusType` objects pointing back at the case type's own slug.
	 *
	 * @return array<string, array<int, string>> Case-type slug to status names.
	 */
	private function shippedStatuses(): array {
		$settings = __DIR__ . '/../../../lib/Settings';
		$byCaseType = [];

		$vth = json_decode((string)file_get_contents($settings . '/vth_seed_data.json'), true);
		foreach ((array)(($vth['caseTypes'] ?? [])) as $caseType) {
			$slug = (string)($caseType['slug'] ?? '');
			if ($slug === '') {
				continue;
			}

			foreach ((array)($caseType['statusTypes'] ?? []) as $status) {
				$byCaseType[$slug][] = (string)($status['name'] ?? '');
			}
		}

		$registers = array_merge(
			[$settings . '/dossiq_register.json'],
			(array)glob($settings . '/register.d/*.json')
		);

		foreach ($registers as $register) {
			$decoded = json_decode((string)file_get_contents((string)$register), true);
			foreach ((array)(($decoded['components']['objects'] ?? [])) as $object) {
				if (is_array($object) === false
					|| (string)(($object['@self']['schema'] ?? '')) !== 'statusType'
				) {
					continue;
				}

				$owner = (string)($object['caseType'] ?? '');
				if ($owner !== '') {
					$byCaseType[$owner][] = (string)($object['name'] ?? '');
				}
			}
		}

		return $byCaseType;
	}

	/**
	 * THE SWEEP: a template may not name a status its case type does not have.
	 *
	 * @return void
	 */
	public function testEveryTemplateStatusExistsOnItsCaseType(): void {
		$shipped = $this->shippedStatuses();
		self::assertNotSame([], $shipped, 'No shipped statuses were found, so this sweep would pass on anything.');

		$missing = [];
		foreach ($this->catalogue() as $slug => $entry) {
			$caseTypeSlug = (string)($entry['caseTypeSlug'] ?? '');
			if (isset($shipped[$caseTypeSlug]) === false) {
				continue;
			}

			$known = $shipped[$caseTypeSlug];
			foreach ($this->statusNamesIn(entry: $entry) as $name) {
				if (in_array($name, $known, true) === false) {
					$missing[] = $slug . ' names "' . $name . '", but ' . $caseTypeSlug
						. ' has "' . implode('", "', $known) . '"';
				}
			}
		}

		self::assertSame(
			[],
			array_values(array_unique($missing)),
			"These shipped VTH templates name a status their case type does not have. The seeder\n"
			. "cannot resolve it, so the whole template is skipped on every install, and the skip\n"
			. "looks like idempotency rather than a defect:\n - "
			. implode("\n - ", array_unique($missing))
		);
	}

	/**
	 * A catalogue entry whose case type nothing ships is a decision, not a typo.
	 *
	 * @return void
	 */
	public function testEveryTemplateNamesACaseTypeThisAppShipsOrExplainsWhyNot(): void {
		$shipped = $this->shippedStatuses();

		$unshipped = [];
		foreach ($this->catalogue() as $slug => $entry) {
			if ((bool)($entry['crossLink'] ?? false) === true) {
				continue;
			}

			$caseTypeSlug = (string)($entry['caseTypeSlug'] ?? '');
			if (isset($shipped[$caseTypeSlug]) === true
				|| array_key_exists($slug, self::CASE_TYPE_NOT_SHIPPED) === true
			) {
				continue;
			}

			$unshipped[] = $slug . ' (case type "' . $caseTypeSlug . '")';
		}

		self::assertSame(
			[],
			$unshipped,
			"These VTH templates name a case type this app ships nowhere, so they can never be\n"
			. "seeded. Ship the case type, point the entry at one that exists, or add it to\n"
			. "CASE_TYPE_NOT_SHIPPED with the reason:\n - "
			. implode("\n - ", $unshipped)
		);
	}

	/**
	 * The exemption table may not outlive the entries it excuses.
	 *
	 * @return void
	 */
	public function testNoExemptionIsStale(): void {
		$catalogue = $this->catalogue();
		$shipped = $this->shippedStatuses();

		$stale = [];
		foreach (array_keys(self::CASE_TYPE_NOT_SHIPPED) as $slug) {
			if (isset($catalogue[$slug]) === false) {
				$stale[] = $slug . ' is no longer in the catalogue';
				continue;
			}

			$caseTypeSlug = (string)($catalogue[$slug]['caseTypeSlug'] ?? '');
			if (isset($shipped[$caseTypeSlug]) === true) {
				$stale[] = $slug . ' now has a shipped case type, so the exemption hides a working entry';
			}
		}

		self::assertSame([], $stale, 'Stale CASE_TYPE_NOT_SHIPPED entries: ' . implode(', ', $stale));
	}

	/**
	 * Two catalogue entries on one case type must declare distinct routes, and
	 * must still name each other.
	 *
	 * 🔴 THE SECOND PUBLISH USED TO RETIRE THE FIRST, SILENTLY. One published
	 * definition per CASE TYPE was what `lifecycleStatus` declared, so
	 * `handhavingszaak` carrying both `handhavingstraject` and `spoedig-herstel`
	 * meant whichever the glob reached last deprecated the other. Nothing broke
	 * and nothing errored: a workflow simply stopped backing new cases.
	 *
	 * The rule is now one published definition per (case type, ROUTE), so the
	 * pairing is legal and both templates stay active. The hazard did not go
	 * away, it changed shape: two entries on one case type with the SAME route
	 * still deprecate each other, exactly as before.
	 *
	 * So this test is stricter than the one it replaces. An entry sharing a case
	 * type must declare a variant, the variants must differ, and each entry must
	 * still name its siblings. A third enforcement template can land, and it has
	 * to say which route it is.
	 *
	 * @return void
	 */
	public function testEntriesSharingACaseTypeDeclareDistinctRoutesAndSaySo(): void {
		$byCaseType = [];
		foreach ($this->catalogue() as $slug => $entry) {
			if ((bool)($entry['crossLink'] ?? false) === true) {
				continue;
			}

			$byCaseType[(string)($entry['caseTypeSlug'] ?? '')][$slug] = $entry;
		}

		$problems = [];
		foreach ($byCaseType as $caseTypeSlug => $entries) {
			if (count($entries) < 2) {
				continue;
			}

			$problems = array_merge(
				$problems,
				$this->routeProblemsIn(caseTypeSlug: $caseTypeSlug, entries: $entries),
				$this->pairingProblemsIn(caseTypeSlug: $caseTypeSlug, entries: $entries)
			);
		}

		self::assertSame(
			[],
			$problems,
			"Two templates on one case type only coexist when they are different ROUTES.\n"
			. "Give each a distinct `variant`, and name the siblings in `_sharesItsCaseTypeWith`:\n - "
			. implode("\n - ", $problems)
		);
	}

	/**
	 * Every entry on a shared case type must name a route, and no two may share
	 * one. Two entries on the same route deprecate each other on publish.
	 *
	 * @param string $caseTypeSlug The shared case type.
	 * @param array<string, array<string, mixed>> $entries The entries on it, by slug.
	 *
	 * @return array<int, string> The problems found.
	 */
	private function routeProblemsIn(string $caseTypeSlug, array $entries): array {
		$problems = [];
		$seen = [];
		foreach ($entries as $slug => $entry) {
			$variant = trim((string)($entry['variant'] ?? ''));
			if ($variant === '') {
				$problems[] = $slug . ' shares case type "' . $caseTypeSlug
					. '" and declares no variant, so publishing it would deprecate the other';
				continue;
			}

			if (isset($seen[$variant]) === true) {
				$problems[] = $slug . ' declares variant "' . $variant . '" on case type "'
					. $caseTypeSlug . '", which ' . $seen[$variant] . ' already declares';
				continue;
			}

			$seen[$variant] = $slug;
		}

		return $problems;
	}

	/**
	 * Every entry on a shared case type must name its siblings in prose, so a
	 * human reading one file learns the other exists.
	 *
	 * @param string $caseTypeSlug The shared case type.
	 * @param array<string, array<string, mixed>> $entries The entries on it, by slug.
	 *
	 * @return array<int, string> The problems found.
	 */
	private function pairingProblemsIn(string $caseTypeSlug, array $entries): array {
		$problems = [];
		foreach ($entries as $slug => $entry) {
			$note = (string)($entry['_sharesItsCaseTypeWith'] ?? '');
			foreach (array_diff(array_keys($entries), [$slug]) as $sibling) {
				if (str_contains($note, $sibling) === false) {
					$problems[] = $slug . ' shares case type "' . $caseTypeSlug
						. '" with ' . $sibling . ', and does not name it in _sharesItsCaseTypeWith';
				}
			}
		}

		return $problems;
	}

	/**
	 * Exactly one entry on a shared case type is the default route.
	 *
	 * Without this, which route a new case takes is decided by `glob()` order.
	 *
	 * @return void
	 */
	public function testOneEntryOnASharedCaseTypeIsTheDefaultRoute(): void {
		$byCaseType = [];
		foreach ($this->catalogue() as $slug => $entry) {
			if ((bool)($entry['crossLink'] ?? false) === true) {
				continue;
			}

			$byCaseType[(string)($entry['caseTypeSlug'] ?? '')][$slug] = $entry;
		}

		$problems = [];
		foreach ($byCaseType as $caseTypeSlug => $entries) {
			if (count($entries) < 2) {
				continue;
			}

			$defaults = [];
			foreach ($entries as $slug => $entry) {
				if ((bool)($entry['isDefaultVariant'] ?? false) === true) {
					$defaults[] = $slug;
				}
			}

			if (count($defaults) !== 1) {
				$problems[] = 'case type "' . $caseTypeSlug . '" has ' . count($defaults)
					. ' entries marked isDefaultVariant, and needs exactly one: '
					. implode(', ', array_keys($entries));
			}
		}

		self::assertSame(
			[],
			$problems,
			"A case type carrying several routes needs one of them marked `isDefaultVariant`.\n - "
			. implode("\n - ", $problems)
		);
	}



	/**
	 * Every status name one catalogue entry refers to.
	 *
	 * The wildcard `*` is a legal fromStatus meaning "from any status", so it
	 * is not a name to look up.
	 *
	 * @param array<string, mixed> $entry The decoded catalogue entry.
	 *
	 * @return array<int, string> The status names, de-duplicated.
	 */
	private function statusNamesIn(array $entry): array {
		$names = [];
		foreach ((array)($entry['steps'] ?? []) as $step) {
			$names[] = (string)($step['statusName'] ?? '');
		}

		foreach ((array)($entry['transitions'] ?? []) as $transition) {
			$names[] = (string)($transition['fromStatus'] ?? '');
			$names[] = (string)($transition['toStatus'] ?? '');
		}

		return array_values(
			array_unique(
				array_filter(
					$names,
					static function (string $name): bool {
						return ($name !== '' && $name !== '*');
					}
				)
			)
		);
	}
}
