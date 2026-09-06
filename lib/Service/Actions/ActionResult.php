<?php

/**
 * Dossiq Automatic Action Result
 *
 * Immutable value object returned by every ActionHandlerInterface::handle()
 * call. Captures whether the action succeeded, an optional static error
 * code, and any handler-specific data (e.g. messageId, documentId, rendered
 * preview payload for dry-run).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Actions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Actions;

/**
 * Result of dispatching a single automatic action.
 *
 * The `error` field MUST be a static machine-readable string (e.g.
 * `webhook_timeout`, `unknown_action_ref`). Handlers MUST NEVER include
 * `$e->getMessage()` or raw exception text here — log the exception via
 * `LoggerInterface::error()` instead.
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */
final class ActionResult {
	/**
	 * Constructor for ActionResult.
	 *
	 * @param bool $succeeded Whether the action completed successfully.
	 * @param string|null $error Static error code on failure, null on success.
	 * @param array $data Handler-specific data (messageId, documentId,
	 *                    rendered preview payload, etc.).
	 * @param array $caseChanges The case fields this action wrote to storage,
	 *                           so the caller can stamp them onto its outgoing
	 *                           case snapshot. Without this a downstream step
	 *                           holds a snapshot that predates the write.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly bool $succeeded,
		public readonly ?string $error = null,
		public readonly array $data = [],
		public readonly array $caseChanges = [],
	) {
	}//end __construct()

	/**
	 * Convert this result to a primitive array for persistence on
	 * `statusRecord.dispatchedActions[]`.
	 *
	 * @return array
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function toArray(): array {
		$out = ['ok' => $this->succeeded];
		if ($this->error !== null) {
			$out['error'] = $this->error;
		}

		if ($this->data !== []) {
			$out['data'] = $this->data;
		}

		return $out;
	}//end toArray()
}//end class
