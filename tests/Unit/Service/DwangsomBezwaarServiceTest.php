<?php

/**
 * Unit tests for DwangsomBezwaarService.
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

use OCA\Dossiq\Service\DwangsomBezwaarService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\DwangsomBezwaarService
 *
 * @uses \OCA\Dossiq\Service\TermijnService
 */
class DwangsomBezwaarServiceTest extends TestCase {
	private FakeTermijnStore $objects;
	private DwangsomBezwaarService $service;

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
					'dwangsom_berekening_schema' => 'penaltyPaymentCalculation',
					'dwangsom_uitbetaling_schema' => 'dwangsomUitbetaling',
					default => '',
				};
			},
		);

		$logger = $this->createMock(LoggerInterface::class);
		$this->service = new DwangsomBezwaarService(
			$settings,
			new TermijnService($settings, $logger),
			$logger
		);

		// Seed berekening + uitbetaling.
		$this->objects->seed('penaltyPaymentCalculation', [
			'id' => 'b-1',
			'deadlineInstance' => 'ti-1',
			'status' => 'gestopt-wegens-decision',
			'definitiveAmount' => 50000,
		]);
		$this->objects->seed('dwangsomUitbetaling', [
			'id' => 'u-1',
			'penaltyPaymentCalculation' => 'b-1',
			'amount' => 50000,
			'status' => 'voorbereid',
		]);
	}

	/**
	 * @return void
	 */
	public function testRegisterBezwaarFreezesBerekeningAndHoldsUitbetaling(): void {
		$b = $this->service->registerBezwaar('b-1', 'AWB 7:1', 'Belanghebbende betwist bedrag');
		self::assertSame('objection-bevroren', $b['status']);

		$u = $this->objects->store['dwangsomUitbetaling']['u-1'];
		self::assertSame('on-hold-objection', $u['status']);

		// bezwaar-ingediend event recorded.
		$events = array_values($this->objects->store['termijnGebeurtenis'] ?? []);
		self::assertNotEmpty($events);
		self::assertSame('objection-submitted', $events[0]['type']);
	}

	/**
	 * @return void
	 */
	public function testResolveBezwaarAdjustsAmountAndResumes(): void {
		$this->service->registerBezwaar('b-1', 'AWB 7:1', 'foo');
		$b = $this->service->resolveBezwaar('b-1', 30000, 'AWB 7:11');

		self::assertSame(30000, $b['definitiveAmount']);
		self::assertSame('completed', $b['status']);

		$u = $this->objects->store['dwangsomUitbetaling']['u-1'];
		self::assertSame(30000, $u['amount']);
		self::assertSame('voorbereid', $u['status']);
	}

	/**
	 * @return void
	 */
	public function testResolveBezwaarRejectsNegativeAmount(): void {
		$this->expectException(RuntimeException::class);
		$this->service->resolveBezwaar('b-1', -1, 'AWB 7:11');
	}

	/**
	 * @return void
	 */
	public function testRegisterBezwaarOnUnknownBerekeningFails(): void {
		$this->expectException(RuntimeException::class);
		$this->service->registerBezwaar('does-not-exist', 'AWB 7:1', 'foo');
	}
}
