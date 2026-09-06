<?php

/**
 * Both directions of "which status is this?".
 *
 * A status has two identities: the uuid the case stores, and the name a person
 * reads. Code needs to go both ways — a detail panel turns the id into a name,
 * and a SHIPPED flow can only carry the name, because statusType uuids are
 * minted per installation.
 *
 * They live together because they are one question asked from two sides, and
 * because keeping them apart is how the two ends of it drift: this class reads
 * `statusTypes` and its older spelling `statusses` in ONE place, so a case type
 * whose statuses resolve in one direction cannot silently fail in the other.
 *
 * Split out of {@see CaseStatusStore} when adding the name→id direction pushed
 * that class past its complexity ceiling. The store still exposes
 * `lookupStatusName()` and delegates here, so no caller moved.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Service\SettingsService;
use Throwable;

/**
 * Resolves a statusType by id or by name within a case type.
 *
 * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
 */
class StatusTypeLookup {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Resolves the object service and configured schemas.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * A statusType's human-readable name.
	 *
	 * @param string $statusTypeId StatusType UUID.
	 *
	 * @return string The name, or the empty string when unresolvable.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
	 */
	public function nameFor(string $statusTypeId): string {
		if ($statusTypeId === '') {
			return '';
		}

		$statusType = $this->read(schemaKey: 'status_type_schema', id: $statusTypeId);

		return (string)($statusType['name'] ?? ($statusType['title'] ?? ''));
	}//end nameFor()

	/**
	 * A case type's statusType id, by name.
	 *
	 * Comparison is trimmed and case-insensitive, because the name is authored
	 * by hand in seed data and in the UI. It is NOT fuzzy beyond that: a near
	 * miss returns the empty string so the caller can refuse, rather than
	 * silently moving a case to whichever status looked closest.
	 *
	 * Scoped to the case's OWN type, which is what stops it matching a
	 * same-named status belonging to a different one.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 * @param string $statusName The status's name, as authored.
	 *
	 * @return string The statusType UUID, or '' when there is no such status.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
	 */
	public function idForName(string $caseTypeId, string $statusName): string {
		$wanted = strtolower(trim($statusName));
		if ($caseTypeId === '' || $wanted === '') {
			return '';
		}

		foreach ($this->statusesOf(caseTypeId: $caseTypeId) as $id => $name) {
			if (strtolower(trim($name)) === $wanted) {
				return (string)$id;
			}
		}

		return '';
	}//end idForName()

	/**
	 * A case type's statusType id, by the ROLE the status plays.
	 *
	 * WHY A ROLE AND NOT A NAME. A shipped flow cannot carry a statusType uuid,
	 * so it named the status instead — and a name is not an identifier either:
	 *
	 * - `statusType.name` is declared `x-translatable`, so the same status is
	 *   "In behandeling" on one instance and "In progress" on another. A flow
	 *   matching a literal is broken by translation alone.
	 * - Every case type spells its working phase differently and all of them are
	 *   right: a subsidy is under `Beoordeling`, a complaint under `Onderzoek`,
	 *   a permit `In behandeling`. Measured on the shipped seeds, the literal
	 *   "In behandeling" exists on 2 of 4 demo case types, which is why 8 of 18
	 *   demo runs died at `status_not_found_on_case_type`.
	 *
	 * A role is a machine key, untranslated and authored per case type, so it
	 * survives both. Names remain the fallback, so a case type nobody has
	 * annotated behaves exactly as it did before.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 * @param string $role       The role: intake, pending-info, in-progress, review, closed, stranded.
	 *
	 * @return string The statusType UUID, or '' when this case type models no such role.
	 *
	 * @spec openspec/changes/case-flow-status-roles/specs/status-transition-engine/spec.md
	 */
	public function idForRole(string $caseTypeId, string $role): string {
		$wanted = strtolower(trim($role));
		if ($caseTypeId === '' || $wanted === '') {
			return '';
		}

		// Lowest `order` wins when a case type gives two statuses the same role
		// — a three-phase inspection whose every phase is `in-progress`, say.
		// Deterministic rather than "whichever the store returned first",
		// because a flow that lands on a different phase between two runs of
		// the same case type is the kind of thing nobody reproduces.
		$best = '';
		$bestOrder = null;
		foreach ($this->statusRowsFor(caseTypeId: $caseTypeId) as $row) {
			$id = (string)($row['id'] ?? ($row['uuid'] ?? ''));
			if ($id === '' || strtolower(trim((string)($row['role'] ?? ''))) !== $wanted) {
				continue;
			}

			$order = (int)($row['order'] ?? 0);
			if ($bestOrder === null || $order < $bestOrder) {
				$best = $id;
				$bestOrder = $order;
			}
		}

		return $best;
	}//end idForRole()

