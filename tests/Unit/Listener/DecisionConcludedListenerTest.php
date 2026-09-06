<?php

/**
 * DecisionConcludedListener Unit Tests
 *
 * Verifies that dossiq materialises the ZGW Besluit from decidesk's
 * DecisionConcludedEvent: events for this source app with a terminal status are
 * projected onto the matching case via BesluitMaterialisationService; events
 * from another source app, or with a non-terminal status, are ignored
 * (REQ-PDCD-003).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

// The CURRENT namespace. The decision app renamed OCA\Decidesk -> OCA\Decidiq
// with no alias, and the production resolver now prefers the current spelling —
// so a test importing the OLD class asserts against an object the code no longer
// builds. CrossAppEventNamesTest guards the ordering these follow.
use OCA\Decidiq\Event\DecisionConcludedEvent;
use OCA\Dossiq\Listener\DecisionConcludedListener;
use OCA\Dossiq\Service\BesluitMaterialisationService;
use OCA\Dossiq\Service\Bezwaar\AdvisoryCommitteeService;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Object-service stub exposing the OpenRegister search/find surface the
 * SearchesObjects trait calls.
 */
interface ConcludedObjectServiceStub {
	/**
	 * @param string $registerSlug Register slug.
	 * @param string $schemaSlug Schema slug.
	 * @param array<string,mixed> $filters Filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters): array;
}//end interface

/**
 * Unit tests for DecisionConcludedListener.
 *
 * @covers \OCA\Dossiq\Listener\DecisionConcludedListener
 */
class DecisionConcludedListenerTest extends TestCase {
	/**
	 * A terminal decidesk outcome for this app materialises the ZGW Besluit.
	 *
	 * @return void
	 */
	public function testMaterialisesBesluitForDossiqSourceApp(): void {
		$objectService = $this->createMock(ConcludedObjectServiceStub::class);
		$objectService->method('searchObjectsBySlug')
			->willReturn([['decisionRef' => 'dec-1', 'case' => 'case-9', 'besluitRef' => 'bes-2']]);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);

		$materialiser = $this->createMock(BesluitMaterialisationService::class);
		$materialiser->expects($this->once())
			->method('materialiseFromConcludedEvent')
			->willReturnCallback(
				function (string $caseId, string $decisionId, array $event): array {
					$this->assertSame('case-9', $caseId);
					$this->assertSame('bes-2', $decisionId);
					$this->assertSame('approved', $event['status']);
					return ['ok' => true];
				}
			);

		$listener = new DecisionConcludedListener(
			$settings,
			$materialiser,
			$this->createMock(AdvisoryCommitteeService::class),
			$this->createMock(LoggerInterface::class)
		);

		$listener->handle($this->event(sourceApp: 'procest', status: 'approved'));
	}//end testMaterialisesBesluitForDossiqSourceApp()

	/**
	 * Events from another source app are ignored.
	 *
	 * @return void
	 */
	public function testIgnoresOtherSourceApp(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->expects($this->never())->method('getObjectService');

		$materialiser = $this->createMock(BesluitMaterialisationService::class);
		$materialiser->expects($this->never())->method('materialiseFromConcludedEvent');

		$listener = new DecisionConcludedListener(
			$settings,
			$materialiser,
			$this->createMock(AdvisoryCommitteeService::class),
			$this->createMock(LoggerInterface::class)
		);

		$listener->handle($this->event(sourceApp: 'docudesk', status: 'approved'));
	}//end testIgnoresOtherSourceApp()

	/**
	 * A non-terminal (pending) status does not materialise a Besluit.
	 *
	 * @return void
	 */
	public function testIgnoresNonTerminalStatus(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->expects($this->never())->method('getObjectService');

		$materialiser = $this->createMock(BesluitMaterialisationService::class);
		$materialiser->expects($this->never())->method('materialiseFromConcludedEvent');

		$listener = new DecisionConcludedListener(
			$settings,
			$materialiser,
			$this->createMock(AdvisoryCommitteeService::class),
			$this->createMock(LoggerInterface::class)
		);

		$listener->handle($this->event(sourceApp: 'procest', status: 'pending'));
	}//end testIgnoresNonTerminalStatus()

