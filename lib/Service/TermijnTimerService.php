<?php

/**
 * Dossiq TermijnTimerService.
 *
 * The arm mapping between the AWB termijnbewaking and OpenRegister's
 * business-timer engine (flow-business-timers). One `due`/`wettelijk`
 * FlowTimer measures each active TermijnInstance; opschorting maps onto
 * engine suspend/resume; verdaging onto extend/extendWithOverride;
 * completion cancels. The engine decides WHEN, dossiq keeps the domain
 * WHAT (escalation bookkeeping, dwangsom arithmetic, case data).
 *
 * OpenRegister is an optional runtime dependency: every call resolves the
 * engine lazily through {@see SettingsService::getOpenRegisterClass()} and
 * degrades to a logged no-op when it is unavailable, so the domain flow
 * never breaks on an absent engine.
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
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Arms, suspends, resumes, extends and cancels engine timers for AWB terms.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */
class TermijnTimerService {
	/**
	 * The engine service class, resolved lazily.
	 *
	 * @var string
	 */
	public const ENGINE_CLASS = 'OCA\OpenRegister\Service\Flow\Timer\FlowTimerService';

	/**
	 * The seeded 14/7/2/0 escalation ladder — the same matrix
	 * {@see DeadlineEscalationService::DEFAULT_MATRIX} hardcodes.
	 *
	 * @var string
	 */
	public const LADDER_DEFAULT = 'nl-termijn-default';

	/**
	 * Metadata marker the listener filters on.
	 *
	 * @var string
	 */
	public const METADATA_SOURCE = 'dossiq-termijn';

	/**
	 * Metadata kinds.
	 */
	public const KIND_BESLISTERMIJN = 'beslistermijn';

	public const KIND_HERSTELTERMIJN = 'hersteltermijn';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Lazy OpenRegister access.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Arm the beslistermijn timer for a TermijnInstance.
	 *
	 * Maps the instance onto a `due`/`wettelijk` FlowTimer: SLA in
	 * `calendarDays` spanning startDate to endDateCurrent (so an in-flight
	 * instance arms at its CURRENT deadline, pauses and extensions
	 * included), ladder `nl-termijn-default`, anchored at `case_created`
	 * on the start date, extension ceiling from the TermijnDefinitie. No
	 * onExpiry: reaching the beslistermijn never transitions the case.
	 *
	 * @param array<string, mixed> $instance The TermijnInstance row.
	 * @param array<string, mixed> $definitie The resolved TermijnDefinitie (may be empty).
	 *
	 * @return string|null The armed timer uuid, or null when the engine is unavailable.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function armBeslistermijn(array $instance, array $definitie): ?string {
		$instanceId = (string)($instance['id'] ?? '');
		$start = $this->dateOrNull(value: (string)($instance['startDate'] ?? ''));
		if ($instanceId === '' || $start === null) {
			return null;
		}

		$slaDays = $this->slaDaysFor(instance: $instance, definitie: $definitie, start: $start);

		$config = [
			'subjectType' => 'object',
			'subjectUuid' => $instanceId,
			'appId' => 'dossiq',
			'title' => 'Beslistermijn ' . (string)($instance['case'] ?? $instanceId),
			'purpose' => 'due',
			'legalEffect' => 'wettelijk',
			'sla' => [
				'value' => $slaDays,
				'unit' => 'calendarDays',
			],
			'ladder' => self::LADDER_DEFAULT,
			'extensionMax' => max(1, (int)($definitie['countExtensions'] ?? 1)),
			'anchorEvent' => 'case_created',
			'anchorEventAt' => $start,
			'metadata' => [
				'source' => self::METADATA_SOURCE,
				'kind' => self::KIND_BESLISTERMIJN,
				'termijnInstanceId' => $instanceId,
				'caseId' => (string)($instance['case'] ?? ''),
				'deadlineDefinition' => (string)($instance['deadlineDefinition'] ?? ''),
				'basis' => (string)($definitie['legalBasis'] ?? 'AWB 4:13'),
			],
		];

		return $this->arm(config: $config, context: 'beslistermijn', instanceId: $instanceId);
	}//end armBeslistermijn()

