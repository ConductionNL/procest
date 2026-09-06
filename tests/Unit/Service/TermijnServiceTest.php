<?php

/**
 * Unit tests for TermijnService.
 *
 * Drives termijn instance creation, definition version resolution,
 * completion, and error handling against an in-memory ObjectService fake.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\TermijnService
 */
class TermijnServiceTest extends TestCase {

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

		// Seed two definitions: omgevingsvergunning 56d (active) + Wmo 42d (active).
		$this->objects->seed('deadlineDefinition',
			[
				'id' => 'td-omgevingsvergunning-regulier',
				'caseType' => 'omgevingsvergunning-regulier',
				'legalBasis' => 'Wabo 3.9 lid 1',
				'standardDurationDays' => 56,
				'countExtensions' => 1,
				'validFrom' => '2026-01-01',
			]
		);
		$this->objects->seed('deadlineDefinition',
			[
				'id' => 'td-wmo-aanvraag',
				'caseType' => 'wmo-melding',
				'legalBasis' => 'Wmo 2015 art 2.3.5',
				'standardDurationDays' => 42,
				'countExtensions' => 0,
				'validFrom' => '2026-01-01',
			]
		);
	}//end setUp()

	/**
	 * @return void
	 */
	public function testCreateTermijnInstanceForOmgevingsvergunningHas56DayDeadline(): void {
		$start = new DateTimeImmutable('2026-06-01T10:00:00+00:00');
		$instance = $this->service->createTermijnInstance('Z/2026/123', 'omgevingsvergunning-regulier', $start);

		self::assertSame('Z/2026/123', $instance['case']);
		self::assertSame('td-omgevingsvergunning-regulier', $instance['deadlineDefinition']);
		self::assertSame('lopend', $instance['status']);
		self::assertSame('2026-07-27', $instance['endDateCalculated']);
		self::assertSame('2026-07-27', $instance['endDateCurrent']);

		// Start event recorded.
		$events = $this->objects->store['termijnGebeurtenis'] ?? [];
		self::assertCount(1, $events);
		$event = array_values($events)[0];
		self::assertSame('start', $event['type']);
		self::assertSame(56, $event['daysImpact']);
		self::assertSame('Wabo 3.9 lid 1', $event['basis']);
	}//end testCreateTermijnInstanceForOmgevingsvergunningHas56DayDeadline()

	/**
	 * @return void
	 */
	public function testCreateTermijnInstanceForWmoHas42DayDeadline(): void {
		$start = new DateTimeImmutable('2026-06-01T10:00:00+00:00');
		$instance = $this->service->createTermijnInstance('Z/2026/124', 'wmo-melding', $start);

		self::assertSame('2026-07-13', $instance['endDateCalculated']);
	}//end testCreateTermijnInstanceForWmoHas42DayDeadline()

	/**
	 * @return void
	 */
	public function testCreateTermijnInstanceFailsWithoutMatchingDefinition(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('REQ-TERM-001-A');

		$this->service->createTermijnInstance('Z/2026/125', 'unknown-zaaktype');
	}//end testCreateTermijnInstanceFailsWithoutMatchingDefinition()

	/**
	 * @return void
	 */
	public function testGetTermijnDefinitieReturnsLatestActiveVersion(): void {
		// Add a newer version of the omgevingsvergunning definition.
		$this->objects->seed('deadlineDefinition',
			[
				'id' => 'td-omgevingsvergunning-regulier-v2',
				'caseType' => 'omgevingsvergunning-regulier',
				'legalBasis' => 'Wabo 3.9 lid 1',
				'standardDurationDays' => 70,
				'validFrom' => '2026-03-01',
			]
		);

		// Reset cache by creating a new service.
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
		$service = new TermijnService($settings, $this->createMock(LoggerInterface::class));

		$resolved = $service->getTermijnDefinitie('omgevingsvergunning-regulier');
		self::assertNotNull($resolved);
		self::assertSame('td-omgevingsvergunning-regulier-v2', $resolved['id']);
		self::assertSame(70, $resolved['standardDurationDays']);
	}//end testGetTermijnDefinitieReturnsLatestActiveVersion()

	/**
	 * @return void
	 */
	public function testMarkTermijnCompletedRecordsVoltooiEvent(): void {
		$instance = $this->service->createTermijnInstance('Z/2026/126', 'omgevingsvergunning-regulier');
		$id = (string)$instance['id'];

		$voltooid = $this->service->markTermijnCompleted($id, new DateTimeImmutable('2026-07-01'));
		self::assertNotNull($voltooid);
		self::assertSame('completed', $voltooid['status']);
		self::assertSame('2026-07-01', $voltooid['voltooiDatum']);

		$events = array_values($this->objects->store['termijnGebeurtenis'] ?? []);
		$voltooiEv = array_values(array_filter($events, static fn (array $e): bool => $e['type'] === 'voltooi'));
		self::assertCount(1, $voltooiEv);
	}//end testMarkTermijnCompletedRecordsVoltooiEvent()

	/**
	 * @return void
	 */
	public function testGetTermijnInstanceForZaakReturnsLatest(): void {
		$first = $this->service->createTermijnInstance(
			'Z/2026/127',
			'wmo-melding',
			new DateTimeImmutable('2026-05-01T10:00:00+00:00')
		);
		$second = $this->service->createTermijnInstance(
			'Z/2026/127',
			'wmo-melding',
			new DateTimeImmutable('2026-06-01T10:00:00+00:00')
		);

		$resolved = $this->service->getTermijnInstanceForZaak('Z/2026/127');
		self::assertNotNull($resolved);
		self::assertSame($second['id'], $resolved['id']);
	}//end testGetTermijnInstanceForZaakReturnsLatest()

	/**
	 * Version-pinning: an existing TermijnInstance keeps its original
	 * `termijnDefinitie` reference even after a new definition version is
	 * published for the same zaaktype. Only newly-created instances bind to
	 * the latest active version. Closes the
	 * `termijnbewaking-dwangsom-engine-11-tests-admin-docs` "new cases use
	 * latest version; existing retain original" deferral.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-11-tests-admin-docs/tasks.md
	 */
	public function testExistingTermijnInstanceRetainsOriginalDefinitieAfterVersionBump(): void {
		// Phase 1 — case opens against v1 (56 days).
		$existing = $this->service->createTermijnInstance(
			'Z/2026/300',
			'omgevingsvergunning-regulier',
			new DateTimeImmutable('2026-01-15T09:00:00+00:00')
		);
		self::assertSame('td-omgevingsvergunning-regulier', $existing['deadlineDefinition']);
		// 2026-01-15 + 56 days = 2026-03-12.
		self::assertSame('2026-03-12', $existing['endDateCalculated']);

		// Phase 2 — publish a new v2 (70 days) for the same zaaktype.
		$this->objects->seed('deadlineDefinition',
			[
				'id' => 'td-omgevingsvergunning-regulier-v2',
				'caseType' => 'omgevingsvergunning-regulier',
				'legalBasis' => 'Wabo 3.9 lid 1',
				'standardDurationDays' => 70,
				'validFrom' => '2026-03-01',
			]
		);

		// Phase 3 — re-fetch the same instance: definitie reference is
		// the v1 row, NOT v2. The instance row was persisted with the v1 id
		// at creation time and is never re-resolved against the catalogue.
		$reloaded = $this->service->getTermijnInstance((string)$existing['id']);
		self::assertNotNull($reloaded);
		self::assertSame('td-omgevingsvergunning-regulier', $reloaded['deadlineDefinition']);
		self::assertSame('2026-03-12', $reloaded['endDateCalculated']);

		// Phase 4 — a brand-new instance for the same zaaktype binds to v2.
		// Reset the definitie cache by creating a fresh service so the new
		// active row wins the validFrom sort.
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
		$freshService = new TermijnService($settings, $this->createMock(LoggerInterface::class));

		$fresh = $freshService->createTermijnInstance(
			'Z/2026/301',
			'omgevingsvergunning-regulier',
			new DateTimeImmutable('2026-04-01T09:00:00+00:00')
		);
		self::assertSame('td-omgevingsvergunning-regulier-v2', $fresh['deadlineDefinition']);
		// 2026-04-01 + 70 days = 2026-06-10.
		self::assertSame('2026-06-10', $fresh['endDateCalculated']);
	}//end testExistingTermijnInstanceRetainsOriginalDefinitieAfterVersionBump()
}//end class

// `FakeTermijnStore` is now declared in tests/Unit/Fixtures/FakeTermijnStore.php
// and loaded by tests/bootstrap.php so every termijnbewaking + archief-edepot
// unit test file can resolve the class even when run standalone (e.g. via
// `phpunit --filter Foo tests/Unit/Service/ArchivalServicesTest.php`).
