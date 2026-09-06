<?php

/**
 * FlowTimerEngineFake fixture.
 *
 * Records every engine lifecycle call with the REAL FlowTimerService
 * signatures (parameter names included, because every call site uses
 * named arguments): a fake that agreed with the caller instead of with
 * the engine could not fail. Shared by the timer-mapping, pause and
 * repair-step tests. Lives in tests/Unit/Fixtures/ and is required from
 * tests/bootstrap.php so filtered single-file runs resolve it.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeInterface;
use OCA\OpenRegister\Db\FlowTimer;
use RuntimeException;

if (class_exists(FlowTimerEngineFake::class, false) === true) {
	return;
}

/**
 * Records every engine lifecycle call, with the engine's own signatures.
 */
class FlowTimerEngineFake {
	/**
	 * Recorded calls, keyed by operation.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	public array $calls = [];

	/**
	 * When set, every call throws (the refusing-engine path).
	 *
	 * @var bool
	 */
	public bool $refuse = false;

	/**
	 * Arm a timer (signature mirrors FlowTimerService::arm()).
	 *
	 * @param array<string, mixed> $config The timer configuration.
	 * @param string|null $actor The arming identity.
	 * @param DateTimeInterface|null $now The clock.
	 *
	 * @return FlowTimer
	 */
	public function arm(array $config, ?string $actor, ?DateTimeInterface $now = null): FlowTimer {
		$this->record(operation: 'arm', args: ['config' => $config, 'actor' => $actor, 'now' => $now]);
		$timer = new FlowTimer();
		$timer->setUuid('timer-' . count($this->calls['arm']));
		return $timer;
	}

	/**
	 * Suspend (signature mirrors FlowTimerService::suspend()).
	 *
	 * @param string $uuid The timer uuid.
	 * @param string $reason Why.
	 * @param DateTimeInterface|null $until Expected end.
	 * @param string|null $actor Actor.
	 * @param string|null $basis Legal ground.
	 * @param DateTimeInterface|null $now The clock.
	 *
	 * @return FlowTimer
	 */
	public function suspend(
		string $uuid,
		string $reason,
		?DateTimeInterface $until,
		?string $actor,
		?string $basis = null,
		?DateTimeInterface $now = null,
	): FlowTimer {
		$this->record(operation: 'suspend', args: ['uuid' => $uuid, 'reason' => $reason, 'until' => $until, 'actor' => $actor, 'basis' => $basis, 'now' => $now]);
		return new FlowTimer();
	}

	/**
	 * Resume (signature mirrors FlowTimerService::resume()).
	 *
	 * @param string $uuid The timer uuid.
	 * @param string|null $reason Why.
	 * @param string|null $actor Actor.
	 * @param DateTimeInterface|null $now The clock.
	 *
	 * @return FlowTimer
	 */
	public function resume(string $uuid, ?string $reason, ?string $actor, ?DateTimeInterface $now = null): FlowTimer {
		$this->record(operation: 'resume', args: ['uuid' => $uuid, 'reason' => $reason, 'actor' => $actor, 'now' => $now]);
		return new FlowTimer();
	}

	/**
	 * Extend (signature mirrors FlowTimerService::extend()).
	 *
	 * @param string $uuid The timer uuid.
	 * @param int $amount Amount.
	 * @param string $unit Unit.
	 * @param string $rationale Why.
	 * @param string|null $actor Actor.
	 * @param DateTimeInterface|null $now The clock.
	 *
	 * @return FlowTimer
	 */
	public function extend(
		string $uuid,
		int $amount,
		string $unit,
		string $rationale,
		?string $actor,
		?DateTimeInterface $now = null,
	): FlowTimer {
		$this->record(operation: 'extend', args: ['uuid' => $uuid, 'amount' => $amount, 'unit' => $unit, 'rationale' => $rationale, 'actor' => $actor]);
		return new FlowTimer();
	}

	/**
	 * Extend beyond the bound (mirrors FlowTimerService::extendWithOverride()).
	 *
	 * @param string $uuid The timer uuid.
	 * @param int $amount Amount.
	 * @param string $unit Unit.
	 * @param string $rationale Why.
	 * @param string $actor The authorizing identity.
	 * @param DateTimeInterface|null $now The clock.
	 *
	 * @return FlowTimer
	 */
	public function extendWithOverride(
		string $uuid,
		int $amount,
		string $unit,
		string $rationale,
		string $actor,
		?DateTimeInterface $now = null,
	): FlowTimer {
		$this->record(operation: 'extendWithOverride', args: ['uuid' => $uuid, 'amount' => $amount, 'unit' => $unit, 'rationale' => $rationale, 'actor' => $actor]);
		return new FlowTimer();
	}

	/**
	 * Cancel by subject (mirrors FlowTimerService::cancelForSubject()).
	 *
	 * @param string $subjectType Subject type.
	 * @param string $subjectUuid Subject uuid.
	 * @param string $reason Why.
	 * @param string|null $actor Actor.
	 * @param DateTimeInterface|null $now The clock.
	 *
	 * @return int
	 */
	public function cancelForSubject(
		string $subjectType,
		string $subjectUuid,
		string $reason,
		?string $actor,
		?DateTimeInterface $now = null,
	): int {
		$this->record(operation: 'cancelForSubject', args: ['subjectType' => $subjectType, 'subjectUuid' => $subjectUuid, 'reason' => $reason, 'actor' => $actor]);
		return 2;
	}

	/**
	 * Record one call, or refuse.
	 *
	 * @param string $operation Operation name.
	 * @param array<string, mixed> $args Arguments.
	 *
	 * @return void
	 */
	private function record(string $operation, array $args): void {
		if ($this->refuse === true) {
			throw new RuntimeException('engine refuses ' . $operation);
		}

		$this->calls[$operation][] = $args;
	}
}

