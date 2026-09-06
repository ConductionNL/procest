<?php

/**
 * The heartbeat delivers an answer whose signal was refused — for the node the
 * shipped case flow actually waits on.
 *
 * THE GAP THIS CLOSES. openregister#3358 taught the engine's own
 * `openregister.user-task` to re-read a completed task on a heartbeat instead
 * of re-suspending forever. On the rig, half of that fix was proven live and
 * the other half never fired: the shipped dossiq flow waits on
 * `dossiq.askPerson`, which advanced only on a signal. A refused or lost
 * completion therefore wedged the run permanently — `resume_at` rolled 11:28 →
 * 11:29 → 11:30 while the task sat `completed` in somebody's finished list.
 *
 * 🔴 DRIVEN THROUGH THE REAL ENGINE, ON PURPOSE. openregister#3362 measured
 * what a mocked seam is worth here: 30 of 32 added statements uncovered,
 * because every recovery test mocked the bridge it was testing. The property
 * that matters is not inside the node — it is what the ENGINE hands a parked
 * node when it re-enters it on a timer, and what survives between passes. So
 * this suite builds the real FlowRunService, engine, registry, dispatcher,
 * stream walk, claims and commit path over in-memory mappers, and drives the
 * real `FlowRunSignalService` guard to produce the refusal. Only storage is
 * faked: the task is a row in a table.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @covers \OCA\Dossiq\Flow\DossiqAskPersonNode
 * @uses   \OCA\Dossiq\Flow\AskPersonTaskStore
 *
 * @spec openspec/changes/askperson-recovers-a-missed-answer/specs/case-flow-human-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Flow;

// 🔑 ASK FOR THE REAL ENGINE, HERE, AT FILE SCOPE.
//
// The bootstrap registers OpenRegister's own source and its dependencies only
// when this is set, and every other suite in this app runs against the stubs
// it always did. That is deliberate rather than shy: swapping the whole app's
// suite onto the real classes surfaces 48 stub-versus-real disagreements, each
// a real finding and none of them this change's business.
//
// File scope, because PHPUnit loads every test file while building the suite —
// long before it forks the isolated processes below, which inherit it.
putenv('DOSSIQ_REAL_FLOW_ENGINE=1');

use OCA\Dossiq\Flow\DossiqAskPersonNode;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\FlowClaim;
use OCA\OpenRegister\Db\FlowDefinition;
use OCA\OpenRegister\Db\FlowDefinitionMapper;
use OCA\OpenRegister\Db\FlowClaimMapper;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowRunStep;
use OCA\OpenRegister\Db\FlowRunStepMapper;
use OCA\OpenRegister\Db\FlowStateMapper;
use OCA\OpenRegister\Db\FlowStream;
use OCA\OpenRegister\Db\FlowStreamMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\FlowVersionMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\FlowSignalRefused;
use OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder;
use OCA\OpenRegister\Service\Flow\FlowDefinitionPin;
use OCA\OpenRegister\Service\Flow\FlowEngine;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\FlowPlaceClaims;
use OCA\OpenRegister\Service\Flow\FlowRunCommit;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowRunSignalService;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** The case a run carries; the marking lives on the run. */
class AskPersonSubject {
}

/** A step that passes items through, so the graph can split. */
class AskPersonPassNode implements IFlowNode {

	public function getId(): string {
		return 'test.pass';
	}

	public function getDisplayName(): string {
		return 'Pass';
	}

	public function getDescription(): string {
		return 'Passes items through.';
	}

	public function getIcon(): string {
		return 'i.svg';
	}

	public function isAvailableForScope(int $scope): bool {
		return true;
	}

	public function validateConfig(array $config): void {
	}

	public function execute(array $items, array $config, array $context): array {
		return $items;
	}
}//end class

