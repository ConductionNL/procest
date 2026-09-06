<?php

/**
 * The heartbeat delivers a conclusion whose announcement never arrived — for
 * the decision step a case flow actually waits on.
 *
 * THE GAP THIS CLOSES. dossiq#1756 closed this wedge for `dossiq.askPerson` and
 * named this node as the one left open: `ContractDecisionDelegationService`
 * could raise a decision and could not read one back, so a heartbeat had
 * nothing to consult and `execute()` advanced only on a signal. A conclusion
 * whose announcement was missed therefore wedged the run permanently, with
 * `resume_at` rolling forward while the Decision sat concluded in decidiq.
 * decidiq#1118 shipped the read seam; this suite proves dossiq uses it.
 *
 * 🔴 DRIVEN THROUGH THE REAL ENGINE, ON PURPOSE. openregister#3362 measured
 * what a mocked seam is worth here: 30 of 32 added statements uncovered,
 * because every recovery test mocked the bridge it was testing. The property
 * that matters is not inside the node — it is what the ENGINE hands a parked
 * node when it re-enters it on a timer, and what survives between passes. So
 * this suite builds the real FlowRunService, engine, registry, dispatcher,
 * stream walk, claims and commit path over in-memory mappers, and drives the
 * REAL `ContractDecisionDelegationService` across a real event bus.
 *
 * WHAT IS FAKED, AND WHY ONLY THAT. decidiq itself: its listener answers from
 * an in-memory table of decisions. Its authorization rule is modelled, not
 * copied — the owner may read, anybody else is refused — because REQ-DCDH-101
 * belongs to decidiq and is tested there, and a copy of it here would be a
 * second implementation validated against itself. The same goes for its status
 * derivation: this fake STORES a status rather than deriving one, so the
 * vocabulary crossing the seam is asserted and decidiq's arithmetic is not
 * re-implemented.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @covers \OCA\Dossiq\Flow\DossiqRequestDecisionNode
 * @uses   \OCA\Dossiq\Service\ContractDecisionDelegationService
 *
 * @spec openspec/changes/requestdecision-recovers-a-missed-conclusion/specs/case-flow-human-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Flow;

// 🔑 ASK FOR THE REAL ENGINE, HERE, AT FILE SCOPE.
//
// The bootstrap registers OpenRegister's own source and its dependencies only
// when this is set, and every other suite in this app runs against the stubs it
// always did. File scope, because PHPUnit loads every test file while building
// the suite — long before it forks the isolated processes below, which inherit
// it.
putenv('DOSSIQ_REAL_FLOW_ENGINE=1');

use OCA\Decidiq\Event\DecisionRequestedEvent;
use OCA\Decidiq\Event\DecisionStateRequestedEvent;
use OCA\Dossiq\Flow\DossiqRequestDecisionNode;
use OCA\Dossiq\Service\ContractDecisionDelegationService;
// A step that kills its run leaves the run FAILED, not STOPPED. These three
// assertions said `stopped` until openregister#3425 ("a run killed by a failing
// step is failed, not stopped") drew the distinction deliberately: `stopped` is
// an operator halting a run, `failed` is the run dying of its own step. The
// three cases below are all the second kind — a withdrawn decision, a vanished
// one, and a read the run's identity is not permitted — and none of them is
// something another heartbeat fixes, which is what the messages say and what
// both statuses have in common. Only the name changed.
use OCA\OpenRegister\Db\FlowClaim;
use OCA\OpenRegister\Db\FlowClaimMapper;
use OCA\OpenRegister\Db\FlowDefinition;
use OCA\OpenRegister\Db\FlowDefinitionMapper;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowRunStep;
use OCA\OpenRegister\Db\FlowRunStepMapper;
use OCA\OpenRegister\Db\FlowStateMapper;
use OCA\OpenRegister\Db\FlowStream;
use OCA\OpenRegister\Db\FlowStreamMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\FlowVersionMapper;
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
class RequestDecisionSubject {
}

/** A step that passes items through, so the graph can split. */
class RequestDecisionPassNode implements IFlowNode {

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
 * The wedge, reproduced against dossiq's decision node and recovered.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A harness for a real engine
 * names the engine's parts; that is what makes it a real engine.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One public method per property
 * of the recovery; collapsing them would report one failure for six defects.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class RequestDecisionHeartbeatRecoveryTest extends TestCase {

	/**
	 * The decisions the fake decidiq holds, by id.
	 *
	 * @var array<string, array{owner: string, status: string, decidedAt: string}>
	 */
	private array $decisions = [];

	/**
	 * Decision ids in raise order. Growing past one per node is the
	 * duplicate-decision defect a heartbeat used to be able to cause.
	 *
	 * @var array<int, string>
	 */
	private array $raised = [];

	/**
	 * The uid the fake decidiq stamps as a raised decision's owner.
	 *
	 * decidiq takes it from the identity the raise ran as, which under the
	 * engine is the run's `runAs`. Made settable so the refusal branch can be
	 * produced the way it really happens: a decision owned by somebody else.
	 *
	 * @var string
	 */
	private string $decisionOwner = 'alice';

	/**
	 * Whether the fake decidiq can answer a state read at all.
	 *
	 * False leaves the event UNHANDLED, which is exactly what decidiq's own
	 * listener does when the lookup cannot be resolved.
	 *
	 * @var boolean
	 */
	private bool $seamAnswers = true;

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
	 * The node under test.
	 *
	 * @var DossiqRequestDecisionNode|null
	 */
	private ?DossiqRequestDecisionNode $node = null;

	/**
	 * The pass-through node the graph splits on.
	 *
	 * @var RequestDecisionPassNode|null
	 */
	private ?RequestDecisionPassNode $pass = null;

	/**
	 * The real run service under test.
	 *
	 * @var FlowRunService
	 */
	private FlowRunService $service;

	/**
	 * The real guarded signal seam, so an announcement is a real resume.
	 *
	 * @var FlowRunSignalService
	 */
	private FlowRunSignalService $signals;

	protected function setUp(): void {
		parent::setUp();

		$this->requireTheRealEngine();

		$this->decisions = [];
		$this->raised = [];
		$this->decisionOwner = 'alice';
		$this->seamAnswers = true;
		$this->row = null;
		$this->streams = [];
		$this->claims = [];

		$runs = $this->runMapper();
		$streamMapper = $this->streamMapper();
		$claimMapper = $this->claimMapper();

		// ONE BUS, as in production: the same dispatcher carries the engine's
		// node-registration event and dossiq's cross-app decision contract.
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->bus($event);
			}
		);

