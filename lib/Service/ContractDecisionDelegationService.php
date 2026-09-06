<?php

/**
 * Dossiq Contract Decision Delegation Service
 *
 * Delegates contract approval / renewal / besluit decisions to decidesk via
 * the Nextcloud event dispatcher (decidesk's merged event contract). dossiq
 * keeps ZGW case management; decidesk owns the deciding. This service:
 *
 * - Raises a decidesk Decision by dispatching `DecisionRequestedEvent`.
 * - Reads the synchronous result the decidesk listener writes back onto the
 *   event (`isHandled()` / `getDecisionId()`).
 * - Reads a raised Decision BACK by dispatching `DecisionStateRequestedEvent`,
 *   for the case where the conclusion was announced and nobody heard it.
 * - FAILS CLOSED when decidesk is unavailable (never auto-approves).
 *
 * The terminal outcome is normally delivered by decidesk dispatching a
 * `DecisionConcludedEvent` consumed by {@see \OCA\Dossiq\Listener\DecisionConcludedListener}.
 * `readDecisionState()` is NOT a second delivery mechanism: it is what a
 * consumer consults when that announcement did not arrive, so a run waiting on
 * a decision has something to ask instead of re-suspending forever.
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
 * @spec openspec/changes/dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Raises decidesk Decisions (via `DecisionRequestedEvent`) for contract /
 * besluit decisions.
 *
 * @spec openspec/changes/dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md
 */
class ContractDecisionDelegationService {
	/**
	 * Decision types supported by the contract delegation surface.
	 */
	public const DECISION_TYPE_CONTRACT_RENEWAL = 'contract-renewal';
	public const DECISION_TYPE_REPORT_ADOPTION = 'report-adoption';
	public const DECISION_TYPE_BEZWAAR = 'bezwaar-beslissing';

	/**
	 * Decision types for the remaining decision/advice flows delegated by
	 * `procest-delegate-remaining-decisions-to-decidesk` (ADR-005 decisionType).
	 */
	public const DECISION_TYPE_BEZWAAR_DECISION = 'bezwaar-decision';
	public const DECISION_TYPE_ADVICE = 'advice';

	/**
	 * Every spelling of the decision-request event FQN, newest first.
	 *
	 * Guarded by class_exists so dossiq stays installable without the decision
	 * app, which is an optional runtime dependency.
	 *
	 * TWO SPELLINGS because a cross-app event class name is a RUNTIME lookup
	 * this app can only follow, never move. The app renamed its namespace from
	 * OCA\Decidesk to OCA\Decidiq with no compatibility alias, and this constant
	 * named only the old one — so the guard below started throwing "decidesk is
	 * not installed" on an instance where it very much was, and every contract
	 * decision was blocked by a message that pointed at the wrong problem.
	 *
	 * @var array<int, string>
	 */
	private const DECISION_REQUESTED_EVENTS = [
		'\\OCA\\Decidiq\\Event\\DecisionRequestedEvent',
		'\\OCA\\Decidesk\\Event\\DecisionRequestedEvent',
	];

	/**
	 * Every spelling of the decision-state read event FQN, newest first.
	 *
	 * ONE SPELLING, not two, and that is not an oversight. The read half of the
	 * contract (decidiq#1118) was added AFTER the OCA\Decidesk -> OCA\Decidiq
	 * rename, so `OCA\Decidesk\Event\DecisionStateRequestedEvent` has never
	 * existed anywhere. Listing it would not be resilience — it would be this
	 * app inventing a class name the other app never published, and a
	 * class_exists() that can only ever answer false is dead code pretending to
	 * be a fallback. The list stays a list because that is where a genuine
	 * second spelling would go if decidiq ever published one.
	 *
	 * @var array<int, string>
	 */
	private const DECISION_STATE_EVENTS = [
		'\\OCA\\Decidiq\\Event\\DecisionStateRequestedEvent',
	];

	/**
	 * This app's id AS DECIDIQ KNOWS IT — frozen, and not our own app id.
	 *
	 * Decidiq matches this value exactly and echoes it back to
	 * DecisionConcludedListener::SOURCE_APP. Renaming it silently drops every
	 * in-flight and already-persisted decision, so it moves only in a
	 * coordinated pass that moves emitter and receiver together.
	 *
	 * @var string
	 */
	private const SOURCE_APP = 'procest';

