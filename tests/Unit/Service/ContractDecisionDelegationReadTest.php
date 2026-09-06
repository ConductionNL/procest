<?php

/**
 * Reading a decision back: the six answers, and the two that must never be
 * confused.
 *
 * 🔴 THE POINT IS THAT UNREADABLE IS NOT DENIED AND NOT GONE. decidiq#1118
 * reports "I could not resolve this" distinctly from "you may not see it" and
 * from "there is no such decision", and the caller acts differently on each: an
 * unreadable seam is worth waiting through, a refusal is a misconfiguration to
 * surface, and a vanished decision is not worth waiting for at all. A service
 * that collapsed them would let an unreachable OpenRegister fail a case whose
 * decision is sitting there taken.
 *
 * DRIVEN OVER THE REAL EVENT CLASS, not a hand-rolled double of it. The event's
 * three result slots are the contract; a fake with two would agree with this
 * service by construction and could not fail.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/requestdecision-recovers-a-missed-conclusion/specs/case-flow-human-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Decidiq\Event\DecisionStateRequestedEvent;
use OCA\Dossiq\Service\ContractDecisionDelegationService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class ContractDecisionDelegationReadTest extends TestCase {

	/**
	 * How many events the service actually dispatched.
	 *
	 * @var integer
	 */
	private int $dispatched = 0;

	/**
	 * The last read event decidiq was handed.
	 *
	 * @var DecisionStateRequestedEvent|null
	 */
	private ?DecisionStateRequestedEvent $seen = null;

	protected function setUp(): void {
		$this->dispatched = 0;
		$this->seen = null;
	}//end setUp()

	/**
	 * The service, wired to a decidiq that answers as told.
	 *
	 * @param callable|null $answer What decidiq does with the read event; null throws.
	 *
	 * @return ContractDecisionDelegationService The service under test.
	 */
	private function service(?callable $answer): ContractDecisionDelegationService {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use ($answer): void {
				$this->dispatched++;

				if ($event instanceof DecisionStateRequestedEvent === false) {
					return;
				}

				$this->seen = $event;

				if ($answer === null) {
					throw new RuntimeException('the bus blew up');
				}

				$answer($event);
			}
		);

		return new ContractDecisionDelegationService($dispatcher, new NullLogger());
	}//end service()

	/**
	 * decidiq answers with a concluded decision.
	 *
	 * @param string $status The status word decidiq reports.
	 *
	 * @return callable The answer.
	 */
	private function reports(string $status): callable {
		return static function (DecisionStateRequestedEvent $event) use ($status): void {
			$event->setPermitted(true);
			$event->setFound(true);
			$event->setEnvelope(['decisionId' => $event->getDecisionId(), 'status' => $status, 'decidedAt' => '2026-09-03T11:28:00+00:00']);
			$event->setHandled(true);
		};
	}//end reports()

	public function testAConcludedDecisionReadsAsDecided(): void {
		$read = $this->service($this->reports('approved'))->readDecisionState('decision-1', 'alice');

		self::assertSame(ContractDecisionDelegationService::DECISION_STATE_DECIDED, $read['state']);
		self::assertSame('approved', $read['status']);
		self::assertSame('2026-09-03T11:28:00+00:00', ($read['envelope']['decidedAt'] ?? null));
	}//end testAConcludedDecisionReadsAsDecided()

	public function testARejectedDecisionIsAlsoDecided(): void {
		$read = $this->service($this->reports('rejected'))->readDecisionState('decision-1', 'alice');

		self::assertSame(ContractDecisionDelegationService::DECISION_STATE_DECIDED, $read['state']);
		self::assertSame('rejected', $read['status']);
	}//end testARejectedDecisionIsAlsoDecided()

	public function testAPendingDecisionReadsAsOpen(): void {
		$read = $this->service($this->reports('pending'))->readDecisionState('decision-1', 'alice');

		self::assertSame(ContractDecisionDelegationService::DECISION_STATE_OPEN, $read['state']);
	}//end testAPendingDecisionReadsAsOpen()

	public function testAWithdrawnDecisionIsItsOwnAnswer(): void {
		$read = $this->service($this->reports('withdrawn'))->readDecisionState('decision-1', 'alice');

		self::assertSame(ContractDecisionDelegationService::DECISION_STATE_WITHDRAWN, $read['state']);
	}//end testAWithdrawnDecisionIsItsOwnAnswer()

	/**
	 * A status word this app does not know can only come from a newer decidiq.
	 * Waiting costs a heartbeat; guessing it means "decided" would advance a
	 * case on an outcome nobody here can name.
	 */
	public function testAnUnknownStatusReadsAsStillOpen(): void {
		$read = $this->service($this->reports('escalated-to-the-council'))->readDecisionState('decision-1', 'alice');

		self::assertSame(ContractDecisionDelegationService::DECISION_STATE_OPEN, $read['state']);
	}//end testAnUnknownStatusReadsAsStillOpen()

	/**
	 * 🔴 A REFUSAL IS NOT A MISSING DECISION.
	 */
	public function testARefusedReadIsRefusedAndNotGone(): void {
		$read = $this->service(
			static function (DecisionStateRequestedEvent $event): void {
				$event->setHandled(true);
			}
		)->readDecisionState('decision-1', 'mallory');

		self::assertSame(ContractDecisionDelegationService::DECISION_STATE_REFUSED, $read['state']);
	}//end testARefusedReadIsRefusedAndNotGone()

	/**
	 * Permitted and not found is a genuine miss, answered as 404 rather than
	 * converted into a 403.
	 */
	public function testAPermittedReadThatFindsNothingIsGone(): void {
		$read = $this->service(
			static function (DecisionStateRequestedEvent $event): void {
				$event->setPermitted(true);
				$event->setHandled(true);
			}
		)->readDecisionState('decision-1', 'alice');

		self::assertSame(ContractDecisionDelegationService::DECISION_STATE_GONE, $read['state']);
	}//end testAPermittedReadThatFindsNothingIsGone()

	/**
	 * 🔴 UNHANDLED IS "ASK ME AGAIN". decidiq leaves the event unhandled when
	 * the lookup could not be resolved — an unreachable OpenRegister — and that
	 * must never read as a refusal or as a vanished decision.
	 */
	public function testAnUnhandledReadIsUnreadableAndNotDenied(): void {
		$read = $this->service(static function (DecisionStateRequestedEvent $event): void {
		})->readDecisionState('decision-1', 'alice');

		self::assertSame(ContractDecisionDelegationService::DECISION_STATE_UNREADABLE, $read['state']);
		self::assertNotSame(ContractDecisionDelegationService::DECISION_STATE_REFUSED, $read['state']);
		self::assertNotSame(ContractDecisionDelegationService::DECISION_STATE_GONE, $read['state']);
	}//end testAnUnhandledReadIsUnreadableAndNotDenied()

	/**
	 * A dispatch that throws says nothing about whether the decision exists.
	 */
	public function testADispatchThatThrowsIsUnreadable(): void {
		$read = $this->service(null)->readDecisionState('decision-1', 'alice');

		self::assertSame(ContractDecisionDelegationService::DECISION_STATE_UNREADABLE, $read['state']);
	}//end testADispatchThatThrowsIsUnreadable()

	/**
	 * 🔴 A READ NAMING NOBODY IS NOT DISPATCHED.
	 *
	 * decidiq refuses it, deliberately — it is what stops a consumer reading
	 * back decisions its own runs never raised — so asking is pointless, and
	 * asserting that NOTHING was dispatched is what pins that the guard is in
	 * front of the bus rather than behind it.
	 */
	public function testAReadWithNoActorIsNotEvenDispatched(): void {
		$read = $this->service($this->reports('approved'))->readDecisionState('decision-1', '   ');

		self::assertSame(ContractDecisionDelegationService::DECISION_STATE_UNREADABLE, $read['state']);
		self::assertSame(0, $this->dispatched, 'A read naming nobody must not reach the bus.');
	}//end testAReadWithNoActorIsNotEvenDispatched()

	public function testAReadWithNoDecisionIsNotEvenDispatched(): void {
		$read = $this->service($this->reports('approved'))->readDecisionState('  ', 'alice');

		self::assertSame(ContractDecisionDelegationService::DECISION_STATE_UNREADABLE, $read['state']);
		self::assertSame(0, $this->dispatched);
	}//end testAReadWithNoDecisionIsNotEvenDispatched()

	/**
	 * The read carries the frozen source app and the identity it is scoped to,
	 * which is the whole authorization input decidiq gets.
	 */
	public function testTheReadNamesTheSourceAppAndTheIdentity(): void {
		$this->service($this->reports('approved'))->readDecisionState(' decision-1 ', ' alice ');

		self::assertNotNull($this->seen);
		self::assertSame('procest', $this->seen->getSourceApp());
		self::assertSame('decision-1', $this->seen->getDecisionId(), 'Trimmed, so a padded ref still resolves.');
		self::assertSame('alice', $this->seen->getActorId());
	}//end testTheReadNamesTheSourceAppAndTheIdentity()
}//end class
