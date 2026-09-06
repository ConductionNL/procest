<?php

/**
 * Dossiq KCC BelplanRoutingService
 *
 * Routes inbound KCC calls to the best-matched specialist based on a
 * `belplan` configuration: keuzemenu options → vaardigheid mapping →
 * specialist availability lookup → overflow handling. The service is
 * stateless: callers pass in the belplan + the current specialist pool
 * snapshot and receive a routing decision.
 *
 * Real wiring to a pipelinq KCC switchboard happens at the OpenConnector
 * edge; this service owns the routing-decision algorithm so it can be
 * unit-tested deterministically without an upstream KCC dependency.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Kcc
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Kcc;

/**
 * Belplan-driven KCC call routing.
 *
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
 */
class BelplanRoutingService {
	/**
	 * Default overflow thresholds (seconds wachttijd / queue length).
	 */
	public const DEFAULT_OVERFLOW_WACHTTIJD = 180;
	public const DEFAULT_OVERFLOW_WACHTRIJ_LENGTE = 5;

	/**
	 * Status values that count as "available" for routing.
	 *
	 * @var array<int, string>
	 */
	public const AVAILABLE_STATUSES = ['beschikbaar', 'available', 'idle'];

	/**
	 * Match a phone number against a belplan's triggerNummer (E.164 or local).
	 *
	 * @param string $phoneNumber The inbound number.
	 * @param array<int, array<string, mixed>> $belplannen The list of belplan records.
	 *
	 * @return array<string, mixed>|null The matched belplan or null when none matches.
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
	 */
	public function getActiveBelplan(string $phoneNumber, array $belplannen): ?array {
		$normalised = $this->normalisePhone(phoneNumber: $phoneNumber);

		foreach ($belplannen as $bp) {
			if (($bp['isActive'] ?? true) === false) {
				continue;
			}

			$trigger = $this->normalisePhone(phoneNumber: (string)($bp['triggerNumber'] ?? ''));
			if ($trigger === '') {
				continue;
			}

			if ($trigger === $normalised || str_ends_with($normalised, $trigger) === true) {
				return $bp;
			}
		}

		return null;
	}//end getActiveBelplan()

	/**
	 * Resolve a vaardigheid (skill) for the chosen menu option.
	 *
	 * @param array<string, mixed> $belplan The belplan record.
	 * @param string|int $menuSelection The 1-based menu key OR option label.
	 *
	 * @return string The vaardigheid, or '' when not resolvable.
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
	 */
	public function resolveVaardigheid(array $belplan, string|int $menuSelection): string {
		$steps = $belplan['routingSteps'] ?? [];
		if (is_array($steps) === false) {
			return '';
		}

		// Numeric selection: 1-based index into routeringStappen[].
		if (is_int($menuSelection) === true || ctype_digit((string)$menuSelection) === true) {
			$idx = ((int)$menuSelection) - 1;
			if (isset($steps[$idx]) === true && is_array($steps[$idx]) === true) {
				return (string)($steps[$idx]['vaardigheid'] ?? '');
			}

			return '';
		}

		// Otherwise, match the option label case-insensitively.
		$needle = mb_strtolower((string)$menuSelection);
		foreach ($steps as $step) {
			if (is_array($step) === false) {
				continue;
			}

			$label = mb_strtolower((string)($step['label'] ?? ''));
			if ($label === $needle) {
				return (string)($step['vaardigheid'] ?? '');
			}
		}

		return '';
	}//end resolveVaardigheid()

