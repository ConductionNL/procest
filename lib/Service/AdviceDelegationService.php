<?php

/**
 * Dossiq Advice Delegation Service
 *
 * Delegates advice/consultation decisions to decidesk via the decidesk
 * `DecisionRequestedEvent` (IEventDispatcher). Reused by three dossiq advice surfaces — the
 * BAC (bezwaarschriftencommissie) advice, the general adviesAanvraag, and the
 * consultatie/zienswijze — each of which maps to a decidesk `advice`
 * decisionType (ADR-005). dossiq keeps its domain rules (BAC
 * panel-independence, advice IDOR gate) and receives the outcome as a
 * projection via decidesk's `DecisionConcludedEvent` (consumed by
 * {@see \OCA\Dossiq\Listener\DecisionConcludedListener}); decidesk owns the
 * *making* of the advice.
 *
 * This is a thin sibling of ContractDecisionDelegationService — it reuses that
 * service's shared raiseDecision (event-dispatch) core and only fixes the
 * decisionType + provenance. It does NOT add a second delegation mechanism.
 *
 * FAILS CLOSED when the decidesk leaf is unavailable (never auto-advises).
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/remaining-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * Raises and consumes decidesk `advice` Decisions.
 *
 * @spec openspec/specs/remaining-decision-delegation/spec.md
 */
class AdviceDelegationService {
	/**
	 * Constructor.
	 *
	 * @param ContractDecisionDelegationService $core Shared event-dispatch raiseDecision core.
	 */
	public function __construct(
		private readonly ContractDecisionDelegationService $core,
	) {
	}//end __construct()

	/**
	 * Raise a decidesk `advice` Decision for a BAC / adviesAanvraag / consultatie request.
	 *
	 * The caller MUST have run its dossiq domain rule (BAC panel-independence,
	 * the advice IDOR gate) BEFORE invoking this. FAILS CLOSED when the
	 * decidesk leaf is unavailable.
	 *
	 * @param string $subjectSchema The dossiq subject schema (bacAdviceRequest, adviesAanvraag, consultation).
	 * @param string $subjectId The subject object UUID.
	 * @param array<string,mixed> $payload Advice context + provenance: subjectRegister,
	 *                                     subjectLabel, externalReference, question,
	 *                                     adviceType, etc.
	 *
	 * @return string The decidesk decisionRef (UUID) to persist on the case.
	 *
	 * @throws \RuntimeException When the decidesk leaf is unavailable or the Decision could not be created.
	 *
	 * @spec openspec/specs/remaining-decision-delegation/spec.md
	 * @spec openspec/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-002-delegation-fails-closed-when-decidesk-is-unavailable
	 */
	public function raiseAdviceDecision(string $subjectSchema, string $subjectId, array $payload = []): string {
		return $this->core->raiseDecision(
			decisionType: ContractDecisionDelegationService::DECISION_TYPE_ADVICE,
			externalReference: (string)($payload['externalReference'] ?? $subjectId),
			subject: [
				'subjectRegister' => (string)($payload['subjectRegister'] ?? ''),
				'subjectSchema' => $subjectSchema,
				'subjectId' => $subjectId,
				'subjectLabel' => (string)($payload['subjectLabel'] ?? ''),
			],
			context: [
				'question' => (string)($payload['question'] ?? ''),
				'adviceType' => (string)($payload['adviceType'] ?? ''),
				'advisor' => (string)($payload['advisor'] ?? ''),
			],
		);
	}//end raiseAdviceDecision()
}//end class
