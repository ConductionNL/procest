<?php

/**
 * Unit tests for DeadlineReportingService.
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

use OCA\Dossiq\Service\DeadlineReportingService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\DeadlineReportingService
 */
class DeadlineReportingServiceTest extends TestCase {
	private FakeTermijnStore $objects;
	private DeadlineReportingService $service;

	protected function setUp(): void {
		$this->objects = new FakeTermijnStore();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'termijn_instance_schema' => 'deadlineInstance',
					'dwangsom_uitbetaling_schema' => 'dwangsomUitbetaling',
					default => '',
				};
			},
		);

		$this->service = new DeadlineReportingService($settings);

		// Seed 5 instances spread across Q2-2026 for one zaaktype.
		for ($i = 1; $i <= 5; $i++) {
			$this->objects->seed('deadlineInstance', [
				'id' => 'ti-q2-' . $i,
				'caseType' => 'omgevingsvergunning-regulier',
				'case' => 'Z/2026/' . (400 + $i),
				'startDate' => '2026-05-0' . $i . 'T10:00:00+00:00',
				'endDateCurrent' => '2026-07-0' . $i,
				'status' => ($i <= 3 ? 'completed' : ($i === 4 ? 'exceeded' : 'lopend')),
				'countExtensions' => ($i === 5 ? 1 : 0),
			]);
		}
	}

	/**
	 * @return void
	 */
	public function testQuarterlyReportAggregatesPerType(): void {
		$report = $this->service->generateQuarterlyReport('2026-Q2');
		self::assertSame('2026-04-01', $report['from']);
		self::assertSame('2026-06-30', $report['until']);

		$row = $report['perType']['omgevingsvergunning-regulier'];
		self::assertSame(5, $row['totaal']);
		self::assertSame(60.0, $row['binnenTermijnPct']);
		self::assertSame(1, $row['overschrijdingen']);
		self::assertSame(1, $row['verlengingen']);
	}

	/**
	 * @return void
	 */
	public function testCsvExportShape(): void {
		$report = $this->service->generateQuarterlyReport('2026-Q2');
		$csv = $this->service->quarterlyReportAsCsv($report);
		$lines = explode("\n", $csv);
		self::assertStringStartsWith('caseType,totaal,binnenTermijnPct', $lines[0]);
		self::assertStringStartsWith('omgevingsvergunning-regulier,5,60', $lines[1]);
	}

	/**
	 * @return void
	 */
	public function testKpiSummary(): void {
		$k = $this->service->getTermijnKpi();
		self::assertSame(5, $k['totalZaken']);
		self::assertSame(60.0, $k['withinTermijnPercent']);
		self::assertSame(1, $k['overrunCount']);
	}

	/**
	 * @return void
	 */
	public function testInvalidPeriodeRaises(): void {
		$this->expectException(\RuntimeException::class);
		$this->service->generateQuarterlyReport('not-a-quarter');
	}

	/**
	 * @return void
	 */
	public function testDwangsomAuditReportFiltersByYear(): void {
		// Seed two uitbetalingen, one in 2026 one in 2025.
		$this->objects->seed('dwangsomUitbetaling', [
			'id' => 'u-1',
			'reference' => 'REF-1',
			'amount' => 35700,
			'actualPaymentDate' => '2026-04-20',
			'betalingsreferentie' => 'ERP-1',
			'status' => 'paid',
			'wettelijkeGrondslag' => 'AWB 4:17',
			'iban' => 'NL91ABNA0417164300',
		]);
		$this->objects->seed('dwangsomUitbetaling', [
			'id' => 'u-2',
			'reference' => 'REF-2',
			'amount' => 50000,
			'actualPaymentDate' => '2025-12-31',
			'betalingsreferentie' => 'ERP-2',
			'status' => 'paid',
		]);

		$report = $this->service->generateDwangsomAuditReport(2026);
		self::assertSame(1, $report['summary']['count']);
		self::assertSame(35700, $report['summary']['totalCents']);
	}
}
