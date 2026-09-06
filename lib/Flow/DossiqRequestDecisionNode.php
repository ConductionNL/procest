<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Flow
 * @package   OCA\Dossiq\Flow
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Flow;

use DateTime;
use OCA\Dossiq\Service\ContractDecisionDelegationService;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCP\IL10N;
use OCP\WorkflowEngine\IManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * Ask decidiq for a decision, and wait for it.
 *
 * DECISIONS ARE DECIDIQ'S. This node raises one and suspends; it does not
 * decide, and it does not project an outcome of its own. What comes back is
 * whatever decidiq concluded, announced by its `DecisionConcludedEvent` and
 * routed to this run by {@see \OCA\Dossiq\Listener\DecisionConcludedListener}.
 *
 * 🔴 IT FAILS CLOSED. When decidiq is unavailable the step FAILS and the run
 * stops at the decision. The alternative — carry on and let a later step assume
 * approval — produces a case decided by nobody, which is the one outcome a
 * decision step exists to prevent. The delegation service already fails closed
 * for exactly this reason; this node must not soften it by catching.
 *
 * THE REF IS THE CORRELATION KEY. The `decisionRef` decidiq returns is written
 * into this node's resume slot, and the listener matches on it. Matching on the
 * CASE instead would wake the run whenever any of that case's decisions
 * concluded — and a case has several in its life, so the run would advance on
 * an answer to a different question.
 *
 * 🔴 THE DECISION DECIDES, NOT THE SIGNAL. This node used to advance only when
 * a signal carrying a `decision` arrived, which made the heartbeat a timer that
 * could do nothing but suspend again. A conclusion whose announcement was
 * missed — the listener threw, this app was mid-upgrade, the run had already
 * been resumed by something else — therefore wedged the run PERMANENTLY, with
 * `resume_at` rolling forward while the Decision sat concluded in decidiq. That
 * is the same wedge dossiq#1756 closed for `dossiq.askPerson`, and it could not
 * be closed here until decidiq had a way to be ASKED: `raiseDecision()` could
 * raise a decision and nothing could read one back, so a heartbeat had nothing
 * to consult.
 *
 * decidiq#1118 added that read seam, so a re-entry now RE-READS the decision
 * this node raised and the DECISION's state is what advances the run. A signal
 * is then what it always should have been: a wake, whose payload adds detail
 * and never decides the answer. That also closes a race the node used to lose —
 * `context.signal` is one slot per RUN, so a flow with two decisions had the
 * second read the answer given to the first.
 *
 * 🔴 UNREADABLE IS NOT GONE, AND FAIL-CLOSED IS NOT FAIL-ON-SILENCE. "Failing
 * closed" here means the run never advances past a decision nobody made. It
 * does NOT mean failing a run because the reader was briefly unavailable: an
 * unreachable seam buys one more heartbeat, because concluding "no such
 * decision" from a hiccup would fail a case whose decision is sitting there
 * taken.
 *
 * @spec openspec/changes/requestdecision-recovers-a-missed-conclusion/specs/case-flow-human-steps/spec.md
 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The node speaks OpenRegister's
 *     whole suspend/resume vocabulary (suspension, resume slot, run context,
 *     signal key) AND dossiq's cross-app decision seam. Splitting a class to
 *     shed an import would separate the raise from the wait it exists to pair.
 */
class DossiqRequestDecisionNode implements IFlowNode {

    /**
     * Minutes between heartbeats when the config names none.
     *
     * Longer than the task node's: a decision involves a person being convened,
     * not a form being filled, so a lost-signal safety net that fires every
     * half hour is noise.
     *
     * @var integer
     */
    private const DEFAULT_HEARTBEAT_MINUTES = 120;

    /**
     * The shortest heartbeat this node will honour.
     *
     * @var integer
     */
    private const MIN_HEARTBEAT_MINUTES = 15;


