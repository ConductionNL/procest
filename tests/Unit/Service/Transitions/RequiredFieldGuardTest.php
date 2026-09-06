<?php

/**
 * RequiredFieldGuard Unit Tests
 *
 * Verifies that the requiredField guard treats null, '', and empty-array
 * values as missing, and passes for any non-empty scalar/array.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
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
 * @spec openspec/changes/workflow-engine-enhancement/tasks.md#W-19
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\Transitions\RequiredFieldGuard;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Transitions\RequiredFieldGuard
 *
 * @uses \OCA\Dossiq\Service\Transitions\GuardResult
 */
class RequiredFieldGuardTest extends TestCase {
	/**
	 * @return void
	 */
	public function testFailsWhenFieldConfigMissing(): void {
		$guard = new RequiredFieldGuard();
		$result = $guard->evaluate(guardConfig: [], case: ['id' => 'c'], userId: 'u');

		self::assertFalse($result->passed);
		self::assertSame('Required-field guard missing field', $result->failureMessage);
	}//end testFailsWhenFieldConfigMissing()

	/**
	 * @return void
	 */
	public function testFailsWhenFieldIsNull(): void {
		$guard = new RequiredFieldGuard();
		$result = $guard->evaluate(
			guardConfig: ['field' => 'result'],
			case: ['id' => 'c'],
			userId: 'u',
		);

		self::assertFalse($result->passed);
		self::assertSame('result', $result->details['field']);
	}//end testFailsWhenFieldIsNull()

	/**
	 * @return void
	 */
	public function testFailsWhenFieldIsEmptyString(): void {
		$guard = new RequiredFieldGuard();
		$result = $guard->evaluate(
			guardConfig: ['field' => 'result'],
			case: ['result' => ''],
			userId: 'u',
		);

		self::assertFalse($result->passed);
	}//end testFailsWhenFieldIsEmptyString()

	/**
	 * @return void
	 */
	public function testFailsWhenFieldIsEmptyArray(): void {
		$guard = new RequiredFieldGuard();
		$result = $guard->evaluate(
			guardConfig: ['field' => 'tags'],
			case: ['tags' => []],
			userId: 'u',
		);

		self::assertFalse($result->passed);
	}//end testFailsWhenFieldIsEmptyArray()

	/**
	 * @return void
	 */
	public function testPassesWhenFieldHasValue(): void {
		$guard = new RequiredFieldGuard();
		$result = $guard->evaluate(
			guardConfig: ['field' => 'result'],
			case: ['result' => 'toegewezen'],
			userId: 'u',
		);

		self::assertTrue($result->passed);
		self::assertSame('result', $result->details['field']);
	}//end testPassesWhenFieldHasValue()

	/**
	 * @return void
	 */
	public function testPassesForZeroNumericValue(): void {
		// 0 is a legitimate value, not "empty".
		$guard = new RequiredFieldGuard();
		$result = $guard->evaluate(
			guardConfig: ['field' => 'amount'],
			case: ['amount' => 0],
			userId: 'u',
		);

		self::assertTrue($result->passed);
	}//end testPassesForZeroNumericValue()

	/**
	 * A case-type property is answered in `properties[]`, not as a column.
	 *
	 * A case type declares its own extra fields as propertyDefinitions, and the
	 * case answers them in `properties[]` as `{propertyDefinition, name,
	 * value}`. `stemuitslag` is declared exactly that way by all three
	 * besluitvorming bundles, so a guard that only read the top level could
	 * never see the answer and the transition out of "Vergadering" was
	 * unreachable.
	 *
	 * @return void
	 */
	public function testPassesOnAnAnsweredCaseTypeProperty(): void {
		$guard = new RequiredFieldGuard();
		$result = $guard->evaluate(
			guardConfig: ['field' => 'stemuitslag'],
			case: ['properties' => [['propertyDefinition' => 'pd-1', 'name' => 'stemuitslag', 'value' => 'Unaniem']]],
			userId: 'u',
		);

		self::assertTrue($result->passed);
	}//end testPassesOnAnAnsweredCaseTypeProperty()

	/**
	 * An unanswered case-type property still blocks the transition.
	 *
	 * @return void
	 */
	public function testFailsOnAnUnansweredCaseTypeProperty(): void {
		$guard = new RequiredFieldGuard();
		$result = $guard->evaluate(
			guardConfig: ['field' => 'stemuitslag'],
			case: ['properties' => [['propertyDefinition' => 'pd-1', 'name' => 'stemuitslag', 'value' => '']]],
			userId: 'u',
		);

		self::assertFalse($result->passed);
		self::assertSame('Vereist veld ontbreekt: stemuitslag', $result->failureMessage);
	}//end testFailsOnAnUnansweredCaseTypeProperty()

	/**
	 * A column on the case still wins over the properties array.
	 *
	 * @return void
	 */
	public function testATopLevelValueStillWins(): void {
		$guard = new RequiredFieldGuard();
		$result = $guard->evaluate(
			guardConfig: ['field' => 'result'],
			case: ['result' => 'toegewezen', 'properties' => [['name' => 'result', 'value' => '']]],
			userId: 'u',
		);

		self::assertTrue($result->passed);
	}//end testATopLevelValueStillWins()
}//end class