		$this->pass = new RequestDecisionPassNode();
		$this->node = $this->requestDecisionNode($dispatcher);

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
	 * A suite whose whole point is the engine's behaviour must not quietly pass
	 * against a stub of it. On a developer machine without OpenRegister beside
	 * this app there is nothing to measure and the suite says so; in CI the
	 * sibling checkout is part of the job, so its absence is a broken
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
	 * Route one dispatched event to whoever answers it.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 */
	private function bus(Event $event): void {
		if ($event instanceof RegisterFlowNodesEvent) {
			$event->registerNode($this->node);
			$event->registerNode($this->pass);

			return;
		}

		if ($event instanceof DecisionRequestedEvent) {
			$this->decidiqRaises($event);

			return;
		}

		if ($event instanceof DecisionStateRequestedEvent) {
			$this->decidiqReports($event);
		}
	}//end bus()

	/**
	 * decidiq creates the Decision and answers with its id.
	 *
	 * @param DecisionRequestedEvent $event The raise.
	 *
	 * @return void
	 */
	private function decidiqRaises(DecisionRequestedEvent $event): void {
		$id = 'decision-' . (count($this->raised) + 1);

		$this->decisions[$id] = ['owner' => $this->decisionOwner, 'status' => 'pending', 'decidedAt' => ''];
		$this->raised[] = $id;

		$event->setDecisionId($id);
		$event->setHandled(true);
	}//end decidiqRaises()

