<?php

/**
 * Unit tests for RoutingTableEvaluator — KCC rules on the shared DMN engine.
 *
 * These run against the REAL evaluation semantics: tests/Stubs/Service/Dmn
 * carries verbatim copies of OpenRegister's pure Dmn classes (the humaniq#289
 * pattern), so what is proven here is the actual compilation contract, not a
 * hand-scripted fake's agreement with it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/kcc-routing-onto-or-decision-tables/specs/kcc-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Kcc;

use DateTimeImmutable;
use OCA\Dossiq\Service\Kcc\RoutingTableEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Kcc\RoutingTableEvaluator
 */
class RoutingTableEvaluatorTest extends TestCase {

	private RoutingTableEvaluator $evaluator;

	protected function setUp(): void {
		$this->evaluator = new RoutingTableEvaluator();
	}//end setUp()

	/**
	 * One rule, for composing fixtures.
	 *
	 * @param array<string, mixed> $overrides Fields to set or override.
	 *
	 * @return array<string, mixed> The rule.
	 */
	private function rule(array $overrides = []): array {
		return array_merge(
			[
				'name' => 'Paspoort → Burgerzaken',
				'priority' => 1,
				'enabled' => true,
				'matchConditions' => [['type' => 'keyword', 'value' => 'paspoort']],
				'assignedDomain' => 'burgerzaken',
				'assignedTeam' => 'Burgerzaken',
				'escalationTeam' => 'Frontoffice',
			],
			$overrides
		);
	}//end rule()

	public function testAKeywordRuleRoutesByTheHaystack(): void {
		$result = $this->evaluator->route(
			rules: [$this->rule()],
			contactMoment: ['subject' => 'Nieuw paspoort aanvragen', 'summary' => '']
		);

		self::assertSame('Paspoort → Burgerzaken', $result['rule']);
		self::assertSame('burgerzaken', $result['assignedDomain']);
		self::assertSame('Burgerzaken', $result['assignedTeam']);
		self::assertSame('Frontoffice', $result['escalationTeam']);
	}//end testAKeywordRuleRoutesByTheHaystack()

	public function testNoMatchAnswersNull(): void {
		self::assertNull(
			$this->evaluator->route(
				rules: [$this->rule()],
				contactMoment: ['subject' => 'Kapotte lantaarnpaal', 'summary' => '']
			)
		);
	}//end testNoMatchAnswersNull()

	public function testTheLowestPriorityMatchWinsHoweverTheRulesAreListed(): void {
		$rules = [
			$this->rule(['name' => 'Later', 'priority' => 9, 'assignedTeam' => 'B']),
			$this->rule(['name' => 'Eerst', 'priority' => 1, 'assignedTeam' => 'A']),
		];

		$result = $this->evaluator->route(rules: $rules, contactMoment: ['subject' => 'paspoort kwijt']);

		self::assertSame('Eerst', $result['rule']);
	}//end testTheLowestPriorityMatchWinsHoweverTheRulesAreListed()

	public function testADisabledRuleIsSkipped(): void {
		$rules = [
			$this->rule(['name' => 'Uit', 'enabled' => false, 'priority' => 1]),
			$this->rule(['name' => 'Aan', 'priority' => 2, 'assignedTeam' => 'Actief']),
		];

		$result = $this->evaluator->route(rules: $rules, contactMoment: ['subject' => 'paspoort']);

		self::assertSame('Aan', $result['rule']);
	}//end testADisabledRuleIsSkipped()

	public function testAllConditionsOfARuleMustHold(): void {
		$rules = [
			$this->rule(
				[
					'matchConditions' => [
						['type' => 'keyword', 'value' => 'paspoort'],
						['type' => 'channel', 'value' => 'telefoon'],
					],
				]
			),
		];

		self::assertNull(
			$this->evaluator->route(
				rules: $rules,
				contactMoment: ['subject' => 'paspoort', 'channel' => 'email']
			),
			'A rule is a conjunction: one failing condition refuses the whole rule.'
		);

		$result = $this->evaluator->route(
			rules: $rules,
			contactMoment: ['subject' => 'paspoort', 'channel' => 'telefoon']
		);
		self::assertNotNull($result);
	}//end testAllConditionsOfARuleMustHold()

