<?php

/**
 * Unit tests for StatusTypeLookup — a status by id, and by name.
 *
 * The name→id direction is what a SHIPPED flow depends on: it can only carry a
 * status NAME, because statusType uuids are minted per installation. So a
 * lookup that quietly fails to resolve is a case that quietly stops moving.
 *
 * 🔴 THESE TESTS WERE REWRITTEN AFTER THEY PASSED AGAINST A BROKEN
 * IMPLEMENTATION. The first version fed the lookup a caseType carrying a
 * `statusTypes` array — a property the schema does not have. The relationship
 * runs the other way: every `statusType` holds a `caseType` back-reference. The
 * fixtures matched the assumption instead of the schema, so eleven green tests
 * sat on top of a lookup that returned an empty map for every real case type,
 * which made SetStatusHandler refuse every status move. The e2e found it.
 *
 * The double below therefore models `searchObjects` filtered on `caseType`,
 * which is what the code now actually calls.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class StatusTypeLookupTest extends TestCase {

	/**
	 * A lookup over a fixed set of objects, keyed by id.
	 *
	 * @param array<string, array<string, mixed>> $objects The store contents.
	 * @param boolean                             $throws  Whether reads throw.
	 *
	 * @return StatusTypeLookup The lookup.
	 */
	private function lookup(array $statusRows, bool $throws = false, array $byId = []): StatusTypeLookup {
		$objectService = new class($statusRows, $throws, $byId) {
			public function __construct(
				private array $rows,
				private bool $throws,
				private array $byId,
			) {
			}

			/**
			 * Filtered search, as the real ObjectService performs it.
			 *
			 * Asserts the query is SCOPED: a lookup that fetched every status
			 * type and filtered client-side would match a same-named status
			 * belonging to another case type.
			 */
			public function searchObjects(array $query): array {
				if ($this->throws === true) {
					throw new RuntimeException('unreadable');
				}

				$wanted = (string)($query['caseType'] ?? '');

				return array_values(
					array_filter(
						$this->rows,
						static fn (array $r): bool => (string)($r['caseType'] ?? '') === $wanted
					)
				);
			}

			public function find(string $id, string $register, string $schema): array {
				if ($this->throws === true) {
					throw new RuntimeException('unreadable');
				}

				return ($this->byId[$id] ?? []);
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'register' ? 'dossiq' : $key)
		);

		return new StatusTypeLookup($settings);
	}//end lookup()

	/**
	 * A status belonging to the case type resolves by name.
	 */
	public function testAStatusOfThatCaseTypeResolvesByName(): void {
		$lookup = $this->lookup([
			['id' => 's-2', 'name' => 'In behandeling', 'caseType' => 'ct-1'],
		]);

		self::assertSame('s-2', $lookup->idForName(caseTypeId: 'ct-1', statusName: 'In behandeling'));
	}//end testAStatusOfThatCaseTypeResolvesByName()

	/**
	 * The name is authored by hand in seed data and in the UI, so the match is
	 * trimmed and case-insensitive.
	 */
	public function testTheMatchIsTrimmedAndCaseInsensitive(): void {
		$lookup = $this->lookup([
			['id' => 's-2', 'name' => 'In behandeling', 'caseType' => 'ct-1'],
		]);

		self::assertSame('s-2', $lookup->idForName(caseTypeId: 'ct-1', statusName: '  IN BEHANDELING '));
	}//end testTheMatchIsTrimmedAndCaseInsensitive()

	/**
	 * 🔴 A near miss returns NOTHING, so the caller can refuse.
	 */
	public function testANearMissResolvesToNothing(): void {
		$lookup = $this->lookup([
			['id' => 's-2', 'name' => 'In behandeling', 'caseType' => 'ct-1'],
		]);

		self::assertSame('', $lookup->idForName(caseTypeId: 'ct-1', statusName: 'In behandelin'));
	}//end testANearMissResolvesToNothing()

	/**
	 * 🔴 A SAME-NAMED STATUS ON ANOTHER CASE TYPE IS NOT A MATCH.
	 *
	 * This is what scoping the query buys, and the reason it is filtered
	 * server-side on the back-reference rather than in PHP.
	 */
	public function testAStatusOfADifferentCaseTypeIsNotMatched(): void {
		$lookup = $this->lookup([
			['id' => 'other', 'name' => 'In behandeling', 'caseType' => 'ct-OTHER'],
		]);

		self::assertSame('', $lookup->idForName(caseTypeId: 'ct-1', statusName: 'In behandeling'));
	}//end testAStatusOfADifferentCaseTypeIsNotMatched()

	public function testACaseTypeWithNoStatusesResolvesToNothing(): void {
		$lookup = $this->lookup([]);

		self::assertSame('', $lookup->idForName(caseTypeId: 'ct-1', statusName: 'Ontvangen'));
	}//end testACaseTypeWithNoStatusesResolvesToNothing()

	public function testEmptyArgumentsResolveToNothing(): void {
		$lookup = $this->lookup([['id' => 's', 'name' => 'X', 'caseType' => 'ct-1']]);

		self::assertSame('', $lookup->idForName(caseTypeId: '', statusName: 'X'));
		self::assertSame('', $lookup->idForName(caseTypeId: 'ct-1', statusName: '  '));
	}//end testEmptyArgumentsResolveToNothing()

	public function testAnUnreadableStoreResolvesToNothingRatherThanThrowing(): void {
		$lookup = $this->lookup([['id' => 's', 'name' => 'X', 'caseType' => 'ct-1']], throws: true);

		self::assertSame('', $lookup->idForName(caseTypeId: 'ct-1', statusName: 'X'));
	}//end testAnUnreadableStoreResolvesToNothingRatherThanThrowing()

	public function testTheIdToNameDirectionAlsoWorks(): void {
		$lookup = $this->lookup([], byId: ['s-1' => ['name' => 'Bij commissie']]);

		self::assertSame('Bij commissie', $lookup->nameFor(statusTypeId: 's-1'));
		self::assertSame('', $lookup->nameFor(statusTypeId: ''));
	}//end testTheIdToNameDirectionAlsoWorks()

	public function testStatusesOfReturnsTheWholeMapForThatCaseType(): void {
		$lookup = $this->lookup([
			['id' => 's-1', 'name' => 'Ontvangen', 'caseType' => 'ct-1'],
			['id' => 's-2', 'name' => 'Afgehandeld', 'caseType' => 'ct-1'],
			['id' => 's-9', 'name' => 'Elders', 'caseType' => 'ct-2'],
		]);

		self::assertSame(
			['s-1' => 'Ontvangen', 's-2' => 'Afgehandeld'],
			$lookup->statusesOf(caseTypeId: 'ct-1')
		);
	}//end testStatusesOfReturnsTheWholeMapForThatCaseType()

	/**
	 * A row with no id or no name is skipped rather than producing a broken entry.
	 */
	public function testRowsWithoutAnIdOrNameAreSkipped(): void {
		$lookup = $this->lookup([
			['name' => 'Nameless', 'caseType' => 'ct-1'],
			['id' => 's-blank', 'name' => '', 'caseType' => 'ct-1'],
			['id' => 's-1', 'name' => 'Real', 'caseType' => 'ct-1'],
		]);

		self::assertSame(['s-1' => 'Real'], $lookup->statusesOf(caseTypeId: 'ct-1'));
	}//end testRowsWithoutAnIdOrNameAreSkipped()

	/**
	 * 🔴 THE DEFECT THIS DIRECTION EXISTS FOR.
	 *
	 * Two case types, each with a working phase, each calling it something
	 * else. Matching the literal "In behandeling" — which is what the shipped
	 * flow did — finds the permit's and misses the subsidy's, and that single
	 * miss is what killed 8 of 18 demo runs. Asserted over BOTH case types in
	 * one test, because the property is that ONE instruction reaches both.
	 */
	public function testOneRoleReachesCaseTypesThatNameTheirPhasesDifferently(): void {
		$lookup = $this->lookup([
			['id' => 'perm-2', 'name' => 'In behandeling', 'role' => 'in-progress', 'order' => 2, 'caseType' => 'permit'],
			['id' => 'subs-2', 'name' => 'Beoordeling', 'role' => 'in-progress', 'order' => 2, 'caseType' => 'subsidy'],
		]);

		self::assertSame('perm-2', $lookup->idForRole(caseTypeId: 'permit', role: 'in-progress'));
		self::assertSame('subs-2', $lookup->idForRole(caseTypeId: 'subsidy', role: 'in-progress'));

		// And the literal that used to be the contract reaches only one of them,
		// which is the whole reason the role direction had to exist.
		self::assertSame('perm-2', $lookup->idForName(caseTypeId: 'permit', statusName: 'In behandeling'));
		self::assertSame('', $lookup->idForName(caseTypeId: 'subsidy', statusName: 'In behandeling'));
	}//end testOneRoleReachesCaseTypesThatNameTheirPhasesDifferently()

	/**
	 * A case type that models no such phase resolves to nothing, so the caller
	 * decides whether that is a skip or a refusal.
	 */
	public function testARoleTheCaseTypeDoesNotModelResolvesToNothing(): void {
		$lookup = $this->lookup([
			['id' => 's-1', 'name' => 'Ontvangen', 'role' => 'intake', 'order' => 1, 'caseType' => 'ct-1'],
		]);

		self::assertSame('', $lookup->idForRole(caseTypeId: 'ct-1', role: 'review'));
		self::assertSame('', $lookup->idForRole(caseTypeId: 'ct-1', role: '  '));
		self::assertSame('', $lookup->idForRole(caseTypeId: '', role: 'intake'));
	}//end testARoleTheCaseTypeDoesNotModelResolvesToNothing()

	/**
	 * A role on ANOTHER case type is not a match, exactly as a name is not.
	 */
	public function testARoleOnADifferentCaseTypeIsNotMatched(): void {
		$lookup = $this->lookup([
			['id' => 'other', 'name' => 'Onderzoek', 'role' => 'in-progress', 'order' => 2, 'caseType' => 'ct-OTHER'],
		]);

		self::assertSame('', $lookup->idForRole(caseTypeId: 'ct-1', role: 'in-progress'));
	}//end testARoleOnADifferentCaseTypeIsNotMatched()

	/**
	 * Two statuses sharing a role resolve to the EARLIEST, deterministically.
	 *
	 * A three-phase inspection legitimately has three working statuses. Taking
	 * "whichever the store listed first" would land a case on a different phase
	 * between two runs of the same case type, which nobody reproduces.
	 */
	public function testTheEarliestStatusWinsWhenARoleIsSharedAndOrderIsIgnoredByTheStore(): void {
		$lookup = $this->lookup([
			['id' => 'fase-3', 'name' => 'Inspectie fase 3', 'role' => 'in-progress', 'order' => 4, 'caseType' => 'ct-1'],
			['id' => 'fase-1', 'name' => 'Inspectie fase 1', 'role' => 'in-progress', 'order' => 2, 'caseType' => 'ct-1'],
			['id' => 'fase-2', 'name' => 'Inspectie fase 2', 'role' => 'in-progress', 'order' => 3, 'caseType' => 'ct-1'],
		]);

		self::assertSame('fase-1', $lookup->idForRole(caseTypeId: 'ct-1', role: 'in-progress'));
	}//end testTheEarliestStatusWinsWhenARoleIsSharedAndOrderIsIgnoredByTheStore()

	/**
	 * The role match is trimmed and case-insensitive, like the name match, and
	 * a status carrying no role is never a candidate.
	 */
	public function testTheRoleMatchIsTrimmedAndAnUnannotatedStatusNeverMatches(): void {
		$lookup = $this->lookup([
			['id' => 's-1', 'name' => 'Ontvangen', 'role' => ' In-Progress ', 'order' => 1, 'caseType' => 'ct-1'],
			['id' => 's-2', 'name' => 'Iets anders', 'caseType' => 'ct-1'],
		]);

		self::assertSame('s-1', $lookup->idForRole(caseTypeId: 'ct-1', role: 'in-progress'));
		self::assertSame('', $lookup->idForRole(caseTypeId: 'ct-1', role: 'closed'));
	}//end testTheRoleMatchIsTrimmedAndAnUnannotatedStatusNeverMatches()
}//end class
