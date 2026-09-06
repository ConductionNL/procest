<?php

/**
 * Dossiq Action Result value object.
 *
 * Carries the outcome of a dispatched automatic action: success flag,
 * optional static error message (never leak exception detail), and
 * action-specific result data.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

/**
 * Immutable value object returned by every ActionHandler.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T07
 */
final class ActionResult {
	/**
	 * Constructor.
	 *
	 * @param bool $succeeded Whether the action succeeded
	 * @param string|null $error Static error message (no exception detail)
	 * @param array<string, mixed> $data Optional structured data from the action
	 * @param array<string, mixed> $caseChanges The case fields this action wrote to
	 *                                          storage, so the caller can stamp them
	 *                                          onto its outgoing case snapshot.
	 *                                          Without this a downstream step holds
	 *                                          a snapshot that predates the write.
	 */
	public function __construct(
		public readonly bool $succeeded,
		public readonly ?string $error = null,
		public readonly array $data = [],
		public readonly array $caseChanges = [],
	) {
	}//end __construct()
}//end class