	/**
	 * A case type's statuses as `id => name`.
	 *
	 * 🔴 THE LINK LIVES ON THE CHILD, NOT THE PARENT. `caseType` has no
	 * `statusTypes` property — every `statusType` carries a `caseType`
	 * back-reference instead (see how TemplateLibraryService creates them:
	 * `$statusData['caseType'] = $caseTypeId`). An earlier version of this
	 * method read `caseType['statusTypes']`, which does not exist, so it always
	 * returned an empty map — meaning `idForName()` always returned '' and
	 * `SetStatusHandler` refused EVERY status move. The flow could never move a
	 * case, and every unit test passed, because the fixtures were written to
	 * match the assumption rather than the schema. Found by the e2e.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array<string, string> The statuses, keyed by id.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
	 */
	public function statusesOf(string $caseTypeId): array {
		if (trim($caseTypeId) === '') {
			return [];
		}

		$resolved = [];
		foreach ($this->statusRowsFor(caseTypeId: $caseTypeId) as $row) {
			$id = (string)($row['id'] ?? ($row['uuid'] ?? ''));
			$name = (string)($row['name'] ?? ($row['title'] ?? ''));

			if ($id === '' || $name === '') {
				continue;
			}

			$resolved[$id] = $name;
		}

		return $resolved;
	}//end statusesOf()

	/**
	 * The raw statusType rows belonging to one case type.
	 *
	 * Filtered SERVER-side on the back-reference. Fetching every status type and
	 * filtering here would drop rows the first page did not contain, and would
	 * match a same-named status belonging to another case type.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
	 */
	private function statusRowsFor(string $caseTypeId): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$statusTypeSchema = $this->settingsService->getConfigValue(key: 'status_type_schema');
		if ($register === '' || $statusTypeSchema === '') {
			return [];
		}

		try {
			$found = $objectService->searchObjects(
				[
					'@self' => ['register' => $register, 'schema' => $statusTypeSchema],
					'caseType' => $caseTypeId,
					'_limit' => 200,
				]
			);
		} catch (Throwable $e) {
			return [];
		}

		return $this->asRows(value: $found);
	}//end statusRowsFor()

	/**
	 * Normalise whatever the object store returned into plain rows.
	 *
	 * The store answers with either a bare list or a paged envelope, and each
	 * row as an array or an entity. Reading only one of those shapes is how a
	 * lookup silently finds nothing on an instance that answers the other way.
	 *
	 * @param mixed $value The search result.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
	 */
	private function asRows(mixed $value): array {
		if (is_array($value) === true && isset($value['results']) === true) {
			$value = $value['results'];
		}

		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach ($value as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end asRows()

	/**
	 * Read one object from a configured schema.
	 *
	 * @param string $schemaKey The settings key naming the schema.
	 * @param string $id        The object's id.
	 *
	 * @return array<string, mixed> The object, or an empty array when unreadable.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
	 */
	private function read(string $schemaKey, string $id): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: $schemaKey);
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$found = $objectService->find($id, register: $register, schema: $schema);
		} catch (Throwable $e) {
			return [];
		}

		if (is_object($found) === true && method_exists($found, 'jsonSerialize') === true) {
			$found = $found->jsonSerialize();
		}

		if (is_array($found) === false) {
			return [];
		}

		return $found;
	}//end read()
}//end class
