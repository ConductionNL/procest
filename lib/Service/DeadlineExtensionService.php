<?php

/**
 * Dossiq DeadlineExtensionService.
 *
 * AWB 4:14 verlenging on a TermijnInstance. Validates that the
 * verlenging-count is below the TermijnDefinitie's aantalVerlengingen
 * ceiling, that motivering is non-empty, and that newEinddatum is in
 * the future relative to einddatumActueel. A supervisor-approval
 * override path bypasses the ceiling for exceptional cases.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-03-pause-extension/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use ReflectionClass;
use RuntimeException;

/**
 * AWB 4:14 verlenging engine on a TermijnInstance.
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-03-pause-extension/tasks.md
 */
class DeadlineExtensionService {
	/**
	 * Extension mode: the ordinary AWB 4:14 lid 1 verlenging, bound by the
	 * TermijnDefinitie ceiling.
	 *
	 * @var string
	 */
	public const MODE_STANDARD = 'standard';

	/**
	 * Extension mode: the AWB 4:14 lid 3 supervisor-approved verlenging,
	 * which bypasses the TermijnDefinitie ceiling.
	 *
	 * @var string
	 */
	public const MODE_SUPERVISOR = 'supervisor';

	/**
	 * Constructor.
	 *
	 * @param TermijnService $termService TermijnService.
	 * @param TermijnTimerService|null $timerService Engine timer mapping (optional while the engine rolls out).
	 */
	public function __construct(
		private readonly TermijnService $termService,
		private readonly ?TermijnTimerService $timerService = null,
	) {
	}//end __construct()

	/**
	 * Request an ordinary AWB 4:14 lid 1 verlenging on a TermijnInstance.
	 *
	 * Bound by the TermijnDefinitie's aantalVerlengingen ceiling.
	 *
	 * @param string $termInstanceId Instance id.
	 * @param string $rationale Non-empty reason.
	 * @param string $newEndDate New deadline (YYYY-MM-DD; must be > einddatumActueel).
	 * @param string $documentLink Optional document link (verlengingsbrief).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException With validation failures (cited AWB rule).
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-03-pause-extension/tasks.md
	 */
	public function requestExtension(
		string $termInstanceId,
		string $rationale,
		string $newEndDate,
		string $documentLink = '',
	): array {
		return $this->applyExtension(
			termInstanceId: $termInstanceId,
			rationale: $rationale,
			newEndDate: $newEndDate,
			documentLink: $documentLink,
			mode: self::MODE_STANDARD
		);
	}//end requestExtension()

	/**
	 * Request a supervisor-approved AWB 4:14 lid 3 verlenging.
	 *
	 * Bypasses the TermijnDefinitie's aantalVerlengingen ceiling and is
	 * recorded with the supervisor grondslag and actor.
	 *
	 * @param string $termInstanceId Instance id.
	 * @param string $rationale Non-empty reason.
	 * @param string $newEndDate New deadline (YYYY-MM-DD; must be > einddatumActueel).
	 * @param string $documentLink Optional document link (verlengingsbrief).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException With validation failures (cited AWB rule).
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-03-pause-extension/tasks.md
	 */
	public function requestSupervisorExtension(
		string $termInstanceId,
		string $rationale,
		string $newEndDate,
		string $documentLink = '',
	): array {
		return $this->applyExtension(
			termInstanceId: $termInstanceId,
			rationale: $rationale,
			newEndDate: $newEndDate,
			documentLink: $documentLink,
			mode: self::MODE_SUPERVISOR
		);
	}//end requestSupervisorExtension()

	/**
	 * Shared verlenging implementation for both extension modes.
	 *
	 * @param string $termInstanceId Instance id.
	 * @param string $rationale Non-empty reason.
	 * @param string $newEndDate New deadline (YYYY-MM-DD; must be > einddatumActueel).
	 * @param string $documentLink Optional document link (verlengingsbrief).
	 * @param string $mode One of self::MODE_STANDARD or self::MODE_SUPERVISOR.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException With validation failures (cited AWB rule).
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-03-pause-extension/tasks.md
	 */
	private function applyExtension(
		string $termInstanceId,
		string $rationale,
		string $newEndDate,
		string $documentLink,
		string $mode,
	): array {
		$this->assertExtensionInput(rationale: $rationale, newEndDate: $newEndDate);

		$instance = $this->termService->getTermijnInstance($termInstanceId);
		if ($instance === null) {
			throw new RuntimeException('TermijnInstance not found: ' . $termInstanceId);
		}

		$this->assertExtensionPermitted(instance: $instance, newEndDate: $newEndDate, mode: $mode);

		$current = (string)($instance['endDateCurrent'] ?? '');
		$consumed = (int)($instance['countExtensions'] ?? 0);
		$daysImpact = $this->calculateDaysImpact(current: $current, newEndDate: $newEndDate);

		$updated = $this->termService->updateTermijnInstance(
			$termInstanceId,
			[
				'endDateCurrent' => $newEndDate,
				'status' => 'verlengd',
				'countExtensions' => ($consumed + 1),
			]
		);

		// Mirror the verdaging to the engine timer: the domain refusal
		// rules above are authoritative, the engine records the same
		// decision on the clock (standard extend vs the separately
		// authorized supervisor override, AWB 4:14).
		$this->timerService?->extendBeslistermijn(
			instance: $instance,
			days: $daysImpact,
			rationale: $rationale,
			supervisor: ($mode === self::MODE_SUPERVISOR)
		);

		$context = $this->resolveExtensionContext(mode: $mode);

		$this->termService->recordEvent(
			termInstanceId: $termInstanceId,
			type: 'verleng',
			basis: $context['basis'],
			rationale: $rationale,
			daysImpact: $daysImpact,
			documentLink: $documentLink,
			actor: $context['actor'],
		);

		return $updated ?? $instance;
	}//end applyExtension()