	/**
	 * A concluded besluit that departs from the BAC advice mirrors the
	 * deviation onto the advice request's audit trail (Awb art. 7:13 lid 7).
	 *
	 * @return void
	 */
	public function testRecordsCouncilDeviationWhenDecisionDepartsFromAdvice(): void {
		$bac = $this->createMock(AdvisoryCommitteeService::class);

		$listener = $this->listenerForDecision(
			record: [
				'decisionRef' => 'dec-1',
				'case' => 'case-9',
				'besluitRef' => 'bes-2',
				'advisoryOpinion' => 'bac-req-7',
				'followsAdvice' => false,
				'deviationRationale' => 'Commissie miste de nieuwe feiten',
			],
			bac: $bac
		);

		$bac->expects($this->once())
			->method('recordCouncilDeviation')
			->with('bac-req-7', 'bes-2', 'Commissie miste de nieuwe feiten');

		$listener->handle($this->event(sourceApp: 'procest', status: 'approved'));
	}//end testRecordsCouncilDeviationWhenDecisionDepartsFromAdvice()

	/**
	 * A besluit that follows the committee advice records no deviation.
	 *
	 * @return void
	 */
	public function testRecordsNoDeviationWhenDecisionFollowsAdvice(): void {
		$bac = $this->createMock(AdvisoryCommitteeService::class);

		$listener = $this->listenerForDecision(
			record: [
				'decisionRef' => 'dec-1',
				'case' => 'case-9',
				'besluitRef' => 'bes-2',
				'advisoryOpinion' => 'bac-req-7',
				'followsAdvice' => true,
			],
			bac: $bac
		);

		$bac->expects($this->never())->method('recordCouncilDeviation');

		$listener->handle($this->event(sourceApp: 'procest', status: 'approved'));
	}//end testRecordsNoDeviationWhenDecisionFollowsAdvice()

	/**
	 * A besluit that was never referred to a committee records no deviation.
	 *
	 * @return void
	 */
	public function testRecordsNoDeviationWhenNoCommitteeWasInvolved(): void {
		$bac = $this->createMock(AdvisoryCommitteeService::class);

		$listener = $this->listenerForDecision(
			record: [
				'decisionRef' => 'dec-1',
				'case' => 'case-9',
				'besluitRef' => 'bes-2',
			],
			bac: $bac
		);

		$bac->expects($this->never())->method('recordCouncilDeviation');

		$listener->handle($this->event(sourceApp: 'procest', status: 'approved'));
	}//end testRecordsNoDeviationWhenNoCommitteeWasInvolved()

	/**
	 * A concluded decision resumes the run whose slot names its ref.
	 *
	 * The signal must carry decidiq's verdict as the `decision` (a payload
	 * without one is a nudge, and the awaiting node suspends again), plus the
	 * ref, so the requesting node can tell this answer from any other.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
	 */
	public function testAConcludedDecisionResumesTheRunThatAskedForIt(): void {
		$signals = [];

		$listener = $this->listenerForRuns(
			runs: [$this->suspendedRun(uuid: 'run-7', decisionRef: 'dec-1')],
			signals: $signals
		);

		$listener->handle($this->event(sourceApp: 'procest', status: 'approved'));

		$this->assertCount(1, $signals);
		$this->assertSame('run-7', $signals[0]['run']);
		$this->assertSame('approved', $signals[0]['payload']['decision']);
		$this->assertSame('dec-1', $signals[0]['payload']['decisionRef']);
	}//end testAConcludedDecisionResumesTheRunThatAskedForIt()

	/**
	 * A run waiting on a DIFFERENT decision is left suspended.
	 *
	 * The match is on the decisionRef, not on the case: this run belongs to the
	 * right case, so matching on the case would wrongly advance it. Leaving it
	 * suspended is the spec'd behaviour, not an omission.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
	 */
	public function testAnUnrelatedDecisionLeavesTheRunSuspended(): void {
		$signals = [];

		$listener = $this->listenerForRuns(
			runs: [$this->suspendedRun(uuid: 'run-7', decisionRef: 'dec-OTHER')],
			signals: $signals
		);

		$listener->handle($this->event(sourceApp: 'procest', status: 'approved'));

		$this->assertSame([], $signals);
	}//end testAnUnrelatedDecisionLeavesTheRunSuspended()

	/**
	 * Of several suspended runs, only the one naming the ref is signalled.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
	 */
	public function testOnlyTheRunNamingTheRefIsResumed(): void {
		$signals = [];

		$listener = $this->listenerForRuns(
			runs: [
				$this->suspendedRun(uuid: 'run-a', decisionRef: 'dec-OTHER'),
				$this->suspendedRun(uuid: 'run-b', decisionRef: 'dec-1'),
			],
			signals: $signals
		);

		$listener->handle($this->event(sourceApp: 'procest', status: 'approved'));

		$this->assertCount(1, $signals);
		$this->assertSame('run-b', $signals[0]['run']);
	}//end testOnlyTheRunNamingTheRefIsResumed()

