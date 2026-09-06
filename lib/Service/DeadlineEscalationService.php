<?php

/**
 * Dossiq DeadlineEscalationService.
 *
 * Resolves per-threshold escalation recipients and dispatches escalation
 * notifications when a TermijnInstance reaches a configured proximity
 * (14d / 7d / 2d / 0d). Duplicate suppression: each instance tracks
 * `notificatiesVerstuurd` (the thresholds already sent) and the service
 * skips any threshold already in the list.
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-04-daily-scan-escalation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use Psr\Log\LoggerInterface;

/**
 * Threshold-aware escalation dispatcher for the daily termijn scan.
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-04-daily-scan-escalation/tasks.md
 */
class DeadlineEscalationService {
	/**
	 * Default escalation matrix.
	 *
	 * Maps threshold-in-days → {recipients, priority, template}.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const DEFAULT_MATRIX = [
		14 => ['recipients' => ['handler'],                        'priority' => 'low',      'template' => 'termijn-14d'],
		7 => ['recipients' => ['handler', 'teamleader'],          'priority' => 'medium',   'template' => 'termijn-7d'],
		2 => ['recipients' => ['handler', 'teamleader', 'manager'], 'priority' => 'high',     'template' => 'termijn-2d'],
		0 => ['recipients' => ['handler', 'teamleader', 'manager'], 'priority' => 'critical', 'template' => 'termijn-overschreden'],
	];

	/**
	 * Constructor.
	 *
	 * @param TermijnService $termService TermijnService for instance lookup/update.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly TermijnService $termService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Available threshold buckets, sorted descending so the earliest first.
	 *
	 * @return array<int, int>
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-04-daily-scan-escalation/tasks.md
	 */
	public function thresholds(): array {
		$keys = array_keys(self::DEFAULT_MATRIX);
		rsort($keys);
		return $keys;
	}//end thresholds()

	/**
	 * Compute the threshold bucket for a number of days remaining.
	 *
	 * Returns null when above the highest threshold, 0 when zero/negative.
	 *
	 * @param int $daysToDeadline Days to deadline (negative = overschreden).
	 *
	 * @return int|null
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-04-daily-scan-escalation/tasks.md
	 */
	public function bucketFor(int $daysToDeadline): ?int {
		if ($daysToDeadline <= 0) {
			return 0;
		}

		// Walk ascending so the *tightest* matching threshold wins
		// (7 days remaining → bucket 7, not 14).
		$buckets = $this->thresholds();
		sort($buckets);
		foreach ($buckets as $bucket) {
			if ($bucket > 0 && $daysToDeadline <= $bucket) {
				return $bucket;
			}
		}

		return null;
	}//end bucketFor()

	/**
	 * Notify a threshold for a TermijnInstance (idempotent on duplicates).
	 *
	 * @param array<string, mixed> $instance TermijnInstance row.
	 * @param int $threshold Threshold bucket (14/7/2/0).
	 *
	 * @return bool True if a notification was sent (i.e. not a duplicate).
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-04-daily-scan-escalation/tasks.md
	 */
	public function notifyThreshold(array $instance, int $threshold): bool {
		$instanceId = (string)($instance['id'] ?? '');
		if ($instanceId === '') {
			return false;
		}

		$alreadySent = (array)($instance['notificatiesVerstuurd'] ?? []);
		if (in_array($threshold, array_map(static fn ($v): int => (int)$v, $alreadySent), true) === true) {
			return false;
		}

		$config = self::DEFAULT_MATRIX[$threshold] ?? null;
		if ($config === null) {
			return false;
		}

		$payload = [
			'threshold' => $threshold,
			'template' => $config['template'],
			'priority' => $config['priority'],
			'recipients' => $config['recipients'],
			'instanceId' => $instanceId,
			'caseId' => (string)($instance['case'] ?? ''),
			'deadline' => (string)($instance['endDateCurrent'] ?? ''),
		];

		$this->logger->info('Dossiq termijn escalation dispatched', $payload);

		// Mark threshold as sent (duplicate suppression).
		$alreadySent[] = $threshold;
		$this->termService->updateTermijnInstance(
			$instanceId,
			['notificatiesVerstuurd' => array_values(array_unique(array_map('intval', $alreadySent)))]
		);

		return true;
	}//end notifyThreshold()

	/**
	 * Get the full escalation matrix (for admin UI rendering).
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-04-daily-scan-escalation/tasks.md
	 */
	public function matrix(): array {
		return self::DEFAULT_MATRIX;
	}//end matrix()
}//end class
