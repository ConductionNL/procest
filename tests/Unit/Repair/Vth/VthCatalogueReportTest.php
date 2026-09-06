<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Repair\Vth
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair\Vth;

use OCA\Dossiq\Repair\Vth\VthCatalogueReport;
use PHPUnit\Framework\TestCase;

/**
 * The summary an administrator reads after the VTH catalogue is seeded.
 *
 * The case under test is the one the catalogue actually hits: two entries on
 * one case type. They used to deprecate each other, invisibly. They are now two
 * ROUTES through that case type, so the second publish displaces nothing, and
 * the line that used to explain the deprecation has to be gone.
 *
 * @covers \OCA\Dossiq\Repair\Vth\VthCatalogueReport
 */
class VthCatalogueReportTest extends TestCase {

	/**
	 * The report under test.
	 *
	 * @var VthCatalogueReport
	 */
	private VthCatalogueReport $report;

	/**
	 * Build a fresh report.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->report = new VthCatalogueReport();
	}//end setUp()

	/**
	 * A publish onto an empty case type displaces nothing.
	 *
	 * @return void
	 */
	public function testNothingIsDisplacedWhenTheCaseTypeHadNoDefinition(): void {
		self::assertSame(
			'',
			$this->report->displacedTitle(displaced: null, publishedId: 'new-id')
		);
	}//end testNothingIsDisplacedWhenTheCaseTypeHadNoDefinition()

	/**
	 * Republishing the same definition does not count as displacing itself.
	 *
	 * @return void
	 */
	public function testADefinitionDoesNotDisplaceItself(): void {
		self::assertSame(
			'',
			$this->report->displacedTitle(
				displaced: ['id' => 'same-id', 'title' => 'Handhavingstraject'],
				publishedId: 'same-id'
			)
		);
	}//end testADefinitionDoesNotDisplaceItself()

	/**
	 * A second definition on one case type displaces the first, by name.
	 *
	 * @return void
	 */
	public function testTheDefinitionAPublishRetiresIsNamed(): void {
		self::assertSame(
			'Handhavingstraject',
			$this->report->displacedTitle(
				displaced: ['id' => 'first-id', 'title' => 'Handhavingstraject'],
				publishedId: 'second-id'
			)
		);
	}//end testTheDefinitionAPublishRetiresIsNamed()

	/**
	 * An ordinary seed reads as an ordinary seed, and names its route.
	 *
	 * @return void
	 */
	public function testTheSeededLineSaysOnlyWhatHappened(): void {
		$reason = $this->report->seededReason(
			title: 'Toezichtbezoek',
			version: 1,
			variant: 'standaard',
			displacedTitle: '',
			isDefaultRoute: false
		);

		self::assertSame(
			'seeded and published as "Toezichtbezoek" version 1, on the "standaard" route.',
			$reason
		);
	}//end testTheSeededLineSaysOnlyWhatHappened()

	/**
	 * 🔴 A SECOND ROUTE DISPLACES NOTHING, AND THE LINE MUST NOT SAY IT DOES.
	 *
	 * This test used to assert the opposite. `spoedig-herstel` publishing on
	 * `handhavingszaak` deprecated `handhavingstraject`, and the line explained
	 * that one published definition per case type was the model. Both templates
	 * are now routes through that case type, so the second one retires nothing
	 * and the sentence that explained the retirement is gone with it.
	 *
	 * @return void
	 */
	public function testASecondRouteReportsNoDeprecation(): void {
		$reason = $this->report->seededReason(
			title: 'Spoedig herstel (Awb 5:31)',
			version: 1,
			variant: 'spoedeisend',
			displacedTitle: '',
			isDefaultRoute: false
		);

		self::assertStringContainsString('"Spoedig herstel (Awb 5:31)" version 1', $reason);
		self::assertStringContainsString('on the "spoedeisend" route', $reason);
		self::assertStringNotContainsString('deprecated', $reason);
		self::assertStringNotContainsString('replaced', $reason);
	}//end testASecondRouteReportsNoDeprecation()

	/**
	 * A new version of a route names the version it replaced.
	 *
	 * @return void
	 */
	public function testANewVersionOfARouteNamesWhatItReplaced(): void {
		$reason = $this->report->seededReason(
			title: 'Handhavingstraject',
			version: 2,
			variant: 'regulier',
			displacedTitle: 'Handhavingstraject',
			isDefaultRoute: true
		);

		self::assertStringContainsString('version 2, on the "regulier" route', $reason);
		self::assertStringContainsString('New cases on this case type follow this route.', $reason);
		self::assertStringContainsString(
			'This replaced "Handhavingstraject", the previous published version of that route.',
			$reason
		);
	}//end testANewVersionOfARouteNamesWhatItReplaced()

	/**
	 * A retired entry is named, with the way back, and is never republished.
	 *
	 * @return void
	 */
	public function testARetiredEntryIsNamedWithTheWayBack(): void {
		$reason = $this->report->deprecatedReason(
			title: 'Spoedig herstel (Awb 5:31)',
			variant: 'spoedeisend'
		);

		self::assertStringContainsString('"Spoedig herstel (Awb 5:31)"', $reason);
		self::assertStringContainsString('"spoedeisend" route', $reason);
		self::assertStringContainsString('will not republish it', $reason);
		self::assertStringContainsString('publish the copy', $reason);
	}//end testARetiredEntryIsNamedWithTheWayBack()

	/**
	 * A retired entry gets its own bucket in the summary, and its own nudge.
	 *
	 * A count that folds a retired entry into "already present" is the shape of
	 * report that hid `toezichtbezoek` for the life of the catalogue.
	 *
	 * @return void
	 */
	public function testTheSummaryCountsRetiredEntriesSeparately(): void {
		$this->report->reset();
		$this->report->record(entry: 'handhavingstraject', outcome: 'present', reason: 'present.');
		$this->report->record(entry: 'spoedig-herstel', outcome: 'deprecated', reason: 'retired.');

		$lines = [];
		$output = $this->createMock(\OCP\Migration\IOutput::class);
		$output->method('info')->willReturnCallback(
			static function (string $line) use (&$lines): void {
				$lines[] = $line;
			}
		);

		$this->report->write(output: $output);

		$joined = implode("\n", $lines);
		self::assertStringContainsString('1 already present', $joined);
		self::assertStringContainsString('1 present but retired', $joined);
		self::assertStringContainsString('will not turn it back on', $joined);
	}//end testTheSummaryCountsRetiredEntriesSeparately()

	/**
	 * The summary counts every entry and prints one line each.
	 *
	 * @return void
	 */
	public function testTheSummaryPrintsALinePerEntry(): void {
		$this->report->reset();
		$this->report->record(entry: 'handhavingstraject', outcome: 'seeded', reason: 'seeded.');
		$this->report->record(entry: 'klacht-toezicht', outcome: 'skipped', reason: 'no case type.');

		$lines = [];
		$output = $this->createMock(\OCP\Migration\IOutput::class);
		$output->method('info')->willReturnCallback(
			static function (string $line) use (&$lines): void {
				$lines[] = $line;
			}
		);

		$this->report->write(output: $output);

		self::assertStringContainsString('1 seeded', $lines[0]);
		self::assertStringContainsString('1 skipped', $lines[0]);
		self::assertContains('  handhavingstraject: seeded.', $lines);
		self::assertContains('  klacht-toezicht: no case type.', $lines);
	}//end testTheSummaryPrintsALinePerEntry()
}//end class
