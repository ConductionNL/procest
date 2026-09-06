<?php

/**
 * Dossiq KCC Routing Engine
 *
 * Evaluates KCC routing rules against a contact moment to determine the
 * destination domain/team, and ranks candidate agents by workload, skill
 * match and contact continuity. The matching and ranking logic is pure and
 * deterministic so it can be exercised by unit tests without OpenRegister.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-03
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Kcc;

use DateTimeImmutable;

/**
 * Deterministic routing-rule evaluation and agent ranking for the KCC.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-03
 */
class RoutingEngine {
	/**
	 * Evaluate routing rules against a contact moment.
	 *
	 * Rules are evaluated in ascending priority order; the FIRST enabled rule
	 * whose conditions all match wins. Returns the matched rule plus the
	 * resolved domain/team, or null when nothing matches.
	 *
	 * DEPRECATED (kcc-routing-onto-or-decision-tables): runtime routing goes
	 * through {@see RoutingTableEvaluator}, which compiles these rules onto
	 * OpenRegister's shared decision-table evaluator. This method stays ONLY
	 * as the parity oracle — KccRoutingParityTest drives both paths over one
	 * fixture matrix — until the staged retirement in that change's tasks.
	 * Do not add new callers. Agent ranking below is NOT deprecated.
	 *
	 * @param array<int, array<string, mixed>> $rules Routing rules.
	 * @param array<string, mixed> $contactMoment The contact moment.
	 * @param \DateTimeImmutable|null $now Reference time (for time-of-day rules).
	 *
	 * @return array<string, mixed>|null The routing result, or null when unmatched.
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-03
	 */
	public function evaluate(array $rules, array $contactMoment, ?\DateTimeImmutable $now = null): ?array {
		$now = ($now ?? new DateTimeImmutable());

		$enabled = array_values(
			array_filter(
				$rules,
				static function (array $rule): bool {
					return (($rule['enabled'] ?? true) === true);
				}
			)
		);

		usort(
			$enabled,
			static function (array $first, array $second): int {
				return (((int)($first['priority'] ?? 0)) <=> ((int)($second['priority'] ?? 0)));
			}
		);

		foreach ($enabled as $rule) {
			if ($this->ruleMatches(rule: $rule, contactMoment: $contactMoment, now: $now) === true) {
				return [
					'rule' => ($rule['name'] ?? ''),
					'assignedDomain' => ($rule['assignedDomain'] ?? ''),
					'assignedTeam' => ($rule['assignedTeam'] ?? ''),
					'escalationTeam' => ($rule['escalationTeam'] ?? ''),
				];
			}
		}

		return null;
	}//end evaluate()

	/**
	 * Determine whether every condition of a rule matches the contact moment.
	 *
	 * @param array<string, mixed> $rule The routing rule.
	 * @param array<string, mixed> $contactMoment The contact moment.
	 * @param \DateTimeImmutable $now Reference time.
	 *
	 * @return bool True when all conditions match.
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-03
	 */
	public function ruleMatches(array $rule, array $contactMoment, \DateTimeImmutable $now): bool {
		$conditions = ($rule['matchConditions'] ?? []);
		if (is_array($conditions) === false || $conditions === []) {
			return false;
		}

		$haystack = strtolower(
			trim(
				((string)($contactMoment['subject'] ?? '')) . ' ' . ((string)($contactMoment['summary'] ?? ''))
			)
		);

		foreach ($conditions as $condition) {
			if (is_array($condition) === false) {
				return false;
			}

			if ($this->conditionMatches(condition: $condition, contactMoment: $contactMoment, haystack: $haystack, now: $now) === false) {
				return false;
			}
		}

		return true;
	}//end ruleMatches()

	/**
	 * Evaluate a single routing condition.
	 *
	 * @param array<string, mixed> $condition The condition.
	 * @param array<string, mixed> $contactMoment The contact moment.
	 * @param string $haystack Lower-cased subject + summary.
	 * @param \DateTimeImmutable $now Reference time.
	 *
	 * @return bool True when the condition matches.
	 */
	private function conditionMatches(array $condition, array $contactMoment, string $haystack, \DateTimeImmutable $now): bool {
		$type = (string)($condition['type'] ?? '');
		$value = (string)($condition['value'] ?? '');

		switch ($type) {
			case 'keyword':
				return (str_contains($haystack, strtolower($value)) === true);
			case 'regex':
				// Anchor-free, case-insensitive match. Delimiters are added
				// here so rule authors never inject raw delimiters.
				$pattern = '/' . str_replace('/', '\/', $value) . '/i';
				return (preg_match($pattern, $haystack) === 1);
			case 'channel':
				return (((string)($contactMoment['channel'] ?? '')) === $value);
			case 'customer_type':
				return ($this->customerType(contactMoment: $contactMoment) === $value);
			case 'time_of_day':
				return $this->timeOfDayMatches(value: $value, now: $now);
			case 'day_of_week':
				return (strtolower($now->format('l')) === strtolower($value));
			default:
				return false;
		}//end switch
	}//end conditionMatches()

