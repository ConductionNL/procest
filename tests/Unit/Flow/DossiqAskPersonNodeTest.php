<?php

/**
 * Unit tests for DossiqAskPersonNode — create the task, then wait.
 *
 * 🔴 THE TEST THAT MATTERS MOST IS THE HEARTBEAT ONE. This node suspends with a
 * resume time as a safety net against a lost signal, which means it is
 * re-entered on a timer with no answer present. Creating the task
 * unconditionally would leave one task per heartbeat in somebody's list — every
 * one of them able to resume the run, all but one of them noise. Nothing about
 * the resulting tasks would look malformed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Flow;

use OCA\Dossiq\Flow\DossiqAskPersonNode;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use UnexpectedValueException;

class DossiqAskPersonNodeTest extends TestCase {

	/**
	 * Every task the object service was asked to write.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * The task rows the store holds, by id — what a re-entry reads back.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $stored = [];

	/**
	 * Set to make every read fail, standing in for an unreachable store.
	 *
	 * @var boolean
	 */
	private bool $readsFail = false;

	protected function setUp(): void {
		$this->written = [];
		$this->stored = [];
		$this->readsFail = false;
	}//end setUp()

	/**
	 * The node, wired to a recording object service.
	 *
	 * The fake's saveObject returns an ObjectEntity BECAUSE THE REAL ONE DOES.
	 * This fake used to return an array — the caller's own wrong assumption —
	 * so the suite proved the node could read a shape production never
	 * produces, and stayed green while every live save was followed by
	 * "could not identify the task it created". A fake that agrees with the
	 * caller cannot fail.
	 *
	 * @return DossiqAskPersonNode The node under test.
	 */
	private function node(): DossiqAskPersonNode {
		$objectService = new class($this->written, $this->stored, $this->readsFail) {
			public function __construct(private array &$sink, private array &$store, private bool &$readsFail) {
			}

			public function saveObject(array $object, string $register, string $schema): ObjectEntity {
				$this->sink[] = $object;
				$uuid = 'task-' . count($this->sink);

				$entity = new ObjectEntity();
				$entity->setUuid($uuid);
				$entity->setObject($object);

				// A written task is READABLE afterwards, because a real one is.
				// A fake that took writes and served no reads would have let
				// the node's re-entry path be "tested" against nothing.
				$this->store[$uuid] = $object;

				return $entity;
			}

			/**
			 * The real signature, so the node's named arguments bind the same
			 * way they do against ObjectService — and so a MISS raises what a
			 * miss really raises, rather than returning a tidy null the node
			 * would read as "the row is gone" for every failure alike.
			 */
			public function find(
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				mixed $register = null,
				mixed $schema = null
			): ?ObjectEntity {
				if ($this->readsFail === true) {
					throw new \RuntimeException('the store is unreachable');
				}

				if (isset($this->store[(string)$id]) === false) {
					throw new DoesNotExistException(sprintf('No task %s', $id));
				}

				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setObject($this->store[(string)$id]);

				return $entity;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'register' ? 'dossiq' : 'caseTask')
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new DossiqAskPersonNode($settings, $l10n, new NullLogger());
	}//end node()

	/**
	 * A valid configuration.
	 *
	 * @return array<string, mixed> The config.
	 */
	private function config(): array {
		return [
			'question' => 'Vul uw aanvraag aan',
			'assignee' => 'alice',
			'dueInDays' => '14',
		];
	}//end config()

	/**
	 * One item carrying a case.
	 *
	 * @return array<int, array<string, mixed>> The items.
	 */
	private function items(): array {
		return [['json' => ['id' => 'case-1', 'title' => 'Dakkapel']]];
	}//end items()

	/**
	 * A run context with this node's resume slot.
	 *
	 * @param FlowNodeResumeState $resume The slot.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function context(FlowNodeResumeState $resume): array {
		return [
			FlowNodeResumeState::CONTEXT_KEY => $resume,
			FlowRunContext::CONTEXT_RUN => 'run-abc',
		];
	}//end context()

	/**
	 * One node's resume slot, built the way the ENGINE builds it.
	 *
	 * A `FlowNodeResumeState` is not constructible on its own: it is a scoped
	 * VIEW onto the run-level `FlowResumeState`, and its real constructor takes
	 * that parent plus the node id. Tests here used to call
	 * `new FlowNodeResumeState('ask-indiener', [...])` — a two-argument shape
	 * the real class has never had — so 21 of them fataled against a real
	 * OpenRegister while passing against the stub.
	 *
	 * @param string               $nodeId The node the slot belongs to.
	 * @param array<string, mixed> $values What the slot already holds.
	 *
	 * @return FlowNodeResumeState The scoped handle the engine would hand the node.
	 */
	private static function resumeSlot(string $nodeId, array $values = []): FlowNodeResumeState {
		$slots = [];
		if ($values !== []) {
			$slots[$nodeId] = $values;
		}

		return (new FlowResumeState($slots))->forNode($nodeId);
	}//end resumeSlot()

	public function testItCreatesTheTaskAndSuspends(): void {
		$resume = self::resumeSlot('ask-indiener');

		try {
			$this->node()->execute($this->items(), $this->config(), $this->context($resume));
			self::fail('The node must suspend while the task is outstanding.');
		} catch (FlowSuspension $suspension) {
			self::assertStringContainsString('Vul uw aanvraag aan', $suspension->getMessage());
		}

		self::assertCount(1, $this->written);
		self::assertSame('Vul uw aanvraag aan', $this->written[0]['title']);
		self::assertSame('case-1', $this->written[0]['case']);
		self::assertSame('alice', $this->written[0]['assignee']);
		self::assertSame('available', $this->written[0]['status']);
	}//end testItCreatesTheTaskAndSuspends()

	/**
	 * 🔴 THE SAVE RESULT IS AN ObjectEntity, AND ITS UUID IS THE TASK ID.
	 *
	 * The node used to check `is_array($created)` against a service that
	 * returns an entity, so every SUCCESSFUL save was followed by "could not
	 * identify the task it created": the run STOPPED instead of suspending,
	 * the resume slot was never written, and the task sat orphaned. This
	 * asserts the whole contract — suspension (not a stop), and a resume slot
	 * carrying the entity's uuid so the completed task can wake the run.
	 */
	public function testTheSavedTaskIsIdentifiedByItsEntityUuid(): void {
		$resume = self::resumeSlot('ask-indiener');

		try {
			$this->node()->execute($this->items(), $this->config(), $this->context($resume));
			self::fail('The node must suspend while the task is outstanding.');
		} catch (FlowSuspension $suspension) {
			// Suspended, not stopped: the save result was understood.
		}

		self::assertTrue($resume->has('taskId'), 'The resume slot must remember the task, or every heartbeat writes another.');
		self::assertSame('task-1', $resume->get('taskId'), 'The remembered id is the saved entity\'s uuid.');
	}//end testTheSavedTaskIsIdentifiedByItsEntityUuid()

	// The runAs tests retired with dossiq's FlowRunAsScope: the engine's
	// RegistryStepDispatcher executes every contributed node inside
	// ObjectService::runAs() as the run's validated acting identity
	// (openregister#3332, proven by its RegistryStepDispatcherRunAsTest), so a
	// local wrap — and a test demanding one — would re-encode the retired
	// requirement.

	/**
	 * 🔴 A TEMPLATED ASSIGNEE IS RENDERED AGAINST THE CASE.
	 *
	 * The shipped declaration says `{{ case.assignee }}` because a flow cannot
	 * name a real person — the uid differs per case. The engine templates only
	 * inside its own nodes, so this node renders the value itself. Storing the
	 * literal is what orphaned every applicant task live: the resume guard
	 * compared real uids against the placeholder text and refused all of them.
	 */
	public function testATemplatedAssigneeIsRenderedAgainstTheCase(): void {
		$resume = self::resumeSlot('ask-indiener');
		$items = [['json' => ['id' => 'case-1', 'title' => 'Dakkapel', 'assignee' => 'alice']]];
		$config = array_merge($this->config(), ['assignee' => '{{ case.assignee }}']);

		try {
			$this->node()->execute($items, $config, $this->context($resume));
		} catch (FlowSuspension $e) {
			// expected: the task is outstanding
		}

		self::assertCount(1, $this->written);
		self::assertSame('alice', $this->written[0]['assignee'], 'The task must carry the RENDERED assignee.');
		self::assertSame('alice', $resume->get('assignee'), 'So must the resume slot the guard reads back.');
	}//end testATemplatedAssigneeIsRenderedAgainstTheCase()

	/**
	 * An assignee template that resolves to nothing refuses LOUDLY when the
	 * step declares nowhere else to send the ask. A quiet empty assignee would
	 * create a task ANYONE can answer, because OpenRegister's resume guard
	 * treats silence as "no restriction".
	 */
	public function testAnAssigneeTemplateThatResolvesToNothingRefuses(): void {
		$resume = self::resumeSlot('ask-indiener');
		$config = array_merge($this->config(), ['assignee' => '{{ case.assignee }}']);

		$this->expectException(RuntimeException::class);

		try {
			// The case names no assignee, so the placeholder resolves empty.
			$this->node()->execute($this->items(), $config, $this->context($resume));
		} finally {
			self::assertSame([], $this->written, 'No task may be created for an assignee that resolved to nobody.');
		}
	}//end testAnAssigneeTemplateThatResolvesToNothingRefuses()

	/**
	 * 🔴 A CASE WITH NO ASSIGNEE GETS THE DECLARED FALLBACK, NOT A DEAD RUN.
	 *
	 * `assignee` is not in the case schema's `required`, so a case filed from
	 * the New case dialog with only a title and a case type has none. The
	 * supplement ask then threw
	 * `could not resolve the assignee "{{ case.assignee }}"`, the run FAILED,
	 * and the case sat in "Wacht op aanvulling" with no task for anybody and
	 * nothing waiting on it. Reproduced twice on independent clean installs;
	 * the e2e never saw it because every case it files names an assignee.
	 *
	 * The shipped flow now declares `behandelaars` as the fallback, so
	 * unclaimed work reaches the handlers' queue instead of killing the case.
	 *
	 * @return void
	 */
	public function testTheDeclaredFallbackTakesTheAskWhenTheCaseNamesNobody(): void {
		$resume = self::resumeSlot('ask-aanvulling');
		$config = array_merge(
			$this->config(),
			['assignee' => '{{ case.assignee }}', 'assigneeFallback' => 'behandelaars']
		);

		try {
			// The case names no assignee: exactly the New case dialog's shape.
			$this->node()->execute($this->items(), $config, $this->context($resume));
		} catch (FlowSuspension $e) {
			// expected: the task is outstanding
		}

		self::assertCount(1, $this->written, 'The ask must still produce exactly one task.');
		self::assertSame('behandelaars', $this->written[0]['assignee'], 'The task must carry the fallback group.');
		self::assertSame(
			'behandelaars',
			$resume->get('assignee'),
			'So must the resume slot, or the guard refuses every member of that group.'
		);
	}//end testTheDeclaredFallbackTakesTheAskWhenTheCaseNamesNobody()

	/**
	 * The fallback is a SECOND choice, never the first one.
	 *
	 * A case that does name a handler must reach that handler. A fallback that
	 * won over a resolvable assignee would quietly move every ask onto a group
	 * and lose the one person who owns the case.
	 *
	 * @return void
	 */
	public function testAResolvableAssigneeWinsOverTheFallback(): void {
		$resume = self::resumeSlot('ask-aanvulling');
		$items = [['json' => ['id' => 'case-1', 'title' => 'Dakkapel', 'assignee' => 'alice']]];
		$config = array_merge(
			$this->config(),
			['assignee' => '{{ case.assignee }}', 'assigneeFallback' => 'behandelaars']
		);

		try {
			$this->node()->execute($items, $config, $this->context($resume));
		} catch (FlowSuspension $e) {
			// expected: the task is outstanding
		}

		self::assertCount(1, $this->written);
		self::assertSame('alice', $this->written[0]['assignee'], 'The case names a handler, so the handler is asked.');
	}//end testAResolvableAssigneeWinsOverTheFallback()

	/**
	 * A fallback that resolves to nobody either still fails CLOSED.
	 *
	 * The fallback exists to give the ask a defined destination, not to make
	 * "nobody" acceptable. If neither names a principal the step must refuse:
	 * an unassigned task is answerable by any authenticated user.
	 *
	 * @return void
	 */
	public function testAFallbackThatResolvesToNothingStillRefuses(): void {
		$resume = self::resumeSlot('ask-aanvulling');
		$config = array_merge(
			$this->config(),
			['assignee' => '{{ case.assignee }}', 'assigneeFallback' => '{{ case.caseTypeOwner }}']
		);

		$this->expectException(RuntimeException::class);

		try {
			$this->node()->execute($this->items(), $config, $this->context($resume));
		} finally {
			self::assertSame([], $this->written, 'No task may be created when neither value names a principal.');
		}
	}//end testAFallbackThatResolvesToNothingStillRefuses()

	/**
	 * 🔑 The task names the run AND the node — both are needed to resume.
	 */
	public function testTheTaskCarriesTheRunAndTheNodeThatAskedIt(): void {
		$resume = self::resumeSlot('ask-indiener');

		try {
			$this->node()->execute($this->items(), $this->config(), $this->context($resume));
		} catch (FlowSuspension $e) {
			// expected
		}

		self::assertSame('run-abc', $this->written[0]['flowRun']);
		self::assertSame('ask-indiener', $this->written[0]['flowNode']);
	}//end testTheTaskCarriesTheRunAndTheNodeThatAskedIt()

	/**
	 * 🔴 THE HEARTBEAT MUST NOT CREATE A SECOND TASK.
	 */
	public function testAHeartbeatDoesNotCreateAnotherTask(): void {
		$resume = self::resumeSlot('ask-indiener');
		$node = $this->node();

		foreach ([1, 2, 3] as $ignored) {
			try {
				$node->execute($this->items(), $this->config(), $this->context($resume));
			} catch (FlowSuspension $e) {
				// expected on every pass while unanswered
			}
		}

		self::assertCount(1, $this->written, 'One task per question, however many times the run wakes.');
	}//end testAHeartbeatDoesNotCreateAnotherTask()

	/**
	 * An answer passes through and lands on every item.
	 */
	public function testAnAnswerIsCarriedOntoTheItems(): void {
		$resume = self::resumeSlot('ask-indiener', ['taskId' => 'task-1', 'askedAt' => 'now']);
		$this->stored['task-1'] = ['status' => 'completed', 'case' => 'case-1'];

		$context = array_merge(
			$this->context($resume),
			['signal' => ['decision' => 'completed', 'completedBy' => 'alice']]
		);

		$out = $this->node()->execute($this->items(), array_merge($this->config(), ['signalKey' => 'aanvulling']), $context);

		self::assertCount(1, $out);
		self::assertSame('completed', $out[0]['json']['aanvulling']['decision']);
		self::assertSame('alice', $out[0]['json']['aanvulling']['completedBy'], 'The wake still contributes who answered.');
		self::assertFalse($out[0]['json']['aanvulling']['recovered'], 'This answer arrived by signal, not by heartbeat.');
		self::assertSame([], $this->written, 'Answering must not write another task.');
	}//end testAnAnswerIsCarriedOntoTheItems()

	/**
	 * 🔴 THE RECOVERY, WHICH IS THE WHOLE POINT OF THE HEARTBEAT.
	 *
	 * A completion whose signal was refused or lost leaves the run parked with
	 * NOTHING in `context.signal`. The node used to read only that slot, so
	 * every wake re-suspended and `resume_at` rolled forever over a task that
	 * had been answered. Reading the task is what turns the timer into a
	 * delivery.
	 */
	public function testAHeartbeatDeliversAnAnswerWhoseSignalNeverArrived(): void {
		$resume = self::resumeSlot('ask-indiener', ['taskId' => 'task-1', 'askedAt' => 'now']);
		$this->stored['task-1'] = ['status' => 'completed', 'case' => 'case-1', 'completedDate' => '2026-09-03T10:00:00+00:00'];

		$out = $this->node()->execute(
			$this->items(),
			array_merge($this->config(), ['signalKey' => 'aanvulling']),
			$this->context($resume)
		);

		self::assertSame('completed', $out[0]['json']['aanvulling']['decision']);
		self::assertTrue($out[0]['json']['aanvulling']['recovered'], 'A run that advanced without its wake must say so.');
		self::assertSame('task-1', $out[0]['json']['aanvulling']['taskId']);
		self::assertSame([], $this->written, 'Recovery must never create a task.');
	}//end testAHeartbeatDeliversAnAnswerWhoseSignalNeverArrived()

	/**
	 * A signal is a WAKE, not an answer: the task decides.
	 *
	 * `context.signal` is one slot per RUN, so a flow with two asks would have
	 * the second read the answer given to the first. Reading the row removes
	 * the race instead of guarding it.
	 */
	public function testASignalCannotAnswerForATaskThatIsStillOpen(): void {
		$resume = self::resumeSlot('ask-indiener', ['taskId' => 'task-1', 'askedAt' => 'now']);
		$this->stored['task-1'] = ['status' => 'available', 'case' => 'case-1'];

		$context = array_merge($this->context($resume), ['signal' => ['decision' => 'completed']]);

		$this->expectException(FlowSuspension::class);
		$this->node()->execute($this->items(), $this->config(), $context);
	}//end testASignalCannotAnswerForATaskThatIsStillOpen()

	/**
	 * A withdrawn ask is not an answer, and it is not a wait either.
	 *
	 * Carrying on would move the case past a question nobody answered;
	 * suspending would wait for one that can never come.
	 */
	public function testATerminatedTaskFailsTheStepRatherThanInventingAnAnswer(): void {
		$resume = self::resumeSlot('ask-indiener', ['taskId' => 'task-1', 'askedAt' => 'now']);
		$this->stored['task-1'] = ['status' => 'terminated', 'case' => 'case-1'];

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/never be answered/');
		$this->node()->execute($this->items(), $this->config(), $this->context($resume));
	}//end testATerminatedTaskFailsTheStepRatherThanInventingAnAnswer()

	/**
	 * The row this run was waiting on is gone: waiting further waits forever.
	 */
	public function testATaskThatNoLongerExistsFailsTheStep(): void {
		$resume = self::resumeSlot('ask-indiener', ['taskId' => 'task-gone', 'askedAt' => 'now']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/no longer exists/');
		$this->node()->execute($this->items(), $this->config(), $this->context($resume));
	}//end testATaskThatNoLongerExistsFailsTheStep()

	/**
	 * An unreachable store is NOT a missing task. Concluding "gone" from a
	 * hiccup would fail a case whose task is sitting there answered, so the
	 * node buys one more heartbeat instead.
	 */
	public function testAnUnreadableStoreParksAgainInsteadOfFailing(): void {
		$resume = self::resumeSlot('ask-indiener', ['taskId' => 'task-1', 'askedAt' => 'now']);
		$this->stored['task-1'] = ['status' => 'completed', 'case' => 'case-1'];
		$this->readsFail = true;

		$this->expectException(FlowSuspension::class);
		$this->node()->execute($this->items(), $this->config(), $this->context($resume));
	}//end testAnUnreadableStoreParksAgainInsteadOfFailing()

	/**
	 * 🔴 An unassigned question would be answerable by ANYONE, because
	 * OpenRegister's resume guard treats silence as "no restriction".
	 */
	public function testAConfigWithNoAssigneeIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->node()->validateConfig(['question' => 'Something?']);
	}//end testAConfigWithNoAssigneeIsRefused()

	public function testAConfigWithNoQuestionIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->node()->validateConfig(['assignee' => 'alice']);
	}//end testAConfigWithNoQuestionIsRefused()

	/**
	 * Without a resume slot the step cannot be made idempotent, so it refuses
	 * rather than writing a task it will duplicate on the next heartbeat.
	 */
	public function testWithoutAResumeSlotItRefuses(): void {
		$this->expectException(RuntimeException::class);

		$this->node()->execute($this->items(), $this->config(), [FlowRunContext::CONTEXT_RUN => 'run-abc']);
	}//end testWithoutAResumeSlotItRefuses()

	public function testWithNoCaseItRefuses(): void {
		$this->expectException(RuntimeException::class);

		$this->node()->execute([['json' => []]], $this->config(), $this->context(self::resumeSlot('n')));
	}//end testWithNoCaseItRefuses()

	/**
	 * A node over an object service whose saveObject returns whatever the
	 * test says, so the OTHER result shapes createdTaskId() accepts stay
	 * honest: each shape below is one a duck-typed service can legitimately
	 * hand back, and each must still identify the task.
	 *
	 * @param mixed $result What saveObject returns.
	 *
	 * @return DossiqAskPersonNode The node under test.
	 */
	private function nodeReturning(mixed $result): DossiqAskPersonNode {
		$objectService = new class($this->written, $result) {
			public function __construct(private array &$sink, private mixed $result) {
			}

			public function saveObject(array $object, string $register, string $schema): mixed {
				$this->sink[] = $object;

				return $this->result;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'register' ? 'dossiq' : 'caseTask')
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new DossiqAskPersonNode($settings, $l10n, new NullLogger());
	}//end nodeReturning()

	/**
	 * A LEGACY ARRAY result still identifies the task. The service is
	 * duck-typed, and refusing a shape that carries a perfectly good id
	 * would recreate the orphaned-task bug for the other shape.
	 */
	public function testALegacyArraySaveResultStillIdentifiesTheTask(): void {
		$resume = self::resumeSlot('ask-indiener');
		$node = $this->nodeReturning(['id' => 'legacy-task-7']);

		try {
			$node->execute($this->items(), $this->config(), $this->context($resume));
		} catch (FlowSuspension $e) {
			// expected: the task is outstanding
		}

		self::assertSame('legacy-task-7', $resume->get('taskId'));
	}//end testALegacyArraySaveResultStillIdentifiesTheTask()

	/**
	 * An entity with NO uuid falls back to its serialised form, which the
	 * real ObjectEntity guarantees carries a top-level id.
	 */
	public function testAnEntityWithoutAUuidFallsBackToItsSerialisedId(): void {
		$entity = new ObjectEntity();
		$entity->setObject(['id' => 'serialised-task-9', 'title' => 'x']);

		$resume = self::resumeSlot('ask-indiener');
		$node = $this->nodeReturning($entity);

		try {
			$node->execute($this->items(), $this->config(), $this->context($resume));
		} catch (FlowSuspension $e) {
			// expected: the task is outstanding
		}

		self::assertSame('serialised-task-9', $resume->get('taskId'));
	}//end testAnEntityWithoutAUuidFallsBackToItsSerialisedId()

	/**
	 * A result that names NOTHING refuses: an unidentifiable task means an
	 * empty resume slot, so the next heartbeat would write a duplicate.
	 */
	public function testAResultNamingNoIdRefuses(): void {
		$resume = self::resumeSlot('ask-indiener');
		$node = $this->nodeReturning('not-a-result-shape');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('could not identify');

		$node->execute($this->items(), $this->config(), $this->context($resume));
	}//end testAResultNamingNoIdRefuses()

	public function testItAnnouncesItsIdentity(): void {
		$node = $this->node();

		self::assertSame('dossiq.askPerson', $node->getId());
		self::assertNotSame('', $node->getDisplayName());
		self::assertNotSame('', $node->getDescription());
	}//end testItAnnouncesItsIdentity()
}//end class
