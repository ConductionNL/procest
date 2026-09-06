<?php

/**
 * Dossiq Seed KCC-Werkplek Data Repair Step.
 *
 * Seeds the default KCC quick-actions and two example belplannen into
 * OpenRegister via {@see KccWerkplekSeedDataService}. Idempotent: existing
 * objects (matched by id) are skipped.
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
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/specs.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Service\KccWerkplekSeedDataService;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step that seeds the KCC-werkplek defaults into OpenRegister.
 *
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/specs.md
 */
class SeedKccWerkplekData implements IRepairStep {
	/**
	 * Constructor.
	 *
	 * @param KccWerkplekSeedDataService $seedService Seed service.
	 * @param SettingsService $settingsService Settings service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly KccWerkplekSeedDataService $seedService,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the repair-step display name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/specs.md
	 */
	public function getName(): string {
		return 'Seed default KCC-werkplek quick-actions and example belplannen';
	}//end getName()

	/**
	 * Run the repair step.
	 *
	 * @param IOutput $output Output sink.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/specs.md
	 */
	public function run(IOutput $output): void {
		$output->info('Seeding KCC-werkplek defaults...');

		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->warning('OpenRegister is not available. Skipping KCC-werkplek seed.');
			return;
		}

		try {
			$result = $this->seedService->seed();
			if (($result['success'] ?? false) === true) {
				$output->info(
					'KCC-werkplek seed complete: '
					. ((int)($result['quickActions'] ?? 0)) . ' quick-actions, '
					. ((int)($result['belplannen'] ?? 0)) . ' belplannen ('
					. ((int)($result['skipped'] ?? 0)) . ' overgeslagen)'
				);
				return;
			}

			// REFUSED ROWS ARE NAMED IN THE COUNT. A seed that seeded nothing
			// must not produce success-shaped output, so the failure line
			// carries the same counters the success line does — an operator
			// reading "0 quick-actions, 0 belplannen" then knows whether the
			// rows were already present or refused.
			$output->warning(
				'KCC-werkplek seed issue: ' . ((string)($result['message'] ?? 'unknown error'))
				. ' (' . ((int)($result['quickActions'] ?? 0)) . ' quick-actions, '
				. ((int)($result['belplannen'] ?? 0)) . ' belplannen, '
				. ((int)($result['skipped'] ?? 0)) . ' overgeslagen, '
				. ((int)($result['failed'] ?? 0)) . ' geweigerd)'
			);
		} catch (Throwable $e) {
			$output->warning('Could not seed KCC-werkplek data: ' . $e->getMessage());
			$this->logger->error('Dossiq KCC-werkplek seed failed', ['exception' => $e->getMessage()]);
		}
	}//end run()
}//end class
