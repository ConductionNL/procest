<?php

/**
 * Dossiq Bezwaar Decision Service.
 *
 * Domain service for the bezwaar-decision capability — the formal
 * beslissing op bezwaar under Awb art. 7:11/7:12. Dossiq owns the
 * decision record, the structured rechtsmiddelenclausule, the
 * proceskostenvergoeding bookkeeping, and the publication + notification
 * flow. This service composes those operations and delegates every
 * persistence call to OpenRegister via SettingsService::getObjectService();
 * it never owns bespoke CRUD or a custom controller.
 *
 *  - draft()             — create a bezwaarDecision in status "draft" with
 *                          the canonical Awb 7:11 disposition enum and
 *                          link it to its bezwaar; required fields are
 *                          surface-validated.
 *  - publish()           — promote a draft to published after the
 *                          per-dispositionType mandatory-field guard
 *                          passes (replacementDecision for
 *                          gegrond_wijzigen; appealNotice completeness;
 *                          deviationRationale when advisoryOpinion is set
 *                          and the decision deviates; proceskosten rules
 *                          when herroepen/wijzigen). Sets publishedAt,
 *                          stamps notifiedRecipients, calls
 *                          applyToBezwaar() so the case transitions to
 *                          "Decision on objection" via the
 *                          status-transition-engine.
 *  - applyToBezwaar()    — write the decision back onto the linked
 *                          bezwaar by invoking the
 *                          StatusTransitionService — no bespoke
 *                          transition logic lives here.
 *
 * Identity for the publication actor is ALWAYS derived from
 * IUserSession. Static error messages only — exception details never
 * bubble to controllers.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Bezwaar
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

namespace OCA\Dossiq\Service\Bezwaar;

use OCA\Dossiq\Service\BezwaarDecisionDelegationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Bezwaar decision service: draft, publish, and apply to the linked
 * bezwaar via the status-transition-engine.
 *
 * @spec openspec/specs/bezwaar-decision/spec.md
 */
class DecisionService {

	use SearchesObjects;

	/**
	 * Canonical Awb art. 7:11 disposition values (REQ-BD-2).
	 *
	 * Declared once on {@see DecisionValidator} — the class that enforces
	 * them — and re-exported here for backwards compatibility with
	 * existing consumers of `DecisionService::VALID_DISPOSITIONS`.
	 *
	 * @var array<int, string>
	 */
	public const VALID_DISPOSITIONS = DecisionValidator::VALID_DISPOSITIONS;

	/**
	 * Bezwaar status target on publication (handed off to the
	 * status-transition-engine).
	 */
	private const TARGET_BEZWAAR_STATUS = 'Decision on objection';

