<?php

/**
 * A completed task resumes its run — and only when it should.
 *
 * WHAT THESE TESTS DO AND DO NOT PROVE. The authorization RULE lives in
 * OpenRegister, inside `FlowRunSignalService::signalAs()` (openregister#3332),
 * and is tested there, including a mutation check. dossiq's suite resolves
 * OpenRegister to stubs, so re-testing the guard here would test a stub
 * against itself — a second implementation validated by a second fake,
 * drifting from the real one while both suites stay green.
 *
 * So what is proven here is the half that IS dossiq's: that the listener
 * signals through the guarded seam with the right actor and node, and that it
 * OBEYS a refusal — the typed `FlowSignalRefused` is caught and the run is
 * left alone. The double below is told what to answer, and the assertions are
 * about what the listener then does.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/adopt-flow-engine-consumer-seams/specs/task-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\TaskCompletionResumeListener;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Exception\FlowSignalRefused;
use OCA\OpenRegister\Service\Flow\FlowRunSignalService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TaskCompletionResumeListenerTest extends TestCase {

	/**
	 * Records every delivered signal: uuid, payload, actor, node.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $signals = [];

	/**
	 * The refusal the seam double throws, when it refuses at all.
	 *
	 * @var FlowSignalRefused|null
	 */
	private ?FlowSignalRefused $refusal = null;

	protected function setUp(): void {
		$this->signals = [];
		$this->refusal = null;
	}//end setUp()

	/**
	 * A task object, as the event carries it.
	 *
	 * @param array $overrides Fields to set or override.
	 *
	 * @return array The task.
	 */
	private function task(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'task-1',
				'status' => 'completed',
				'flowRun' => 'run-1',
				'flowNode' => 'ask-indiener',
			],
			$overrides
		);
	}//end task()

	/**
	 * An update event carrying a before and after state.
	 *
	 * @param array      $new The task after.
	 * @param array|null $old The task before.
	 *
	 * @return ObjectUpdatedEvent The event.
	 */
	private function event(array $new, ?array $old): ObjectUpdatedEvent {
		$newEntity = $this->createMock(ObjectEntity::class);
		$newEntity->method('getObject')->willReturn($new);

		$oldEntity = null;
		if ($old !== null) {
			$oldEntity = $this->createMock(ObjectEntity::class);
			$oldEntity->method('getObject')->willReturn($old);
		}

		$event = $this->createMock(ObjectUpdatedEvent::class);
		$event->method('getNewObject')->willReturn($newEntity);
		$event->method('getOldObject')->willReturn($oldEntity);

		return $event;
	}//end event()

	/**
	 * The listener, wired to a seam double that records what it delivers.
	 *
	 * @param string|null $uid The acting user.
	 *
	 * @return TaskCompletionResumeListener The listener.
	 */
	private function listener(?string $uid = 'alice'): TaskCompletionResumeListener {
		$signals = new class($this->signals, $this->refusal) extends FlowRunSignalService {
			public function __construct(private array &$sink, private ?FlowSignalRefused &$refusal) {
			}

			public function signalAs(string $runUuid, array $payload, ?string $actorUid, ?string $nodeId = null): FlowRun {
				if ($this->refusal !== null) {
					throw $this->refusal;
				}

				$this->sink[] = ['run' => $runUuid, 'payload' => $payload, 'actor' => $actorUid, 'node' => $nodeId];

				$run = new FlowRun();
				$run->setUuid($runUuid);

				return $run;
			}
		};

		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		return new TaskCompletionResumeListener(
			signals: $signals,
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end listener()

	/**
	 * The old state is `active`, not `available`: the task schema's CMMN
	 * lifecycle (REQ-TASK-002) refuses a one-step available → completed
	 * update, so the only completion event the store can emit comes from an
	 * active task. The seeded states here mirror that.
	 */
	public function testCompletingAFlowTaskResumesItsRun(): void {
		$this->listener()->handle(
			$this->event($this->task(), $this->task(['status' => 'active']))
		);

		$this->assertCount(1, $this->signals);
		$this->assertSame('run-1', $this->signals[0]['run']);
		$this->assertSame('completed', $this->signals[0]['payload']['decision']);
		$this->assertSame('ask-indiener', $this->signals[0]['payload']['node']);
	}//end testCompletingAFlowTaskResumesItsRun()

	/**
	 * The guard can only judge the actor and the node it is HANDED. Passing
	 * the session user and the task's node is dossiq's whole remaining duty on
	 * this path; a listener that passed null-for-actor would turn an assigned
	 * step's refusal into an anonymous one.
	 */
	public function testTheSeamIsHandedTheActorAndTheAddressedNode(): void {
		$this->listener(uid: 'alice')->handle(
			$this->event($this->task(), $this->task(['status' => 'active']))
		);

		$this->assertCount(1, $this->signals);
		$this->assertSame('alice', $this->signals[0]['actor']);
		$this->assertSame('ask-indiener', $this->signals[0]['node']);
		$this->assertSame('alice', $this->signals[0]['payload']['completedBy']);
	}//end testTheSeamIsHandedTheActorAndTheAddressedNode()

	/**
	 * 🔴 THE SECURITY TEST. A refusal from the guarded seam withholds the
	 * resume and raises nothing: the task stays completed, the run stays
	 * parked, and the listener neither retries nor falls back to the
	 * unguarded primitive.
	 */
	public function testARefusalFromTheSeamWithholdsTheResumeQuietly(): void {
		$this->refusal = new FlowSignalRefused(
			reason: FlowSignalRefused::NOT_ASSIGNEE,
			message: 'not the assignee',
			runUuid: 'run-1',
			actorUid: 'mallory'
		);

		$this->listener(uid: 'mallory')->handle(
			$this->event($this->task(), $this->task(['status' => 'active']))
		);

		$this->assertSame([], $this->signals, 'The run must not advance for somebody who was not asked.');
	}//end testARefusalFromTheSeamWithholdsTheResumeQuietly()

	public function testATaskWithNoRunResumesNothing(): void {
		$this->listener()->handle(
			$this->event(
				$this->task(['flowRun' => '', 'flowNode' => '']),
				$this->task(['flowRun' => '', 'flowNode' => '', 'status' => 'active'])
			)
		);

		$this->assertSame([], $this->signals);
	}//end testATaskWithNoRunResumesNothing()

	/**
	 * A task naming a run but no node cannot say which question it answers, so
	 * it resumes nothing rather than guessing.
	 */
	public function testATaskWithARunButNoNodeResumesNothing(): void {
		$this->listener()->handle(
			$this->event(
				$this->task(['flowNode' => '']),
				$this->task(['flowNode' => '', 'status' => 'active'])
			)
		);

		$this->assertSame([], $this->signals);
	}//end testATaskWithARunButNoNodeResumesNothing()

	/**
	 * 🔴 Editing an ALREADY-completed task does not resume the run again.
	 *
	 * Any later edit — a typo fixed in the description — is still an update
	 * whose status reads `completed`. Resuming on the state rather than on the
	 * transition would advance the run a second time.
	 */
	public function testEditingAnAlreadyCompletedTaskDoesNotResumeAgain(): void {
		$this->listener()->handle(
			$this->event(
				$this->task(['title' => 'fixed a typo']),
				$this->task()
			)
		);

		$this->assertSame([], $this->signals);
	}//end testEditingAnAlreadyCompletedTaskDoesNotResumeAgain()

	/**
	 * An update that does not complete the task resumes nothing.
	 */
	public function testAnUnrelatedUpdateResumesNothing(): void {
		$this->listener()->handle(
			$this->event(
				$this->task(['status' => 'active']),
				$this->task(['status' => 'available'])
			)
		);

		$this->assertSame([], $this->signals);
	}//end testAnUnrelatedUpdateResumesNothing()

	/**
	 * With no previous state the transition cannot be established, so nothing
	 * is resumed — the safer of the two possible mistakes, since the other one
	 * re-signals on every write to a completed task.
	 */
	public function testAnUpdateWithNoPreviousStateResumesNothing(): void {
		$this->listener()->handle($this->event($this->task(), null));

		$this->assertSame([], $this->signals);
	}//end testAnUpdateWithNoPreviousStateResumesNothing()

	/**
	 * A vanished run is not an error for the person completing the task: the
	 * seam refuses with RUN_NOT_FOUND, and the listener records it quietly
	 * instead of raising.
	 */
	public function testATaskWhoseRunHasGoneStillCompletesQuietly(): void {
		$this->refusal = new FlowSignalRefused(
			reason: FlowSignalRefused::RUN_NOT_FOUND,
			message: 'no such run',
			runUuid: 'run-1',
			actorUid: 'alice'
		);

		$this->listener()->handle($this->event($this->task(), $this->task(['status' => 'active'])));

		$this->assertSame([], $this->signals);
	}//end testATaskWhoseRunHasGoneStillCompletesQuietly()
}//end class
