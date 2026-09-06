<?php

/**
 * MigratePartnersToOrganisations Tests
 *
 * The step exists so the partner rows travel on UPGRADE rather than waiting for
 * an administrator to run an occ command — which is what makes retiring the
 * Partners page safe in the same change.
 *
 * So what these tests pin is the step's OBLIGATIONS to an upgrade, not the
 * migration itself (PartnerMigrationServiceTest owns that): it must run the
 * migration, it must never throw, and it must report what happened. An upgrade
 * that dies because OpenRegister happened to be mid-install leaves the instance
 * in a worse state than an unmigrated partner does.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/partners-are-organisations/specs/partner-organisations/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\MigratePartnersToOrganisations;
use OCA\Dossiq\Service\PartnerMigrationService;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The upgrade-time obligations of the partner migration.
 *
 * @spec openspec/changes/partners-are-organisations/specs/partner-organisations/spec.md
 */
class MigratePartnersToOrganisationsTest extends TestCase {
	/**
	 * Build the step over a migration that returns a given summary.
	 *
	 * @param array<string, mixed>|null $summary The summary, or null to throw.
	 *
	 * @return MigratePartnersToOrganisations The step.
	 */
	private function step(?array $summary): MigratePartnersToOrganisations {
		$migration = $this->getMockBuilder(PartnerMigrationService::class)
			->disableOriginalConstructor()
			->onlyMethods(['migrate'])
			->getMock();

		if ($summary === null) {
			$migration->method('migrate')->willThrowException(new RuntimeException('register unavailable'));
		} else {
			$migration->method('migrate')->willReturn($summary);
		}

		$settings = $this->createMock(SettingsService::class);
		// No runAsSystem on this fake, so withSystemIdentity falls through and
		// runs the work directly — the documented degradation, exercised here
		// rather than assumed.
		$settings->method('getObjectService')->willReturn(new \stdClass());

		return new MigratePartnersToOrganisations(
			$migration,
			$settings,
			$this->createMock(LoggerInterface::class)
		);
	}//end step()

	/**
	 * A migration that moved rows is reported with its counts.
	 *
	 * @return void
	 */
	public function testAMigrationThatMovedRowsIsReported(): void {
		$step = $this->step(['migrated' => 3, 'skipped' => 1, 'failed' => 0, 'total' => 4]);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())
			->method('info')
			->with($this->stringContains('migrated 3'));

		$step->run($output);
	}//end testAMigrationThatMovedRowsIsReported()

	/**
	 * An instance with no partners says so, rather than reporting counts of
	 * zero that read like a failure.
	 *
	 * @return void
	 */
	public function testAnInstanceWithNoPartnersSaysSo(): void {
		$step = $this->step(['migrated' => 0, 'skipped' => 0, 'failed' => 0, 'total' => 0]);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())
			->method('info')
			->with($this->stringContains('no partner organisations'));

		$step->run($output);
	}//end testAnInstanceWithNoPartnersSaysSo()

	/**
	 * 🔴 The step NEVER throws.
	 *
	 * An exception here aborts the upgrade. A partner that has not moved is a
	 * recoverable state; a half-upgraded instance is not.
	 *
	 * @return void
	 */
	public function testAFailingMigrationWarnsRatherThanAbortingTheUpgrade(): void {
		$step = $this->step(null);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())
			->method('warning')
			->with($this->stringContains('could not run'));
		$output->expects($this->never())->method('info');

		$step->run($output);
	}//end testAFailingMigrationWarnsRatherThanAbortingTheUpgrade()

	/**
	 * The step names itself for the upgrade output.
	 *
	 * @return void
	 */
	public function testTheStepNamesItself(): void {
		$name = $this->step(['migrated' => 0, 'skipped' => 0, 'failed' => 0, 'total' => 0])->getName();

		$this->assertStringContainsString('partner', strtolower($name));
	}//end testTheStepNamesItself()
}//end class
