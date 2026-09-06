<?php

/**
 * Unit tests for MandaatEscalatieService.
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

use OCA\Dossiq\Service\MandaatEscalatieService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\MandaatEscalatieService
 */
class MandaatEscalatieServiceTest extends TestCase {

	private FakeTermijnStore $objects;

	private MandaatEscalatieService $service;

	protected function setUp(): void {
		$this->objects = new FakeTermijnStore();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'mandaat_schema' => 'mandate',
					'medewerker_rol_toewijzing_schema' => 'medewerkerRolToewijzing',
					'mandaat_escalatie_schema' => 'mandaatEscalatie',
					default => '',
				};
			},
		);
		$this->service = new MandaatEscalatieService($settings, $this->createMock(LoggerInterface::class));

		// Seed mandates + assignments.
		$this->objects->seed('mandate',
			[
				'id' => 'm-low',
				'mandateeRole' => 'rol-consulent',
				'terms' => ['plafondCents' => 500000, 'decisionTypes' => ['wmo-toekenning']],
				'status' => 'active',
			]
		);
		$this->objects->seed('mandate',
			[
				'id' => 'm-high',
				'mandateeRole' => 'rol-manager',
				'terms' => ['plafondCents' => 2500000, 'decisionTypes' => ['wmo-toekenning']],
				'status' => 'active',
			]
		);
		$this->objects->seed('medewerkerRolToewijzing',
			[
				'userId' => 'carol',
				'roleId' => 'rol-manager',
				'allocationType' => 'primair',
				'validFrom' => '2026-01-01',
			]
		);
	}//end setUp()

	/**
	 * @return void
	 */
	public function testCreateEscalatieResolvesNextHigherHolder(): void {
		$row = $this->service->createEscalatie('Z/2026/E1', 'wmo-toekenning', 'alice', 'ceiling_exceeded');
		self::assertSame('open', $row['status']);
		self::assertSame('carol', $row['targetUserId']);
		self::assertSame('m-high', $row['targetMandateId']);
	}//end testCreateEscalatieResolvesNextHigherHolder()

	/**
	 * @return void
	 */
	public function testApproveByCorrectMandateHolder(): void {
		$created = $this->service->createEscalatie('Z/2026/E2', 'wmo-toekenning', 'alice', 'non_competent');
		$approved = $this->service->approveEscalatie((string)$created['id'], 'carol');
		self::assertSame('approved', $approved['status']);
	}//end testApproveByCorrectMandateHolder()

	/**
	 * @return void
	 */
	public function testApproveByWrongUserRejects(): void {
		$created = $this->service->createEscalatie('Z/2026/E3', 'wmo-toekenning', 'alice', 'non_competent');
		$this->expectException(RuntimeException::class);
		$this->service->approveEscalatie((string)$created['id'], 'bob');
	}//end testApproveByWrongUserRejects()

	/**
	 * @return void
	 */
	public function testRejectEscalatieRecordsReason(): void {
		$created = $this->service->createEscalatie('Z/2026/E4', 'wmo-toekenning', 'alice', 'non_competent');
		$rejected = $this->service->rejectEscalatie((string)$created['id'], 'Onvoldoende onderbouwing');
		self::assertSame('rejected', $rejected['status']);
		self::assertSame('Onvoldoende onderbouwing', $rejected['rejectedReason']);
	}//end testRejectEscalatieRecordsReason()

	/**
	 * @return void
	 */
	public function testAutoRerouteOnPersonnelChange(): void {
		$this->service->createEscalatie('Z/2026/E5', 'wmo-toekenning', 'alice', 'non_competent');
		$this->service->createEscalatie('Z/2026/E6', 'wmo-toekenning', 'alice', 'ceiling_exceeded');

		$count = $this->service->autoRerouteOnPersonnelChange('carol', 'dave');
		self::assertSame(2, $count);

		foreach ($this->objects->store['mandaatEscalatie'] as $row) {
			self::assertSame('dave', $row['targetUserId']);
		}
	}//end testAutoRerouteOnPersonnelChange()
}//end class