	/**
	 * decidiq reports what became of a Decision, under its own rules.
	 *
	 * Mirrors `DecisionStateRequestedListener` where the CONTRACT lives —
	 * unhandled means "ask me again", a read naming nobody is refused rather
	 * than elevated, a caller who is not the owner is refused, and a genuine
	 * miss is permitted-but-not-found so it reads as 404 rather than 403.
	 *
	 * @param DecisionStateRequestedEvent $event The read.
	 *
	 * @return void
	 */
	private function decidiqReports(DecisionStateRequestedEvent $event): void {
		if ($this->seamAnswers === false) {
			// Left UNHANDLED, exactly as decidiq leaves an unresolvable lookup.
			return;
		}

		$id = trim($event->getDecisionId());
		$actor = trim($event->getActorId());
		if ($id === '' || $actor === '') {
			$event->setHandled(true);

			return;
		}

		$decision = ($this->decisions[$id] ?? null);
		if ($decision !== null && $decision['owner'] !== $actor) {
			$event->setHandled(true);

			return;
		}

		$event->setPermitted(true);

		if ($decision === null) {
			$event->setHandled(true);

			return;
		}

		$event->setFound(true);
		$event->setEnvelope(
			[
				'decisionId' => $id,
				'decisionType' => 'advice',
				'status' => $decision['status'],
				'decidedAt' => $decision['decidedAt'],
				'signed' => false,
			]
		);
		$event->setHandled(true);
	}//end decidiqReports()

	/**
	 * The node under test, wired to the REAL delegation service.
	 *
	 * @param IEventDispatcher $dispatcher The bus decidiq answers on.
	 *
	 * @return DossiqRequestDecisionNode The node.
	 */
	private function requestDecisionNode(IEventDispatcher $dispatcher): DossiqRequestDecisionNode {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new DossiqRequestDecisionNode(
			new ContractDecisionDelegationService($dispatcher, new NullLogger()),
			$l10n,
			new NullLogger()
		);
	}//end requestDecisionNode()

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
	 * Everything else is unresolvable, which the engine reads as "no identity
	 * scope": the node runs bare, exactly as it does on the interactive path.
	 * The run's `runAs` still reaches the node context, which is what this
	 * change reads the acting identity from.
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
	 * One decision, on a single path — the shape a case flow uses, and the one
	 * whose OUTCOME can be read off the run's items.
	 *
	 * @return array<string, mixed> The flow document.
	 */
	private function chainFlow(): array {
		return [
			'id' => 'case-flow',
			'nodes' => [
				['id' => 'start', 'type' => 'test.pass'],
				[
					'id' => 'decide-register-b',
					'type' => 'dossiq.requestDecision',
					'config' => [
						'question' => 'Toets aan register B',
						'signalKey' => 'toets',
						'heartbeatMinutes' => 120,
					],
				],
			],
			'edges' => [
				['id' => 'e1', 'from' => 'start', 'to' => 'decide-register-b'],
			],
		];
	}//end chainFlow()

	/**
	 * A graph that asks for two decisions at once — the shape whose per-node
	 * slots a run-level signal cannot tell apart.
	 *
	 * @return array<string, mixed> The flow document.
	 */
	private function forkFlow(): array {
		$flow = $this->chainFlow();
		$flow['nodes'][] = [
			'id' => 'decide-mandaat',
			'type' => 'dossiq.requestDecision',
			'config' => [
				'question' => 'Is er mandaat',
				'signalKey' => 'mandaat',
				'heartbeatMinutes' => 120,
			],
		];
		$flow['edges'][] = ['id' => 'e2', 'from' => 'start', 'to' => 'decide-mandaat'];

		return $flow;
	}//end forkFlow()

