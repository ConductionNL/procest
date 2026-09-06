<?php

/**
 * Dossiq Transition Spec Reader.
 *
 * Reads the shapes a workflowTemplate transition may legally take. Split out
 * of StatusTransitionService so that service keeps only the engine logic: the
 * knowledge that a transition may carry its guards as `guards: []` or as a
 * bare `allowedRoles: []` to be promoted into a roleGuard, that its actions
 * may be spelled `automaticActions` or `actions`, and that a silently-failing
 * roleGuard hides a transition rather than reporting it — all of that
 * tolerance for template dialects lives here and nowhere else.
 *
 * Stateless and dependency-free: every method is a pure function of the
 * transition (or guard-evaluation) array it is handed.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

/**
 * Normalises the guard, action and role-visibility shapes of a transition.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class TransitionSpecReader {
	/**
	 * Extract the guards list from a transition definition (supports both
	 * `guards: []` and a single `guard: {...}` shape).
	 *
	 * @param array<string, mixed> $transition The transition.
	 *
	 * @return array<int, array<string, mixed>> The normalised guard list.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function extractGuards(array $transition): array {
		$guards = $transition['guards'] ?? [];
		if (is_array($guards) === false) {
			$guards = [];
		}

		// Promote allowedRoles[] on the transition itself into a roleGuard entry.
		$allowedRoles = $transition['allowedRoles'] ?? null;
		if (is_array($allowedRoles) === true && count($allowedRoles) > 0) {
			$guards[] = ['type' => 'roleGuard', 'allowedRoles' => $allowedRoles];
		}

		$list = [];
		foreach ($guards as $guard) {
			if (is_array($guard) === true) {
				$list[] = $this->normaliseGuard(guard: $guard);
			}
		}

		return $list;
	}//end extractGuards()

	/**
	 * Translate one guard entry into the spelling the evaluators read.
	 *
	 * Every evaluator reads its parameters from the TOP LEVEL of the guard
	 * entry, and that is the position the whole system already writes: the
	 * visual workflow editor stores `{type, fieldName}` flat on the
	 * transition, `extractGuards()` itself promotes `allowedRoles` into a flat
	 * roleGuard, and the frontend's own evaluator reads flat. What the editor
	 * does NOT share is the engine's spelling: it stores `fieldName`,
	 * `documentTypeName` and `roleTypeId` where the evaluators read `field`,
	 * `documentType` and `allowedRoles`. Those guards are stored data on
	 * existing installs, so the engine translates them here rather than asking
	 * administrators to re-author their workflows.
	 *
	 * A parameter block nested under `config` is NOT translated. The engine
	 * has one position for guard parameters; teaching it a second would make
	 * two files that look different behave the same, and the next author would
	 * have no way to tell which one the engine actually reads.
	 * {@see GuardRegistry::evaluateAll()} reports such an entry so the no-op is
	 * loud rather than silent.
	 *
	 * @param array<string, mixed> $guard One guard entry.
	 *
	 * @return array<string, mixed> The guard entry in the engine's spelling.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function normaliseGuard(array $guard): array {
		// The visual editor's spellings, mapped onto the engine's.
		foreach (['field' => 'fieldName', 'documentType' => 'documentTypeName'] as $canonical => $editorKey) {
			if (isset($guard[$canonical]) === false && isset($guard[$editorKey]) === true) {
				$guard[$canonical] = $guard[$editorKey];
			}
		}

		$allowed = ($guard['allowedRoles'] ?? null);
		if (is_array($allowed) === true && $allowed !== []) {
			return $guard;
		}

		// A single role, however it is spelled, is a one-entry allow-list.
		foreach (['roleTypeId', 'roleName', 'role', 'requiredRole'] as $singleKey) {
			$single = trim((string)($guard[$singleKey] ?? ''));
			if ($single !== '') {
				$guard['allowedRoles'] = [$single];
				break;
			}
		}

		return $guard;
	}//end normaliseGuard()

	/**
	 * Extract automaticActions[] from a transition definition.
	 *
	 * @param array<string, mixed> $transition The transition.
	 *
	 * @return array<int, array<string, mixed>> The normalised action list.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function extractActions(array $transition): array {
		$actions = $transition['automaticActions'] ?? ($transition['actions'] ?? []);
		if (is_array($actions) === false) {
			return [];
		}

		$list = [];
		foreach ($actions as $action) {
			if (is_array($action) === true) {
				$list[] = $action;
			}
		}

		return $list;
	}//end extractActions()

	/**
	 * Detect whether the role guard has hidden the transition silently.
	 *
	 * @param array<int, array<string, mixed>> $evalResults Guard evaluation snapshots.
	 *
	 * @return bool True when the transition must not be offered at all.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function isRoleHidden(array $evalResults): bool {
		foreach ($evalResults as $entry) {
			if (($entry['type'] ?? '') === 'roleGuard'
				&& $entry['passed'] === false
				&& (($entry['details']['silent'] ?? false) === true)
			) {
				return true;
			}
		}

		return false;
	}//end isRoleHidden()
}//end class
