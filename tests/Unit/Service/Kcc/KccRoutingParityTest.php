<?php

/**
 * The parity oracle: legacy RoutingEngine vs the shared-evaluator compilation.
 *
 * THE DISCIPLINE (humaniq#289). The legacy evaluator is not deleted on the
 * strength of a new one that has not run its data: it stays as the oracle,
 * and this test drives BOTH paths over one pinned fixture matrix — every
 * condition type, the priority order, the disabled flag, the inexpressible
 * rules, and a sweep of contact moments and clock times. A disagreement
 * anywhere is a compiler defect caught before the staged retirement
 * (tasks section 5 of kcc-routing-onto-or-decision-tables) removes the
 * oracle.
 *
 * The table path runs the REAL shared semantics: tests/Stubs/Service/Dmn
 * carries verbatim copies of OpenRegister's pure Dmn classes, loaded only
 * when the real ones are absent.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/kcc-routing-onto-or-decision-tables/specs/kcc-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Kcc;

use DateTimeImmutable;
use OCA\Dossiq\Service\Kcc\RoutingEngine;
use OCA\Dossiq\Service\Kcc\RoutingTableEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Kcc\RoutingTableEvaluator
 * @covers \OCA\Dossiq\Service\Kcc\RoutingEngine
 */
class KccRoutingParityTest extends TestCase {

	/**
	 * The pinned rule matrix: every condition type, plus the shapes that can
	 * never match (contradiction, malformed window, unknown type, empty
	 * conditions, disabled) sitting at HIGH priority so a compiler that
	 * wrongly admits one changes an answer somewhere in the sweep.
	 *
	 * @return array<int, array<string, mixed>> The rules.
	 */
	private function rules(): array {
		return [
			[
				'name' => 'Tegenspraak (nooit)',
				'priority' => 0,
				'enabled' => true,
				'matchConditions' => [
					['type' => 'channel', 'value' => 'telefoon'],
					['type' => 'channel', 'value' => 'email'],
				],
				'assignedDomain' => 'x',
				'assignedTeam' => 'X',
			],
			[
				'name' => 'Kapotte tijd (nooit)',
				'priority' => 0,
				'enabled' => true,
				'matchConditions' => [['type' => 'time_of_day', 'value' => 'middagje']],
				'assignedDomain' => 'x',
				'assignedTeam' => 'X',
			],
			[
				'name' => 'Onbekend type (nooit)',
				'priority' => 0,
				'enabled' => true,
				'matchConditions' => [['type' => 'sentiment', 'value' => 'boos']],
				'assignedDomain' => 'x',
				'assignedTeam' => 'X',
			],
			[
				'name' => 'Leeg (nooit)',
				'priority' => 0,
				'enabled' => true,
				'matchConditions' => [],
				'assignedDomain' => 'x',
				'assignedTeam' => 'X',
			],
			[
				'name' => 'Uitgeschakeld (nooit)',
				'priority' => 0,
				'enabled' => false,
				'matchConditions' => [['type' => 'keyword', 'value' => 'paspoort']],
				'assignedDomain' => 'x',
				'assignedTeam' => 'X',
			],
			[
				'name' => 'Spoed telefoon buiten kantoortijd',
				'priority' => 1,
				'enabled' => true,
				'matchConditions' => [
					['type' => 'keyword', 'value' => 'spoed'],
					['type' => 'channel', 'value' => 'telefoon'],
					['type' => 'time_of_day', 'value' => 'after_17:00'],
				],
				'assignedDomain' => 'calamiteiten',
				'assignedTeam' => 'Piket',
				'escalationTeam' => 'Frontoffice',
			],
			[
				'name' => 'Paspoort → Burgerzaken',
				'priority' => 2,
				'enabled' => true,
				'matchConditions' => [['type' => 'keyword', 'value' => 'Paspoort']],
				'assignedDomain' => 'burgerzaken',
				'assignedTeam' => 'Burgerzaken',
				'escalationTeam' => 'Frontoffice',
			],
			[
				'name' => 'WMO regex',
				'priority' => 3,
				'enabled' => true,
				'matchConditions' => [['type' => 'regex', 'value' => 'wmo.*(aanvraag|verzoek)']],
				'assignedDomain' => 'wmo',
				'assignedTeam' => 'Sociaal Domein',
			],
			[
				'name' => 'Bedrijven overdag',
				'priority' => 4,
				'enabled' => true,
				'matchConditions' => [
					['type' => 'customer_type', 'value' => 'bedrijf'],
					['type' => 'time_of_day', 'value' => 'after_09:00'],
					['type' => 'time_of_day', 'value' => 'before_17:00'],
				],
				'assignedDomain' => 'ondernemersloket',
				'assignedTeam' => 'Ondernemersloket',
			],
			[
				'name' => 'Weekend naar balie-dinsdag',
				'priority' => 5,
				'enabled' => true,
				'matchConditions' => [['type' => 'day_of_week', 'value' => 'Saturday']],
				'assignedDomain' => 'frontoffice',
				'assignedTeam' => 'Weekendploeg',
			],
			[
				'name' => 'Streepjeskanaal',
				'priority' => 6,
				'enabled' => true,
				'matchConditions' => [['type' => 'channel', 'value' => '-']],
				'assignedDomain' => 'overig',
				'assignedTeam' => 'Overig',
			],
			[
				'name' => 'Anoniem vangnet',
				'priority' => 7,
				'enabled' => true,
				'matchConditions' => [['type' => 'customer_type', 'value' => 'anoniem']],
				'assignedDomain' => 'algemeen',
				'assignedTeam' => 'KCC Algemeen',
			],
		];
	}//end rules()