	/**
	 * Park the single decision on the decision it just raised.
	 *
	 * @return FlowRun The suspended run.
	 */
	private function suspendedOnOneDecision(): FlowRun {
		$run = $this->service->queue('case-flow', user: 'alice');
		$run = $this->service->execute(
			$run,
			$this->chainFlow(),
			new RequestDecisionSubject(),
			seedItems: [FlowItems::item(json: ['id' => 'case-7', 'title' => 'Dakkapel'])]
		);

		self::assertSame(FlowRun::STATUS_SUSPENDED, $run->getStatus(), (string)$run->getError());
		self::assertCount(1, $this->raised, 'One question raised once, on the first pass.');

		return $run;
	}//end suspendedOnOneDecision()

	/**
	 * Park both branches on the decisions they just raised.
	 *
	 * @return FlowRun The suspended run.
	 */
	private function suspendedOnBothDecisions(): FlowRun {
		$run = $this->service->queue('case-flow', user: 'alice');
		$run = $this->service->execute(
			$run,
			$this->forkFlow(),
			new RequestDecisionSubject(),
			seedItems: [FlowItems::item(json: ['id' => 'case-7', 'title' => 'Dakkapel'])]
		);

		self::assertSame(FlowRun::STATUS_SUSPENDED, $run->getStatus(), (string)$run->getError());
		self::assertCount(2, $this->raised, 'One decision per step, raised on the first pass.');

		return $run;
	}//end suspendedOnBothDecisions()

	/**
	 * Conclude a decision exactly as decidiq leaves one.
	 *
	 * @param string $id     The decision.
	 * @param string $status decidiq's own status word.
	 *
	 * @return void
	 */
	private function conclude(string $id, string $status = 'approved'): void {
		$this->decisions[$id]['status'] = $status;
		$this->decisions[$id]['decidedAt'] = '2026-09-03T11:28:00+00:00';
	}//end conclude()

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
	 * Rewrite one parked node's slot, to model a run parked before a change.
	 *
	 * @param FlowRun              $run    The run.
	 * @param string               $nodeId The node.
	 * @param array<string, mixed> $slot   What the slot should hold.
	 *
	 * @return void
	 */
	private function rewriteSlot(FlowRun $run, string $nodeId, array $slot): void {
		$context = $run->getContext();
		$context['resumeState'][$nodeId] = $slot;
		$run->setContext($context);
	}//end rewriteSlot()

	/**
	 * The reason a step failed, off the run's own log.
	 *
	 * READ FROM THE LOG, NOT FROM `getError()`. A node that throws ends the run
	 * on the engine's default `onError` policy, which finalises it as STOPPED
	 * and leaves the run-level error null; the reason is recorded against the
	 * STEP. Asserting on `getError()` would therefore assert on an empty string
	 * and pass for any failure, including the wrong one.
	 *
	 * @param FlowRun $run    The terminal run.
	 * @param string  $nodeId The step that failed.
	 *
	 * @return string The recorded reason, or '' when that step did not fail.
	 */
	private function stepFailure(FlowRun $run, string $nodeId): string {
		foreach ((array)$run->getLog() as $entry) {
			$entry = (array)$entry;
			if (($entry['transition'] ?? null) === $nodeId && ($entry['status'] ?? null) === 'failed') {
				return (string)($entry['error'] ?? '');
			}
		}

		return '';
	}//end stepFailure()