    /**
     * Constructor.
     *
     * @param ContractDecisionDelegationService $delegation Raises the decision in decidiq.
     * @param IL10N                             $l10n       The localisation service.
     * @param LoggerInterface                   $logger     The logger.
     *
     * @return void
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function __construct(
        private readonly ContractDecisionDelegationService $delegation,
        private readonly IL10N $l10n,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()


    /**
     * This node's catalogue id.
     *
     * @return string The namespaced node id.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function getId(): string {
        return 'dossiq.requestDecision';

    }//end getId()


    /**
     * The node's display name.
     *
     * @return string The translated name.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function getDisplayName(): string {
        return $this->l10n->t('Request a decision');

    }//end getDisplayName()


    /**
     * What the node does.
     *
     * @return string The translated description.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function getDescription(): string {
        return $this->l10n->t('Ask Decidiq to decide, and pause the case until it has.');

    }//end getDescription()


    /**
     * The node's icon.
     *
     * @return string The icon name.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function getIcon(): string {
        return 'gavel';

    }//end getIcon()


    /**
     * Where this node may be offered.
     *
     * @param integer $scope The Nextcloud workflow scope.
     *
     * @return boolean True when available in this scope.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function isAvailableForScope(int $scope): bool {
        return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);

    }//end isAvailableForScope()


    /**
     * Refuse a decision request with no question.
     *
     * @param array $config The step configuration.
     *
     * @return void
     *
     * @throws UnexpectedValueException When the question is missing.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function validateConfig(array $config): void {
        if (trim((string) ($config['question'] ?? '')) === '') {
            throw new UnexpectedValueException(
                $this->l10n->t('Say what is being decided, or the decision cannot be presented.')
            );
        }

    }//end validateConfig()


    /**
     * Raise the decision on the first pass; on every later pass, read it back.
     *
     * The whole shape of this method is the recovery: a re-entry — a wake from
     * the conclusion, or a heartbeat with nothing in hand — asks DECIDIQ what
     * became of the decision this node raised. That makes the heartbeat able to
     * deliver a conclusion whose announcement never arrived, which is the
     * difference between a safety net and a timer that only ever re-suspends.
     *
     * @param array $items   The input items.
     * @param array $config  The step configuration.
     * @param array $context Run-level metadata.
     *
     * @return array The items, each carrying the outcome.
     *
     * @throws FlowSuspension While the decision is outstanding, or unreadable.
     * @throws RuntimeException When the node has no resume slot, the read is
     *                          refused, the decision is gone, or it was withdrawn.
     *
     * @spec openspec/changes/requestdecision-recovers-a-missed-conclusion/specs/case-flow-human-steps/spec.md
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function execute(array $items, array $config, array $context): array {
        $this->validateConfig(config: $config);

        $resume = ($context[FlowNodeResumeState::CONTEXT_KEY] ?? null);
        if (($resume instanceof FlowNodeResumeState) === false) {
            // No slot means no way to record the ref, so every heartbeat would
            // raise ANOTHER decision in decidiq — convening people repeatedly
            // for a question already asked — and nothing would name the
            // decision to read back.
            throw new RuntimeException('dossiq.requestDecision requires a node resume slot');
        }

        $ref = trim((string) $resume->get(key: 'decisionRef', default: ''));
        if ($ref === '') {
            $this->raise(items: $items, config: $config, context: $context, resume: $resume);

            throw $this->suspension(config: $config);
        }

        return $this->onReEntry(items: $items, config: $config, context: $context, resume: $resume, ref: $ref);

    }//end execute()


    /**
     * Ask decidiq what became of the decision, and act on the answer.
     *
     * Six answers, six directions, and none of them invents an outcome:
     *
     * - DECIDED    — advance with decidiq's own word, wake or no wake.
     * - OPEN       — suspend again, WITHOUT touching the slot: the remembered
     *                ref and the time it was asked survive every heartbeat.
     * - WITHDRAWN  — fail. The question was taken off the table, so there is
     *                nothing to wait for and nothing to carry on with. A case
     *                that proceeded past a withdrawn decision would proceed as
     *                though somebody had decided it, and nobody did.
     * - GONE       — fail naming the ref, rather than waiting forever on a
     *                record that is not there.
     * - REFUSED    — fail. decidiq answered and would not show us the decision
     *                we raised, which is a misconfiguration to surface rather
     *                than a state to poll: no number of heartbeats changes it.
     * - UNREADABLE — suspend. The seam could not answer, which says nothing
     *                about the decision; one more heartbeat costs a case
     *                minutes, reading it as "gone" costs it the case.
     *
     * @param array               $items   The input items.
     * @param array               $config  The step configuration.
     * @param array               $context Run-level metadata.
     * @param FlowNodeResumeState $resume  This node's resume slot.
     * @param string              $ref     The decision this node raised.
     *
     * @return array The items, each carrying the outcome.
     *
     * @throws FlowSuspension While the decision is outstanding, or unreadable.
     * @throws RuntimeException When the read is refused, the decision is gone,
     *                          or it was withdrawn.
     *
     * @spec openspec/changes/requestdecision-recovers-a-missed-conclusion/specs/case-flow-human-steps/spec.md
     */
    private function onReEntry(array $items, array $config, array $context, FlowNodeResumeState $resume, string $ref): array {
        $actor = $this->actorFor(context: $context, resume: $resume);
        if ($actor === '') {
            // The seam refuses a read naming nobody, and rightly: it is what
            // stops one app reading back decisions it never raised. Without an
            // identity there is nothing to ask, so this run keeps the
            // behaviour it had before the recovery existed and waits for its
            // announcement — loudly, so the missing `runAs` can be found.
            $this->logger->warning(
                'Dossiq requestDecision: this run names no acting identity, so decision ' . $ref
                    . ' cannot be read back; waiting for the announcement instead',
                ['node' => $resume->nodeId()]
            );

            throw $this->suspension(config: $config);
        }

        $read  = $this->delegation->readDecisionState(decisionId: $ref, actorId: $actor);
        $state = (string) $read['state'];

        if ($state === ContractDecisionDelegationService::DECISION_STATE_UNREADABLE) {
            $this->logger->warning(
                'Dossiq requestDecision: could not read decision ' . $ref . '; waiting another heartbeat',
                ['node' => $resume->nodeId(), 'actor' => $actor]
            );

            throw $this->suspension(config: $config);
        }

        if ($state === ContractDecisionDelegationService::DECISION_STATE_REFUSED) {
            throw new RuntimeException(
                sprintf(
                    'Decidiq refused to report decision %s to "%s", the identity this run raised it as, '
                        . 'so dossiq.requestDecision cannot tell whether it was taken.',
                    $ref,
                    $actor
                )
            );
        }

        if ($state === ContractDecisionDelegationService::DECISION_STATE_GONE) {
            throw new RuntimeException(
                sprintf('Decision %s, which dossiq.requestDecision was waiting on, no longer exists.', $ref)
            );
        }

        if ($state === ContractDecisionDelegationService::DECISION_STATE_WITHDRAWN) {
            throw new RuntimeException(
                sprintf(
                    'Decision %s was withdrawn, so the question dossiq.requestDecision asked will never be answered.',
                    $ref
                )
            );
        }

        if ($state !== ContractDecisionDelegationService::DECISION_STATE_DECIDED) {
            // Still open. Suspend again, and do NOT touch the slot.
            throw $this->suspension(config: $config);
        }

        return $this->placeOutcome(
            items: $items,
            config: $config,
            outcome: $this->outcomeFor(read: $read, ref: $ref, context: $context, resume: $resume)
        );

    }//end onReEntry()


