<?php

/**
 * Dossiq Seed LHS Matrix Repair Step
 *
 * Idempotent repair step that seeds the default national Landelijke
 * Handhavingsstrategie 2024 matrix (3 ernst x 4 gedrag x 4 actorType = 48
 * cells) as `lhsMatrix` version 1, active=true. Re-runs are no-ops once an
 * active matrix exists.
 *
 * Cell payload is loaded from lib/Settings/seed/lhs-matrix-2024.json.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step that seeds the default LHS matrix into OpenRegister.
 *
 * @spec openspec/changes/enforcement-lhs/tasks.md#T02
 */
class SeedLhsMatrix implements IRepairStep {
	use RunsUnderSystemIdentity;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings bridge
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private SettingsService $settingsService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/enforcement-lhs/tasks.md#T02
	 */
	public function getName(): string {
		return 'Seed default LHS matrix (Landelijke Handhavingsstrategie 2024) for Dossiq';
	}//end getName()

	/**
	 * Run the repair step.
	 *
	 * @param IOutput $output Output interface for progress reporting
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enforcement-lhs/spec.md
	 */
	public function run(IOutput $output): void {
		$output->info('Seeding default LHS matrix...');

		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->warning('OpenRegister is not available. Skipping LHS matrix seed.');
			return;
		}

		try {
			// Under a system identity: an upgrade has no session, and
			// OpenRegister refuses `create` for 'Anonymous'. Without it this
			// seed writes nothing and says so only in a warning, which does not
			// fail an upgrade.
			$this->withSystemIdentity(
				objectService: $this->settingsService->getObjectService(),
				work: function () use ($output): void {
					$this->seedMatrix(output: $output);
				}
			);
		} catch (Throwable $e) {
			$output->warning('Could not seed LHS matrix: ' . $e->getMessage());
			$this->logger->error(
				'Dossiq LHS matrix seed failed',
				['exception' => $e->getMessage()]
			);
		}//end try
	}//end run()

	/**
	 * Seed the single active LHS matrix, unless one already exists or the seed file is unusable.
	 *
	 * @param IOutput $output Output interface for progress reporting
	 *
	 * @return void
	 */
	private function seedMatrix(IOutput $output): void {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->warning('ObjectService unavailable. Skipping LHS matrix seed.');
			return;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('lhs_matrix_schema');
		if ($register === '' || $schema === '') {
			$output->warning(
				'LHS register/schema not configured. Skipping LHS matrix seed.'
			);
			return;
		}

		$existing = $objectService->findAll(
			[
				'filters' => ['register' => $register, 'schema' => $schema, 'active' => true],
				'limit' => 1,
			],
		);
		if ($this->hasRow(results: $existing) === true) {
			$output->info('Active LHS matrix already exists. Skipping seed.');
			return;
		}

		$seedPath = __DIR__ . '/../Settings/seed/lhs-matrix-2024.json';
		if (file_exists($seedPath) === false) {
			$output->warning('LHS seed file not found: ' . $seedPath);
			return;
		}

		$raw = (string)file_get_contents($seedPath);
		$payload = json_decode($raw, true);
		if (is_array($payload) === false) {
			$output->warning('LHS seed file is not valid JSON.');
			return;
		}

		$payload['createdAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

		$objectService->saveObject(
			register: $register,
			schema: $schema,
			object: $payload,
		);

		$cellCount = 0;
		if (is_array($payload['cells'] ?? null) === true) {
			$cellCount = count($payload['cells']);
		}

		$output->info('LHS matrix seeded: 1 matrix with ' . $cellCount . ' cells.');
	}//end seedMatrix()

	/**
	 * Whether the ObjectService result contains at least one row.
	 *
	 * @param mixed $results Raw getObjects() return
	 *
	 * @return bool
	 */
	private function hasRow(mixed $results): bool {
		if (is_array($results) === false) {
			return false;
		}

		if (isset($results[0]) === true) {
			return true;
		}

		if (isset($results['results']) === true
			&& is_array($results['results']) === true
			&& count($results['results']) > 0
		) {
			return true;
		}

		return false;
	}//end hasRow()
}//end class