	public function testATimeWindowCompilesToOneRangeCell(): void {
		$rules = [
			$this->rule(
				[
					'name' => 'Kantooruren',
					'matchConditions' => [
						['type' => 'time_of_day', 'value' => 'after_09:00'],
						['type' => 'time_of_day', 'value' => 'before_17:00'],
					],
				]
			),
		];

		self::assertNotNull(
			$this->evaluator->route(rules: $rules, contactMoment: [], now: new DateTimeImmutable('2026-09-02 10:30:00'))
		);
		self::assertNull(
			$this->evaluator->route(rules: $rules, contactMoment: [], now: new DateTimeImmutable('2026-09-02 18:30:00'))
		);
		self::assertNull(
			$this->evaluator->route(rules: $rules, contactMoment: [], now: new DateTimeImmutable('2026-09-02 08:59:00')),
			'The lower bound is inclusive at 09:00 and refuses 08:59.'
		);
	}//end testATimeWindowCompilesToOneRangeCell()

	public function testAChannelValueTheBareGrammarWouldMisreadIsQuoted(): void {
		// `-` is the DMN wildcard when written bare; as a channel VALUE it must
		// mean the literal string, so the compiler quotes it.
		$rules = [$this->rule(['matchConditions' => [['type' => 'channel', 'value' => '-']]])];

		self::assertNotNull($this->evaluator->route(rules: $rules, contactMoment: ['channel' => '-']));
		self::assertNull($this->evaluator->route(rules: $rules, contactMoment: ['channel' => 'balie']));
	}//end testAChannelValueTheBareGrammarWouldMisreadIsQuoted()

	public function testAnInexpressibleRuleCompilesToNothingInsteadOfToAWrongAnswer(): void {
		$rules = [
			// Two DIFFERENT channels can never both hold.
			$this->rule(
				[
					'name' => 'Tegenspraak',
					'priority' => 1,
					'matchConditions' => [
						['type' => 'channel', 'value' => 'telefoon'],
						['type' => 'channel', 'value' => 'email'],
					],
				]
			),
			// A malformed time window never matched under the legacy engine.
			$this->rule(
				[
					'name' => 'Kapotte tijd',
					'priority' => 2,
					'matchConditions' => [['type' => 'time_of_day', 'value' => 'rond de middag']],
				]
			),
			// An unknown condition type never matched either.
			$this->rule(
				[
					'name' => 'Onbekend type',
					'priority' => 3,
					'matchConditions' => [['type' => 'sentiment', 'value' => 'boos']],
				]
			),
			// No conditions at all: the legacy engine refused the rule.
			$this->rule(['name' => 'Leeg', 'priority' => 4, 'matchConditions' => []]),
			$this->rule(['name' => 'Vangnet', 'priority' => 9, 'assignedTeam' => 'Wel']),
		];

		$result = $this->evaluator->route(
			rules: $rules,
			contactMoment: ['subject' => 'paspoort', 'channel' => 'telefoon']
		);

		self::assertSame('Vangnet', $result['rule'], 'Only the expressible rule may match.');
	}//end testAnInexpressibleRuleCompilesToNothingInsteadOfToAWrongAnswer()

	public function testCustomerTypeIsDerivedFromTheReference(): void {
		$rules = [
			$this->rule(['name' => 'Bedrijven', 'priority' => 1, 'matchConditions' => [['type' => 'customer_type', 'value' => 'bedrijf']]]),
			$this->rule(['name' => 'Burgers', 'priority' => 2, 'matchConditions' => [['type' => 'customer_type', 'value' => 'burger']]]),
			$this->rule(['name' => 'Anoniem', 'priority' => 3, 'matchConditions' => [['type' => 'customer_type', 'value' => 'anoniem']]]),
		];

		self::assertSame('Bedrijven', $this->evaluator->route(rules: $rules, contactMoment: ['customerRef' => '12345678'])['rule']);
		self::assertSame('Burgers', $this->evaluator->route(rules: $rules, contactMoment: ['customerRef' => 'bsn-999'])['rule']);
		self::assertSame('Anoniem', $this->evaluator->route(rules: $rules, contactMoment: [])['rule']);
	}//end testCustomerTypeIsDerivedFromTheReference()

	public function testNoRulesAnswersNull(): void {
		self::assertNull($this->evaluator->route(rules: [], contactMoment: ['subject' => 'wat dan ook']));
	}//end testNoRulesAnswersNull()
}//end class
