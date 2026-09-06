<?php

/**
 * Unit tests for MandaatCheckService.
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

use OCA\Dossiq\Service\ConflictOfInterestService;
use OCA\Dossiq\Service\MandaatCheckService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\MandaatCheckService
 *
 * @uses \OCA\Dossiq\Service\ConflictOfInterestService
 */
class MandaatCheckServiceTest extends TestCase {
	private FakeTermijnStore $objects;
	private MandaatCheckService $service;

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
					default => '',
				};
			},
		);
		// A conflict-of-interest service must be bound: MandaatCheckService now
		// treats an unbound one as indeterminate and DENIES, rather than
		// silently skipping the check (authz-bypass-fixes design D5). These
		// fixtures carry no natural-person applicant, so the bound service
		// correctly reports "no conflict" and the mandaat logic under test runs.
		$this->service = new MandaatCheckService(
			$settings,
			$this->createMock(LoggerInterface::class),
			new ConflictOfInterestService($this->createMock(LoggerInterface::class)),
		);

		// Seed two roles: consulent (low) + afdelingsmanager (high).
		// Seed three mandaten:
		//   - m-consulent: rol=consulent, decisionType=wmo-toekenning, plafond €5000
		//   - m-manager:   rol=afdelingsmanager, decisionType=wmo-toekenning, plafond €25000, subdelegatie=true
		//   - m-bestuurder: rol=bestuurder, decisionType=omgevingsvergunning, plafond=infinity
		$this->objects->seed('mandate', [
			'id' => 'm-consulent',
			'mandaatNummer' => 'WMO-1',
			'mandateeRole' => 'rol-consulent',
			'terms' => [
				'plafondCents' => 500000,
				'decisionTypes' => ['wmo-toekenning'],
			],
			'validFrom' => '2026-01-01',
			'status' => 'active',
		]);
		$this->objects->seed('mandate', [
			'id' => 'm-manager',
			'mandaatNummer' => 'WMO-2',
			'mandateeRole' => 'rol-afdelingsmanager',
			'terms' => [
				'plafondCents' => 2500000,
				'subdelegatie' => true,
				'decisionTypes' => ['wmo-toekenning'],
			],
			'validFrom' => '2026-01-01',
			'status' => 'active',
		]);

		// Seed users: alice = consulent (primair), bob = consulent (waarnemer), eve = nobody.
		$this->objects->seed('medewerkerRolToewijzing', [
			'userId' => 'alice',
			'roleId' => 'rol-consulent',
			'allocationType' => 'primair',
			'validFrom' => '2026-01-01',
		]);
		$this->objects->seed('medewerkerRolToewijzing', [
			'userId' => 'bob',
			'roleId' => 'rol-consulent',
			'allocationType' => 'observer',
			'validFrom' => '2026-01-01',
			'observerFor' => 'alice',
		]);
		$this->objects->seed('medewerkerRolToewijzing', [
			'userId' => 'carol',
			'roleId' => 'rol-afdelingsmanager',
			'allocationType' => 'primair',
			'validFrom' => '2026-01-01',
		]);
	}

	/**
	 * @return void
	 */
	public function testAuthorizedWhenRoleHoldsAndUnderPlafond(): void {
		$r = $this->service->isAuthorized('alice', 'wmo-toekenning', 'Z/2026/1', ['bedragCents' => 100000]);
		self::assertTrue($r['authorized']);
		self::assertSame('m-consulent', $r['mandaatId']);
	}

	/**
	 * @return void
	 */
	public function testNotAuthorizedWhenRoleDoesNotHold(): void {
		$r = $this->service->isAuthorized('eve', 'wmo-toekenning', 'Z/2026/2');
		self::assertFalse($r['authorized']);
		self::assertSame(MandaatCheckService::REDEN_NIET_BEVOEGD, $r['reason']);
	}

	/**
	 * @return void
	 */
	public function testPlafondOverschreden(): void {
		$r = $this->service->isAuthorized('alice', 'wmo-toekenning', 'Z/2026/3', ['bedragCents' => 1000000]);
		self::assertFalse($r['authorized']);
		self::assertSame(MandaatCheckService::REDEN_PLAFOND_OVERSCHREDEN, $r['reason']);
	}

	/**
	 * @return void
	 */
	public function testSubdelegatieNietToegestaan(): void {
		$r = $this->service->isAuthorized('alice', 'wmo-toekenning', 'Z/2026/4', [
			'bedragCents' => 100000,
			'subdelegatieRequested' => true,
		]);
		self::assertFalse($r['authorized']);
		self::assertSame(MandaatCheckService::REDEN_SUBDELEGATIE_NIET_TOEGESTAAN, $r['reason']);
	}

	/**
	 * @return void
	 */
	public function testManagerWithSubdelegatiePermitted(): void {
		$r = $this->service->isAuthorized('carol', 'wmo-toekenning', 'Z/2026/5', [
			'bedragCents' => 1500000,
			'subdelegatieRequested' => true,
		]);
		self::assertTrue($r['authorized']);
		self::assertSame('m-manager', $r['mandaatId']);
	}

	/**
	 * @return void
	 */
	public function testWaarnemerHasSameAuthority(): void {
		// Bob is a waarnemer for alice on rol-consulent; should still authorize.
		$r = $this->service->isAuthorized('bob', 'wmo-toekenning', 'Z/2026/6', ['bedragCents' => 100000]);
		self::assertTrue($r['authorized']);
	}

	/**
	 * An unbound conflict-of-interest service is INDETERMINATE, and
	 * indeterminate denies — it must not skip the belangenconflict check.
	 *
	 * The `authz-bypass-fixes` spec has required this since the original
	 * change ("A missing conflict service denies rather than skips"), and the
	 * behaviour is implemented at `MandaatCheckService::isAuthorized()`, but no
	 * test exercised it: every fixture in this class binds a real service. The
	 * scenario was the one requirement in that spec with no coverage to point
	 * at, so it is covered here rather than waived.
	 *
	 * The arm is deliberately one that WOULD be authorized with the service
	 * bound — `alice` is under her plafond and the fixtures carry no
	 * natural-person applicant — so this asserts the null service is what
	 * denies, not the mandaat logic.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function testMissingConflictServiceDeniesRatherThanSkips(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'mandaat_schema' => 'mandate',
					'medewerker_rol_toewijzing_schema' => 'medewerkerRolToewijzing',
					default => '',
				};
			},
		);

		// Positive control: the SAME call authorizes when a service is bound.
		$bound = new MandaatCheckService(
			$settings,
			$this->createMock(LoggerInterface::class),
			new ConflictOfInterestService($this->createMock(LoggerInterface::class)),
		);
		$allowed = $bound->isAuthorized('alice', 'wmo-toekenning', 'Z/2026/7', ['bedragCents' => 100000]);
		self::assertTrue($allowed['authorized']);

		// Same inputs, conflict service absent.
		$unbound = new MandaatCheckService(
			$settings,
			$this->createMock(LoggerInterface::class),
			null,
		);
		$denied = $unbound->isAuthorized('alice', 'wmo-toekenning', 'Z/2026/7', ['bedragCents' => 100000]);

		self::assertFalse($denied['authorized']);
		self::assertSame(MandaatCheckService::REDEN_BELANGENCONFLICT, $denied['reason']);
		self::assertSame(
			ConflictOfInterestService::REASON_IDENTITY_INDETERMINATE,
			$denied['conflictReason'],
		);
	}
}
