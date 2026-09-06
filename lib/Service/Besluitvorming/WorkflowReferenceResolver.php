<?php

/**
 * Dossiq WorkflowReferenceResolver.
 *
 * Rewrites the name-based cross-references inside a besluitvorming template's
 * workflowTemplate payload into the UUIDs of the statusType and roleType
 * records that were just created for the bundle.
 *
 * A shipped template bundle cannot know the ids its records will get, so it
 * refers to them by name (`statusName`, `fromStatusName`, `toStatusName`, a
 * roleGuard's `config.roleName`). Split out of BesluitvormingTemplateService
 * so that service keeps only the activation orchestration: this name→id
 * rewrite, and the wildcard `*` transition convention that survives it, live
 * here and nowhere else.
 *
 * Pure transformation — nothing is read from or written to OpenRegister.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Besluitvorming
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * @spec openspec/specs/besluitvorming-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Besluitvorming;

/**
 * Resolves workflow step/transition name references to created UUIDs.
 *
 * @spec openspec/specs/besluitvorming-workflow/spec.md
 */
class WorkflowReferenceResolver {
	/**
	 * Resolve workflow step/transition name references to created UUIDs.
	 *
	 * @param array<string, mixed> $workflowData The raw workflow template payload.
	 * @param array<string, string> $statusNameMap Map of statusType name => id.
	 * @param array<string, string> $roleNameMap Map of roleType name => id.
	 * @param string $caseTypeId The owning caseType id.
	 *
	 * @return array<string, mixed> The workflow payload with resolved references.
	 *
	 * @spec openspec/specs/besluitvorming-workflow/spec.md
	 */
	public function resolveWorkflowReferences(
		array $workflowData,
		array $statusNameMap,
		array $roleNameMap,
		string $caseTypeId,
	): array {
		$workflowData['caseType'] = $caseTypeId;

		$workflowData['steps'] = json_encode(
			$this->resolveWorkflowSteps(
				steps: (array)($workflowData['steps'] ?? []),
				statusNameMap: $statusNameMap,
			)
		);

		$workflowData['transitions'] = json_encode(
			$this->resolveWorkflowTransitions(
				transitions: (array)($workflowData['transitions'] ?? []),
				statusNameMap: $statusNameMap,
				roleNameMap: $roleNameMap,
			)
		);

		return $workflowData;
	}//end resolveWorkflowReferences()

	/**
	 * Resolve the statusName reference on every workflow step.
	 *
	 * @param array<int, mixed> $steps The raw workflow steps.
	 * @param array<string, string> $statusNameMap Map of statusType name => id.
	 *
	 * @return array<int, array<string, mixed>> The resolved steps.
	 *
	 * @spec openspec/specs/besluitvorming-workflow/spec.md
	 */
	private function resolveWorkflowSteps(array $steps, array $statusNameMap): array {
		$resolvedSteps = [];
		foreach ($steps as $step) {
			if (is_array($step) === false) {
				continue;
			}

			$statusName = (string)($step['statusName'] ?? '');
			unset($step['statusName']);
			$step['id'] = $this->generateUUID();
			$step['status'] = ($statusNameMap[$statusName] ?? '');
			$resolvedSteps[] = $step;
		}//end foreach

		return $resolvedSteps;
	}//end resolveWorkflowSteps()

	/**
	 * Resolve the status and role references on every workflow transition.
	 *
	 * @param array<int, mixed> $transitions The raw workflow transitions.
	 * @param array<string, string> $statusNameMap Map of statusType name => id.
	 * @param array<string, string> $roleNameMap Map of roleType name => id.
	 *
	 * @return array<int, array<string, mixed>> The resolved transitions.
	 *
	 * @spec openspec/specs/besluitvorming-workflow/spec.md
	 */
	private function resolveWorkflowTransitions(
		array $transitions,
		array $statusNameMap,
		array $roleNameMap,
	): array {
		$resolvedTransitions = [];
		foreach ($transitions as $transition) {
			if (is_array($transition) === false) {
				continue;
			}

			$fromName = (string)($transition['fromStatusName'] ?? '');
			$toName = (string)($transition['toStatusName'] ?? '');
			unset($transition['fromStatusName'], $transition['toStatusName']);

			$transition['id'] = $this->generateUUID();
			$transition['fromStatus'] = ($statusNameMap[$fromName] ?? '');
			if ($fromName === '*') {
				$transition['fromStatus'] = '*';
			}

			$transition['toStatus'] = ($statusNameMap[$toName] ?? '');
			$transition['guards'] = $this->resolveTransitionGuards(
				guards: (array)($transition['guards'] ?? []),
				roleNameMap: $roleNameMap,
			);
			$transition['automaticActions'] = $this->resolveTransitionActions(
				actions: (array)($transition['automaticActions'] ?? []),
				roleNameMap: $roleNameMap,
			);

			$resolvedTransitions[] = $transition;
		}//end foreach

		return $resolvedTransitions;
	}//end resolveWorkflowTransitions()

	/**
	 * Resolve the roleName reference on every roleGuard of a transition.
	 *
	 * @param array<int, mixed> $guards The raw transition guards.
	 * @param array<string, string> $roleNameMap Map of roleType name => id.
	 *
	 * @return array<int, mixed> The resolved guards.
	 *
	 * @spec openspec/specs/besluitvorming-workflow/spec.md
	 */
	private function resolveTransitionGuards(array $guards, array $roleNameMap): array {
		$resolvedGuards = [];
		foreach ($guards as $guard) {
			if (is_array($guard) === true
				&& ($guard['type'] ?? '') === 'roleGuard'
				&& isset($guard['config']['roleName']) === true
			) {
				$guard['config']['roleId'] = ($roleNameMap[$guard['config']['roleName']] ?? '');
			}

			$resolvedGuards[] = $guard;
		}//end foreach

		return $resolvedGuards;
	}//end resolveTransitionGuards()

	/**
	 * Resolve the roleName reference on every automatic action of a transition.
	 *
	 * An action that names a role by NAME is inert once the seed has run,
	 * because the engine addresses roles by id — the same shape of silence
	 * a roleGuard suffers, one field along.
	 *
	 * @param array<int, mixed>     $actions     The raw automatic actions.
	 * @param array<string, string> $roleNameMap Map of roleType name => id.
	 *
	 * @return array<int, mixed> The resolved actions.
	 *
	 * @spec openspec/specs/besluitvorming-workflow/spec.md
	 */
	private function resolveTransitionActions(array $actions, array $roleNameMap): array {
		$resolved = [];
		foreach ($actions as $action) {
			if (is_array($action) === false) {
				$resolved[] = $action;
				continue;
			}

			if (isset($action['config']['roleName']) === true) {
				$action['config']['roleId'] = ($roleNameMap[$action['config']['roleName']] ?? '');
			}

			$resolved[] = $action;
		}//end foreach

		return $resolved;
	}//end resolveTransitionActions()

	/**
	 * Generate a UUID v4 string.
	 *
	 * @return string A new UUID.
	 *
	 * @spec openspec/specs/besluitvorming-workflow/spec.md
	 */
	private function generateUUID(): string {
		$data = random_bytes(16);
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40);
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}//end generateUUID()
}//end class
