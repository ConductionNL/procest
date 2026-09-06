<?php

/**
 * Dossiq VTH seed row reader.
 *
 * The shape-tolerant half of the VTH seed lookups: OpenRegister search results
 * arrive as plain arrays, as `{results: [...]}` envelopes, or as ObjectEntity
 * instances, and every VTH lookup has to cope with all three. Split out of
 * {@see VthSeedLookup} so the lookups read as queries and this class owns the
 * coercion rules alone.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair\Vth
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
 * @spec openspec/specs/vth-workflow-templates/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair\Vth;

/**
 * Coerces OpenRegister result rows into the shapes the VTH seed needs.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/vth-workflow-templates/spec.md
 */
class VthSeedRowReader {
	/**
	 * Extract the first row id from an OpenRegister result set.
	 *
	 * @param mixed $rows Raw result from searchObjectsAsArrays()
	 *
	 * @return string The first id or empty string
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	public function firstId(mixed $rows): string {
		$row = $this->firstRow(rows: $rows);
		if ($row === null) {
			return '';
		}

		return $this->rowId(row: $row);
	}//end firstId()

	/**
	 * Extract the first usable row from an OpenRegister result set.
	 *
	 * A row without an id is skipped: it cannot be acted on, and treating it as
	 * a hit is how an unusable row passes for a real one.
	 *
	 * @param mixed $rows Raw result from searchObjectsAsArrays()
	 *
	 * @return array<string, mixed>|null The first row carrying an id, or null
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	public function firstRow(mixed $rows): ?array {
		if (is_array($rows) === false) {
			return null;
		}

		// Handle paginated `{ results: [...] }` shape.
		if (isset($rows['results']) === true && is_array($rows['results']) === true) {
			$rows = $rows['results'];
		}

		foreach ($rows as $row) {
			$normalized = $this->normalizeRow(row: $row);
			if ($normalized !== null && $this->rowId(row: $normalized) !== '') {
				return $normalized;
			}
		}

		return null;
	}//end firstRow()

	/**
	 * Reduce statusType rows to a name → UUID map, dropping unusable rows.
	 *
	 * @param array<int, mixed> $rows The raw statusType rows
	 *
	 * @return array<string, string> Map of statusType name to UUID
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	public function statusMap(array $rows): array {
		$map = [];
		foreach ($rows as $row) {
			$normalized = $this->normalizeRow(row: $row);
			if ($normalized === null) {
				continue;
			}

			$name = (string)($normalized['name'] ?? '');
			$id = $this->rowId(row: $normalized);
			if ($name !== '' && $id !== '') {
				$map[$name] = $id;
			}
		}

		return $map;
	}//end statusMap()

	/**
	 * Read the identifier off one result row, whatever shape it arrives in.
	 *
	 * @param mixed $row Result row from ObjectService.
	 *
	 * @return string The row id / uuid, or empty string when unusable.
	 */
	private function rowId(mixed $row): string {
		$normalized = $this->normalizeRow(row: $row);
		if ($normalized === null) {
			return '';
		}

		return (string)($normalized['id'] ?? ($normalized['uuid'] ?? ''));
	}//end rowId()

	/**
	 * Coerce an OpenRegister result row to an associative array.
	 *
	 * @param mixed $row Result row from ObjectService
	 *
	 * @return array<string, mixed>|null
	 */
	private function normalizeRow(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === false) {
			return null;
		}

		if (method_exists($row, 'jsonSerialize') === true) {
			$serialized = $row->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (method_exists($row, 'getId') === true) {
			return ['id' => (string)$row->getId()];
		}

		return null;
	}//end normalizeRow()
}//end class