	/**
	 * Pick the best specialist for a given vaardigheid from the supplied pool.
	 *
	 * The algorithm:
	 *   1. Filter specialists who have the vaardigheid AND are available.
	 *   2. Pick the one with the lowest huidigeWachtrijLengte (queue length).
	 *   3. On ties, the one with the lowest gemiddeldeBehandelduur wins.
	 *   4. When no specialist is available AND the busy queue would push
	 *      callers past overflow thresholds → return a generalist routing
	 *      decision flagged with escalatieAanbevolen=true.
	 *
	 * @param string $vaardigheid The required skill.
	 * @param array<int, array<string, mixed>> $pool Specialist availability snapshot.
	 * @param int $overflowWachttijd Seconds threshold.
	 * @param int $maxQueueLengte Queue threshold.
	 *
	 * @return array{destinationSpecialistId: string|null, escalatieFlag: bool,
	 *               estimatedWaitTime: int, vaardigheid: string,
	 *               candidatePool: int}
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
	 */
	public function routeCall(
		string $vaardigheid,
		array $pool,
		int $overflowWachttijd = self::DEFAULT_OVERFLOW_WACHTTIJD,
		int $maxQueueLengte = self::DEFAULT_OVERFLOW_WACHTRIJ_LENGTE,
	): array {
		if ($vaardigheid === '') {
			return [
				'destinationSpecialistId' => null,
				'escalatieFlag' => false,
				'estimatedWaitTime' => 0,
				'vaardigheid' => '',
				'candidatePool' => 0,
			];
		}

		$candidates = $this->filterCandidates(pool: $pool, vaardigheid: $vaardigheid);

		if (count($candidates) === 0) {
			return [
				'destinationSpecialistId' => null,
				'escalatieFlag' => true,
				'estimatedWaitTime' => 0,
				'vaardigheid' => $vaardigheid,
				'candidatePool' => 0,
			];
		}

		$available = array_filter(
			$candidates,
			static function (array $c): bool {
				$status = mb_strtolower((string)($c['status'] ?? ''));
				return in_array($status, self::AVAILABLE_STATUSES, true);
			}
		);

		// No-one available: overflow check.
		if (count($available) === 0) {
			$minQueue = $this->minQueueLength(pool: $candidates);
			$estWait = $minQueue * $this->avgHandlingDuration(pool: $candidates);
			$overflow = ($estWait > $overflowWachttijd) || ($minQueue > $maxQueueLengte);

			return [
				'destinationSpecialistId' => null,
				'escalatieFlag' => $overflow,
				'estimatedWaitTime' => $estWait,
				'vaardigheid' => $vaardigheid,
				'candidatePool' => count($candidates),
			];
		}

		usort(
			$available,
			static function (array $a, array $b): int {
				$queueA = (int)($a['currentQueueLength'] ?? 0);
				$queueB = (int)($b['currentQueueLength'] ?? 0);
				if ($queueA !== $queueB) {
					return $queueA <=> $queueB;
				}

				$durationA = (int)($a['averageHandlingDuration'] ?? 0);
				$durationB = (int)($b['averageHandlingDuration'] ?? 0);
				return $durationA <=> $durationB;
			}
		);

		$picked = $available[0];
		$picked['currentQueueLength'] = (int)($picked['currentQueueLength'] ?? 0);
		$picked['averageHandlingDuration'] = (int)($picked['averageHandlingDuration'] ?? 0);

		return [
			'destinationSpecialistId' => (string)($picked['employeeId'] ?? ($picked['id'] ?? '')),
			'escalatieFlag' => false,
			'estimatedWaitTime' => $picked['currentQueueLength'] * $picked['averageHandlingDuration'],
			'vaardigheid' => $vaardigheid,
			'candidatePool' => count($candidates),
		];
	}//end routeCall()

	/**
	 * Normalise a phone number: keep digits + leading +.
	 *
	 * @param string $phoneNumber Input.
	 *
	 * @return string Normalised form.
	 */
	private function normalisePhone(string $phoneNumber): string {
		$clean = preg_replace('/[^0-9+]/', '', $phoneNumber);
		return $clean ?? '';
	}//end normalisePhone()

	/**
	 * Filter the pool to specialists having the requested vaardigheid.
	 *
	 * @param array<int, array<string, mixed>> $pool Pool snapshot.
	 * @param string $vaardigheid Required skill.
	 *
	 * @return array<int, array<string, mixed>> The matching candidates.
	 */
	private function filterCandidates(array $pool, string $vaardigheid): array {
		$candidates = [];
		$needle = mb_strtolower($vaardigheid);
		foreach ($pool as $sp) {
			$skills = $sp['expertises'] ?? ($sp['vaardigheden'] ?? []);
			if (is_array($skills) === false) {
				continue;
			}

			foreach ($skills as $skill) {
				if (mb_strtolower((string)$skill) === $needle) {
					$candidates[] = $sp;
					break;
				}
			}
		}

		return $candidates;
	}//end filterCandidates()

	/**
	 * Smallest queue length across a pool.
	 *
	 * @param array<int, array<string, mixed>> $pool The pool.
	 *
	 * @return int The min queue length.
	 */
	private function minQueueLength(array $pool): int {
		$min = PHP_INT_MAX;
		foreach ($pool as $sp) {
			$queue = (int)($sp['currentQueueLength'] ?? 0);
			if ($queue < $min) {
				$min = $queue;
			}
		}

		if ($min === PHP_INT_MAX) {
			return 0;
		}

		return $min;
	}//end minQueueLength()

	/**
	 * Average behandelduur across a pool (seconds).
	 *
	 * @param array<int, array<string, mixed>> $pool The pool.
	 *
	 * @return int The average duur.
	 */
	private function avgHandlingDuration(array $pool): int {
		if (count($pool) === 0) {
			return 0;
		}

		$sum = 0;
		foreach ($pool as $sp) {
			$sum += (int)($sp['averageHandlingDuration'] ?? 0);
		}

		return (int)round($sum / count($pool));
	}//end avgBehandelduur()
}//end class
