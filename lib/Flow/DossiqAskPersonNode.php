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
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\FlowValueTemplate;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCP\IL10N;
use OCP\WorkflowEngine\IManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * Ask a person something, and wait for their answer.
 *
 * WHY THIS IS ONE NODE AND NOT TWO. The obvious composition — `createTask`
 * followed by `await-signal` — cannot work, and the reason is worth stating
 * because it looks like it should. A run holds one awaiting slot PER NODE, so
 * whatever resumes it must name the node, not just the run. `createTask` runs
 * BEFORE the await node and therefore cannot know which node the task will end
 * up blocking. The task would be created with no way back to the question it
 * answers, and the flow would look correct while nothing could ever wake it.
 *
 * So the node that suspends is the node that creates the task, and it stamps
 * the task with its own run and its own id. That is the whole design.
 *
 * 🔴 THE HEARTBEAT MUST NOT CREATE A SECOND TASK. This node suspends with a
 * resume time as a safety net against a lost signal, which means it is
 * re-entered on a timer with no answer present. Creating the task
 * unconditionally would leave one task per heartbeat sitting in somebody's
 * list — every one of them able to resume the run, and all but one of them
 * noise. Creation is therefore guarded on the resume slot, exactly as
 * AwaitSignalNode guards its own request record.
 *
 * 🔴 THE ANSWER IS IN THE TASK, NOT IN THE SIGNAL. This node used to advance
 * only when a signal carrying a `decision` arrived, which made the heartbeat a
 * timer that could do nothing but suspend again. A completion whose signal was
 * refused (the assignee guard, a group that did not exist yet) or lost
 * therefore wedged the run PERMANENTLY: `resume_at` rolled 11:28 → 11:29 →
 * 11:30 while the task sat `completed` in somebody's finished list. That is
 * exactly the wedge openregister#3358 taught `UserTaskNode` to recover from,
 * and the recovery never reached this node because the recovery lives in the
 * engine's node and the shipped case flow waits on this one.
 *
 * So a re-entry RE-READS the task the slot names, and terminality — a property
 * of that row — is what advances the run. A signal is then what it always
 * should have been: a wake, whose payload adds detail the row does not carry
 * (who completed it) and never decides the answer. That also removes a race
 * this node used to lose: `context.signal` is ONE slot per run, so a flow with
 * two asks had the second read the answer given to the first.
 *
 * @spec openspec/changes/askperson-recovers-a-missed-answer/specs/case-flow-human-steps/spec.md
 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One over the threshold, and
 *     every dependency is load-bearing: the node speaks OpenRegister's whole
 *     suspend/resume vocabulary (suspension, resume slot, run context, signal
 *     key, value template) AND dossiq's own storage seam. Splitting a class to
 *     shed an import would separate the ask from the wait it exists to pair.
 */
class DossiqAskPersonNode implements IFlowNode {

    /**
     * Minutes between heartbeats when the config names none.
     *
     * @var integer
     */
    private const DEFAULT_HEARTBEAT_MINUTES = 30;

    /**
     * The shortest heartbeat this node will honour.
     *
     * A lower one is not faster: a completed task wakes the run immediately
     * through the signal, and the heartbeat is what delivers the answer in the
     * case where that signal was refused or lost. It bounds how late a
     * recovery can be, not how quick the normal path is.
     *
     * @var integer
     */
    private const MIN_HEARTBEAT_MINUTES = 5;

    /**
     * The status that means the person answered.
     *
     * @var string
     */
    private const STATUS_COMPLETED = 'completed';