	/**
	 * Arm the advisory hersteltermijn helper timer for a paused instance.
	 *
	 * `legalEffect: none` with a single explicit `slaBreached` rule (message
	 * `pauze-verlopen`), so no ladder rungs fire on a short pause and the
	 * pause-expiry signal survives the daily scan's retirement. The engine
	 * has no single-timer cancel, so a helper outliving an on-time
	 * aanvulling is dropped by the listener's still-paused guard instead.
	 *
	 * @param array<string, mixed> $instance The TermijnInstance row.
	 * @param int $durationDays The hersteltermijn length in days.
	 *
	 * @return string|null The armed timer uuid, or null when the engine is unavailable.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function armHersteltermijn(array $instance, int $durationDays): ?string {
		$instanceId = (string)($instance['id'] ?? '');
		if ($instanceId === '' || $durationDays <= 0) {
			return null;
		}

		$config = [
			'subjectType' => 'object',
			'subjectUuid' => $instanceId,
			'appId' => 'dossiq',
			'title' => 'Hersteltermijn ' . (string)($instance['case'] ?? $instanceId),
			'purpose' => 'due',
			'legalEffect' => 'none',
			'sla' => [
				'value' => $durationDays,
				'unit' => 'calendarDays',
			],
			'escalationRules' => [
				[
					'trigger' => 'slaBreached',
					'offset' => 0,
					'offsetUnit' => 'calendarDays',
					'notifyRole' => ['handler'],
					'escalateToRole' => [],
					'priority' => 'high',
					'message' => 'pauze-verlopen',
					'openIncident' => false,
				],
			],
			'anchorEvent' => 'hersteltermijn_start',
			'metadata' => [
				'source' => self::METADATA_SOURCE,
				'kind' => self::KIND_HERSTELTERMIJN,
				'termijnInstanceId' => $instanceId,
				'caseId' => (string)($instance['case'] ?? ''),
				'basis' => 'AWB 4:5',
			],
		];

		return $this->arm(config: $config, context: 'hersteltermijn', instanceId: $instanceId);
	}//end armHersteltermijn()

	/**
	 * Suspend the beslistermijn timer (opschorting, AWB 4:5).
	 *
	 * The engine banks the consumed budget: while suspended the timer
	 * neither fires nor escalates nor reports overdue, and resuming
	 * re-projects from the unconsumed remainder.
	 *
	 * @param array<string, mixed> $instance The TermijnInstance row (carries `engineTimerId`).
	 * @param string $reason The opschorting rationale (recorded as evidence).
	 * @param DateTimeImmutable|null $until The expected pause end, display-only.
	 *
	 * @return bool True when the engine suspended the timer.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function suspendBeslistermijn(array $instance, string $reason, ?DateTimeImmutable $until): bool {
		$timerId = (string)($instance['engineTimerId'] ?? '');
		if ($timerId === '') {
			return false;
		}

		$engine = $this->engine();
		if ($engine === null) {
			return false;
		}

		try {
			$engine->suspend(uuid: $timerId, reason: $reason, until: $until, actor: null, basis: 'Awb 4:5');
			return true;
		} catch (\Throwable $e) {
			$this->logFailure(operation: 'suspend', timerId: $timerId, error: $e);
			return false;
		}
	}//end suspendBeslistermijn()

	/**
	 * Resume the beslistermijn timer after an aanvulling (AWB 4:15).
	 *
	 * @param array<string, mixed> $instance The TermijnInstance row (carries `engineTimerId`).
	 * @param string $reason Why the term resumes.
	 *
	 * @return bool True when the engine resumed the timer.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function resumeBeslistermijn(array $instance, string $reason): bool {
		$timerId = (string)($instance['engineTimerId'] ?? '');
		if ($timerId === '') {
			return false;
		}

		$engine = $this->engine();
		if ($engine === null) {
			return false;
		}

		try {
			$engine->resume(uuid: $timerId, reason: $reason, actor: null);
			return true;
		} catch (\Throwable $e) {
			$this->logFailure(operation: 'resume', timerId: $timerId, error: $e);
			return false;
		}
	}//end resumeBeslistermijn()

	/**
	 * Mirror a verdaging (AWB 4:14) to the engine timer.
	 *
	 * The standard mode uses the engine's bounded `extend()`; the
	 * supervisor mode (AWB 4:14 lid 3) uses the separately authorized
	 * `extendWithOverride()`. Dossiq's own ceiling check has already run:
	 * the domain is authoritative, the engine records the same decision on
	 * the clock.
	 *
	 * @param array<string, mixed> $instance The TermijnInstance row (carries `engineTimerId`).
	 * @param int $days How many days the deadline moves.
	 * @param string $rationale The verdaging motivering.
	 * @param bool $supervisor True for the AWB 4:14 lid 3 override path.
	 *
	 * @return bool True when the engine extended the timer.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function extendBeslistermijn(array $instance, int $days, string $rationale, bool $supervisor): bool {
		$timerId = (string)($instance['engineTimerId'] ?? '');
		if ($timerId === '' || $days <= 0) {
			return false;
		}

		$engine = $this->engine();
		if ($engine === null) {
			return false;
		}

		try {
			if ($supervisor === true) {
				$engine->extendWithOverride(uuid: $timerId, amount: $days, unit: 'calendarDays', rationale: $rationale, actor: 'supervisor');
				return true;
			}

			$engine->extend(uuid: $timerId, amount: $days, unit: 'calendarDays', rationale: $rationale, actor: null);
			return true;
		} catch (\Throwable $e) {
			$this->logFailure(operation: 'extend', timerId: $timerId, error: $e);
			return false;
		}
	}//end extendBeslistermijn()

	/**
	 * Cancel every open timer of an instance, in the operation that made
	 * the term terminal.
	 *
	 * @param string $instanceId The TermijnInstance id.
	 * @param string $reason Why, recorded on each timer.
	 *
	 * @return int How many timers were cancelled.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function cancelForInstance(string $instanceId, string $reason): int {
		if ($instanceId === '') {
			return 0;
		}

		$engine = $this->engine();
		if ($engine === null) {
			return 0;
		}

		try {
			return (int)$engine->cancelForSubject(subjectType: 'object', subjectUuid: $instanceId, reason: $reason, actor: null);
		} catch (\Throwable $e) {
			$this->logFailure(operation: 'cancel', timerId: $instanceId, error: $e);
			return 0;
		}
	}//end cancelForInstance()

	/**
	 * Arm one timer, returning the persisted uuid.
	 *
	 * @param array<string, mixed> $config The engine arm configuration.
	 * @param string $context Which term kind, for logging.
	 * @param string $instanceId The instance, for logging.
	 *
	 * @return string|null The timer uuid, or null on an unavailable or refusing engine.
	 */
	private function arm(array $config, string $context, string $instanceId): ?string {
		$engine = $this->engine();
		if ($engine === null) {
			return null;
		}

		try {
			$timer = $engine->arm(config: $config, actor: null);
			$uuid = (string)$timer->getUuid();
			$this->logger->info(
				'Dossiq termijn: engine timer armed',
				['kind' => $context, 'instance' => $instanceId, 'timer' => $uuid]
			);
			return $uuid;
		} catch (\Throwable $e) {
			$this->logFailure(operation: 'arm ' . $context, timerId: $instanceId, error: $e);
			return null;
		}
	}//end arm()

