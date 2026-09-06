<?php

/**
 * Dossiq Dashboard KPI Cards Test
 *
 * Pins the dashboard's KPI row to what openspec/specs/dashboard/spec.md
 * REQ-DASH-001 actually asks for: five cards, each named as the spec names it,
 * each reading its value AND its sub-label number from the KPI endpoint.
 *
 * 🔴 THE CARDS DRIFTED FROM THE SPEC AND NOTHING NOTICED. Before this, the row
 * held four cards; the first was titled "New cases" and counted `startDate`
 * inside the date-range picker's window rather than counting open cases; the
 * Overdue card counted closed cases too and read 8 against a list of 5; My Tasks
 * dropped any task without a due date; and the SLA card did not exist. Every one
 * of those renders a plausible number, so only a reader holding the spec could
 * tell. That is what a test is for.
 *
 * The sub-labels are asserted as TOKENS, not as rendered text: `{newToday}` is
 * interpolated from the endpoint payload at runtime, so asserting the token is
 * asserting that the card is bound to a real second number rather than to a
 * static string that happens to read like one.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The dashboard KPI row, against REQ-DASH-001.
 */
final class DashboardKpiCardsTest extends TestCase {
	/**
	 * The endpoint every KPI card reads.
	 *
	 * @var string
	 */
	private const KPI_URL = '/apps/dossiq/api/dashboard/kpis';

	/**
	 * The five cards the spec requires, in order, with the payload field each
	 * one displays and the token its sub-label interpolates.
	 *
	 * @var array<string, array{0: string, 1: string, 2: ?string}>
	 */
	private const CARDS = [
		'kpi-open-cases' => ['Open Cases', 'openCount', 'newToday'],
		'kpi-overdue' => ['Overdue', 'overdueCount', null],
		'kpi-completed' => ['Completed This Month', 'completedCount', 'avgProcessingDays'],
		'kpi-my-tasks' => ['My Tasks', 'taskCount', 'tasksDueToday'],
		'kpi-sla-compliance' => ['SLA Compliance', 'slaCompliance', null],
	];

	/**
	 * The dashboard page's widget list, keyed by widget id.
	 *
	 * @var array<string, array>
	 */
	private array $widgets = [];

	/**
	 * The dashboard page's grid layout.
	 *
	 * @var array<int, array>
	 */
	private array $layout = [];

	/**
	 * Decode the shipped manifest.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$path = (__DIR__ . '/../../../src/manifest.json');
		$this->assertFileExists($path);

		$manifest = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($manifest, 'The manifest must be valid JSON.');

		$dashboard = null;
		foreach (($manifest['pages'] ?? []) as $page) {
			if (($page['id'] ?? '') === 'Dashboard') {
				$dashboard = $page;
				break;
			}
		}

		$this->assertNotNull($dashboard, 'The manifest must declare a Dashboard page.');

		foreach (($dashboard['config']['widgets'] ?? []) as $widget) {
			$this->widgets[$widget['id']] = $widget;
		}

		$this->layout = ($dashboard['config']['layout'] ?? []);
	}

	/**
	 * All five cards exist and carry the spec's titles.
	 *
	 * @return void
	 */
	public function testTheSpecsFiveCardsAreAllPresent(): void {
		foreach (self::CARDS as $id => [$title, $field, $token]) {
			$this->assertArrayHasKey($id, $this->widgets, 'REQ-DASH-001 requires a "' . $title . '" card.');
			$this->assertSame(
				$title,
				$this->widgets[$id]['title'],
				'The card must carry the title the spec names it by.'
			);
		}
	}

	/**
	 * Each card reads its number from the KPI endpoint, not from a direct
	 * OpenRegister query.
	 *
	 * The two are mutually exclusive by manifest rule, and only the endpoint
	 * carries the second number each sub-label needs.
	 *
	 * @return void
	 */
	public function testEveryCardIsBoundToTheKpiEndpoint(): void {
		foreach (self::CARDS as $id => [$title, $field, $token]) {
			$content = $this->widgets[$id]['content'];

			$this->assertArrayNotHasKey(
				'source',
				$content,
				'"' . $title . '" must not also declare an OpenRegister source: exactly one binding is allowed.'
			);
			$this->assertSame(
				self::KPI_URL,
				($content['endpointSource']['url'] ?? null),
				'"' . $title . '" must read the KPI endpoint.'
			);
			$this->assertSame(
				$field,
				($content['valueField'] ?? null),
				'"' . $title . '" must display the ' . $field . ' field.'
			);
		}
	}

	/**
	 * The cards whose scenarios name a sub-label interpolate a real payload
	 * field rather than stating a fixed string.
	 *
	 * @return void
	 */
	public function testSubLabelsInterpolateASecondNumber(): void {
		foreach (self::CARDS as $id => [$title, $field, $token]) {
			if ($token === null) {
				continue;
			}

			$caption = (string)($this->widgets[$id]['content']['caption'] ?? '');
			$this->assertStringContainsString(
				'{' . $token . '}',
				$caption,
				'"' . $title . '" must interpolate ' . $token . ' into its sub-label, not hard-code a number.'
			);
		}
	}

	/**
	 * Every card is placed on the grid, and the row fills exactly one width.
	 *
	 * A widget the layout does not mention is not rendered at all, which is how
	 * the SLA card was added once and stayed invisible.
	 *
	 * @return void
	 */
	public function testEveryCardIsPlacedOnTheGridAndTheRowFits(): void {
		$placed = [];
		foreach ($this->layout as $entry) {
			$placed[$entry['widgetId']] = $entry;
		}

		$width = 0;
		foreach (self::CARDS as $id => [$title, $field, $token]) {
			$this->assertArrayHasKey(
				$id,
				$placed,
				'"' . $title . '" is declared but not placed on the grid, so it never renders.'
			);
			$this->assertSame(0, $placed[$id]['gridY'], 'The KPI cards share the top row.');
			$width += (int)$placed[$id]['gridWidth'];
		}

		$this->assertSame(12, $width, 'The five cards must fill the 12-column row exactly.');
	}

	/**
	 * No KPI card claims the date-range chip.
	 *
	 * The cards read a server-computed payload that the range picker does not
	 * reach, so a chip advertising the selected range would be a false claim
	 * about what the number covers.
	 *
	 * @return void
	 */
	public function testNoKpiCardAdvertisesTheDateRange(): void {
		foreach ($this->layout as $entry) {
			if (isset(self::CARDS[$entry['widgetId']]) === false) {
				continue;
			}

			$this->assertNotTrue(
				($entry['dateChip'] ?? false),
				'"' . $entry['widgetId'] . '" ignores the date range, so it must not show the range chip.'
			);
		}
	}
}