    /**
     * The statuses a task never leaves, from the task schema's own CMMN
     * vocabulary — the same three its `isTerminalStatus` calculation names.
     *
     * Only `completed` is an ANSWER. The other two are the question being
     * withdrawn, which is why reaching them fails the step rather than
     * continuing: a case that carried on past a cancelled ask would proceed as
     * though somebody had answered it, and nobody did.
     *
     * @var string[]
     */
    private const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        'terminated',
        'disabled',
    ];


    /**
     * The task rows this ask writes and reads back.
     *
     * @var AskPersonTaskStore
     */
    private readonly AskPersonTaskStore $tasks;


    /**
     * Constructor.
     *
     * The store is BUILT here rather than injected, so the node's published
     * dependencies are unchanged and every existing construction site — the
     * container, and the suites that build it by hand — keeps working. It
     * needs nothing this node was not already given.
     *
     * @param SettingsService $settingsService Resolves the object service and configured schemas.
     * @param IL10N           $l10n            The localisation service.
     * @param LoggerInterface $logger          The logger.
     *
     * @return void
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function __construct(
        SettingsService $settingsService,
        private readonly IL10N $l10n,
        private readonly LoggerInterface $logger,
    ) {
        $this->tasks = new AskPersonTaskStore(settingsService: $settingsService);

    }//end __construct()


    /**
     * This node's catalogue id.
     *
     * @return string The namespaced node id.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function getId(): string {
        return 'dossiq.askPerson';

    }//end getId()


    /**
     * The node's display name.
     *
     * @return string The translated name.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function getDisplayName(): string {
        return $this->l10n->t('Ask a person');

    }//end getDisplayName()


    /**
     * What the node does.
     *
     * @return string The translated description.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function getDescription(): string {
        return $this->l10n->t('Create a task for somebody and pause the case until they complete it.');

    }//end getDescription()


    /**
     * The node's icon.
     *
     * @return string The icon name.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function getIcon(): string {
        return 'account-question';

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
     * Refuse a step that asks nothing of nobody.
     *
     * @param array $config The step configuration.
     *
     * @return void
     *
     * @throws UnexpectedValueException When the question or the assignee is missing.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function validateConfig(array $config): void {
        if (trim((string) ($config['question'] ?? '')) === '') {
            throw new UnexpectedValueException(
                $this->l10n->t('Say what is being asked, or nobody can answer it.')
            );
        }

        // An unassigned question is not a question. OpenRegister's resume guard
        // deliberately lets ANYONE answer a step that names no assignee, so an
        // empty assignee here would not merely be untidy — it would open the
        // case's progress to any authenticated user.
        if (trim((string) ($config['assignee'] ?? '')) === '') {
            throw new UnexpectedValueException(
                $this->l10n->t('Say who is being asked. A task nobody is assigned can be completed by anyone.')
            );
        }

    }//end validateConfig()


    /**
     * Create the task on the first pass; on every later pass, read it.
     *
     * The whole shape of this method is the recovery: a re-entry — a wake from
     * the completion signal, or a heartbeat with nothing in hand — asks the
     * TASK whether it is finished. That makes the heartbeat able to deliver an
     * answer whose signal never arrived, which is the difference between a
     * safety net and a timer that only ever re-suspends.
     *
     * @param array $items   The input items.
     * @param array $config  The step configuration.
     * @param array $context Run-level metadata.
     *
     * @return array The items, each carrying the answer.
     *
     * @throws FlowSuspension While the task is outstanding, or unreadable.
     * @throws RuntimeException When the node has no resume slot, its task is
     *                          gone, or the ask was withdrawn.
     *
     * @spec openspec/changes/askperson-recovers-a-missed-answer/specs/case-flow-human-steps/spec.md
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function execute(array $items, array $config, array $context): array {
        $this->validateConfig(config: $config);

        $resume = ($context[FlowNodeResumeState::CONTEXT_KEY] ?? null);
        if (($resume instanceof FlowNodeResumeState) === false) {
            // Without a slot there is nowhere to record that the task exists,
            // so a heartbeat would create another one every time it woke, and
            // nothing would name the task to re-read. Refusing is the safe
            // direction: a step that cannot be made idempotent must not run.
            throw new RuntimeException('dossiq.askPerson requires a node resume slot');
        }

        $taskId = trim((string) $resume->get(key: 'taskId', default: ''));
        if ($taskId === '') {
            $this->createTask(items: $items, config: $config, context: $context, resume: $resume);

            throw $this->suspension(config: $config);
        }

        try {
            $task = $this->tasks->find(taskId: $taskId);
        } catch (Throwable $failure) {
            // Storage was unreachable, not the task absent. Parking again
            // costs one heartbeat; concluding "gone" from a hiccup would fail
            // a case whose task is sitting there answered.
            $this->logger->warning(
                'Dossiq askPerson: could not read task ' . $taskId . '; waiting another heartbeat',
                ['error' => $failure->getMessage(), 'node' => $resume->nodeId()]
            );

            throw $this->suspension(config: $config);
        }//end try

        if ($task === null) {
            // The row this run was waiting on is gone. Waiting further would
            // wait forever; carrying on would invent an answer.
            throw new RuntimeException(
                sprintf('Task %s, which dossiq.askPerson was waiting on, no longer exists.', $taskId)
            );
        }

        $status = strtolower(trim((string) ($task['status'] ?? '')));
        if (in_array($status, self::TERMINAL_STATUSES, true) === false) {
            // Reassigned, re-dated, a checklist item ticked: none of those is
            // an answer. Suspend again, and do NOT touch the slot.
            throw $this->suspension(config: $config);
        }

        if ($status !== self::STATUS_COMPLETED) {
            throw new RuntimeException(
                sprintf(
                    'Task %s was %s, so the question dossiq.askPerson asked will never be answered.',
                    $taskId,
                    $status
                )
            );
        }

        return $this->placeAnswer(
            items: $items,
            config: $config,
            answer: $this->answerFor(task: $task, taskId: $taskId, context: $context, resume: $resume)
        );

    }//end execute()


    /**
     * Create the task, once, and remember which one it is.
     *
     * @param array               $items   The input items; the first carries the case.
     * @param array               $config  The step configuration.
     * @param array               $context Run-level metadata.
     * @param FlowNodeResumeState $resume  This node's resume slot.
     *
     * @return void
     *
     * @spec openspec/changes/askperson-recovers-a-missed-answer/specs/case-flow-human-steps/spec.md
     */
    private function createTask(array $items, array $config, array $context, FlowNodeResumeState $resume): void {
        $caseId   = $this->caseIdFrom(items: $items);
        $assignee = $this->renderedAssignee(config: $config, items: $items);
        $taskId   = $this->tasks->create(
            task: $this->buildTask(caseId: $caseId, assignee: $assignee, config: $config, context: $context, resume: $resume)
        );

        $resume->merge(
            values: [
                'taskId'   => $taskId,
                'askedAt'  => (new DateTime())->format('c'),
                'question' => trim((string) ($config['question'] ?? '')),
                // Read back by OpenRegister's resume guard, which refuses a
                // signal from anyone but this person or their group. The
                // RENDERED name, never the raw template: the guard compares
                // this against real uids and group ids, so a stored literal
                // "{{ case.assignee }}" would refuse every real user.
                'assignee' => $assignee,
            ]
        );

        $this->logger->info(
            'Dossiq askPerson: created task ' . $taskId . ' and suspended the run',
            ['case' => $caseId, 'node' => $resume->nodeId()]
        );

    }//end createTask()


    /**
     * The suspension this node parks on, with a reason that names what is
     * being waited for so a paused case explains itself.
     *
     * @param array $config The step configuration.
     *
     * @return FlowSuspension The suspension to throw.
     *
     * @spec openspec/changes/askperson-recovers-a-missed-answer/specs/case-flow-human-steps/spec.md
     */
    private function suspension(array $config): FlowSuspension {
        $question = trim((string) ($config['question'] ?? ''));
        if ($question === '') {
            $question = 'a task';
        }

        return new FlowSuspension(
            resumeAt: $this->heartbeatAt(config: $config),
            reason: sprintf('waiting for a person: %s', $question)
        );

    }//end suspension()


    /**
     * The answer the flow routes on, taken from the task.
     *
     * THE TASK DECIDES, THE SIGNAL DECORATES. The row's status is what makes
     * this an answer, so it is written last and cannot be overridden; the
     * wake's payload contributes only what the row does not carry — who
     * completed it, which the schema does not record. On the heartbeat path
     * there is no payload at all, and `recovered` says so, because a run that
     * advanced without its wake is worth being able to find afterwards.
     *
     * @param array               $task    The terminal task.
     * @param string              $taskId  The task id held in the slot.
     * @param array               $context Run-level metadata.
     * @param FlowNodeResumeState $resume  This node's resume slot.
     *
     * @return array The answer bag.
     *
     * @spec openspec/changes/askperson-recovers-a-missed-answer/specs/case-flow-human-steps/spec.md
     */
    private function answerFor(array $task, string $taskId, array $context, FlowNodeResumeState $resume): array {
        $signal = ($context[FlowRunService::SIGNAL_CONTEXT_KEY] ?? null);
        if (is_array($signal) === false) {
            $signal = [];
        }

        $recovered = ($signal === []);
        if ($recovered === true) {
            $this->logger->info(
                'Dossiq askPerson: a heartbeat delivered the answer to task ' . $taskId
                    . '; its completion signal never reached the run',
                ['node' => $resume->nodeId(), 'case' => (string) ($task['case'] ?? '')]
            );
        }

        return array_merge(
            $signal,
            [
                'decision'      => self::STATUS_COMPLETED,
                'status'        => self::STATUS_COMPLETED,
                'taskId'        => $taskId,
                'node'          => $resume->nodeId(),
                'completedDate' => (string) ($task['completedDate'] ?? ''),
                'recovered'     => $recovered,
            ]
        );

    }//end answerFor()


    /**
     * Write the answer onto every item, under the configured key.
     *
     * Onto every item, not into the run: the steps after this route per item,
     * and a switch cannot branch on something only the run holds.
     *
     * @param array $items  The items to pass on.
     * @param array $config The step configuration.
     * @param array $answer The answer bag.
     *
     * @return array The items, each carrying the answer.
     *
     * @spec openspec/changes/askperson-recovers-a-missed-answer/specs/case-flow-human-steps/spec.md
     */
    private function placeAnswer(array $items, array $config, array $answer): array {
        $key = trim((string) ($config['signalKey'] ?? ''));
        if ($key === '') {
            $key = 'answer';
        }

        $out = [];
        foreach ($items as $item) {
            if (is_array($item) === true) {
                $json         = (array) ($item['json'] ?? []);
                $json[$key]   = $answer;
                $item['json'] = $json;
            }

            $out[] = $item;
        }

        return $out;

    }//end placeAnswer()


    /**
     * The case the items carry.
     *
     * @param array $items The input items; the first carries the case.
     *
     * @return string The case id.
     *
     * @throws RuntimeException When there is no case to attach a task to.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    private function caseIdFrom(array $items): string {
        $case  = [];
        $first = ($items[0] ?? null);
        if (is_array($first) === true) {
            $case = (array) ($first['json'] ?? []);
        }

        $caseId = (string) ($case['id'] ?? ($case['uuid'] ?? ''));
        if ($caseId === '') {
            throw new RuntimeException('dossiq.askPerson had no case to attach a task to');
        }

        return $caseId;

    }//end caseIdFrom()


    /**
     * The assignee this ask resolves to, rendered against the case.
     *
     * A declared flow cannot name a real person: the uid differs per case, so
     * the shipped declaration says `{{ case.assignee }}` and somebody must
     * render it. The engine does not — it templates only inside its own
     * set-fields and object-read nodes — so a node that stamps authored values
     * onto storage renders them itself, exactly as OpenRegister's own
     * value-bearing nodes do through FlowValueTemplate. Storing the literal is
     * what orphaned every applicant task live: FlowRunAssignee compared real
     * uids against the un-rendered placeholder and refused all of them.
     *
     * 🔴 AN EMPTY RENDERING NEVER FALLS BACK TO NOBODY. A template that
     * resolves to nothing would create an UNASSIGNED task, and OpenRegister's
     * resume guard deliberately lets anyone answer a step that names no
     * assignee — so a quiet empty here would open the case's progress to any
     * authenticated user.
     *
     * 🔴 BUT REFUSING WAS NOT A DEFINED BEHAVIOUR EITHER, AND IT KILLED RUNS.
     * `{{ case.assignee }}` is the shipped spelling, and `assignee` is NOT in
     * the case schema's `required`: a case filed from the New case dialog with
     * only a title and a case type has none. The step then threw, the run
     * failed, and the case sat in `Wacht op aanvulling` with nothing waiting
     * on it and no task for anybody. Reproduced twice on clean installs.
     *
     * So a declaration may name an `assigneeFallback`: the principal the ask
     * goes to when the primary resolves to nobody. That is a DECLARED second
     * choice, not a silent one. It is written in the flow, CaseFlowDeclaration
     * Test requires it of every templated assignee, and ProvisionAssignedGroups
     * Test requires the group it names to be provisioned. A declaration with no
     * fallback still refuses, and a fallback that itself resolves to nothing
     * refuses too: failing closed stays the last word.
     *
     * The case is offered under both its own keys and a `case.` prefix,
     * because the declarations write `{{ case.assignee }}` — the same spelling
     * dossiq's template nodes already use — while the item's json IS the case.
     *
     * @param array $config The step configuration.
     * @param array $items  The input items; the first carries the case.
     *
     * @return string The rendered assignee.
     *
     * @throws RuntimeException When neither the assignee nor its declared
     *                          fallback resolves to a principal.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    private function renderedAssignee(array $config, array $items): string {
        $case  = [];
        $first = ($items[0] ?? null);
        if (is_array($first) === true) {
            $case = (array) ($first['json'] ?? []);
        }

        $json = array_merge($case, ['case' => $case]);

        $primary  = trim((string) ($config['assignee'] ?? ''));
        $resolved = $this->renderPrincipal(raw: $primary, json: $json);
        if ($resolved !== '') {
            return $resolved;
        }

        $fallback = trim((string) ($config['assigneeFallback'] ?? ''));
        if ($fallback !== '') {
            $resolved = $this->renderPrincipal(raw: $fallback, json: $json);
            if ($resolved !== '') {
                $this->logger->info(
                    'Dossiq askPerson: "' . $primary . '" named nobody on this case, so the ask goes to its '
                        . 'declared fallback "' . $resolved . '"',
                    ['case' => (string) ($case['id'] ?? ($case['uuid'] ?? ''))]
                );

                return $resolved;
            }
        }

        $why = 'the step declares no assigneeFallback to send the ask to instead';
        if ($fallback !== '') {
            $why = sprintf('its fallback "%s" resolved to nobody either', $fallback);
        }

        throw new RuntimeException(
            sprintf(
                'dossiq.askPerson could not resolve the assignee "%s" against the case, and %s',
                $primary,
                $why
            )
        );

    }//end renderedAssignee()


    /**
     * Render one authored principal against the case, or return nothing.
     *
     * "Nothing" covers all three ways an authored value fails to name
     * somebody: an empty rendering, one the engine could not resolve, and one
     * that came back as a structure rather than a name. The caller decides
     * what to do about it, because the answer differs between the primary
     * assignee and its fallback.
     *
     * @param string $raw  The authored value, template or literal.
     * @param array  $json The case, under its own keys and a `case.` prefix.
     *
     * @return string The rendered principal, or '' when it names nobody.
     *
     * @SuppressWarnings(PHPMD.StaticAccess) FlowValueTemplate is the engine's
     *     canonical rendering API and is published as a static, final class —
     *     there is no instance to inject.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    private function renderPrincipal(string $raw, array $json): string {
        if ($raw === '') {
            return '';
        }

        $rendered = FlowValueTemplate::renderTracked(value: $raw, json: $json);
        $value    = $rendered['value'];
        if (is_array($value) === true || $rendered['unresolved'] !== []) {
            return '';
        }

        return trim((string) $value);

    }//end renderPrincipal()


    /**
     * The task record this step asks somebody to complete.
     *
     * @param string              $caseId   The case the task belongs to.
     * @param string              $assignee The RENDERED assignee, never the raw template.
     * @param array               $config   The step configuration.
     * @param array               $context  Run-level metadata.
     * @param FlowNodeResumeState $resume   This node's resume slot, which knows its id.
     *
     * @return array The task to persist.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    private function buildTask(string $caseId, string $assignee, array $config, array $context, FlowNodeResumeState $resume): array {
        $task = [
            'title'       => trim((string) ($config['question'] ?? '')),
            'description' => trim((string) ($config['details'] ?? '')),
            'case'        => $caseId,
            'status'      => 'available',
            'assignee'    => $assignee,
            // The two fields that make this task an answer to a specific
            // question rather than a loose to-do. Both are required to resume:
            // the run alone cannot say which of its awaiting nodes this is for.
            'flowRun'     => (string) ($context[FlowRunContext::CONTEXT_RUN] ?? ''),
            'flowNode'    => $resume->nodeId(),
        ];

        $due = trim((string) ($config['dueInDays'] ?? ''));
        if ($due !== '' && ctype_digit($due) === true) {
            $task['dueDate'] = (new DateTime())->modify('+' . $due . ' days')->format('c');
        }

        return $task;

    }//end buildTask()


    /**
     * When to wake and re-check, absent an answer.
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