	/**
	 * The SLA in calendar days: start to the CURRENT deadline when known,
	 * else the definition's standard duration.
	 *
	 * @param array<string, mixed> $instance The TermijnInstance row.
	 * @param array<string, mixed> $definitie The TermijnDefinitie row.
	 * @param DateTimeImmutable $start The term's start.
	 *
	 * @return int Calendar days, at least 1 (the engine refuses 0).
	 */
	private function slaDaysFor(array $instance, array $definitie, DateTimeImmutable $start): int {
		$end = $this->dateOrNull(value: (string)($instance['endDateCurrent'] ?? ''));
		if ($end !== null) {
			$startDay = new DateTimeImmutable($start->format('Y-m-d'));
			$days = (int)$startDay->diff($end)->days;
			if ($end >= $startDay && $days > 0) {
				return $days;
			}
		}

		return max(1, (int)($definitie['standardDurationDays'] ?? 1));
	}//end slaDaysFor()

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

	/**
	 * Resolve the engine, or null when OpenRegister (or the timer stack) is absent.
	 *
	 * @return object|null The FlowTimerService.
	 */
	private function engine(): ?object {
		return $this->settingsService->getOpenRegisterClass(self::ENGINE_CLASS);
	}//end engine()

	/**
	 * Log a degraded engine call. The domain flow proceeds on case data;
	 * the log line is the operator's signal that the clocks diverged.
	 *
	 * @param string $operation Which lifecycle call failed.
	 * @param string $timerId The timer or instance involved.
	 * @param \Throwable $error The failure.
	 *
	 * @return void
	 */
	private function logFailure(string $operation, string $timerId, \Throwable $error): void {
		$this->logger->warning(
			'Dossiq termijn: engine timer call failed, domain flow continues on case data',
			['operation' => $operation, 'id' => $timerId, 'error' => $error->getMessage()]
		);
	}//end logFailure()
}//end class