	/**
	 * The seam could not answer: decidiq absent, its listener unregistered, or
	 * the read itself failed. NEVER "there is no such decision".
	 *
	 * @var string
	 */
	public const DECISION_STATE_UNREADABLE = 'unreadable';

	/**
	 * decidiq answered, and refused the read for the identity we named.
	 *
	 * @var string
	 */
	public const DECISION_STATE_REFUSED = 'refused';

	/**
	 * decidiq answered, the read was allowed, and no Decision carries that id.
	 *
	 * @var string
	 */
	public const DECISION_STATE_GONE = 'gone';

	/**
	 * The Decision exists and has not been concluded.
	 *
	 * @var string
	 */
	public const DECISION_STATE_OPEN = 'open';

	/**
	 * The Decision was concluded with an outcome: `approved` or `rejected`.
	 *
	 * @var string
	 */
	public const DECISION_STATE_DECIDED = 'decided';

	/**
	 * The Decision reached a terminal state carrying NO answer.
	 *
	 * @var string
	 */
	public const DECISION_STATE_WITHDRAWN = 'withdrawn';

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher Nextcloud typed event dispatcher.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Raise a decidesk Decision for a contract approval / renewal / sign-off.
	 *
	 * Dispatches a `DecisionRequestedEvent` synchronously and reads the result
	 * the decidesk listener writes back. FAILS CLOSED: when decidesk is not
	 * installed, did not handle the event, or returned no decisionId, this
	 * method throws — it never silently returns null / auto-approves (mirrors
	 * hydra-gate-unsafe-auth-resolver).
	 *
	 * @param string $caseRef The ZGW case reference (UUID) that owns this decision.
	 * @param string $contractRef The contract object UUID.
	 * @param string $decisionType Decision type slug (e.g. self::DECISION_TYPE_CONTRACT_RENEWAL).
	 * @param array<string,mixed> $subject Subject fields: subjectRegister, subjectSchema, subjectId, subjectLabel.
	 * @param array<string,mixed> $mandateContext Mandate context: requestedBy, mandateRole, mandateScope.
	 *
	 * @return string The decidesk decisionRef (UUID) to persist on the case.
	 *
	 * @throws RuntimeException When decidesk is unavailable or the Decision could not be created.
	 *
	 * @spec openspec/changes/dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-001-contract-decisions-are-raised-as-decidesk-decisions-via-events
	 * @spec openspec/changes/dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-002-delegation-fails-closed-when-decidesk-is-unavailable
	 */
	public function raiseContractDecision(
		string $caseRef,
		string $contractRef,
		string $decisionType,
		array $subject,
		array $mandateContext,
	): string {
		return $this->dispatchDecisionRequest(
			decisionType: $decisionType,
			externalReference: $caseRef,
			subject: [
				'subjectRegister' => (string)($subject['subjectRegister'] ?? ''),
				'subjectSchema' => (string)($subject['subjectSchema'] ?? ''),
				'subjectId' => (string)($subject['subjectId'] ?? $contractRef),
				'subjectLabel' => (string)($subject['subjectLabel'] ?? ''),
			],
			actorId: (string)($mandateContext['requestedBy'] ?? ''),
			payload: [
				'title' => (string)($subject['subjectLabel'] ?? ''),
				'context' => $mandateContext,
			],
		);
	}//end raiseContractDecision()

