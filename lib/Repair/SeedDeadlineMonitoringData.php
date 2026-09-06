<?php

/**
 * Dossiq Seed Termijnbewaking Data Repair Step.
 *
 * Seeds the three demo `TermijnDefinitie` rows
 * (Omgevingsvergunning-regulier, Wmo-aanvraag, Woo-verzoek) into
 * OpenRegister via {@see DeadlineMonitoringSeedDataService}.
 * Idempotent: existing definitions are skipped.
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-01-schemas-and-seed/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Service\DeadlineMonitoringSeedDataService;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step that seeds termijnbewaking demo data into OpenRegister.
 *
 * Runs under OpenRegister's system identity: a repair step executes during
 * `occ upgrade` with no session, and OpenRegister refuses Anonymous writes
 * per row. Without the identity every row failed, the failures were counted
 * as nothing, and the step reported "0 definities (0 overgeslagen)" as
 * success — so no TermijnDefinitie ever existed on a fresh install and no
 * termijn timer could arm.
 *
 * @spec openspec/specs/termijnbewaking-schemas/spec.md
 */
class SeedDeadlineMonitoringData implements IRepairStep {
	use RunsUnderSystemIdentity;
	/**
	 * Constructor.
	 *
	 * @param DeadlineMonitoringSeedDataService $seedService Seed service.
	 * @param SettingsService $settingsService Settings service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly DeadlineMonitoringSeedDataService $seedService,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the repair-step display name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/termijnbewaking-schemas/spec.md
	 */
	public function getName(): string {
		return 'Seed demo TermijnDefinities for Dossiq termijnbewaking';
	}//end getName()

	/**
	 * Run the repair step.
	 *
	 * @param IOutput $output Output sink.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-01-schemas-and-seed/tasks.md
	 */
	public function run(IOutput $output): void {
		$output->info('Seeding termijnbewaking definitions...');

		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->warning('OpenRegister is not available. Skipping termijnbewaking seed.');
			return;
		}

		try {
			$result = [];
			$this->withSystemIdentity(
				objectService: $this->settingsService->getObjectService(),
				work: function () use (&$result): void {
					$result = $this->seedService->seed();
				}
			);

			$failed = (int)($result['failed'] ?? 0);
			if (($result['success'] ?? false) === true && $failed === 0) {
				$output->info(
					'Termijnbewaking seed complete: '
					. ((int)($result['definities'] ?? 0)) . ' definities ('
					. ((int)($result['skipped'] ?? 0)) . ' overgeslagen)'
				);
				return;
			}

			// A seed that seeded nothing must not report success-shaped output:
			// every failed row is named in the count, so an operator sees a
			// broken fresh install instead of "0 definities (0 overgeslagen)".
			$output->warning(
				'Termijnbewaking seed issue: '
				. ((int)($result['definities'] ?? 0)) . ' definities, '
				. $failed . ' rijen geweigerd ('
				. ((string)($result['message'] ?? 'per-row failures, see the log')) . ')'
			);
		} catch (\Throwable $e) {
			$output->warning('Could not seed termijnbewaking data: ' . $e->getMessage());
			$this->logger->error('Dossiq termijnbewaking seed failed', ['exception' => $e->getMessage()]);
		}//end try
	}//end run()
}//end class
