<?php

/**
 * Dossiq GuardFailedException.
 *
 * Thrown by `StatusTransitionService::execute()` when server-side guard
 * re-evaluation rejects the transition (REQ-STE-4-002). Carries the list of
 * failed guard snapshots so the caller can render specific messages.
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

use RuntimeException;

/**
 * Exception thrown when one or more guards reject a transition.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T10
 */
class GuardFailedException extends RuntimeException {

	/**
	 * Snapshots of failed guard evaluations.
	 *
	 * @var array<int, array{type: string, passed: bool, failureMessage: ?string, details: array<string, mixed>}>
	 */
	private array $failedGuards;

	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>> $failedGuards The failed guard snapshots
	 * @param string $message Static engine-side message
	 */
	public function __construct(array $failedGuards, string $message = 'guard_failed') {
		parent::__construct(message: $message);
		$this->failedGuards = $failedGuards;
	}//end __construct()

	/**
	 * Get the failed guard snapshots.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/status-transition-engine/tasks.md#T10
	 */
	public function getFailedGuards(): array {
		return $this->failedGuards;
	}//end getFailedGuards()
}//end class
