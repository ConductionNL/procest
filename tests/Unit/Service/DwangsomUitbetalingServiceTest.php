<?php

/**
 * Unit tests for DwangsomUitbetalingService.
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
use OCA\Dossiq\Service\DwangsomUitbetalingService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\DwangsomUitbetalingService
 */
class DwangsomUitbetalingServiceTest extends TestCase {
	private FakeTermijnStore $objects;
	private DwangsomUitbetalingService $service;

	protected function setUp(): void {
		$this->objects = new FakeTermijnStore();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'dwangsom_berekening_schema' => 'penaltyPaymentCalculation',
					'dwangsom_uitbetaling_schema' => 'dwangsomUitbetaling',
					default => '',
				};
			},
		);

		$this->service = new DwangsomUitbetalingService($settings);

		// Seed a stopped berekening.
		$this->objects->seed('penaltyPaymentCalculation', [
			'id' => 'b-stopped',
			'noticeOfDefault' => 'ig-1',
			'deadlineInstance' => 'ti-1',
			'status' => 'gestopt-wegens-decision',
			'definitiveAmount' => 35700,
		]);
	}

	/**
	 * @return void
	 */
	public function testValidIbanPassesValidation(): void {
		// Real test IBANs (mod-97 checksum verified).
		self::assertTrue($this->service->isValidIban('NL91ABNA0417164300'));
		self::assertTrue($this->service->isValidIban('NL91 ABNA 0417 1643 00'));
		self::assertTrue($this->service->isValidIban('DE89370400440532013000'));
	}

	/**
	 * @return void
	 */
	public function testInvalidIbanFailsValidation(): void {
		self::assertFalse($this->service->isValidIban(''));
		self::assertFalse($this->service->isValidIban('NL91ABNA1234567890'));
		self::assertFalse($this->service->isValidIban('not-an-iban'));
	}

	/**
	 * @return void
	 */
	public function testPrepareBetalingCreatesUitbetaling(): void {
		$row = $this->service->prepareBetaling(
			'b-stopped',
			'J. Burger',
			'NL91ABNA0417164300',
			new DateTimeImmutable('2026-04-01')
		);

		self::assertSame(35700, $row['amount']);
		self::assertSame('voorbereid', $row['status']);
		self::assertSame('NL91ABNA0417164300', $row['iban']);
		self::assertSame('2026-04-29', $row['paymentDateLatest']);
		self::assertSame('AWB 4:17', $row['legalBasis']);
		self::assertStringStartsWith('PROC-DWS-', $row['reference']);
	}

	/**
	 * @return void
	 */
	public function testPrepareBetalingRejectsInvalidIban(): void {
		$this->expectException(RuntimeException::class);
		$this->service->prepareBetaling('b-stopped', 'J. Burger', 'INVALID');
	}

	/**
	 * @return void
	 */
	public function testPrepareBetalingRejectsZeroAmount(): void {
		$this->objects->seed('penaltyPaymentCalculation', [
			'id' => 'b-zero',
			'status' => 'gestopt-wegens-decision',
			'definitiveAmount' => 0,
			'cumulativeAmount' => 0,
		]);

		$this->expectException(RuntimeException::class);
		$this->service->prepareBetaling('b-zero', 'J. Burger', 'NL91ABNA0417164300');
	}

	/**
	 * @return void
	 */
	public function testHandleCallbackUpdatesUitbetaling(): void {
		$created = $this->service->prepareBetaling(
			'b-stopped',
			'J. Burger',
			'NL91ABNA0417164300',
			new DateTimeImmutable('2026-04-01')
		);

		$updated = $this->service->handleCallback(
			(string)$created['reference'],
			'paid',
			new DateTimeImmutable('2026-04-20'),
			'ERP-XYZ-987'
		);

		self::assertSame('paid', $updated['status']);
		self::assertSame('2026-04-20', $updated['actualPaymentDate']);
		self::assertSame('ERP-XYZ-987', $updated['betalingsreferentie']);
	}

	/**
	 * @return void
	 */
	public function testHandleCallbackRejectsUnknownReferentie(): void {
		$this->expectException(RuntimeException::class);
		$this->service->handleCallback('UNKNOWN-REF', 'paid', new DateTimeImmutable());
	}
}
