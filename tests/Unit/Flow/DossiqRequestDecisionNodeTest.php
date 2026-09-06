<?php

/**
 * Unit tests for DossiqRequestDecisionNode — ask decidiq, wait, then ask again.
 *
 * 🔴 THE FAIL-CLOSED TEST IS THE POINT. When decidiq is unavailable the step
 * must FAIL and the run must stop at the decision. The tempting alternative —
 * catch, log, carry on — produces a case decided by nobody, which is the single
 * outcome a decision step exists to prevent.
 *
 * The second property is idempotence: a heartbeat must not raise the decision
 * again, or people are convened repeatedly for a question already asked.
 *
 * The third, added with the recovery (dossiq#1756's named gap, decidiq#1118's
 * read seam), is that a re-entry READS THE DECISION BACK. So the delegation
 * double here answers reads as well as taking raises: a double that took raises
 * and answered nothing would leave the whole re-entry path "tested" against
 * nothing, which is exactly the shape of defect this change exists to close.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/requestdecision-recovers-a-missed-conclusion/specs/case-flow-human-steps/spec.md
 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Flow;

use OCA\Dossiq\Flow\DossiqRequestDecisionNode;
use OCA\Dossiq\Service\ContractDecisionDelegationService;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use UnexpectedValueException;

class DossiqRequestDecisionNodeTest extends TestCase {

	/**
	 * How many decisions the delegation was asked to raise.
	 *
	 * @var integer
	 */
	private int $raised = 0;

	/**
	 * The identities the delegation was asked to read AS, in order.
	 *
	 * @var array<int, string>
	 */
	private array $readAs = [];

	/**
	 * What the delegation answers a read with.
	 *
	 * @var array{state: string, status: string, envelope: array<string, mixed>}
	 */
	private array $answer = ['state' => 'open', 'status' => 'pending', 'envelope' => []];

	protected function setUp(): void {
		$this->raised = 0;
		$this->readAs = [];
		$this->answer = ['state' => ContractDecisionDelegationService::DECISION_STATE_OPEN, 'status' => 'pending', 'envelope' => []];
	}//end setUp()

	/**
	 * The node, wired to a delegation that behaves as told.
	 *
	 * @param string|null $ref The ref to return, or null to throw (decidiq down).
	 *
	 * @return DossiqRequestDecisionNode The node under test.
	 */
	private function node(?string $ref = 'decision-1'): DossiqRequestDecisionNode {
		$delegation = $this->createMock(ContractDecisionDelegationService::class);

		if ($ref === null) {
			$delegation->method('raiseDecision')->willReturnCallback(
				function (): string {
					$this->raised++;

					throw new RuntimeException('decidiq unavailable');
				}
			);
		} else {
			$delegation->method('raiseDecision')->willReturnCallback(
				function () use ($ref): string {
					$this->raised++;

					return $ref;
				}
			);
		}

		$delegation->method('readDecisionState')->willReturnCallback(
			function (string $decisionId, string $actorId): array {
				$this->readAs[] = $actorId;

				return $this->answer;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new DossiqRequestDecisionNode($delegation, $l10n, new NullLogger());
	}//end node()

	/**
	 * Say what decidiq reports on the next read.
	 *
	 * @param string $state  One of the DECISION_STATE_* constants.
	 * @param string $status decidiq's own status word.
	 *
	 * @return void
	 */
	private function decidiqReports(string $state, string $status = ''): void {
		$this->answer = [
			'state' => $state,
			'status' => $status,
			'envelope' => ['status' => $status, 'decidedAt' => '2026-09-03T11:28:00+00:00', 'signed' => false],
		];
	}//end decidiqReports()

	/**
	 * One item carrying a case.
	 *
	 * @return array<int, array<string, mixed>> The items.
	 */
	private function items(): array {
		return [['json' => ['id' => 'case-1', 'title' => 'Dakkapel']]];
	}//end items()

	/**
	 * A valid configuration.
	 *
	 * @return array<string, mixed> The config.
	 */
	private function config(): array {
		return ['question' => 'Toets aan register B'];
	}//end config()

	/**
	 * The context the engine hands a node: its resume slot, and the run's
	 * acting identity.
	 *
	 * @param FlowNodeResumeState  $resume The node's slot.
	 * @param array<string, mixed> $extra  Anything else the context carries.
	 * @param string               $runAs  The run's acting identity.
	 *
	 * @return array<string, mixed> The context.
	 */
	private static function context(FlowNodeResumeState $resume, array $extra = [], string $runAs = 'alice'): array {
		return array_merge(
			[
				FlowNodeResumeState::CONTEXT_KEY => $resume,
				FlowRunService::RUN_AS_CONTEXT_KEY => $runAs,
			],
			$extra
		);
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

	public function testItRaisesTheDecisionAndSuspends(): void {
		$resume = self::resumeSlot('decide-register-b');

		try {
			$this->node()->execute($this->items(), $this->config(), self::context($resume));
			self::fail('The node must suspend while the decision is outstanding.');
		} catch (FlowSuspension $suspension) {
			self::assertStringContainsString('Toets aan register B', $suspension->getMessage());
		}

		self::assertSame(1, $this->raised);
		self::assertSame('decision-1', $resume->get('decisionRef'));
	}//end testItRaisesTheDecisionAndSuspends()

	/**
	 * 🔴 THE READ IS SCOPED TO THE IDENTITY THAT RAISED IT.
	 *
	 * decidiq stamps a Decision's owner from the uid that created it, so the
	 * raise records that uid and the read back names it. A read named as
	 * anybody else is answered "not permitted".
	 */
	public function testTheRaiseRecordsTheIdentityAndTheReadUsesIt(): void {
		$resume = self::resumeSlot('decide-register-b');
		$node = $this->node();

		try {
			$node->execute($this->items(), $this->config(), self::context($resume, runAs: 'alice'));
		} catch (FlowSuspension $e) {
			// expected on the first pass
		}

		self::assertSame('alice', $resume->get('raisedBy'));

		// The run's acting identity changed after it parked. The read must
		// still name the identity the decision was raised as.
		try {
			$node->execute($this->items(), $this->config(), self::context($resume, runAs: 'bob'));
		} catch (FlowSuspension $e) {
			// still open
		}

		self::assertSame(['alice'], $this->readAs);
	}//end testTheRaiseRecordsTheIdentityAndTheReadUsesIt()

	/**
	 * A run parked before this change recorded no identity, so the run's
	 * current one is the fallback — no repair step, no wedge left behind.
	 */
	public function testASlotWithNoRecordedIdentityFallsBackToTheRuns(): void {
		$resume = self::resumeSlot('decide-register-b', ['decisionRef' => 'decision-1', 'askedAt' => 'then']);
		$this->decidiqReports(ContractDecisionDelegationService::DECISION_STATE_DECIDED, 'approved');

		$out = $this->node()->execute($this->items(), $this->config(), self::context($resume, runAs: 'carol'));

		self::assertSame(['carol'], $this->readAs);
		self::assertSame('approved', $out[0]['json']['decisionOutcome']['decision']);
	}//end testASlotWithNoRecordedIdentityFallsBackToTheRuns()

	/**
	 * A run naming nobody cannot read, so it waits rather than dispatching a
	 * read decidiq would refuse or inventing a system caller.
	 */
	public function testARunNamingNoIdentitySuspendsWithoutReading(): void {
		$resume = self::resumeSlot('decide-register-b', ['decisionRef' => 'decision-1']);
		$this->decidiqReports(ContractDecisionDelegationService::DECISION_STATE_DECIDED, 'approved');

		$this->expectException(FlowSuspension::class);

		try {
			$this->node()->execute($this->items(), $this->config(), self::context($resume, runAs: ''));
		} finally {
			self::assertSame([], $this->readAs, 'A read naming nobody must not be attempted.');
		}
	}//end testARunNamingNoIdentitySuspendsWithoutReading()

	// The runAs tests retired with dossiq's FlowRunAsScope: the engine's
	// RegistryStepDispatcher executes every contributed node inside
	// ObjectService::runAs() as the run's validated acting identity
	// (openregister#3332, proven by its RegistryStepDispatcherRunAsTest), so
	// the whole dispatch — including decidiq's synchronous listener write — is
	// scoped without a local wrap, and a test demanding one would re-encode
	// the retired requirement.

	/**
	 * 🔴 A heartbeat must not raise a SECOND decision.
	 */
	public function testAHeartbeatDoesNotRaiseTheDecisionAgain(): void {
		$resume = self::resumeSlot('decide-register-b');
		$node = $this->node();

		foreach ([1, 2, 3] as $ignored) {
			try {
				$node->execute($this->items(), $this->config(), self::context($resume));
			} catch (FlowSuspension $e) {
				// expected while unanswered
			}
		}

		self::assertSame(1, $this->raised, 'One question asked once, however many times the run wakes.');
	}//end testAHeartbeatDoesNotRaiseTheDecisionAgain()

	/**
	 * A heartbeat that finds the decision still open parks again on the SAME
	 * decision, and does not restamp when it was asked.
	 */
	public function testAnOpenDecisionSuspendsWithoutTouchingTheSlot(): void {
		$resume = self::resumeSlot('decide-register-b', ['decisionRef' => 'decision-1', 'askedAt' => 'then']);
		$this->decidiqReports(ContractDecisionDelegationService::DECISION_STATE_OPEN, 'pending');

		try {
			$this->node()->execute($this->items(), $this->config(), self::context($resume));
			self::fail('An open decision must suspend.');
		} catch (FlowSuspension $e) {
			self::assertStringContainsString('Toets aan register B', $e->getMessage());
		}

		self::assertSame('decision-1', $resume->get('decisionRef'));
		self::assertSame('then', $resume->get('askedAt'), 'A heartbeat must not restamp askedAt.');
	}//end testAnOpenDecisionSuspendsWithoutTouchingTheSlot()

	/**
	 * 🔴 FAIL CLOSED. decidiq down means the run STOPS, not proceeds.
	 *
	 * Asserted as "not a FlowSuspension": suspending would leave the run
	 * waiting for a decision that was never actually raised, which looks like
	 * patience and is really a case that can never advance.
	 */
	public function testAnUnavailableDecisionServiceFailsTheStep(): void {
		$resume = self::resumeSlot('decide-register-b');

		try {
			$this->node(ref: null)->execute($this->items(), $this->config(), self::context($resume));
			self::fail('The step must fail when the decision cannot be raised.');
		} catch (FlowSuspension $e) {
			self::fail('A failure to raise must NOT read as waiting.');
		} catch (RuntimeException $e) {
			self::assertStringContainsString('decision_could_not_be_raised', $e->getMessage());
		}
	}//end testAnUnavailableDecisionServiceFailsTheStep()

	/**
	 * A raise that returns no reference cannot be correlated later.
	 */
	public function testARaiseWithoutAReferenceFails(): void {
		$this->expectException(RuntimeException::class);

		$this->node(ref: '  ')->execute(
			$this->items(),
			$this->config(),
			self::context(self::resumeSlot('n'))
		);
	}//end testARaiseWithoutAReferenceFails()

	public function testTheOutcomeIsCarriedOntoTheItems(): void {
		$resume = self::resumeSlot('decide-register-b', ['decisionRef' => 'decision-1', 'askedAt' => 'now', 'raisedBy' => 'alice']);
		$this->decidiqReports(ContractDecisionDelegationService::DECISION_STATE_DECIDED, 'approved');

		$out = $this->node()->execute(
			$this->items(),
			array_merge($this->config(), ['signalKey' => 'toets']),
			self::context($resume, ['signal' => ['decision' => 'approved', 'caseId' => 'case-1']])
		);

		self::assertSame('approved', $out[0]['json']['toets']['decision']);
		self::assertSame('decision-1', $out[0]['json']['toets']['decisionRef']);
		self::assertSame('case-1', $out[0]['json']['toets']['caseId'], 'The wake contributes what the read does not carry.');
		self::assertFalse($out[0]['json']['toets']['recovered'], 'This outcome arrived with a wake.');
		self::assertSame(0, $this->raised, 'An arriving outcome must not raise anything.');
	}//end testTheOutcomeIsCarriedOntoTheItems()

	/**
	 * 🔴 THE DECISION DECIDES, THE WAKE DECORATES. A wake claiming an outcome
	 * cannot override what decidiq reported, and a heartbeat with no wake at
	 * all still delivers — marked as recovered, so it can be found afterwards.
	 */
	public function testTheReadDecidesAndTheWakeCannotOverrideIt(): void {
		$resume = self::resumeSlot('decide-register-b', ['decisionRef' => 'decision-1', 'raisedBy' => 'alice']);
		$this->decidiqReports(ContractDecisionDelegationService::DECISION_STATE_DECIDED, 'rejected');

		$out = $this->node()->execute(
			$this->items(),
			$this->config(),
			self::context($resume, ['signal' => ['decision' => 'approved']])
		);

		self::assertSame('rejected', $out[0]['json']['decisionOutcome']['decision'], 'decidiq decides, not the wake.');

		$resume = self::resumeSlot('decide-register-b', ['decisionRef' => 'decision-1', 'raisedBy' => 'alice']);
		$out = $this->node()->execute($this->items(), $this->config(), self::context($resume));

		self::assertSame('rejected', $out[0]['json']['decisionOutcome']['decision']);
		self::assertTrue($out[0]['json']['decisionOutcome']['recovered'], 'A recovered outcome says so.');
	}//end testTheReadDecidesAndTheWakeCannotOverrideIt()

	/**
	 * A withdrawn decision is neither an answer nor something to wait for.
	 */
	public function testAWithdrawnDecisionFailsTheStep(): void {
		$resume = self::resumeSlot('decide-register-b', ['decisionRef' => 'decision-1', 'raisedBy' => 'alice']);
		$this->decidiqReports(ContractDecisionDelegationService::DECISION_STATE_WITHDRAWN, 'withdrawn');

		try {
			$this->node()->execute($this->items(), $this->config(), self::context($resume));
			self::fail('A withdrawn decision must fail the step.');
		} catch (FlowSuspension $e) {
			self::fail('A withdrawn decision must NOT read as waiting.');
		} catch (RuntimeException $e) {
			self::assertStringContainsString('withdrawn', $e->getMessage());
			self::assertStringContainsString('decision-1', $e->getMessage());
		}
	}//end testAWithdrawnDecisionFailsTheStep()

	/**
	 * A decision that is gone fails rather than waiting forever on a record
	 * that is not there.
	 */
	public function testAVanishedDecisionFailsTheStep(): void {
		$resume = self::resumeSlot('decide-register-b', ['decisionRef' => 'decision-1', 'raisedBy' => 'alice']);
		$this->decidiqReports(ContractDecisionDelegationService::DECISION_STATE_GONE);

		try {
			$this->node()->execute($this->items(), $this->config(), self::context($resume));
			self::fail('A vanished decision must fail the step.');
		} catch (FlowSuspension $e) {
			self::fail('A vanished decision must NOT read as waiting.');
		} catch (RuntimeException $e) {
			self::assertStringContainsString('no longer exists', $e->getMessage());
		}
	}//end testAVanishedDecisionFailsTheStep()

	/**
	 * A refusal is a misconfiguration to surface, not a state to poll.
	 */
	public function testARefusedReadFailsTheStep(): void {
		$resume = self::resumeSlot('decide-register-b', ['decisionRef' => 'decision-1', 'raisedBy' => 'alice']);
		$this->decidiqReports(ContractDecisionDelegationService::DECISION_STATE_REFUSED);

		try {
			$this->node()->execute($this->items(), $this->config(), self::context($resume));
			self::fail('A refused read must fail the step.');
		} catch (FlowSuspension $e) {
			self::fail('No number of heartbeats fixes an authorization mistake.');
		} catch (RuntimeException $e) {
			self::assertStringContainsString('refused', $e->getMessage());
			self::assertStringContainsString('alice', $e->getMessage());
		}
	}//end testARefusedReadFailsTheStep()

	/**
	 * 🔴 UNREADABLE IS NOT GONE. An unreachable seam buys one more heartbeat,
	 * because concluding "no such decision" from a hiccup would fail a case
	 * whose decision is sitting there taken.
	 */
	public function testAnUnreadableSeamSuspendsRatherThanFailing(): void {
		$resume = self::resumeSlot('decide-register-b', ['decisionRef' => 'decision-1', 'raisedBy' => 'alice']);
		$this->decidiqReports(ContractDecisionDelegationService::DECISION_STATE_UNREADABLE);

		$this->expectException(FlowSuspension::class);

		$this->node()->execute($this->items(), $this->config(), self::context($resume));
	}//end testAnUnreadableSeamSuspendsRatherThanFailing()

	public function testAConfigWithNoQuestionIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->node()->validateConfig([]);
	}//end testAConfigWithNoQuestionIsRefused()

	public function testWithoutAResumeSlotItRefuses(): void {
		$this->expectException(RuntimeException::class);

		$this->node()->execute($this->items(), $this->config(), []);
	}//end testWithoutAResumeSlotItRefuses()

	public function testItAnnouncesItsIdentity(): void {
		$node = $this->node();

		self::assertSame('dossiq.requestDecision', $node->getId());
		self::assertNotSame('', $node->getDisplayName());
	}//end testItAnnouncesItsIdentity()
}//end class
