<?php

/**
 * Unit tests for NoticeOfDefaultService.
 *
 * Drives the AWB 4:17 registration through valid + premature + duplicate
 * notices, verifies DwangsomBerekening creation, and asserts the
 * one-dwangsom guard semantics.
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
use OCA\Dossiq\Service\NoticeOfDefaultService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\NoticeOfDefaultService
 *
 * @uses \OCA\Dossiq\Service\TermijnService
 */
class NoticeOfDefaultServiceTest extends TestCase {
	private FakeTermijnStore $objects;
	private TermijnService $termService;
	private NoticeOfDefaultService $service;

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
					'ingebrekestelling_schema' => 'noticeOfDefault',
					'dwangsom_berekening_schema' => 'penaltyPaymentCalculation',
					default => '',
				};
			},
		);

		$logger = $this->createMock(LoggerInterface::class);
		$this->termService = new TermijnService($settings, $logger);
		$this->service = new NoticeOfDefaultService($settings, $this->termService, $logger);

		// Seed an AWB-default definition.
		$this->objects->seed('deadlineDefinition', [
			'id' => 'td-ov',
			'caseType' => 'omgevingsvergunning-regulier',
			'wettelijkeGrondslag' => 'Wabo 3.9 lid 1',
			'standardDurationDays' => 56,
			'countExtensions' => 1,
			'validFrom' => '2026-01-01',
		]);

		// Seed an overdue TermijnInstance.
		$this->objects->seed('deadlineInstance', [
			'id' => 'ti-1',
			'case' => 'Z/2026/300',
			'deadlineDefinition' => 'td-ov',
			'startDate' => '2026-01-01T10:00:00+00:00',
			'endDateCalculated' => '2026-02-25',
			'endDateCurrent' => '2026-02-25',
			'status' => 'exceeded',
			'notificatiesVerstuurd' => [],
		]);
	}

	/**
	 * @return void
	 */
	public function testValidNoticeCreatesBerekeningWithCorrectGrace(): void {
		$row = $this->service->registerNoticeOfDefault(
			'ti-1',
			new DateTimeImmutable('2026-03-15'),
			'email',
			'doc:1'
		);

		self::assertTrue($row['gevalideerd']);
		self::assertSame('valid', $row['validityStatus']);
		self::assertArrayHasKey('penaltyPaymentCalculation', $row);

		$b = $row['penaltyPaymentCalculation'];
		self::assertSame('2026-03-29', $b['startDate']);
		self::assertSame(144200, $b['plafondCalculated']);
		self::assertSame('awb-default', $b['regime']);
		self::assertSame('lopend', $b['status']);

		// Instance has the notice linked.
		$updated = $this->objects->store['deadlineInstance']['ti-1'];
		self::assertSame((string)$row['id'], $updated['relevantIngbrekes']);
	}

	/**
	 * @return void
	 */
	public function testPrematureNoticeIsRejected(): void {
		// Use a different instance still in lopend (not overschreden).
		$this->objects->seed('deadlineInstance', [
			'id' => 'ti-lopend',
			'case' => 'Z/2026/301',
			'deadlineDefinition' => 'td-ov',
			'startDate' => '2026-01-01T10:00:00+00:00',
			'endDateCalculated' => '2026-12-31',
			'endDateCurrent' => '2026-12-31',
			'status' => 'lopend',
			'notificatiesVerstuurd' => [],
		]);

		$row = $this->service->registerNoticeOfDefault(
			'ti-lopend',
			new DateTimeImmutable('2026-03-15'),
			'post'
		);

		self::assertFalse($row['gevalideerd']);
		self::assertSame('premaat', $row['validityStatus']);
		self::assertArrayNotHasKey('penaltyPaymentCalculation', $row);
	}

	/**
	 * @return void
	 */
	public function testSecondNoticeDoesNotSpawnSecondBerekening(): void {
		$first = $this->service->registerNoticeOfDefault(
			'ti-1',
			new DateTimeImmutable('2026-03-15'),
			'email'
		);
		self::assertArrayHasKey('penaltyPaymentCalculation', $first);

		$second = $this->service->registerNoticeOfDefault(
			'ti-1',
			new DateTimeImmutable('2026-03-20'),
			'post'
		);
		self::assertTrue($second['gevalideerd']);
		self::assertArrayNotHasKey('penaltyPaymentCalculation', $second);

		// Only one berekening in the store.
		self::assertCount(1, $this->objects->store['penaltyPaymentCalculation'] ?? []);
	}

	/**
	 * @return void
	 */
	public function testCustomRegimeIsResolvedFromDefinition(): void {
		$this->objects->seed('deadlineDefinition', [
			'id' => 'td-woo',
			'caseType' => 'woo-verzoek',
			'wettelijkeGrondslag' => 'Woo art 4.4',
			'standardDurationDays' => 28,
			'countExtensions' => 1,
			'deviatingPenaltyPaymentRegime' => ['dailyTariff' => 1500, 'plafond' => 50000, 'grace' => 14],
			'validFrom' => '2026-01-01',
		]);
		$this->objects->seed('deadlineInstance', [
			'id' => 'ti-woo',
			'case' => 'Z/2026/302',
			'deadlineDefinition' => 'td-woo',
			'startDate' => '2026-01-01T10:00:00+00:00',
			'endDateCalculated' => '2026-01-29',
			'endDateCurrent' => '2026-01-29',
			'status' => 'exceeded',
			'notificatiesVerstuurd' => [],
		]);

		$row = $this->service->registerNoticeOfDefault(
			'ti-woo',
			new DateTimeImmutable('2026-02-15'),
			'post'
		);
		$b = $row['penaltyPaymentCalculation'];
		self::assertSame('afwijkend', $b['regime']);
		self::assertSame(50000, $b['plafondCalculated']);
	}
}