	/**
	 * Transition id wired on the bezwaar workflowTemplate to move into
	 * "Decision on objection".
	 */
	private const TRANSITION_ID = 'beslissing-op-bezwaar';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema/register bridge.
	 * @param IUserSession $userSession Acting identity source.
	 * @param StatusTransitionService $transitions Engine used by
	 *                                             applyToBezwaar()
	 *                                             to transition
	 *                                             the linked
	 *                                             bezwaar
	 *                                             without
	 *                                             bespoke
	 *                                             transition
	 *                                             logic.
	 * @param LoggerInterface $logger Logger.
	 * @param BezwaarDecisionDelegationService $decisionDelegation Decision delegation to decidesk (event dispatch).
	 * @param DecisionValidator $validator The Awb validity matrix (REQ-PDRD-004).
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
		private readonly StatusTransitionService $transitions,
		private readonly LoggerInterface $logger,
		private readonly BezwaarDecisionDelegationService $decisionDelegation,
		private readonly DecisionValidator $validator,
	) {
	}//end __construct()

	/**
	 * Create a bezwaarDecision in status "draft".
	 *
	 * Surface-validates the disposition enum and the per-disposition
	 * mandatory-field rules that apply at draft time (canonical enum,
	 * replacementDecision compatibility, reasoning + legalBasis
	 * presence). Hard guards that only apply at publication time
	 * (appealNotice completeness, proceskosten resolution) are
	 * deferred to publish().
	 *
	 * @param string $objectionId UUID of the bezwaar.
	 * @param array<string, mixed> $payload Decision properties.
	 *
	 * @return array<string, mixed> The created decision record.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable, schemas
	 *                          are unconfigured, or the payload is
	 *                          invalid at draft time.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function draft(string $objectionId, array $payload): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$decisionSchema = $this->settingsService->getConfigValue(
			key: 'bezwaar_decision_schema'
		);
		if ($register === '' || $decisionSchema === '') {
			throw new RuntimeException(
				'BezwaarDecision schema is not configured'
			);
		}

		$this->validator->assertDraftable(payload: $payload);

		$record = array_merge(
			$payload,
			[
				'objectionProceeding' => $objectionId,
				'status' => 'draft',
			]
		);
		// The publishedAt and notifiedRecipients fields are owned by publish().
		unset($record['publishedAt'], $record['notifiedRecipients']);

		try {
			return ($this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $decisionSchema,
				object: $record
			) ?? $record);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq bezwaar-decision: failed to draft: ' . $e->getMessage()
			);
			throw new RuntimeException('Could not draft bezwaarDecision');
		}
	}//end draft()

	/**
	 * Publish a draft bezwaarDecision by delegating the *deciding* to decidesk.
	 *
	 * Runs the full Awb validity matrix (REQ-BD-3, REQ-BD-5, REQ-BD-6,
	 * REQ-BD-7) as dossiq domain validation (REQ-PDRD-004), then raises a
	 * decidesk `bezwaar-decision` Decision by dispatching a `DecisionRequestedEvent`
	 * (REQ-PDRD-001) and persists the returned `decisionRef` on the record.
	 * dossiq no longer authors the besluit locally: there is no
	 * `status:'published'` local decision state — the besluit is materialised
	 * from the decidesk `DecisionConcludedEvent` by
	 * {@see \OCA\Dossiq\Listener\DecisionConcludedListener} (REQ-PDRD-003,
	 * REQ-PDRD-007). FAILS CLOSED when decidesk is unavailable (REQ-PDRD-002):
	 * no local decided state is set as a fallback.
	 *
	 * @param string $decisionId UUID of the bezwaarDecision.
	 *
	 * @return array<string, mixed> The decision record annotated with the decidesk decisionRef.
	 *
	 * @throws RuntimeException When validation fails, the decidesk leaf is
	 *                          unavailable (fail closed), or persistence errors.
	 *
	 * @spec openspec/specs/remaining-decision-delegation/spec.md
	 * @spec openspec/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-002-delegation-fails-closed-when-decidesk-is-unavailable
	 * @spec openspec/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-004-the-awb-and-idor-domain-rules-stay-in-dossiq
	 */
	public function publish(string $decisionId): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$decisionSchema = $this->settingsService->getConfigValue(
			key: 'bezwaar_decision_schema'
		);
		if ($register === '' || $decisionSchema === '') {
			throw new RuntimeException(
				'BezwaarDecision schema is not configured'
			);
		}

		$current = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $decisionSchema, id: $decisionId);
		if ($current === null) {
			throw new RuntimeException('BezwaarDecision not found');
		}

		// REQ-PDRD-004: the Awb validity matrix (7:11 disposition set, 7:12
		// motivering, proceskosten, replacement/appeal guards) stays in dossiq
		// and runs BEFORE the Decision is raised, so no Decision can ever be
		// raised on an Awb-invalid payload.
		$this->validator->assertPublishable(decision: $current);

		$objectionId = (string)($current['objectionProceeding'] ?? '');

		// REQ-PDRD-001 / REQ-PDRD-002: delegate the deciding to decidesk via the
		// decidesk DecisionRequestedEvent. Fail closed — never author the besluit
		// locally as a fallback. The decisionRef returned is persisted on the
		// record so the outcome can be materialised later from the concluded event.
		$objectionRef = $decisionId;
		if ($objectionId !== '') {
			$objectionRef = $objectionId;
		}

		try {
			$decisionRef = $this->decisionDelegation->raiseBezwaarDecision(
				objectionId: $objectionRef,
				payload: [
					'subjectRegister' => $register,
					'subjectSchema' => $decisionSchema,
					'subjectId' => $decisionId,
					'subjectLabel' => (string)($current['title'] ?? ($current['onderwerp'] ?? '')),
					'dispositionType' => (string)($current['dispositionType'] ?? ''),
					'reasoning' => (string)($current['reasoning'] ?? ''),
					'legalBasis' => (string)($current['legalBasis'] ?? ''),
					'replacementDecision' => (string)($current['replacementDecision'] ?? ''),
				],
			);
		} catch (RuntimeException $e) {
			// REQ-PDRD-002: surface the fail-closed error; do NOT set any local
			// decided state as a fallback.
			$this->logger->error(
				'Dossiq bezwaar-decision: decidesk Decision raise failed — failing closed: '
				. $e->getMessage()
			);
			throw new RuntimeException('Decision service unavailable: ' . $e->getMessage(), 0, $e);
		}//end try

		// Persist the decisionRef + notification audit list ONLY — no local
		// "published" decision state; the besluit is the decidesk outcome.
		$patch = [
			'decisionRef' => $decisionRef,
			'status' => 'awaiting-decidesk',
			'notifiedRecipients' => $this->collectRecipients(decision: $current),
		];

		$totalAmount = $this->validator->computeProceskostenTotal(decision: $current);
		if ($totalAmount !== null) {
			$proceskosten = (array)($current['legalCostsCompensation'] ?? []);
			$proceskosten['totalAmount'] = $totalAmount;
			$patch['legalCostsCompensation'] = $proceskosten;
		}

		try {
			$saved = ($this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $decisionSchema,
				object: $patch,
				uuid: (string)$decisionId
			) ?? array_merge($current, $patch));
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq bezwaar-decision: failed to persist decisionRef: '
				. $e->getMessage()
			);
			throw new RuntimeException('Could not record bezwaarDecision delegation');
		}

		return $saved;
	}//end publish()

	/**
	 * Apply the bezwaar status transition once decidesk has concluded.
	 *
	 * The ZGW `Besluit` is materialised from the decidesk outcome by
	 * {@see \OCA\Dossiq\Listener\DecisionConcludedListener} when decidesk
	 * dispatches a `DecisionConcludedEvent` — there is no dossiq-local poll of
	 * the decidesk outcome here. This method only triggers the configured
	 * status transition on the linked bezwaar; the status engine still owns
	 * guards + side effects, and the besluit is never authored locally.
	 *
	 * @param string $objectionId UUID of the source bezwaar.
	 * @param string $decisionId UUID of the bezwaarDecision being applied.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-003-the-zgw-besluit-is-materialised-from-the-decisionconcludedevent
	 */
	public function applyToBezwaar(string $objectionId, string $decisionId): void {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$objectionSchema = $this->settingsService->getConfigValue(key: 'bezwaar_schema');
		if ($register === '' || $objectionSchema === '') {
			return;
		}

		$objection = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $objectionSchema,
			id: $objectionId
		);
		if ($objection === null) {
			return;
		}

		$caseId = (string)($objection['case'] ?? '');
		if ($caseId === '') {
			return;
		}

		try {
			$this->transitions->execute(
				caseId: $caseId,
				transitionId: self::TRANSITION_ID,
				comment: 'Applied beslissing op bezwaar ' . $decisionId,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq bezwaar-decision: transition to '
				. self::TARGET_BEZWAAR_STATUS . ' failed: ' . $e->getMessage()
			);
		}
	}//end applyToBezwaar()

	/**
	 * Build the recipient audit list for the publication notification
	 * flow (REQ-BD-10). Bezwaarmaker, gemachtigde, primair beslisser,
	 * and the BAC secretaris (when advisoryOpinion is set) are
	 * surfaced from the decision payload where available. The actual
	 * notification fan-out is owned by NotificatieService /
	 * BerichtenboxAdapter; this method records who SHOULD be reached.
	 *
	 * @param array<string, mixed> $decision Decision payload.
	 *
	 * @return array<int, string>
	 */
	private function collectRecipients(array $decision): array {
		$recipients = [];

		$acting = $this->userSession->getUser();
		if ($acting !== null) {
			$recipients[] = 'actor:' . $acting->getUID();
		}

		$bezwaarmaker = (string)($decision['bezwaarmaker'] ?? '');
		if ($bezwaarmaker !== '') {
			$recipients[] = 'bezwaarmaker:' . $bezwaarmaker;
		}

		$representative = (string)($decision['authorisedRepresentative'] ?? '');
		if ($representative !== '') {
			$recipients[] = 'gemachtigde:' . $representative;
		}

		$primairBeslisser = (string)($decision['primairBeslisser'] ?? '');
		if ($primairBeslisser !== '') {
			$recipients[] = 'primair-beslisser:' . $primairBeslisser;
		}

		$advisory = (string)($decision['advisoryOpinion'] ?? '');
		if ($advisory !== '') {
			$recipients[] = 'bac-secretaris:' . $advisory;
		}

		return array_values(array_unique($recipients));
	}//end collectRecipients()
}//end class
