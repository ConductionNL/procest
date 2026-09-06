<?php

/**
 * Dossiq besluitvormingPublish action handler.
 *
 * Wires the DROP/LVBB publication dispatcher into the workflow engine. When a
 * case enters the "Bekendmaking" status step, this auto-action invokes
 * PublicationService::dispatch(). Per the spec a failed dispatch MUST NOT roll
 * back the status change — the handler always returns a static ActionResult and
 * the failure is logged on the case for manual retry.
 *
 * Action config shape: `{type: 'besluitvormingPublish'}`.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
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
 * @spec openspec/specs/besluitvorming-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Service\PublicationService;
use Psr\Log\LoggerInterface;

/**
 * Auto-action handler that dispatches a besluit to DROP/LVBB.
 *
 * @spec openspec/specs/besluitvorming-workflow/spec.md
 */
class BesluitvormingPublishHandler implements ActionHandlerInterface {
	/**
	 * Constructor.
	 *
	 * @param PublicationService $publicationService The DROP/LVBB dispatcher.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PublicationService $publicationService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle the besluitvormingPublish action.
	 *
	 * @param array<string, mixed> $actionConfig Action configuration.
	 * @param array<string, mixed> $case Case object.
	 * @param array<string, mixed> $transitionContext Transition context.
	 *
	 * @return ActionResult
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/besluitvorming-workflow/spec.md
	 */
	public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult {
		try {
			$caseId = (string)($case['id'] ?? $case['uuid'] ?? '');
			if ($caseId === '') {
				return new ActionResult(succeeded: false, error: 'no_case_id');
			}

			// The dispatcher records its outcome on the case via
			// ObjectService. On the flow path the engine's
			// RegistryStepDispatcher already runs this handler inside
			// `ObjectService::runAs()` as the run's acting identity
			// (openregister#3332); on the interactive path the ambient session
			// user answers the permission checks. No local wrap needed.
			$result = $this->publicationService->publish($caseId, ['channel' => 'website']);
			if (($result['ok'] ?? false) === true) {
				return new ActionResult(succeeded: true, data: $result);
			}

			// Failure does not block the transition; surface for manual retry.
			return new ActionResult(
				succeeded: false,
				error: (string)($result['error'] ?? 'publication_failed'),
				data: $result,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'BesluitvormingPublishHandler failed',
				['exception' => $e->getMessage(), 'context' => $transitionContext],
			);
			return new ActionResult(succeeded: false, error: 'publication_failed');
		}//end try
	}//end handle()
}//end class