	/**
	 * Derive the customer type from the contact moment.
	 *
	 * An 8-digit numeric customerRef is treated as a KvK number (bedrijf);
	 * any other non-empty reference is a burger; empty is anonymous.
	 *
	 * @param array<string, mixed> $contactMoment The contact moment.
	 *
	 * @return string One of 'bedrijf', 'burger', 'anoniem'.
	 */
	private function customerType(array $contactMoment): string {
		$ref = trim((string)($contactMoment['customerRef'] ?? ''));
		if ($ref === '') {
			return 'anoniem';
		}

		if (preg_match('/^\d{8}$/', $ref) === 1) {
			return 'bedrijf';
		}

		return 'burger';
	}//end customerType()

	/**
	 * Evaluate a time-of-day condition such as "after_17:00" or "before_09:00".
	 *
	 * @param string $value The condition value.
	 * @param \DateTimeImmutable $now Reference time.
	 *
	 * @return bool True when the time-of-day window matches.
	 */
	private function timeOfDayMatches(string $value, \DateTimeImmutable $now): bool {
		if (preg_match('/^(after|before)_(\d{1,2}):(\d{2})$/', $value, $matches) !== 1) {
			return false;
		}

		$boundary = ((int)$matches[2] * 60) + (int)$matches[3];
		$current = ((int)$now->format('G') * 60) + (int)$now->format('i');

		if ($matches[1] === 'after') {
			return ($current >= $boundary);
		}

		return ($current < $boundary);
	}//end timeOfDayMatches()

	/**
	 * Rank candidate agents for an assigned team.
	 *
	 * Agents are scored on availability (must be available), workload
	 * (lower is better), skill match against the routing domain/tags, and
	 * recent-contact continuity with the same customer. Returns at most
	 * $limit candidates, each annotated with a human-readable motivation.
	 *
	 * @param array<int, array<string, mixed>> $agents Candidate agents.
	 * @param string $team The assigned team.
	 * @param array<string, mixed> $contactMoment The contact moment.
	 * @param int $limit Maximum results.
	 *
	 * @return array<int, array<string, mixed>> Ranked agents with motivation.
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-03
	 */
	public function rankAgents(array $agents, string $team, array $contactMoment, int $limit = 3): array {
		$domain = strtolower((string)($contactMoment['assignedDomain'] ?? ''));
		$tags = array_map('strtolower', (array)($contactMoment['tags'] ?? []));
		$customerRef = (string)($contactMoment['customerRef'] ?? '');

		$candidates = array_values(
			array_filter(
				$agents,
				static function (array $agent) use ($team): bool {
					return (($agent['currentStatus'] ?? 'offline') === 'available'
						&& ($team === '' || ((string)($agent['team'] ?? '')) === $team));
				}
			)
		);

		$scored = [];
		foreach ($candidates as $agent) {
			$scored[] = $this->scoreAgent(agent: $agent, domain: $domain, tags: $tags, customerRef: $customerRef);
		}

		usort(
			$scored,
			static function (array $first, array $second): int {
				return ($first['score'] <=> $second['score']);
			}
		);

		$result = [];
		foreach (array_slice($scored, 0, max(0, $limit)) as $entry) {
			$agent = $entry['agent'];
			$result[] = [
				'userRef' => (string)($agent['userRef'] ?? ''),
				'workload' => $entry['workload'],
				'skills' => (array)($agent['skills'] ?? []),
				'skillMatch' => $entry['skillMatch'],
				'continuity' => $entry['continuity'],
				'motivation' => $this->motivation(agent: $agent, entry: $entry),
			];
		}

		return $result;
	}//end rankAgents()

	/**
	 * Score a single candidate agent for ranking.
	 *
	 * Lower score sorts first: workload dominates, a skill match and recent
	 * contact continuity each reduce the effective score.
	 *
	 * @param array<string, mixed> $agent The candidate agent.
	 * @param string $domain Lower-cased routing domain.
	 * @param array<int, string> $tags Lower-cased contact tags.
	 * @param string $customerRef The contact's customer reference.
	 *
	 * @return array<string, mixed> The scored entry.
	 */
	private function scoreAgent(array $agent, string $domain, array $tags, string $customerRef): array {
		$skills = array_map('strtolower', (array)($agent['skills'] ?? []));
		$skillMatch = (in_array($domain, $skills, true) === true);
		if ($skillMatch === false) {
			$skillMatch = (array_intersect($tags, $skills) !== []);
		}

		$continuity = ($customerRef !== ''
			&& ((string)($agent['lastContactCustomerRef'] ?? '')) === $customerRef);

		$workload = (int)($agent['currentWorkload'] ?? 0);

		$score = $workload;
		if ($skillMatch === true) {
			$score -= 100;
		}

		if ($continuity === true) {
			$score -= 50;
		}

		return [
			'agent' => $agent,
			'score' => $score,
			'skillMatch' => $skillMatch,
			'continuity' => $continuity,
			'workload' => $workload,
		];
	}//end scoreAgent()

	/**
	 * Build a human-readable motivation string for an agent suggestion.
	 *
	 * @param array<string, mixed> $agent The agent.
	 * @param array<string, mixed> $entry The scored entry.
	 *
	 * @return string The motivation.
	 */
	private function motivation(array $agent, array $entry): string {
		$parts = [];
		$parts[] = $entry['workload'] . ' open zaken';

		$skills = (array)($agent['skills'] ?? []);
		if ($skills !== []) {
			$parts[] = implode(', ', $skills);
		}

		if ($entry['continuity'] === true) {
			$parts[] = 'eerder contact gehad';
		}

		return ((string)($agent['userRef'] ?? '')) . ': ' . implode(' - ', $parts);
	}//end motivation()
}//end class