	/**
	 * Raise a decidesk Decision of an arbitrary decisionType. This is the shared
	 * core reused by the remaining decision/advice delegation siblings
	 * (BezwaarDecisionDelegationService, AdviceDelegationService) so there is
	 * exactly one delegation mechanism (the event dispatch).
	 *
	 * FAILS CLOSED: when decidesk is unavailable or did not handle the event
	 * this method throws — it never silently returns null / auto-decides.
	 *
	 * @param string $decisionType Decision type slug (ADR-005), e.g. self::DECISION_TYPE_ADVICE.
	 * @param string $externalReference The ZGW case/subject reference persisted on the decidesk Decision.
	 * @param array<string,mixed> $subject Subject fields: subjectRegister, subjectSchema, subjectId, subjectLabel.
	 * @param array<string,mixed> $context Optional decision context (disposition, reasoning, legalBasis, etc.).
	 *
	 * @return string The decidesk decisionRef (UUID) to persist on the case.
	 *
	 * @throws RuntimeException When decidesk is unavailable or the Decision could not be created.
	 *
	 * @spec openspec/changes/dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-001-contract-decisions-are-raised-as-decidesk-decisions-via-events
	 * @spec openspec/changes/dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-002-delegation-fails-closed-when-decidesk-is-unavailable
	 */
	public function raiseDecision(
		string $decisionType,
		string $externalReference,
		array $subject,
		array $context = [],
	): string {
		return $this->dispatchDecisionRequest(
			decisionType: $decisionType,
			externalReference: $externalReference,
			subject: [
				'subjectRegister' => (string)($subject['subjectRegister'] ?? ''),
				'subjectSchema' => (string)($subject['subjectSchema'] ?? ''),
				'subjectId' => (string)($subject['subjectId'] ?? ''),
				'subjectLabel' => (string)($subject['subjectLabel'] ?? ''),
			],
			actorId: (string)($context['actorId'] ?? ''),
			payload: [
				'title' => (string)($subject['subjectLabel'] ?? ''),
				'context' => $context,
			],
		);
	}//end raiseDecision()

	/**
	 * Ask decidiq what became of a Decision this app raised.
	 *
	 * THE READ HALF OF THE SAME SEAM. `raiseDecision()` asks decidiq to decide
	 * and the conclusion is ANNOUNCED back by `DecisionConcludedEvent`. This
	 * method is what a consumer consults when that announcement never arrived —
	 * the listener threw, the app was mid-upgrade, the run had already been
	 * resumed by something else. It is deliberately the same synchronous
	 * request/response-over-the-bus shape (ADR-041, decidiq#1118), so an app
	 * that can raise a decision can read one back without a second mechanism.
	 *
	 * 🔴 IT NAMES AN IDENTITY, AND MUST. The bus carries no session — the
	 * heartbeat that motivates this read runs under the cron worker, where
	 * `IUserSession` holds nobody — so decidiq scopes the read to the uid the
	 * event names and REFUSES an event naming none. Passing an empty actor is
	 * therefore not "read as the system": it is a refusal, and this method does
	 * not even dispatch it.
	 *
	 * 🔴 SIX ANSWERS, NOT A BOOLEAN. "I could not reach the seam", "you may not
	 * read this", "there is no such decision", "still open", "decided" and
	 * "withdrawn" are six different facts and a caller acts differently on each.
	 * Collapsing the first three into one would let an unreachable OpenRegister
	 * read as a vanished decision, which fails a case whose decision is sitting
	 * there taken.
	 *
	 * @param string $decisionId The decidiq decision id this app holds.
	 * @param string $actorId The Nextcloud uid the read is scoped to — never empty.
	 *
	 * @return array{state: string, status: string, envelope: array<string, mixed>} The state, decidiq's own status word, and the outcome envelope.
	 *
	 * @spec openspec/changes/requestdecision-recovers-a-missed-conclusion/specs/case-flow-human-steps/spec.md#requirement-a-consumer-can-read-back-a-decision-it-raised
	 */
	public function readDecisionState(string $decisionId, string $actorId): array {
		$decisionId = trim($decisionId);
		$actorId = trim($actorId);

		$eventClass = $this->firstExistingClass(candidates: self::DECISION_STATE_EVENTS);
		if ($eventClass === null || $decisionId === '' || $actorId === '') {
			$reason = 'no decision id or no actor';
			if ($eventClass === null) {
				$reason = 'the read seam is not installed';
			}

			$this->logger->warning(
				'ContractDecisionDelegationService: cannot read a decision state',
				['decisionRef' => $decisionId, 'hasActor' => ($actorId !== ''), 'reason' => $reason]
			);

			return $this->stateAnswer(state: self::DECISION_STATE_UNREADABLE);
		}

		try {
			// Positional ctor args (decidiq contract): sourceApp, decisionId, actorId.
			$event = new $eventClass(self::SOURCE_APP, $decisionId, $actorId);

			$this->eventDispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			// Unreadable, NOT absent. A dispatch that blew up says nothing
			// about whether the decision exists, and reading it as "gone"
			// would strand a run whose decision is already taken.
			$this->logger->error(
				'ContractDecisionDelegationService: DecisionStateRequestedEvent dispatch failed',
				['decisionRef' => $decisionId, 'error' => $e->getMessage()]
			);

			return $this->stateAnswer(state: self::DECISION_STATE_UNREADABLE);
		}//end try

		// Unhandled is decidiq's own way of saying "ask me again": its listener
		// leaves the event unhandled when the lookup could not be RESOLVED, and
		// marks it handled for every fact it can actually report.
		if ((bool)$event->isHandled() === false) {
			return $this->stateAnswer(state: self::DECISION_STATE_UNREADABLE);
		}

		if ((bool)$event->isPermitted() === false) {
			return $this->stateAnswer(state: self::DECISION_STATE_REFUSED);
		}

		if ((bool)$event->isFound() === false) {
			return $this->stateAnswer(state: self::DECISION_STATE_GONE);
		}

		return $this->stateFromEnvelope(envelope: (array)($event->getEnvelope() ?? []), decisionId: $decisionId);
	}//end readDecisionState()

