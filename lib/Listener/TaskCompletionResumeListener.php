<?php

/**
 * A completed task wakes the run that asked for it.
 *
 * Tasks are ordinary OpenRegister objects edited through the generic object
 * API, so "completing a task" is an object update — there is no dossiq task
 * endpoint to hang this on. This listener is therefore where the human step
 * closes: it sees the update, recognises a task that a flow is waiting on, and
 * signals the run.
 *
 * THE GUARD IS THE ENGINE'S NOW. This path used to consult the assignee rule
 * itself, because `FlowRunService::signal()` delivers unconditionally and the
 * HTTP guard was not inherited. openregister#3332 turned that duty into one
 * seam: {@see FlowRunSignalService::signalAs()} resolves the run, applies the
 * recorded-assignee rule (group resolution included), audits a refusal, and
 * delivers — so there is nothing left here to remember. A refusal arrives as
 * one typed {@see FlowSignalRefused}, whose reason says exactly what was
 * withheld and why.
 *
 * WHY IT NEVER BLOCKS THE UPDATE. The task is already saved by the time this
 * runs. Refusing here cannot un-complete it, so a refusal means "the task is
 * completed but the run is not resumed" — recorded loudly, because the
 * alternative (resuming anyway) is the security hole the seam exists to close.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-flow-human-steps/specs/task-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Exception\FlowSignalRefused;
use OCA\OpenRegister\Service\Flow\FlowRunSignalService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resumes a suspended flow run when the task it is waiting on is completed.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/case-flow-human-steps/specs/task-management/spec.md
 */
class TaskCompletionResumeListener implements IEventListener {
	/**
	 * The task status that means the person has answered.
	 *
	 * @var string
	 */
	private const STATUS_COMPLETED = 'completed';

	/**
	 * Constructor.
	 *
	 * @param FlowRunSignalService $signals     The engine's guarded signal seam:
	 *                                          resolve, assignee guard, audit and
	 *                                          delivery in one call.
	 * @param IUserSession         $userSession Identifies who completed the task.
	 * @param LoggerInterface      $logger      The logger.
	 */
	public function __construct(
		private readonly FlowRunSignalService $signals,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resume the run this task was blocking, if it was blocking one.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adopt-flow-engine-consumer-seams/specs/task-management/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		$task = $this->completedFlowTask(event: $event);
		if ($task === null) {
			return;
		}

		$runUuid = (string)$task['flowRun'];
		$uid = $this->userSession->getUser()?->getUID();

		try {
			$this->signals->signalAs(
				runUuid: $runUuid,
				payload: [
					// A resume with no `decision` is a nudge, not an answer, and
					// the awaiting node suspends again. Saying `completed`
					// explicitly is what makes this an answer.
					'decision' => 'completed',
					'node' => (string)$task['flowNode'],
					'taskId' => (string)($task['id'] ?? ''),
					'completedBy' => (string)$uid,
				],
				actorUid: $uid,
				// Addressing the node the task names makes the guard check THAT
				// node's recorded assignee, so a run awaiting two steps never
				// refuses this one for the other's audience.
				nodeId: (string)$task['flowNode'],
			);
		} catch (FlowSignalRefused $e) {
			$this->recordRefusal(refusal: $e, runUuid: $runUuid, task: $task, uid: $uid);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: could not resume flow run ' . $runUuid . ' after its task was completed',
				['error' => $e->getMessage(), 'task' => ($task['id'] ?? null)]
			);
		}//end try
	}//end handle()

	/**
	 * Record a refusal at the loudness its reason deserves.
	 *
	 * Only NOT_ASSIGNEE is an access refusal — the wrong person tried to
	 * advance somebody else's step, and the engine has already audited it; the
	 * warning here ties that audit to the task. The other reasons are ordinary
	 * life: a task naming a run that no longer exists is completable, it simply
	 * has nothing left to wake, and a run that is no longer suspended needed no
	 * waking. Neither is the completer's fault, so neither is raised.
	 *
	 * @param FlowSignalRefused    $refusal The typed refusal.
	 * @param string               $runUuid The run the task named.
	 * @param array<string, mixed> $task    The completed task.
	 * @param string|null          $uid     Who completed it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adopt-flow-engine-consumer-seams/specs/task-management/spec.md
	 */
	private function recordRefusal(FlowSignalRefused $refusal, string $runUuid, array $task, ?string $uid): void {
		if ($refusal->getReason() === FlowSignalRefused::NOT_ASSIGNEE) {
			$this->logger->warning(
				'Dossiq: the engine refused to resume flow run ' . $runUuid
					. ' — the user who completed the task is not the assignee of the awaiting step',
				['task' => ($task['id'] ?? null), 'user' => $uid, 'node' => $task['flowNode']]
			);
			return;
		}

		$this->logger->info(
			'Dossiq: a completed task named flow run ' . $runUuid . ', which could not be signalled: ' . $refusal->getMessage(),
			['task' => ($task['id'] ?? null), 'reason' => $refusal->getReason()]
		);
	}//end recordRefusal()

	/**
	 * The task this event just completed, when it is one a flow is waiting on.
	 *
	 * Returns null for everything else — an update to a non-task object, a task
	 * belonging to no run, an unrelated field change, or a re-save of a task
	 * that was already completed. Each of those is an ordinary thing to do and
	 * must resume nothing.
	 *
	 * @param ObjectUpdatedEvent $event The update.
	 *
	 * @return array|null The task, or null when nothing should be resumed.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/task-management/spec.md
	 */
	private function completedFlowTask(ObjectUpdatedEvent $event): ?array {
		try {
			$new = $event->getNewObject()->getObject();
			$old = $event->getOldObject()?->getObject();
		} catch (Throwable $e) {
			return null;
		}

		if (is_array($new) === false) {
			return null;
		}

		// Both are required. A task naming a run but no node cannot say WHICH
		// of that run's awaiting nodes it answers, so it resumes nothing rather
		// than guessing.
		if (trim((string)($new['flowRun'] ?? '')) === '' || trim((string)($new['flowNode'] ?? '')) === '') {
			return null;
		}

		if ($this->justCompleted(new: $new, old: $old) === false) {
			return null;
		}

		return $new;
	}//end completedFlowTask()

	/**
	 * Whether this update is the moment the task became completed.
	 *
	 * The transition matters, not the state. Any later edit of an
	 * already-completed task — a typo fixed in its description — is still an
	 * update whose status reads `completed`, and resuming on that would advance
	 * the run a second time.
	 *
	 * A missing previous state is treated as NOT a transition. Resuming on it
	 * would mean every unrelated write to a completed task re-signals the run,
	 * which is the more damaging of the two possible mistakes.
	 *
	 * @param array      $new The task after the update.
	 * @param array|null $old The task before it, when known.
	 *
	 * @return boolean True when the task has just become completed.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/task-management/spec.md
	 */
	private function justCompleted(array $new, ?array $old): bool {
		if (strtolower(trim((string)($new['status'] ?? ''))) !== self::STATUS_COMPLETED) {
			return false;
		}

		if (is_array($old) === false) {
			return false;
		}

		return strtolower(trim((string)($old['status'] ?? ''))) !== self::STATUS_COMPLETED;
	}//end justCompleted()
}//end class
