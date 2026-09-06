<?php

/**
 * Dossiq workflow JSON property codec.
 *
 * The `workflowTemplate` schema stores `steps`, `transitions` and
 * `nodePositions` as STRING properties holding JSON. Every caller that builds a
 * definition row has to encode into that shape, and every caller that reads one
 * has to decode out of it, so the two halves of the coercion live in one place
 * rather than being reimplemented next to each use.
 *
 * Neither half throws. A value that cannot be decoded reads as an empty list,
 * because a malformed `transitions` string is a definition with no transitions
 * and not a reason to abort a publish.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Workflow
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
 * @spec openspec/specs/workflow-definition-model/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Workflow;

/**
 * Encodes into, and decodes out of, the JSON-string properties a
 * workflowTemplate stores.
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */
class WorkflowJsonProperty {

	/**
	 * Coerce a payload property to the JSON string the schema stores. A value
	 * that is already a string is passed through untouched, so a caller handing
	 * over a row it just read does not double-encode it.
	 *
	 * @param mixed $value The raw payload property value.
	 *
	 * @return string|false The JSON string, or false when encoding fails.
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	public function encode(mixed $value): string|false {
		if (is_string($value) === true) {
			return $value;
		}

		return json_encode($value);
	}//end encode()

	/**
	 * Decode a JSON-encoded list property. Returns an empty list on any decoding
	 * error or non-array payload.
	 *
	 * @param mixed $raw The raw property value.
	 *
	 * @return array<int, mixed> The decoded list.
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	public function decodeList(mixed $raw): array {
		if (is_array($raw) === true) {
			return $raw;
		}

		if (is_string($raw) === false || $raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return [];
	}//end decodeList()
}//end class
