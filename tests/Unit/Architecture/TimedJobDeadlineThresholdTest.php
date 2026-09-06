<?php

/**
 * Structural guard: no dossiq TimedJob computes deadline thresholds.
 *
 * The engine sweep (OpenRegister FlowTimerWorker) owns deadline firing;
 * dossiq's domain side hangs off FlowTimerFiredEvent. A TimedJob that
 * computes days-left buckets, overdue state or termijn thresholds is a
 * second clock — exactly the class of defect the One Engine audit ranked
 * highest — so any such job must either not exist or carry a
 * reason-bearing allowlist entry naming the phase that retires it
 * (REQ-TOT-004). The allowlist shrinks to empty as phases 2 to 4 of
 * `termijnbewaking-op-engine-timers` land.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Scans lib/BackgroundJob for TimedJobs that compute deadline thresholds.
 */
class TimedJobDeadlineThresholdTest extends TestCase {
	/**
	 * The job directory under test.
	 *
	 * @var string
	 */
	private const JOB_DIR = __DIR__ . '/../../../lib/BackgroundJob';

	/**
	 * Textual signals that a job computes deadline thresholds itself.
	 *
	 * Deliberately narrow: generic words like a cache "threshold in
	 * seconds" must not hit; the termijn vocabulary and date-diff math
	 * must.
	 *
	 * @var array<int, string>
	 */
	private const SIGNALS = [
		'deadline',
		'termijn',
		'daysleft',
		'daystodeadline',
		'->diff(',
		'thresholddays',
		'bucketfor',
		'overschreden',
	];

	/**
	 * Jobs still allowed to compute thresholds, each with the reason and
	 * the umbrella-change phase that retires it. An empty reason fails the
	 * test: the entry IS the debt record.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWLIST = [
		'AdviceDeadlineJob' => 'advice deadline engine; moves to armed FlowTimers in phase 2 of openspec/changes/termijnbewaking-op-engine-timers (tasks 2.5)',
		'WOODeadlineCheckJob' => 'WOO deadline engine; moves to armed FlowTimers in phase 2 of openspec/changes/termijnbewaking-op-engine-timers (tasks 2.1)',
		'BezwaarTermijnJob' => 'bezwaartermijn scheduler; moves to anchor-shaped FlowTimers in phase 2 of openspec/changes/termijnbewaking-op-engine-timers (tasks 2.2)',
		'DsoDeadlineJob' => 'DSO deadline engine advancing case status from cron; becomes a FlowTimerFiredEvent consumer in phase 2 of openspec/changes/termijnbewaking-op-engine-timers (tasks 2.3)',
		'BottleneckDetectionJob' => 'stalled-milestone detector; moves to businessDays FlowTimers in phase 3 of openspec/changes/termijnbewaking-op-engine-timers (tasks 3.1)',
	];

	/**
	 * The retired daily scan may not come back, under any name it had.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
	 */
	public function testDailyDeadlineScanJobIsRetired(): void {
		self::assertFileDoesNotExist(
			self::JOB_DIR . '/DailyDeadlineScanJob.php',
			'DailyDeadlineScanJob was retired by termijnbewaking-op-engine-timers: '
			. 'the engine sweep fires the armed termijn timers. Do not reintroduce the scan.'
		);
		self::assertArrayNotHasKey(
			'DailyDeadlineScanJob',
			self::ALLOWLIST,
			'The retired scan must not be allowlisted back in.'
		);

		$infoXml = (string)file_get_contents(self::JOB_DIR . '/../../appinfo/info.xml');
		self::assertStringNotContainsString(
			'<job>OCA\Dossiq\BackgroundJob\DailyDeadlineScanJob</job>',
			$infoXml,
			'DailyDeadlineScanJob must not be registered in info.xml.'
		);
	}//end testDailyDeadlineScanJobIsRetired()

	/**
	 * Every TimedJob computing deadline thresholds is allowlisted with a reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
	 */
	public function testTimedJobsDoNotComputeDeadlineThresholds(): void {
		$violations = [];
		foreach ($this->timedJobFiles() as $file) {
			$name = basename($file, '.php');
			if ($this->computesThresholds(file: $file) === false) {
				continue;
			}

			if (array_key_exists($name, self::ALLOWLIST) === true) {
				continue;
			}

			$violations[] = $name;
		}//end foreach

		self::assertSame(
			[],
			$violations,
			'These TimedJobs compute deadline thresholds outside the engine: '
			. implode(', ', $violations) . '. Arm an OpenRegister FlowTimer and consume '
			. 'FlowTimerFiredEvent instead (see openspec/changes/termijnbewaking-op-engine-timers), '
			. 'or add a reason-bearing allowlist entry naming the phase that retires the job.'
		);
	}//end testTimedJobsDoNotComputeDeadlineThresholds()

	/**
	 * Every allowlist entry is live debt: it names an existing job that
	 * still trips the signals, and carries a reason naming the umbrella
	 * change. A stale or reasonless entry fails, so the list can only
	 * shrink honestly.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
	 */
	public function testAllowlistEntriesAreLiveAndReasonBearing(): void {
		foreach (self::ALLOWLIST as $name => $reason) {
			$file = self::JOB_DIR . '/' . $name . '.php';
			self::assertFileExists(
				$file,
				sprintf('Allowlist entry "%s" names no existing job; remove the entry.', $name)
			);
			self::assertTrue(
				$this->computesThresholds(file: $file),
				sprintf('Allowlist entry "%s" no longer computes thresholds; remove the entry.', $name)
			);
			self::assertStringContainsString(
				'termijnbewaking-op-engine-timers',
				$reason,
				sprintf('Allowlist entry "%s" must name the retiring phase in its reason.', $name)
			);
		}//end foreach
	}//end testAllowlistEntriesAreLiveAndReasonBearing()

	/**
	 * All TimedJob files under lib/BackgroundJob.
	 *
	 * @return array<int, string> Absolute file paths.
	 */
	private function timedJobFiles(): array {
		$files = glob(self::JOB_DIR . '/*.php');
		if (is_array($files) === false) {
			return [];
		}

		$jobs = [];
		foreach ($files as $file) {
			$source = (string)file_get_contents($file);
			if (str_contains($source, 'extends TimedJob') === true) {
				$jobs[] = $file;
			}
		}

		return $jobs;
	}//end timedJobFiles()

	/**
	 * Whether a job file carries deadline-threshold computation signals.
	 *
	 * @param string $file The job file.
	 *
	 * @return bool True when any signal matches.
	 */
	private function computesThresholds(string $file): bool {
		$source = strtolower((string)file_get_contents($file));
		foreach (self::SIGNALS as $signal) {
			if (str_contains($source, $signal) === true) {
				return true;
			}
		}

		return false;
	}//end computesThresholds()
}//end class