	/**
	 * 🔴 THE LIVE WEDGE, END TO END: concluded → no announcement → heartbeat →
	 * advanced.
	 *
	 * Before this change the story ended at the second step: the decision was
	 * taken, no signal ever reached the run, and every heartbeat re-suspended
	 * because the node looked only at `context.signal`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/requestdecision-recovers-a-missed-conclusion/specs/case-flow-human-steps/spec.md
	 */
	public function testAHeartbeatDeliversAConclusionWhoseAnnouncementNeverArrived(): void {
		$run = $this->suspendedOnOneDecision();
		$ref = (string)$this->slot($run, 'decide-register-b')['decisionRef'];

		// decidiq decides. Nothing tells the run: the announcement is lost.
		$this->conclude($ref);

		self::assertSame(FlowRun::STATUS_SUSPENDED, $this->row->getStatus(), 'A conclusion nobody heard changes nothing yet.');

		// The heartbeat fires: findDue() -> advance() -> execute().
		$run = $this->service->execute($run, $this->chainFlow(), new RequestDecisionSubject());

		self::assertSame(FlowRun::STATUS_COMPLETED, $run->getStatus(), 'The heartbeat must deliver the outcome, not roll again.');

		$json = (array)($run->getItems()[0]['json'] ?? []);
		self::assertSame('approved', ($json['toets']['decision'] ?? null));
		self::assertSame($ref, ($json['toets']['decisionRef'] ?? null));
		self::assertTrue(($json['toets']['recovered'] ?? false), 'A recovered outcome says so.');
		self::assertSame([$ref], $this->raised, 'Recovery must never raise a second decision.');
	}//end testAHeartbeatDeliversAConclusionWhoseAnnouncementNeverArrived()

	/**
	 * A heartbeat that finds the decisions still open parks again on the SAME
	 * decisions: nobody is convened twice, and `askedAt` is not restamped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/requestdecision-recovers-a-missed-conclusion/specs/case-flow-human-steps/spec.md
	 */
	public function testAHeartbeatWithTheDecisionsStillOpenParksAgainOnTheSameDecisions(): void {
		$run = $this->suspendedOnBothDecisions();
		$before = $this->slot($run, 'decide-register-b');

		$run = $this->service->execute($run, $this->forkFlow(), new RequestDecisionSubject());

		self::assertSame(FlowRun::STATUS_SUSPENDED, $run->getStatus());
		self::assertCount(2, $this->raised, 'A wake must never raise a second decision for a parked node.');

		$after = $this->slot($run, 'decide-register-b');
		self::assertSame($before['decisionRef'], $after['decisionRef']);
		self::assertSame($before['askedAt'], $after['askedAt'], 'A heartbeat must not restamp askedAt.');
	}//end testAHeartbeatWithTheDecisionsStillOpenParksAgainOnTheSameDecisions()

	/**
	 * Per-node slot addressing holds through a recovery: only the node whose
	 * decision concluded advances, and its sibling keeps waiting on its own.
	 *
	 * This is what a run-level `context.signal` cannot express, and why the
	 * outcome is read from decidiq rather than from the wake.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/requestdecision-recovers-a-missed-conclusion/specs/case-flow-human-steps/spec.md
	 */
	public function testOnlyTheNodeWhoseDecisionConcludedAdvances(): void {
		$run = $this->suspendedOnBothDecisions();
		$refA = (string)$this->slot($run, 'decide-register-b')['decisionRef'];
		$refB = (string)$this->slot($run, 'decide-mandaat')['decisionRef'];

		$this->conclude($refA);

		$run = $this->service->execute($run, $this->forkFlow(), new RequestDecisionSubject());

		self::assertSame(FlowRun::STATUS_SUSPENDED, $run->getStatus(), 'The other branch still waits.');
		self::assertCount(2, $this->raised);
		self::assertSame([], $this->slot($run, 'decide-register-b'), 'A node that advanced has nothing left to remember.');
		self::assertSame($refB, (string)$this->slot($run, 'decide-mandaat')['decisionRef'], 'The waiting sibling keeps its own decision.');
	}//end testOnlyTheNodeWhoseDecisionConcludedAdvances()