    /**
     * The identity the read back is scoped to.
     *
     * THE SAME IDENTITY THAT RAISED IT, which is why it is recorded at raise
     * time rather than taken fresh. decidiq stamps a Decision's owner from the
     * uid that created it, so naming any other one answers "not permitted" —
     * and a run whose `runAs` was changed after it parked would otherwise start
     * being refused the decision it raised itself.
     *
     * The run's current acting identity is the fallback, so a run that parked
     * BEFORE this change deployed — and therefore recorded nothing — still
     * gains the recovery on its next heartbeat instead of needing a repair.
     *
     * @param array               $context Run-level metadata.
     * @param FlowNodeResumeState $resume  This node's resume slot.
     *
     * @return string The uid, or '' when this run names none.
     *
     * @spec openspec/changes/requestdecision-recovers-a-missed-conclusion/specs/case-flow-human-steps/spec.md
     */
    private function actorFor(array $context, FlowNodeResumeState $resume): string {
        $recorded = trim((string) $resume->get(key: 'raisedBy', default: ''));
        if ($recorded !== '') {
            return $recorded;
        }

        return trim((string) ($context[FlowRunService::RUN_AS_CONTEXT_KEY] ?? ''));

    }//end actorFor()


    /**
     * The outcome the flow routes on, taken from the decision.
     *
     * THE DECISION DECIDES, THE WAKE DECORATES. decidiq's status is what makes
     * this an outcome, so it is written last and cannot be overridden; the
     * wake's payload contributes only what the read does not carry. On the
     * heartbeat path there is no payload at all, and `recovered` says so,
     * because a run that advanced without its announcement is worth being able
     * to find afterwards.
     *
     * @param array               $read    The delegation's answer.
     * @param string              $ref     The decision this node raised.
     * @param array               $context Run-level metadata.
     * @param FlowNodeResumeState $resume  This node's resume slot.
     *
     * @return array The outcome bag.
     *
     * @spec openspec/changes/requestdecision-recovers-a-missed-conclusion/specs/case-flow-human-steps/spec.md
     */
    private function outcomeFor(array $read, string $ref, array $context, FlowNodeResumeState $resume): array {
        $signal = ($context[FlowRunService::SIGNAL_CONTEXT_KEY] ?? null);
        if (is_array($signal) === false) {
            $signal = [];
        }

        $status    = (string) $read['status'];
        $envelope  = (array) $read['envelope'];
        $recovered = ($signal === []);
        if ($recovered === true) {
            $this->logger->info(
                'Dossiq requestDecision: a heartbeat delivered the outcome of decision ' . $ref
                    . '; its conclusion never reached the run',
                ['node' => $resume->nodeId(), 'status' => $status]
            );
        }

        return array_merge(
            $signal,
            [
                'decision'    => $status,
                'status'      => $status,
                'decisionRef' => $ref,
                'node'        => $resume->nodeId(),
                'decidedAt'   => (string) ($envelope['decidedAt'] ?? ''),
                'signed'      => (bool) ($envelope['signed'] ?? false),
                'recovered'   => $recovered,
            ]
        );

    }//end outcomeFor()


