<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\SeedDeadlineMonitoringData;
use OCA\Dossiq\Service\DeadlineMonitoringSeedDataService;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The termijnbewaking seed runs under a system identity and fails loudly.
 *
 * A repair step executes during `occ upgrade` with no session, so OpenRegister
 * refuses every write as Anonymous. Before this step elevated, all three rows
 * failed and the summary still read "0 definities (0 overgeslagen)": no
 * TermijnDefinitie existed on any fresh install, so no termijn timer ever
 * armed. These tests pin both halves of the fix.
 *
 * @covers \OCA\Dossiq\Repair\SeedDeadlineMonitoringData
 * @uses \OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity
 * @uses \OCA\Dossiq\Service\DeadlineMonitoringSeedDataService
 */
class SeedDeadlineMonitoringDataTest extends TestCase {

	/**
	 * Build the repair step around a seed result and a recording ObjectService.
	 *
	 * @param array<string, mixed> $seedResult What the seed service reports.
	 * @param object|null $objectService What SettingsService hands out.
	 *
	 * @return SeedDeadlineMonitoringData The step under test.
	 */
	private function step(array $seedResult, ?object $objectService): SeedDeadlineMonitoringData {
		$seedService = $this->createMock(DeadlineMonitoringSeedDataService::class);
		$seedService->method('seed')->willReturn($seedResult);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getObjectService')->willReturn($objectService);

		return new SeedDeadlineMonitoringData(
			seedService: $seedService,
			settingsService: $settings,
			logger: new NullLogger(),
		);
	}

	/**
	 * The seed is elevated through runAsSystem when OpenRegister offers it.
	 *
	 * @return void
	 */
	public function testSeedRunsUnderTheSystemIdentity(): void {
		$objectService = new RecordingSystemIdentityObjectService();

		$output = $this->createMock(IOutput::class);
		$output->expects($this->atLeastOnce())->method('info');
		$output->expects($this->never())->method('warning');

		$this->step(
			seedResult: ['success' => true, 'definities' => 3, 'skipped' => 0, 'failed' => 0],
			objectService: $objectService,
		)->run($output);

		$this->assertSame(1, $objectService->elevations, 'The seed must run inside runAsSystem()');
	}

	/**
	 * Refused rows turn the summary into a warning, never success-shaped info.
	 *
	 * @return void
	 */
	public function testRefusedRowsProduceAWarningNotSuccessShapedOutput(): void {
		$warnings = [];
		$output = $this->createMock(IOutput::class);
		$output->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		$this->step(
			seedResult: ['success' => true, 'definities' => 0, 'skipped' => 0, 'failed' => 3],
			objectService: new RecordingSystemIdentityObjectService(),
		)->run($output);

		$this->assertNotEmpty($warnings, 'A seed that seeded nothing must warn');
		$this->assertStringContainsString('3 rijen geweigerd', $warnings[0]);
	}
}//end class

/**
 * ObjectService stand-in that counts runAsSystem() elevations.
 */
class RecordingSystemIdentityObjectService {

	/**
	 * How many times runAsSystem() was entered.
	 *
	 * @var integer
	 */
	public int $elevations = 0;

	/**
	 * Mirror of ObjectService::runAsSystem().
	 *
	 * @param callable $work The elevated work.
	 *
	 * @return mixed The work's return value.
	 */
	public function runAsSystem(callable $work): mixed {
		$this->elevations++;

		return $work();
	}
}