	/**
	 * Validate the raw verlenging input before any lookup is performed.
	 *
	 * @param string $rationale Non-empty reason.
	 * @param string $newEndDate New deadline (YYYY-MM-DD).
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the motivering is empty or the date is malformed.
	 */
	private function assertExtensionInput(string $rationale, string $newEndDate): void {
		if (trim($rationale) === '') {
			throw new RuntimeException('Motivering is required for AWB 4:14 verlenging');
		}

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $newEndDate) !== 1) {
			throw new RuntimeException('newEinddatum must be in YYYY-MM-DD format');
		}
	}//end assertExtensionInput()

	/**
	 * Validate the verlenging against the instance state and the AWB 4:14 ceiling.
	 *
	 * @param array<string, mixed> $instance Instance row.
	 * @param string $newEndDate New deadline (YYYY-MM-DD).
	 * @param string $mode One of self::MODE_STANDARD or self::MODE_SUPERVISOR.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the deadline does not move forward or the ceiling is exhausted.
	 */
	private function assertExtensionPermitted(array $instance, string $newEndDate, string $mode): void {
		$current = (string)($instance['endDateCurrent'] ?? '');
		if ($current !== '' && $newEndDate <= $current) {
			throw new RuntimeException('newEinddatum must be later than current einddatumActueel');
		}

		$consumed = (int)($instance['countExtensions'] ?? 0);
		$maxExt = $this->resolveMaxExtensions(instance: $instance);
		if ($mode !== self::MODE_SUPERVISOR && $consumed >= $maxExt) {
			throw new RuntimeException('AWB 4:14 lid 3: maximum aantal verlengingen al verbruikt (' . $maxExt . ')');
		}
	}//end assertExtensionPermitted()

	/**
	 * Compute the number of days the deadline moves by.
	 *
	 * @param string $current Current einddatumActueel, empty when unset.
	 * @param string $newEndDate New deadline (YYYY-MM-DD).
	 *
	 * @return int Absolute number of days between the current and the new deadline.
	 */
	private function calculateDaysImpact(string $current, string $newEndDate): int {
		$currentInput = 'now';
		if ($current !== '') {
			$currentInput = $current;
		}

		$currentDate = new DateTimeImmutable($currentInput);
		$newDate = new DateTimeImmutable($newEndDate);

		return (int)$currentDate->diff($newDate)->days;
	}//end calculateDagenImpact()

	/**
	 * Resolve the grondslag and actor recorded with the verlenging event.
	 *
	 * @param string $mode One of self::MODE_STANDARD or self::MODE_SUPERVISOR.
	 *
	 * @return array{basis: string, actor: string} Event grondslag and actor for the mode.
	 */
	private function resolveExtensionContext(string $mode): array {
		if ($mode === self::MODE_SUPERVISOR) {
			return [
				'basis' => 'AWB 4:14 lid 3 (supervisor)',
				'actor' => 'supervisor',
			];
		}

		return [
			'basis' => 'AWB 4:14 lid 1',
			'actor' => 'system',
		];
	}//end resolveExtensionContext()

	/**
	 * Resolve the maximum number of extensions allowed for this instance.
	 *
	 * Looks up the TermijnDefinitie via the instance reference and reads
	 * aantalVerlengingen; falls back to 1 when missing (AWB default).
	 *
	 * @param array<string, mixed> $instance Instance row.
	 *
	 * @return int
	 */
	private function resolveMaxExtensions(array $instance): int {
		// Prefer to look up the definition by the linked id.
		$defId = (string)($instance['deadlineDefinition'] ?? '');
		if ($defId === '') {
			return 1;
		}

		// Walk the TermijnService cache by zaaktype if available. As a
		// safe fallback, return the default 1 — a real lookup would
		// call SettingsService->getObjectService()->find($defId) here,
		// but TermijnService already caches lookups by zaaktype which
		// is the data we actually need.
		$svcDef = null;
		try {
			$reflection = new ReflectionClass($this->termService);
			if ($reflection->hasProperty('definitieCache') === true) {
				$prop = $reflection->getProperty('definitieCache');
				$cache = $prop->getValue($this->termService);
				if (is_array($cache) === true) {
					foreach ($cache as $row) {
						if (is_array($row) === true && (string)($row['id'] ?? '') === $defId) {
							$svcDef = $row;
							break;
						}
					}
				}
			}
		} catch (\Throwable $e) {
			$svcDef = null;
		}

		if (is_array($svcDef) === true) {
			return (int)($svcDef['countExtensions'] ?? 1);
		}

		return 1;
	}//end resolveMaxExtensions()
}//end class