/**
 * The wedge, reproduced against dossiq's own node and recovered.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A harness for a real engine
 * names the engine's parts; that is what makes it a real engine.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class AskPersonHeartbeatRecoveryTest extends TestCase {

	/**
	 * The task rows the store holds, by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $tasks = [];

	/**
	 * Task uuids in creation order. Growing past one per node is the
	 * duplicate-task defect a heartbeat used to be able to cause.
	 *
	 * @var array<int, string>
	 */
	private array $created = [];

	/**
	 * The run row.
	 *
	 * @var FlowRun|null
	 */
	private ?FlowRun $row = null;

	/**
	 * @var array<string, FlowStream>
	 */
	private array $streams = [];

	/**
	 * @var array<int, FlowClaim>
	 */
	private array $claims = [];

	/**
	 * The real run service under test.
	 *
	 * @var FlowRunService
	 */
	private FlowRunService $service;

	/**
	 * The real guarded signal seam, so a refusal is a real refusal.
	 *
	 * @var FlowRunSignalService
	 */
	private FlowRunSignalService $signals;

	protected function setUp(): void {
		parent::setUp();

		$this->requireTheRealEngine();

		$this->tasks = [];
		$this->created = [];
		$this->row = null;
		$this->streams = [];
		$this->claims = [];

		$runs = $this->runMapper();
		$streamMapper = $this->streamMapper();
		$claimMapper = $this->claimMapper();

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$node = $this->askPersonNode();
		$pass = new AskPersonPassNode();
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use ($node, $pass): void {
				if ($event instanceof RegisterFlowNodesEvent) {
					$event->registerNode($node);
					$event->registerNode($pass);
				}
			}
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturn(false);

		$steps = $this->createMock(FlowRunStepMapper::class);
		$steps->method('highestSequence')->willReturn(0);
		$steps->method('insert')->willReturnCallback(static fn (FlowRunStep $step): FlowRunStep => $step);

		$this->service = new FlowRunService(
			$runs,
			$this->createMock(FlowStateMapper::class),
			new FlowEngine(new FlowDefinitionBuilder(), new NullLogger()),
			new FlowNodeRegistry($dispatcher, $this->createMock(LoggerInterface::class)),
			$this->createMock(LoggerInterface::class),
			$this->container(),
			null,
			null,
			$streamMapper,
			new FlowPlaceClaims(claims: $claimMapper, db: $db, logger: new NullLogger()),
			new FlowRunCommit(
				db: $db,
				runs: $runs,
				streams: $streamMapper,
				claims: $claimMapper,
				steps: $steps,
				logger: new NullLogger()
			)
		);

		$this->signals = new FlowRunSignalService($runs, $this->service, new NullLogger());
	}//end setUp()

	/**
	 * Refuse to pretend.
	 *
	 * A suite whose whole point is the engine's behaviour must not quietly
	 * pass against a stub of it. On a developer machine without OpenRegister
	 * beside this app there is nothing to measure and the suite says so; in CI
	 * the sibling checkout is part of the job, so its absence is a broken
	 * environment and a skip there would be the instrument lying.
	 *
	 * @return void
	 */
	private function requireTheRealEngine(): void {
		if (class_exists('\\OCA\\OpenRegister\\Service\\Flow\\FlowEngine') === true) {
			return;
		}

		if (($_SERVER['CI'] ?? getenv('CI')) !== false && ($_SERVER['CI'] ?? getenv('CI')) !== '') {
			self::fail(
				'The real flow engine did not load, so it cannot be exercised. It needs OpenRegister beside this '
				. 'app AND its composer dependencies installed. The PHPUnit job does both — it clones OpenRegister '
				. 'to server/apps/openregister and installs it — so this means that step did not finish.'
			);
		}

		self::markTestSkipped(
			'There is no engine to drive: clone ConductionNL/openregister beside this app (../openregister) and '
			. 'run composer install in it. Without both, the bootstrap keeps the stubs and this suite would be '
			. 'measuring them.'
		);
	}//end requireTheRealEngine()

	/**
	 * The node under test, wired to an in-memory task store.
	 *
	 * @return DossiqAskPersonNode The node.
	 */
	private function askPersonNode(): DossiqAskPersonNode {
		$objectService = new class($this->tasks, $this->created) {
			public function __construct(private array &$tasks, private array &$created) {
			}

			public function saveObject(array $object, string $register, string $schema): ObjectEntity {
				$uuid = 'task-' . (count($this->created) + 1);
				$this->created[] = $uuid;
				$this->tasks[$uuid] = $object;

				$entity = new ObjectEntity();
				$entity->setUuid($uuid);
				$entity->setObject($object);

				return $entity;
			}

			public function find(
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				mixed $register = null,
				mixed $schema = null
			): ?ObjectEntity {
				if (isset($this->tasks[(string)$id]) === false) {
					throw new DoesNotExistException(sprintf('No task %s', $id));
				}

				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setObject($this->tasks[(string)$id]);

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
	}//end askPersonNode()

	/**
	 * The run "table": one row, updated in place.
	 *
	 * @return FlowRunMapper The mapper double.
	 */
	private function runMapper(): FlowRunMapper {
		$mapper = $this->createMock(FlowRunMapper::class);
		$mapper->method('insert')->willReturnCallback(function (FlowRun $run): FlowRun {
			$this->row = $run;
			return $run;
		});
		$mapper->method('update')->willReturnCallback(function (FlowRun $run): FlowRun {
			$this->row = $run;
			return $run;
		});
		$mapper->method('lockByUuid')->willReturnCallback(fn (): FlowRun => $this->row);
		$mapper->method('findByUuid')->willReturnCallback(fn (): FlowRun => $this->row);

		return $mapper;
	}//end runMapper()

	/**
	 * The stream "table".
	 *
	 * @return FlowStreamMapper The mapper double.
	 */
	private function streamMapper(): FlowStreamMapper {
		$mapper = $this->createMock(FlowStreamMapper::class);
		$mapper->method('findByRun')->willReturnCallback(function (): array {
			$list = array_values($this->streams);
			usort($list, static fn (FlowStream $a, FlowStream $b): int => strcmp((string)$a->getOrdinalPath(), (string)$b->getOrdinalPath()));
			return $list;
		});
		$mapper->method('findByRunAndStream')->willReturnCallback(
			fn (string $runUuid, string $streamId): ?FlowStream => ($this->streams[$streamId] ?? null)
		);
		$mapper->method('insert')->willReturnCallback(function (FlowStream $stream): FlowStream {
			$this->streams[(string)$stream->getStreamId()] = $stream;
			return $stream;
		});
		$mapper->method('update')->willReturnCallback(function (FlowStream $stream): FlowStream {
			$this->streams[(string)$stream->getStreamId()] = $stream;
			return $stream;
		});
		$mapper->method('allocateNextSequence')->willReturnCallback(function (string $runUuid, string $streamId): int {
			$stream = ($this->streams[$streamId] ?? null);
			if ($stream === null) {
				return 0;
			}

			$next = (int)$stream->getNextSequence();
			$stream->setNextSequence(($next + 1));
			return $next;
		});

		return $mapper;
	}//end streamMapper()

	/**
	 * The claim "table".
	 *
	 * @return FlowClaimMapper The mapper double.
	 */
	private function claimMapper(): FlowClaimMapper {
		$mapper = $this->createMock(FlowClaimMapper::class);
		$mapper->method('countHeldForRun')->willReturn(0);
		$mapper->method('countHeldByOwner')->willReturn(0);
		$mapper->method('insertOrRefuse')->willReturnCallback(function (FlowClaim $claim): bool {
			$this->claims[] = $claim;
			return true;
		});
		$mapper->method('findByRun')->willReturnCallback(fn (): array => array_values($this->claims));
		$mapper->method('release')->willReturnCallback(function (string $runUuid, array $places): int {
			$before = count($this->claims);
			$this->claims = array_values(
				array_filter($this->claims, static fn (FlowClaim $c): bool => in_array($c->getPlace(), $places, true) === false)
			);
			return ($before - count($this->claims));
		});
		$mapper->method('releaseByOwner')->willReturnCallback(function (string $runUuid, string $owner): int {
			$before = count($this->claims);
			$this->claims = array_values(
				array_filter($this->claims, static fn (FlowClaim $c): bool => $c->getOwner() !== $owner)
			);
			return ($before - count($this->claims));
		});

		return $mapper;
	}//end claimMapper()

	/**
	 * The container the run service resolves its two lookups through.
	 *
	 * WHICH VERSION IS LIVE has to be answered, because `queue()` refuses a
	 * flow with no published version and that refusal is a feature — a harness
	 * that skipped it would be testing the refusal instead of the run. The
	 * PINNED DEFINITION deliberately carries none of the graph keys, so the
	 * fixture's own document is what runs while the real lookup path (version
	 * row -> hash -> definition) is still walked.
	 *
	 * Everything else is unresolvable, which the engine reads as "no identity
	 * scope": the node runs bare, exactly as it does on the interactive path.
	 *
	 * @return ContainerInterface The container double.
	 */
	private function container(): ContainerInterface {
		$versions = $this->createMock(FlowVersionMapper::class);
		$version = static function (string $flowUuid): FlowVersion {
			$row = new FlowVersion();
			$row->setFlowUuid($flowUuid);
			$row->setVersion(1);
			$row->setStatus(FlowVersion::STATUS_PUBLISHED);
			$row->setDefinitionHash('test-hash');

			return $row;
		};
		$versions->method('findPublished')->willReturnCallback($version);
		$versions->method('find')->willReturnCallback(
			static fn (string $flowUuid, int $number): ?FlowVersion => ($number === 1 ? $version($flowUuid) : null)
		);

		$definition = new FlowDefinition();
		$definition->setHash('test-hash');
		$definition->setDefinition((string)json_encode(['pinnedBy' => 'test']));
		$definitions = $this->createMock(FlowDefinitionMapper::class);
		$definitions->method('findByHash')->willReturnCallback(
			static fn (string $wanted): ?FlowDefinition => ($wanted === 'test-hash' ? $definition : null)
		);

		$pin = new FlowDefinitionPin($definitions, new NullLogger());

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($versions, $pin): object {
				if ($id === FlowVersionMapper::class) {
					return $versions;
				}

				if ($id === FlowDefinitionPin::class) {
					return $pin;
				}

				throw new \RuntimeException('not available: ' . $id);
			}
		);

		return $container;
	}//end container()

	/**
	 * A graph that asks two people at once — the shape a case really has, and
	 * the one whose per-node slots a run-level signal cannot tell apart.
	 *
	 * @return array<string, mixed> The flow document.
	 */
	private function flow(): array {
		return [
			'id' => 'case-flow',
			'nodes' => [
				['id' => 'start', 'type' => 'test.pass'],
				[
					'id' => 'ask-indiener',
					'type' => 'dossiq.askPerson',
					'config' => [
						'question' => 'Vul uw aanvraag aan',
						'assignee' => 'alice',
						'signalKey' => 'aanvulling',
						'heartbeatMinutes' => 30,
					],
				],
				[
					'id' => 'task-behandelaar',
					'type' => 'dossiq.askPerson',
					'config' => [
						'question' => 'Rond de voorbereiding af',
						'assignee' => 'alice',
						'signalKey' => 'voorbereiding',
						'heartbeatMinutes' => 30,
					],
				],
			],
			'edges' => [
				['id' => 'e1', 'from' => 'start', 'to' => 'ask-indiener'],
				['id' => 'e2', 'from' => 'start', 'to' => 'task-behandelaar'],
			],
		];
	}//end flow()

	/**
	 * Park both branches on their freshly created tasks.
	 *
	 * @return FlowRun The suspended run.
	 */
	private function suspendedOnBothTasks(): FlowRun {
		$run = $this->service->queue('case-flow', user: 'alice');
		$run = $this->service->execute(
			$run,
			$this->flow(),
			new AskPersonSubject(),
			seedItems: [FlowItems::item(json: ['id' => 'case-7', 'title' => 'Dakkapel'])]
		);

		self::assertSame(FlowRun::STATUS_SUSPENDED, $run->getStatus(), (string)$run->getError());
		self::assertCount(2, $this->created, 'One task per ask, created on the first pass.');

		return $run;
	}//end suspendedOnBothTasks()

	/**
	 * One ask, on a single path — the shape the shipped case flow uses, and
	 * the one whose ANSWER can be read off the run's items.
	 *
	 * Two parallel branches each carry their own item set, so the run's items
	 * are the last branch's; a bag assertion on that graph would be reading
	 * whichever branch happened to commit last. The parallel graph is kept for
	 * what it is actually good at — proving per-node slot addressing — and the
	 * answer's shape is asserted here.
	 *
	 * @return array<string, mixed> The flow document.
	 */
	private function chainFlow(): array {
		return [
			'id' => 'case-flow',
			'nodes' => [
				['id' => 'start', 'type' => 'test.pass'],
				[
					'id' => 'ask-indiener',
					'type' => 'dossiq.askPerson',
					'config' => [
						'question' => 'Vul uw aanvraag aan',
						'assignee' => 'alice',
						'signalKey' => 'aanvulling',
						'heartbeatMinutes' => 30,
					],
				],
			],
			'edges' => [
				['id' => 'e1', 'from' => 'start', 'to' => 'ask-indiener'],
			],
		];
	}//end chainFlow()

	/**
	 * Park the single ask on its freshly created task.
	 *
	 * @return FlowRun The suspended run.
	 */
	private function suspendedOnOneTask(): FlowRun {
		$run = $this->service->queue('case-flow', user: 'alice');
		$run = $this->service->execute(
			$run,
			$this->chainFlow(),
			new AskPersonSubject(),
			seedItems: [FlowItems::item(json: ['id' => 'case-7', 'title' => 'Dakkapel'])]
		);

		self::assertSame(FlowRun::STATUS_SUSPENDED, $run->getStatus(), (string)$run->getError());
		self::assertCount(1, $this->created, 'One task per ask, created on the first pass.');

		return $run;
	}//end suspendedOnOneTask()

	/**
	 * Complete a task exactly as its completion verb leaves it.
	 *
	 * @param string $uuid The task.
	 *
	 * @return void
	 */
	private function complete(string $uuid): void {
		$this->tasks[$uuid]['status'] = 'completed';
		$this->tasks[$uuid]['completedDate'] = '2026-09-03T11:28:00+00:00';
	}//end complete()

	/**
	 * The slot a parked node kept.
	 *
	 * @param FlowRun $run    The run.
	 * @param string  $nodeId The node.
	 *
	 * @return array<string, mixed> The slot.
	 */
	private function slot(FlowRun $run, string $nodeId): array {
		return (array)(($run->getContext()['resumeState'] ?? [])[$nodeId] ?? []);
	}//end slot()

	/**
	 * 🔴 THE LIVE WEDGE, END TO END: refused → completed → heartbeat → advanced.
	 *
	 * The refusal is a REAL one, produced by the engine's own guard against the
	 * assignee this node recorded when it suspended. Before this change the
	 * story ended there: the task was completed, no signal ever reached the
	 * run, and every heartbeat re-suspended because the node looked only at
	 * `context.signal`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/askperson-recovers-a-missed-answer/specs/case-flow-human-steps/spec.md
	 */
	public function testAHeartbeatDeliversACompletionWhoseSignalWasRefused(): void {
		$run = $this->suspendedOnOneTask();
		$task = (string)$this->slot($run, 'ask-indiener')['taskId'];

		// Somebody who is not the assignee completes it. The engine refuses to
		// deliver, and the run is left exactly as it was.
		$refused = false;
		try {
			$this->signals->signalAs(
				runUuid: (string)$run->getUuid(),
				payload: ['decision' => 'completed'],
				actorUid: 'mallory',
				nodeId: 'ask-indiener'
			);
		} catch (FlowSignalRefused $refusal) {
			$refused = true;
			self::assertSame(FlowSignalRefused::NOT_ASSIGNEE, $refusal->getReason());
		}

		self::assertTrue($refused, 'The guard must refuse a non-assignee, or this test proves nothing.');
		self::assertSame(FlowRun::STATUS_SUSPENDED, $this->row->getStatus(), 'A refusal changes nothing about the run.');

		// The task is completed all the same — a refusal cannot un-complete one
		// — and no wake ever reaches the run.
		$this->complete($task);

		// The heartbeat fires: findDue() -> advance() -> execute().
		$run = $this->service->execute($run, $this->chainFlow(), new AskPersonSubject());

		self::assertSame(FlowRun::STATUS_COMPLETED, $run->getStatus(), 'The heartbeat must deliver the answer, not roll again.');

		$json = (array)($run->getItems()[0]['json'] ?? []);
		self::assertSame('completed', ($json['aanvulling']['decision'] ?? null));
		self::assertSame($task, ($json['aanvulling']['taskId'] ?? null));
		self::assertTrue(($json['aanvulling']['recovered'] ?? false), 'A recovered delivery says so.');
		self::assertSame([$task], $this->created, 'Recovery must never create a task.');
	}//end testAHeartbeatDeliversACompletionWhoseSignalWasRefused()

	/**
	 * A heartbeat that finds the tasks still open parks again on the SAME
	 * tasks: no duplicate in anybody's list, and `askedAt` is not restamped —
	 * a task reported as minutes old is the reading that stops anyone chasing
	 * it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/askperson-recovers-a-missed-answer/specs/case-flow-human-steps/spec.md
	 */
	public function testAHeartbeatWithTheTasksStillOpenParksAgainOnTheSameTasks(): void {
		$run = $this->suspendedOnBothTasks();
		$before = $this->slot($run, 'ask-indiener');

		$run = $this->service->execute($run, $this->flow(), new AskPersonSubject());

		self::assertSame(FlowRun::STATUS_SUSPENDED, $run->getStatus());
		self::assertCount(2, $this->created, 'A wake must never create a second task for a parked node.');

		$after = $this->slot($run, 'ask-indiener');
		self::assertSame($before['taskId'], $after['taskId']);
		self::assertSame($before['askedAt'], $after['askedAt'], 'A heartbeat must not restamp askedAt.');
	}//end testAHeartbeatWithTheTasksStillOpenParksAgainOnTheSameTasks()

	/**
	 * Per-node slot addressing holds through a recovery: only the node whose
	 * task ended advances, and its sibling keeps waiting on its own task.
	 *
	 * This is what a run-level `context.signal` cannot express, and why the
	 * answer is read from the row rather than from the wake.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/askperson-recovers-a-missed-answer/specs/case-flow-human-steps/spec.md
	 */
	public function testOnlyTheNodeWhoseTaskWasAnsweredAdvances(): void {
		$run = $this->suspendedOnBothTasks();
		$taskA = (string)$this->slot($run, 'ask-indiener')['taskId'];
		$taskB = (string)$this->slot($run, 'task-behandelaar')['taskId'];

		$this->complete($taskA);

		$run = $this->service->execute($run, $this->flow(), new AskPersonSubject());

		self::assertSame(FlowRun::STATUS_SUSPENDED, $run->getStatus(), 'The other branch still waits.');
		self::assertCount(2, $this->created);
		self::assertSame([], $this->slot($run, 'ask-indiener'), 'A node that answered has nothing left to remember.');
		self::assertSame($taskB, (string)$this->slot($run, 'task-behandelaar')['taskId'], 'The waiting sibling keeps its own task.');
	}//end testOnlyTheNodeWhoseTaskWasAnsweredAdvances()

	/**
	 * The delivered path and the recovered path put the SAME answer on the
	 * items, which is the contract that makes the recovery safe to rely on.
	 * The wake adds who answered; it does not decide what the answer was.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/askperson-recovers-a-missed-answer/specs/case-flow-human-steps/spec.md
	 */
	public function testTheSignalledPathAndTheRecoveredPathAgree(): void {
		$run = $this->suspendedOnOneTask();
		$task = (string)$this->slot($run, 'ask-indiener')['taskId'];

		$this->complete($task);

		// The assignee answers, so the guard delivers.
		$run = $this->signals->signalAs(
			runUuid: (string)$run->getUuid(),
			payload: ['decision' => 'completed', 'completedBy' => 'alice'],
			actorUid: 'alice',
			nodeId: 'ask-indiener'
		);

		$run = $this->service->execute($run, $this->chainFlow(), new AskPersonSubject());

		self::assertSame(FlowRun::STATUS_COMPLETED, $run->getStatus());

		$json = (array)($run->getItems()[0]['json'] ?? []);
		self::assertSame('completed', ($json['aanvulling']['decision'] ?? null));
		self::assertSame($task, ($json['aanvulling']['taskId'] ?? null));
		self::assertSame('alice', ($json['aanvulling']['completedBy'] ?? null), 'The wake contributes who answered.');
		self::assertFalse(($json['aanvulling']['recovered'] ?? null), 'This answer arrived by wake, not by heartbeat.');
	}//end testTheSignalledPathAndTheRecoveredPathAgree()
}//end class
