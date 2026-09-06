<?php

/**
 * Dossiq Arm Termijn Engine Timers Repair Step.
 *
 * Migrates in-flight TermijnInstances onto OpenRegister FlowTimers
 * (REQ-TOT-006): every `lopend`/`verlengd`/`paused` instance without an
 * `engineTimerId` gets a timer armed at its CURRENT deadline (pauses and
 * extensions included, because the SLA spans startDate to endDateCurrent),
 * and `paused` rows are suspended immediately after arming. Idempotent:
 * rows that already carry an `engineTimerId` are skipped, as are terminal
 * rows. Catch-up rung fires the engine then produces are absorbed by the
 * `notificatiesVerstuurd` dedup in the fired-listener, so migration causes
 * no duplicate escalations.
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
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Arms engine timers for existing in-flight TermijnInstances.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
class ArmTermijnEngineTimers implements IRepairStep {
	use RunsUnderSystemIdentity;
	use SearchesObjects;

	/**
	 * Statuses that still carry a running or suspended term.
	 *
	 * @var array<int, string>
	 */
	private const OPEN_STATUSES = ['lopend', 'verlengd', 'paused'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings + ObjectService access.
	 * @param TermijnService $termService Instance updates.
	 * @param TermijnTimerService $timerService Engine timer mapping.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly TermijnService $termService,
		private readonly TermijnTimerService $timerService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the repair-step display name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function getName(): string {
		return 'Arm OpenRegister engine timers for in-flight Dossiq termijnen';
	}//end getName()

	/**
	 * Run the migration.
	 *
	 * @param IOutput $output Output sink.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function run(IOutput $output): void {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->warning('OpenRegister is not available. Skipping termijn timer migration.');
			return;
		}

		// NO SESSION, SO OPENREGISTER SEES 'Anonymous'. The listing below is a
		// READ, and a read is fail-closed too on any schema without an explicit
		// `public` grant — so an unelevated run reported "schemas unconfigured"
		// for what was actually an RBAC refusal, and blamed configuration for
		// it. The writes inside migrateInstance() are refused outright. Both
		// halves therefore run under the same system identity every other
		// writing step uses.
		$objectService = $this->settingsService->getObjectService();
		$rows = null;
		$counts = ['armed' => 0, 'suspended' => 0, 'skipped' => 0, 'failed' => 0];

		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use (&$rows, &$counts): void {
				$rows = $this->openInstances();
				if ($rows === null) {
					return;
				}

				foreach ($rows as $row) {
					$this->migrateInstance(row: $row, counts: $counts);
				}
			}
		);

		if ($rows === null) {
			$output->warning('Termijn instances not readable (schemas unconfigured, or the read was refused). Skipping timer migration.');
			return;
		}

		$this->logger->info('Dossiq termijn timer migration complete', $counts);
		$output->info(
			sprintf(
				'Termijn timer migration: %d armed (%d suspended), %d skipped, %d failed.',
				$counts['armed'],
				$counts['suspended'],
				$counts['skipped'],
				$counts['failed']
			)
		);
	}//end run()

	/**
	 * Migrate one instance onto an engine timer.
	 *
	 * A failure is counted and logged loudly, never swallowed into a
	 * green pass: the count line names how many rows still have no timer.
	 *
	 * @param array<string, mixed> $row The TermijnInstance row.
	 * @param array<string, int> $counts Running counts (by reference).
	 *
	 * @return void
	 */
	private function migrateInstance(array $row, array &$counts): void {
		$rowId = (string)($row['id'] ?? '');
		$status = (string)($row['status'] ?? '');
		if ($rowId === ''
			|| in_array($status, self::OPEN_STATUSES, true) === false
			|| (string)($row['engineTimerId'] ?? '') !== ''
		) {
			$counts['skipped']++;
			return;
		}

		$definitie = $this->definitieFor(row: $row);
		$timerId = $this->timerService->armBeslistermijn(instance: $row, definitie: $definitie);
		if ($timerId === null) {
			$counts['failed']++;
			$this->logger->warning('Dossiq termijn timer migration: arming failed', ['instance' => $rowId]);
			return;
		}

		$this->termService->updateTermijnInstance($rowId, ['engineTimerId' => $timerId]);
		$counts['armed']++;

		if ($status === 'paused') {
			$row['engineTimerId'] = $timerId;
			$suspended = $this->timerService->suspendBeslistermijn(
				instance: $row,
				reason: 'Opschorting overgenomen bij migratie naar engine timers',
				until: $this->dateOrNull(value: (string)($row['pauseDeadline'] ?? ''))
			);
			if ($suspended === true) {
				$counts['suspended']++;
			}
		}
	}//end migrateInstance()

	/**
	 * All TermijnInstance rows, or null when the store is unreachable.
	 *
	 * @return array<int, array<string, mixed>>|null The rows.
	 */
	private function openInstances(): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('termijn_instance_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		try {
			return $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: []);
		} catch (\Throwable $e) {
			$this->logger->error('Dossiq termijn timer migration: listing failed', ['error' => $e->getMessage()]);
			return null;
		}
	}//end openInstances()

	/**
	 * The definition backing an instance, for the extension ceiling; the
	 * SLA itself comes from the instance's own current deadline.
	 *
	 * @param array<string, mixed> $row The TermijnInstance row.
	 *
	 * @return array<string, mixed> The TermijnDefinitie row, or empty.
	 */
	private function definitieFor(array $row): array {
		$defId = (string)($row['deadlineDefinition'] ?? '');
		if ($defId === '') {
			return [];
		}

		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('termijn_definitie_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		try {
			$def = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $defId
			);
			if ($def !== null) {
				return $def;
			}
		} catch (\Throwable $e) {
			$this->logger->debug('Dossiq termijn timer migration: definition lookup failed', ['id' => $defId]);
		}

		return [];
	}//end definitieFor()

	/**
	 * Parse a stored date string, or null.
	 *
	 * @param string $value The stored value.
	 *
	 * @return DateTimeImmutable|null The parsed date.
	 */
	private function dateOrNull(string $value): ?DateTimeImmutable {
		if (trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Throwable $e) {
			return null;
		}
	}//end dateOrNull()
}//end class