    /**
     * Write the outcome onto every item, under the configured key.
     *
     * Onto every item, not into the run: the steps after this route per item,
     * and a switch cannot branch on something only the run holds.
     *
     * @param array $items   The items to pass on.
     * @param array $config  The step configuration.
     * @param array $outcome The outcome bag.
     *
     * @return array The items, each carrying the outcome.
     *
     * @spec openspec/changes/requestdecision-recovers-a-missed-conclusion/specs/case-flow-human-steps/spec.md
     */
    private function placeOutcome(array $items, array $config, array $outcome): array {
        $key = trim((string) ($config['signalKey'] ?? ''));
        if ($key === '') {
            $key = 'decisionOutcome';
        }

        $out = [];
        foreach ($items as $item) {
            if (is_array($item) === true) {
                $json         = (array) ($item['json'] ?? []);
                $json[$key]   = $outcome;
                $item['json'] = $json;
            }

            $out[] = $item;
        }

        return $out;

    }//end placeOutcome()


    /**
     * The suspension this node parks on, with a reason that names what is
     * being waited for so a paused case explains itself.
     *
     * @param array $config The step configuration.
     *
     * @return FlowSuspension The suspension to throw.
     *
     * @spec openspec/changes/requestdecision-recovers-a-missed-conclusion/specs/case-flow-human-steps/spec.md
     */
    private function suspension(array $config): FlowSuspension {
        $question = trim((string) ($config['question'] ?? ''));
        if ($question === '') {
            $question = 'a decision';
        }

        return new FlowSuspension(
            resumeAt: $this->heartbeatAt(config: $config),
            reason: sprintf('waiting for a decision: %s', $question)
        );

    }//end suspension()