	/**
	 * Read the outcome envelope decidiq answered with as one of the states.
	 *
	 * The status vocabulary is decidiq's — `approved` / `rejected` /
	 * `withdrawn` / `pending`, the same words `DecisionConcludedEvent` carries —
	 * so it is mapped here once and nowhere else.
	 *
	 * A word this app does not recognise reads as STILL OPEN, deliberately. It
	 * can only come from a decidiq newer than this one, and the two directions
	 * are not symmetric: waiting through a vocabulary extension costs a
	 * heartbeat, while guessing that an unknown word means "decided" would
	 * advance a case on an outcome nobody here can name.
	 *
	 * @param array<string, mixed> $envelope The envelope from getOutcomeEnvelope().
	 * @param string $decisionId The decision id, for the log line.
	 *
	 * @return array{state: string, status: string, envelope: array<string, mixed>} The resolved state.
	 *
	 * @spec openspec/changes/requestdecision-recovers-a-missed-conclusion/specs/case-flow-human-steps/spec.md#requirement-a-consumer-can-read-back-a-decision-it-raised
	 */
	private function stateFromEnvelope(array $envelope, string $decisionId): array {
		$status = strtolower(trim((string)($envelope['status'] ?? '')));

		$state = match ($status) {
			'approved', 'rejected' => self::DECISION_STATE_DECIDED,
			'withdrawn' => self::DECISION_STATE_WITHDRAWN,
			'pending' => self::DECISION_STATE_OPEN,
			default => null,
		};

		if ($state === null) {
			$this->logger->warning(
				'ContractDecisionDelegationService: decidiq reported a decision status this app does not know; treating it as still open',
				['decisionRef' => $decisionId, 'status' => $status]
			);

			$state = self::DECISION_STATE_OPEN;
		}

		return $this->stateAnswer(state: $state, status: $status, envelope: $envelope);
	}//end stateFromEnvelope()

	/**
	 * One answer shape, so no caller has to interpret an absent key.
	 *
	 * @param string $state One of the DECISION_STATE_* constants.
	 * @param string $status decidiq's own status word, when there was one.
	 * @param array<string, mixed> $envelope The outcome envelope, when there was one.
	 *
	 * @return array{state: string, status: string, envelope: array<string, mixed>} The answer.
	 */
	private function stateAnswer(string $state, string $status = '', array $envelope = []): array {
		return ['state' => $state, 'status' => $status, 'envelope' => $envelope];
	}//end stateAnswer()