	/**
	 * The contact moments the sweep crosses with the clock times.
	 *
	 * @return array<string, array<string, mixed>> The moments.
	 */
	private function contactMoments(): array {
		return [
			'paspoort per mail' => ['subject' => 'Nieuw PASPOORT nodig', 'summary' => '', 'channel' => 'email', 'customerRef' => 'bsn-1'],
			'spoed telefoon' => ['subject' => 'SPOED: gaslek', 'summary' => 'ruikt gas', 'channel' => 'telefoon', 'customerRef' => ''],
			'wmo verzoek' => ['subject' => 'Wmo', 'summary' => 'een verzoek om huishoudelijke hulp', 'channel' => 'email', 'customerRef' => 'bsn-2'],
			'bedrijf (kvk)' => ['subject' => 'Vergunning terras', 'summary' => '', 'channel' => 'balie', 'customerRef' => '12345678'],
			'streepjeskanaal' => ['subject' => 'iets', 'summary' => '', 'channel' => '-', 'customerRef' => 'bsn-3'],
			'anoniem niets' => ['subject' => 'algemene vraag', 'summary' => ''],
			'lege moment' => [],
			'negen cijfers is burger' => ['subject' => 'Vergunning', 'channel' => 'balie', 'customerRef' => '123456789'],
		];
	}//end contactMoments()

	/**
	 * The clock times: weekday/weekend, inside/outside office hours, and the
	 * inclusive boundary minute itself.
	 *
	 * @return array<string, DateTimeImmutable> The times.
	 */
	private function clockTimes(): array {
		return [
			'woensdag ochtend' => new DateTimeImmutable('2026-09-02 10:30:00'),
			'woensdag avond' => new DateTimeImmutable('2026-09-02 18:45:00'),
			'zaterdag middag' => new DateTimeImmutable('2026-09-05 13:00:00'),
			'grens 17:00 precies' => new DateTimeImmutable('2026-09-02 17:00:00'),
			'grens 08:59' => new DateTimeImmutable('2026-09-02 08:59:00'),
		];
	}//end clockTimes()

	/**
	 * 🔴 BOTH PATHS AGREE ON EVERY CELL OF THE MATRIX.
	 */
	public function testTheTableCompilationAgreesWithTheLegacyEngineEverywhere(): void {
		$legacy = new RoutingEngine();
		$table = new RoutingTableEvaluator();
		$rules = $this->rules();

		$compared = 0;
		foreach ($this->contactMoments() as $momentLabel => $moment) {
			foreach ($this->clockTimes() as $timeLabel => $now) {
				$expected = $legacy->evaluate(rules: $rules, contactMoment: $moment, now: $now);
				$actual = $table->route(rules: $rules, contactMoment: $moment, now: $now);

				self::assertSame(
					$expected,
					$actual,
					sprintf('Parity broke for "%s" at "%s".', $momentLabel, $timeLabel)
				);
				$compared++;
			}
		}

		self::assertSame(40, $compared, 'The sweep must cover the whole matrix.');
	}//end testTheTableCompilationAgreesWithTheLegacyEngineEverywhere()

	/**
	 * The matrix is not vacuous: the sweep produces real matches AND real
	 * no-matches, so agreement above is agreement about something.
	 */
	public function testTheMatrixExercisesBothMatchAndNoMatch(): void {
		$legacy = new RoutingEngine();
		$rules = $this->rules();

		$matched = 0;
		$unmatched = 0;
		$winners = [];
		foreach ($this->contactMoments() as $moment) {
			foreach ($this->clockTimes() as $now) {
				$result = $legacy->evaluate(rules: $rules, contactMoment: $moment, now: $now);
				if ($result === null) {
					$unmatched++;
					continue;
				}

				$matched++;
				$winners[(string)$result['rule']] = true;
			}
		}

		self::assertGreaterThan(0, $matched, 'A matrix with no matches proves nothing.');
		self::assertGreaterThan(0, $unmatched, 'A matrix with no refusals proves nothing either.');
		self::assertGreaterThan(3, count($winners), 'Several different rules must win somewhere in the sweep.');
	}//end testTheMatrixExercisesBothMatchAndNoMatch()
}//end class