    /**
     * Raise the decision once, and remember which one it is.
     *
     * @param array               $items   The input items; the first carries the case.
     * @param array               $config  The step configuration.
     * @param array               $context Run-level metadata.
     * @param FlowNodeResumeState $resume  This node's resume slot.
     *
     * @return void
     *
     * @throws RuntimeException When the decision cannot be raised or carries no ref.
     *
     * @spec openspec/changes/requestdecision-recovers-a-missed-conclusion/specs/case-flow-human-steps/spec.md
     */
    private function raise(array $items, array $config, array $context, FlowNodeResumeState $resume): void {
        $case  = [];
        $first = ($items[0] ?? null);
        if (is_array($first) === true) {
            $case = (array) ($first['json'] ?? []);
        }

        $caseId = (string) ($case['id'] ?? ($case['uuid'] ?? ''));
        if ($caseId === '') {
            throw new RuntimeException('dossiq.requestDecision had no case to decide on');
        }

        $decisionType = trim((string) ($config['decisionType'] ?? ''));
        if ($decisionType === '') {
            $decisionType = ContractDecisionDelegationService::DECISION_TYPE_ADVICE;
        }

        try {
            // The raise runs under the flow run's `runAs` identity: the
            // engine's RegistryStepDispatcher executes every contributed node
            // inside `ObjectService::runAs()` (openregister#3332), so the
            // whole dispatch — including decidiq's synchronous listener write
            // through the object store — is scoped to the run's acting user
            // without a local wrap.
            $ref = $this->delegation->raiseDecision(
                decisionType: $decisionType,
                externalReference: $caseId,
                subject: [
                    'subjectRegister' => 'dossiq',
                    'subjectSchema'   => 'case',
                    'subjectId'       => $caseId,
                    'subjectLabel'    => (string) ($case['title'] ?? ''),
                ],
                context: [
                    'question' => trim((string) ($config['question'] ?? '')),
                    'advisor'  => trim((string) ($config['advisor'] ?? '')),
                ],
            );
        } catch (Throwable $e) {
            // NOT swallowed. The delegation fails closed when decidiq is
            // absent, and softening that here would let the run proceed past a
            // decision nobody made. Re-thrown so the step fails and the run
            // stops on it.
            $this->logger->error(
                'Dossiq requestDecision: could not raise the decision; the run stops here',
                ['case' => $caseId, 'error' => $e->getMessage()]
            );

            throw new RuntimeException('decision_could_not_be_raised: ' . $e->getMessage(), 0, $e);
        }//end try

        if (trim($ref) === '') {
            throw new RuntimeException('decision_raised_without_a_reference');
        }

        $slot = [
            'decisionRef' => $ref,
            'askedAt'     => (new DateTime())->format('c'),
            'question'    => trim((string) ($config['question'] ?? '')),
        ];

        // Read back by the recovery, which must ask decidiq AS the identity
        // that raised the decision — decidiq stamps the owner from it, so any
        // other uid is answered "not permitted". Recorded only when the run
        // names one, so a run that names none falls back to its live `runAs`
        // rather than to a stored empty string.
        $raisedBy = trim((string) ($context[FlowRunService::RUN_AS_CONTEXT_KEY] ?? ''));
        if ($raisedBy !== '') {
            $slot['raisedBy'] = $raisedBy;
        }

        $resume->merge(values: $slot);

        $this->logger->info(
            'Dossiq requestDecision: raised decision ' . $ref . ' and suspended the run',
            ['case' => $caseId, 'node' => $resume->nodeId()]
        );

    }//end raise()


    /**
     * When to wake and re-check, absent an outcome.
     *
     * @param array $config The step configuration.
     *
     * @return DateTime The next heartbeat.
     */
    private function heartbeatAt(array $config): DateTime {
        $minutes = (int) ($config['heartbeatMinutes'] ?? self::DEFAULT_HEARTBEAT_MINUTES);
        if ($minutes < self::MIN_HEARTBEAT_MINUTES) {
            $minutes = self::MIN_HEARTBEAT_MINUTES;
        }

        return (new DateTime())->modify('+' . $minutes . ' minutes');

    }//end heartbeatAt()


}//end class
