<?php

/**
 * OpenRegister contract regression tests.
 *
 * Pins the two live contracts whose drift shipped green-CI/dead-runtime
 * defects (the 2026-09 live-rig proof, DQ#1663):
 *
 * 1. `ObjectService::saveObject()` takes `$object` FIRST. Dossiq carried
 *    29 call sites in the retired `($register, $schema, $object)` order;
 *    every one of them threw at runtime — the beslistermijn timer never
 *    armed on a live pair — while the suite stayed green because the test
 *    fake declared the same wrong order.
 *
 * 2. `searchObjects()` resolves object identity ONLY through the `@self`
 *    metadata block. A top-level `['id' => $uuid]` filter addresses a
 *    schema property no schema declares and silently matches zero rows;
 *    seven call sites (the parafering raise among them) never found their
 *    row while the fake happily resolved the same filter.
 *
 * These tests drive the REAL services against the contract-pinned
 * FakeTermijnStore, so a regression to either retired form fatals or
 * fails here the way it does live.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TypeError;

/**
 * @covers \OCA\Dossiq\Service\TermijnService
 *
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class OrContractRegressionTest extends TestCase {

	private FakeTermijnStore $objects;

	private TermijnService $service;

	protected function setUp(): void {
		$this->objects = new FakeTermijnStore();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'termijn_definitie_schema' => 'deadlineDefinition',
					'termijn_instance_schema' => 'deadlineInstance',
					'termijn_gebeurtenis_schema' => 'termijnGebeurtenis',
					default => '',
				};
			},
		);

		$this->service = new TermijnService($settings, $this->createMock(LoggerInterface::class));

		$this->objects->seed('deadlineDefinition', [
			'id' => 'td-omgevingsvergunning-regulier',
			'caseType' => 'omgevingsvergunning-regulier',
			'legalBasis' => 'Wabo 3.9 lid 1',
			'standardDurationDays' => 56,
			'validFrom' => '2026-01-01',
		]);
	}//end setUp()

	/**
	 * Defect 1 pin: the termijn persist survives the real saveObject()
	 * signature. Before the fix, TermijnService called
	 * `saveObject($register, $schema, $object)` — string into the
	 * `array|ObjectEntity $object` slot — so every live persist threw and
	 * no beslistermijn ever armed.
	 *
	 * @return void
	 */
	public function testTermijnInstancePersistsThroughTheRealSaveObjectSignature(): void {
		$instance = $this->service->createTermijnInstance(
			caseId: 'Z/2026/CONTRACT',
			caseType: 'omgevingsvergunning-regulier',
		);

		self::assertNotSame('', (string)($instance['id'] ?? ''), 'the persisted instance carries an id');
		self::assertArrayHasKey(
			(string)$instance['id'],
			($this->objects->store['deadlineInstance'] ?? []),
			'the TermijnInstance row actually landed in the store'
		);
		self::assertSame('lopend', $instance['status']);
	}//end testTermijnInstancePersistsThroughTheRealSaveObjectSignature()

	/**
	 * Defect 1 stub-honesty pin: the fake declares the REAL signature, so
	 * the retired `($register, $schema, $object)` order fatals in tests
	 * exactly as it does against the live service. A fake that accepts
	 * both orders cannot fail and therefore proves nothing.
	 *
	 * @return void
	 */
	public function testTheRetiredPositionalSaveObjectOrderFatals(): void {
		$this->expectException(TypeError::class);
		/* @phpstan-ignore-next-line deliberately wrong argument order */
		$this->objects->saveObject('dossiq', 'deadlineInstance', ['id' => 'x']);
	}//end testTheRetiredPositionalSaveObjectOrderFatals()

	/**
	 * Defect 2 pin: a top-level `['id' => …]` search filter matches ZERO
	 * rows (live treats it as an undeclared schema property), while the
	 * service still finds its row through the resolving get-by-id path.
	 *
	 * @return void
	 */
	public function testTopLevelIdFiltersMatchNothingButTheIdPathResolves(): void {
		$instance = $this->service->createTermijnInstance(
			caseId: 'Z/2026/CONTRACT-2',
			caseType: 'omgevingsvergunning-regulier',
		);
		$id = (string)$instance['id'];

		// The retired filter form: silently zero rows, the way live behaves.
		self::assertSame(
			[],
			$this->objects->searchObjectsBySlug('dossiq', 'deadlineInstance', ['id' => $id]),
			'a top-level id filter must resolve nothing'
		);

		// The resolving form the code now uses: the row is found.
		$found = $this->service->getTermijnInstance(termInstanceId: $id);
		self::assertNotNull($found, 'the get-by-id path resolves the instance');
		self::assertSame($id, (string)$found['id']);
	}//end testTopLevelIdFiltersMatchNothingButTheIdPathResolves()
}//end class