	/**
	 * The announced path and the recovered path put the SAME outcome on the
	 * items, which is the contract that makes the recovery safe to rely on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/requestdecision-recovers-a-missed-conclusion/specs/case-flow-human-steps/spec.md
	 */
	public function testTheAnnouncedPathAndTheRecoveredPathAgree(): void {
		$run = $this->suspendedOnOneDecision();
		$ref = (string)$this->slot($run, 'decide-register-b')['decisionRef'];

		$this->conclude($ref);

		// DecisionConcludedListener's resume, reaching the run this time.
		$run = $this->signals->signalAs(
			runUuid: (string)$run->getUuid(),
			payload: ['decision' => 'approved', 'decisionRef' => $ref, 'caseId' => 'case-7'],
			actorUid: 'alice',
			nodeId: 'decide-register-b'
		);

		$run = $this->service->execute($run, $this->chainFlow(), new RequestDecisionSubject());

		self::assertSame(FlowRun::STATUS_COMPLETED, $run->getStatus());

		$json = (array)($run->getItems()[0]['json'] ?? []);
		self::assertSame('approved', ($json['toets']['decision'] ?? null));
		self::assertSame($ref, ($json['toets']['decisionRef'] ?? null));
		self::assertSame('case-7', ($json['toets']['caseId'] ?? null), 'The wake contributes what the read does not carry.');
		self::assertFalse(($json['toets']['recovered'] ?? null), 'This outcome arrived by announcement, not by heartbeat.');
	}//end testTheAnnouncedPathAndTheRecoveredPathAgree()

	/**
	 * 🔴 A SIGNAL CANNOT ANSWER FOR AN OPEN DECISION.
	 *
	 * The run holds ONE signal slot, so a wake carrying somebody else's answer
	 * used to be enough to advance this node. Now decidiq is asked, and decidiq
	 * says the question is open.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/requestdecision-recovers-a-missed-conclusion/specs/case-flow-human-steps/spec.md
	 */
	public function testASignalCannotAnswerForAnOpenDecision(): void {
		$run = $this->suspendedOnOneDecision();

		$run = $this->signals->signalAs(
			runUuid: (string)$run->getUuid(),
			payload: ['decision' => 'approved', 'decisionRef' => 'somebody-elses-decision'],
			actorUid: 'alice',
			nodeId: 'decide-register-b'
		);

		$run = $this->service->execute($run, $this->chainFlow(), new RequestDecisionSubject());

		self::assertSame(FlowRun::STATUS_SUSPENDED, $run->getStatus(), 'An open decision is not answered by a wake.');
	}//end testASignalCannotAnswerForAnOpenDecision()

	/**
	 * A withdrawn decision FAILS the step rather than inventing an answer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/requestdecision-recovers-a-missed-conclusion/specs/case-flow-human-steps/spec.md
	 */
	public function testAWithdrawnDecisionFailsTheStep(): void {
		$run = $this->suspendedOnOneDecision();
		$ref = (string)$this->slot($run, 'decide-register-b')['decisionRef'];

		$this->conclude($ref, status: 'withdrawn');

		$run = $this->service->execute($run, $this->chainFlow(), new RequestDecisionSubject());

		self::assertSame(FlowRun::STATUS_FAILED, $run->getStatus(), 'A withdrawn decision is neither an answer nor something to wait for.');
		$failure = $this->stepFailure($run, 'decide-register-b');
		self::assertStringContainsString($ref, $failure);
		self::assertStringContainsString('withdrawn', $failure);
	}//end testAWithdrawnDecisionFailsTheStep()

	/**
	 * A decision that no longer exists fails the step rather than waiting on a
	 * record that is gone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/requestdecision-recovers-a-missed-conclusion/specs/case-flow-human-steps/spec.md
	 */
	public function testAVanishedDecisionFailsTheStep(): void {
		$run = $this->suspendedOnOneDecision();
		$ref = (string)$this->slot($run, 'decide-register-b')['decisionRef'];

		unset($this->decisions[$ref]);

		$run = $this->service->execute($run, $this->chainFlow(), new RequestDecisionSubject());

		self::assertSame(FlowRun::STATUS_FAILED, $run->getStatus());
		self::assertStringContainsString('no longer exists', $this->stepFailure($run, 'decide-register-b'));
	}//end testAVanishedDecisionFailsTheStep()