	/**
	 * Without the flow collaborators the listener still materialises quietly.
	 *
	 * The nullable mapper/runner exist so older construction sites keep
	 * working; absent, no run is resumed, and nothing raises.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
	 */
	public function testWithoutFlowCollaboratorsNothingIsResumedAndNothingRaises(): void {
		$listener = $this->listenerForDecision(
			record: ['decisionRef' => 'dec-1', 'case' => 'case-9', 'besluitRef' => 'bes-2'],
			bac: $this->createMock(AdvisoryCommitteeService::class)
		);

		$listener->handle($this->event(sourceApp: 'procest', status: 'approved'));

		$this->addToAssertionCount(1);
	}//end testWithoutFlowCollaboratorsNothingIsResumedAndNothingRaises()

	/**
	 * A suspended run whose resume slot records the given decisionRef.
	 *
	 * @param string $uuid        The run uuid.
	 * @param string $decisionRef The ref the run's requesting node stored.
	 *
	 * @return FlowRun
	 */
	private function suspendedRun(string $uuid, string $decisionRef): FlowRun {
		$run = new FlowRun();
		$run->setUuid($uuid);
		$run->setContext(
			[
				'resumeState' => [
					'decide-commissie' => ['decisionRef' => $decisionRef],
				],
			]
		);

		return $run;
	}//end suspendedRun()

	/**
	 * Build a listener whose case lookup resolves and whose flow collaborators
	 * see the given suspended runs, recording every signal into $signals.
	 *
	 * @param FlowRun[] $runs The suspended runs the mapper reports for the case.
	 * @param array $signals Sink for delivered signals (by reference).
	 *
	 * @return DecisionConcludedListener
	 */
	private function listenerForRuns(array $runs, array &$signals): DecisionConcludedListener {
		$objectService = $this->createMock(ConcludedObjectServiceStub::class);
		$objectService->method('searchObjectsBySlug')
			->willReturn([['decisionRef' => 'dec-1', 'case' => 'case-9', 'besluitRef' => 'bes-2']]);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);

		$mapper = $this->createMock(FlowRunMapper::class);
		$mapper->method('findSuspendedBySubject')->willReturn($runs);

		$runner = new class($signals) extends FlowRunService {
			/**
			 * @param array $sink Where delivered signals land.
			 */
			public function __construct(private array &$sink) {
			}

			/**
			 * Record the signal instead of delivering it.
			 *
			 * @param FlowRun $run The run being signalled.
			 * @param array $payload The signal payload.
			 *
			 * @return FlowRun|null
			 */
			public function signal(FlowRun $run, array $payload = []): ?FlowRun {
				$this->sink[] = ['run' => $run->getUuid(), 'payload' => $payload];

				return $run;
			}
		};

		return new DecisionConcludedListener(
			$settings,
			$this->createMock(BesluitMaterialisationService::class),
			$this->createMock(AdvisoryCommitteeService::class),
			$this->createMock(LoggerInterface::class),
			$mapper,
			$runner
		);
	}//end listenerForRuns()

	/**
	 * Build a listener whose decisionRef lookup resolves to $record.
	 *
	 * @param array<string,mixed> $record The bezwaarDecision record the search returns.
	 * @param AdvisoryCommitteeService $bac The BAC service mock the listener writes through.
	 *
	 * @return DecisionConcludedListener
	 */
	private function listenerForDecision(array $record, AdvisoryCommitteeService $bac): DecisionConcludedListener {
		$objectService = $this->createMock(ConcludedObjectServiceStub::class);
		$objectService->method('searchObjectsBySlug')->willReturn([$record]);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);

		return new DecisionConcludedListener(
			$settings,
			$this->createMock(BesluitMaterialisationService::class),
			$bac,
			$this->createMock(LoggerInterface::class)
		);
	}//end listenerForDecision()

	/**
	 * Build a DecisionConcludedEvent fixture.
	 *
	 * @param string $sourceApp The source app id.
	 * @param string $status The terminal/non-terminal status.
	 *
	 * @return DecisionConcludedEvent
	 */
	private function event(string $sourceApp, string $status): DecisionConcludedEvent {
		return new DecisionConcludedEvent(
			'dec-1',
			'contract-renewal',
			$status,
			'granted',
			false,
			null,
			[],
			'2026-06-15T10:00:00+00:00',
			$sourceApp,
			'register-slug',
			'supplierContract',
			'sub-1',
			'case-9',
			'corr-1'
		);
	}//end event()
}//end class