	/**
	 * Build, dispatch and resolve a decidesk `DecisionRequestedEvent`.
	 *
	 * Guarded by class_exists — when decidesk is not installed the method fails
	 * closed (throws). After `dispatchTyped()` the decidesk listener has written
	 * `isHandled()` / `getDecisionId()` onto the event synchronously; when the
	 * event is not handled or carries no decisionId the method fails closed.
	 *
	 * @param string $decisionType The decision type slug.
	 * @param string $externalReference The ZGW case/subject reference.
	 * @param array<string,mixed> $subject Subject fields (subjectRegister/Schema/Id/Label).
	 * @param string $actorId The requesting actor id (may be empty).
	 * @param array<string,mixed> $payload Decision body payload (title/text/decisionDate/outcome/context).
	 *
	 * @return string The decidesk decisionId.
	 *
	 * @throws RuntimeException When decidesk is unavailable or did not handle the request.
	 */
	private function dispatchDecisionRequest(
		string $decisionType,
		string $externalReference,
		array $subject,
		string $actorId,
		array $payload,
	): string {
		$eventClass = $this->resolveRequestEventClass();

		// REQ-PDCD-002: fail closed when the decision app is not installed.
		if ($eventClass === null) {
			$this->logger->error(
				'ContractDecisionDelegationService: the decision app is not installed (DecisionRequestedEvent missing under any known namespace); failing closed',
				[
					'externalReference' => $externalReference,
					'decisionType' => $decisionType,
					'tried' => self::DECISION_REQUESTED_EVENTS,
				]
			);
			throw new RuntimeException(
				'Decision service unavailable: the decision app is not installed. Decision cannot proceed.'
			);
		}

		try {
			// Positional ctor args (decidesk contract): sourceApp, subjectRegister,
			// subjectSchema, subjectId, subjectLabel, decisionType, actorId,
			// payload, externalReference, correlationId.
			//
			// sourceApp is FROZEN — see self::SOURCE_APP, which carries the
			// reason and is the one place both halves of this contract read it
			// from.
			$event = new $eventClass(
				self::SOURCE_APP,
				(string)$subject['subjectRegister'],
				(string)$subject['subjectSchema'],
				(string)$subject['subjectId'],
				(string)$subject['subjectLabel'],
				$decisionType,
				$actorId,
				$payload,
				$externalReference,
				$externalReference
			);

			$this->eventDispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			$this->logger->error(
				'ContractDecisionDelegationService: DecisionRequestedEvent dispatch failed',
				['externalReference' => $externalReference, 'error' => $e->getMessage()]
			);
			// REQ-PDCD-002: re-throw to fail closed; caller must not proceed.
			throw new RuntimeException('Decision service error: ' . $e->getMessage(), 0, $e);
		}//end try

		// REQ-PDCD-002: the decidesk listener writes isHandled()/getDecisionId()
		// back onto the event synchronously. Anything else fails closed.
		$handled = (bool)$event->isHandled();
		$decisionId = $event->getDecisionId();
		if ($handled === false || $decisionId === null || $decisionId === '') {
			$this->logger->error(
				'ContractDecisionDelegationService: decidesk did not handle the decision request; failing closed',
				['externalReference' => $externalReference, 'decisionType' => $decisionType, 'handled' => $handled]
			);
			throw new RuntimeException('Decision service unavailable: decidesk did not handle the decision request. Decision cannot proceed.');
		}

		$this->logger->info(
			'ContractDecisionDelegationService: decidesk Decision raised via event',
			['externalReference' => $externalReference, 'decisionType' => $decisionType, 'decisionRef' => (string)$decisionId]
		);

		return (string)$decisionId;
	}//end dispatchDecisionRequest()
	/**
	 * The first decision-request event class that actually exists.
	 *
	 * Returns null when NONE does, which the caller turns into a fail-closed
	 * refusal. Resolving rather than assuming is the whole point: this app can
	 * only follow the other app's namespace, and a hard-coded spelling turns a
	 * rename over there into a broken feature over here.
	 *
	 * @return string|null The event FQN, or null when the decision app is absent.
	 */
	private function resolveRequestEventClass(): ?string {
		return $this->firstExistingClass(candidates: self::DECISION_REQUESTED_EVENTS);
	}//end resolveRequestEventClass()

	/**
	 * The first of these class names that actually exists.
	 *
	 * Shared by both halves of the contract so the raise and the read cannot
	 * end up resolving a cross-app class name by two different rules.
	 *
	 * @param array<int, string> $candidates The FQNs to try, newest first.
	 *
	 * @return string|null The first that exists, or null when none does.
	 */
	private function firstExistingClass(array $candidates): ?string {
		foreach ($candidates as $candidate) {
			if (class_exists($candidate) === true) {
				return $candidate;
			}
		}

		return null;
	}//end firstExistingClass()
}//end class