	/**
	 * 🔴 UNREADABLE IS NOT GONE. A seam that cannot answer buys one more
	 * heartbeat, and the decision is delivered on the next one.
	 *
	 * The pair is the assertion: suspending on an unreadable seam is only
	 * correct if the run still recovers once the seam answers.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/requestdecision-recovers-a-missed-conclusion/specs/case-flow-human-steps/spec.md
	 */
	public function testAnUnreadableSeamBuysAnotherHeartbeat(): void {
		$run = $this->suspendedOnOneDecision();
		$ref = (string)$this->slot($run, 'decide-register-b')['decisionRef'];

		$this->conclude($ref);
		$this->seamAnswers = false;

		$run = $this->service->execute($run, $this->chainFlow(), new RequestDecisionSubject());

		self::assertSame(FlowRun::STATUS_SUSPENDED, $run->getStatus(), 'An unreachable seam must not fail a case whose decision is taken.');
		self::assertSame($ref, (string)$this->slot($run, 'decide-register-b')['decisionRef']);

		$this->seamAnswers = true;

		$run = $this->service->execute($run, $this->chainFlow(), new RequestDecisionSubject());

		self::assertSame(FlowRun::STATUS_COMPLETED, $run->getStatus(), 'And the next heartbeat delivers it.');
	}//end testAnUnreadableSeamBuysAnotherHeartbeat()

	/**
	 * A refusal is a misconfiguration to surface, not a state to poll.
	 *
	 * Produced the way it really happens: the Decision is owned by somebody
	 * else, so decidiq's owner rule answers "not permitted" to the identity
	 * this run raised it as.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/requestdecision-recovers-a-missed-conclusion/specs/case-flow-human-steps/spec.md
	 */
	public function testAReadRefusedForTheRunsIdentityFailsTheStep(): void {
		$this->decisionOwner = 'somebody-else';

		$run = $this->suspendedOnOneDecision();
		$ref = (string)$this->slot($run, 'decide-register-b')['decisionRef'];

		$this->conclude($ref);

		$run = $this->service->execute($run, $this->chainFlow(), new RequestDecisionSubject());

		self::assertSame(FlowRun::STATUS_FAILED, $run->getStatus(), 'No number of heartbeats fixes an authorization mistake.');
		self::assertStringContainsString('refused', $this->stepFailure($run, 'decide-register-b'));
	}//end testAReadRefusedForTheRunsIdentityFailsTheStep()

	/**
	 * A run parked BEFORE this change recovers with no repair step.
	 *
	 * Its slot records a ref and no `raisedBy`, because the field did not exist
	 * when it parked. The run's current acting identity is the fallback, which
	 * is the same uid in every case that is not the pathological one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/requestdecision-recovers-a-missed-conclusion/specs/case-flow-human-steps/spec.md
	 */
	public function testARunParkedBeforeTheChangeRecoversWithoutARepair(): void {
		$run = $this->suspendedOnOneDecision();
		$slot = $this->slot($run, 'decide-register-b');
		$ref = (string)$slot['decisionRef'];

		unset($slot['raisedBy']);
		$this->rewriteSlot($run, 'decide-register-b', $slot);
		self::assertArrayNotHasKey('raisedBy', $this->slot($run, 'decide-register-b'));

		$this->conclude($ref, status: 'rejected');

		$run = $this->service->execute($run, $this->chainFlow(), new RequestDecisionSubject());

		self::assertSame(FlowRun::STATUS_COMPLETED, $run->getStatus(), (string)$run->getError());
		self::assertSame('rejected', ($run->getItems()[0]['json']['toets']['decision'] ?? null));
	}//end testARunParkedBeforeTheChangeRecoversWithoutARepair()

	/**
	 * The raise records the identity it ran as, so the read back is scoped to
	 * the owner decidiq stamped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/requestdecision-recovers-a-missed-conclusion/specs/case-flow-human-steps/spec.md
	 */
	public function testTheRaiseRecordsTheIdentityItRanAs(): void {
		$run = $this->suspendedOnOneDecision();

		self::assertSame('alice', ($this->slot($run, 'decide-register-b')['raisedBy'] ?? null));
	}//end testTheRaiseRecordsTheIdentityItRanAs()
}//end class
