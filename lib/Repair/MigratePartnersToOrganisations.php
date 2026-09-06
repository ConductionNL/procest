<?php

/**
 * Dossiq Migrate Partners To Organisations Repair Step
 *
 * Moves every `partnerOrganization` row onto an OpenRegister Organisation on
 * upgrade, so the rows have travelled before the surface that used to write
 * them is gone.
 *
 * WHY A REPAIR STEP, where the workflow and LHS projections are occ commands:
 * those two create FLOWS, and `FlowService` refuses to create one without a
 * signed-in owner and an active organisation, which an upgrade running as
 * nobody cannot supply. This migration writes through the Organisation MAPPER
 * directly and needs no acting user, so it can run unattended — and it has to.
 * The Partners settings page is retired in the same change; leaving the move to
 * a command an administrator might never run would strand the rows behind a
 * surface that no longer exists.
 *
 * Idempotent by the partner's own uuid, so re-running an upgrade is a no-op
 * rather than a second set of organisations.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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
 *
 * @spec openspec/changes/partners-are-organisations/specs/partner-organisations/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Service\PartnerMigrationService;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step projecting dossiq partners onto OpenRegister Organisations.
 *
 * @spec openspec/changes/partners-are-organisations/specs/partner-organisations/spec.md
 */
class MigratePartnersToOrganisations implements IRepairStep {
	use RunsUnderSystemIdentity;

	/**
	 * Constructor.
	 *
	 * @param PartnerMigrationService $migration The partner migration.
	 * @param SettingsService $settingsService Resolves OpenRegister's ObjectService.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private PartnerMigrationService $migration,
		private SettingsService $settingsService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/partners-are-organisations/specs/partner-organisations/spec.md
	 */
	public function getName(): string {
		return 'Move Dossiq partner organisations onto OpenRegister Organisations';
	}//end getName()

	/**
	 * Run the migration and report what moved.
	 *
	 * NEVER throws. An upgrade that dies because OpenRegister happened to be
	 * mid-install leaves the instance in a worse state than an unmigrated
	 * partner does, and the service already answers an empty summary when the
	 * organisation services are unavailable.
	 *
	 * Runs under a SYSTEM IDENTITY. An upgrade has no signed-in user, and
	 * OpenRegister is fail-closed for anonymous reads on schemas without a
	 * public grant — so without the elevation the migration would find zero
	 * partner rows and report a cheerful "nothing to migrate" while the rows
	 * sat there. The inputs are this app's own stored data, which is what the
	 * elevation is documented as being for.
	 *
	 * @param IOutput $output Output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/partners-are-organisations/specs/partner-organisations/spec.md
	 */
	public function run(IOutput $output): void {
		$summary = ['migrated' => 0, 'skipped' => 0, 'failed' => 0, 'total' => 0];

		try {
			$this->withSystemIdentity(
				objectService: $this->settingsService->getObjectService(),
				work: function () use (&$summary): void {
					$summary = $this->migration->migrate();
				}
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: partner migration repair step failed',
				['exception' => $e->getMessage()]
			);
			$output->warning('Dossiq: partner migration could not run: ' . $e->getMessage());
			return;
		}

		if ($summary['total'] === 0) {
			$output->info('Dossiq: no partner organisations to migrate.');
			return;
		}

		$output->info(
			sprintf(
				'Dossiq: partners migrated %d, already present %d, failed %d, of %d.',
				$summary['migrated'],
				$summary['skipped'],
				$summary['failed'],
				$summary['total']
			)
		);
	}//end run()
}//end class
