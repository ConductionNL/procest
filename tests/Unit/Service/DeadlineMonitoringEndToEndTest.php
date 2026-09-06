<?php

/**
 * End-to-end integration tests for the AWB termijnbewaking + dwangsom engine.
 *
 * Drives all five required scenarios (REQ-TERM-011-A..E) against a single
 * in-memory ObjectService fake so the full chain (termijn → pause/extend →
 * overschrijding → ingebrekestelling → dwangsom accrual → beschikking →
 * payment → bezwaar) is exercised in one place.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-11-tests-admin-docs/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Listener\TermijnTimerFiredListener;
use OCA\Dossiq\Service\BerichtenboxRoutingService;
use OCA\Dossiq\Service\DeadlineEscalationService;
use OCA\Dossiq\Service\DeadlineExtensionService;
use OCA\Dossiq\Service\DeadlinePauseService;
use OCA\Dossiq\Service\DwangsomBezwaarService;
use OCA\Dossiq\Service\DwangsomCalculationService;
use OCA\Dossiq\Service\DwangsomUitbetalingService;
use OCA\Dossiq\Service\NoticeOfDefaultService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnNotificationService;
use OCA\Dossiq\Service\TermijnService;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Drives all 5 termijnbewaking E2E scenarios against the chain.
 */
class DeadlineMonitoringEndToEndTest extends TestCase {
	private FakeTermijnStore $objects;
	private SettingsService $settings;
	private TermijnService $termService;
	private DeadlinePauseService $pauseService;
	private DeadlineExtensionService $extService;
	private NoticeOfDefaultService $ingService;
	private DwangsomCalculationService $calcService;
	private DwangsomUitbetalingService $outService;
	private DwangsomBezwaarService $bezService;
	private TermijnNotificationService $notifService;
	private TermijnTimerFiredListener $firedListener;

	protected function setUp(): void {
		$this->objects = new FakeTermijnStore();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'termijn_definitie_schema' => 'deadlineDefinition',
					'termijn_instance_schema' => 'deadlineInstance',
					'termijn_gebeurtenis_schema' => 'termijnGebeurtenis',
					'ingebrekestelling_schema' => 'noticeOfDefault',
					'dwangsom_berekening_schema' => 'penaltyPaymentCalculation',
					'dwangsom_uitbetaling_schema' => 'dwangsomUitbetaling',
					default => '',
				};
			},
		);
		$this->settings = $settings;

		$logger = $this->createMock(LoggerInterface::class);
		$this->termService = new TermijnService($settings, $logger);
		$this->pauseService = new DeadlinePauseService($this->termService);
		$this->extService = new DeadlineExtensionService($this->termService);
		$this->ingService = new NoticeOfDefaultService($settings, $this->termService, $logger);
		$this->calcService = new DwangsomCalculationService($settings, $logger);
		$this->outService = new DwangsomUitbetalingService($settings);
		$this->bezService = new DwangsomBezwaarService($settings, $this->termService, $logger);
		$this->notifService = new TermijnNotificationService(
			$this->termService,
			new BerichtenboxRoutingService($logger),
			$logger
		);
		// The retired daily scan's role is now split: the engine sweep
		// fires the armed timers, and this listener does the domain side.
		$this->firedListener = new TermijnTimerFiredListener(
			$this->termService,
			new DeadlineEscalationService($this->termService, $logger),
			$this->calcService,
			$settings,
			$logger
		);

		// Seed AWB-default Wmo definition.
		$this->objects->seed('deadlineDefinition', [
			'id' => 'td-ov',
			'caseType' => 'omgevingsvergunning-regulier',
			'wettelijkeGrondslag' => 'Wabo 3.9 lid 1',
			'standardDurationDays' => 56,
			'countExtensions' => 1,
			'validFrom' => '2026-01-01',
		]);
	}

	/**
	 * Scenario 1: normal case (no pause/extension → voltooi before deadline).
	 *
	 * @return void
	 */
	public function testScenario1NormalCase(): void {
		$instance = $this->termService->createTermijnInstance(
			'Z/2026/S1',
			'omgevingsvergunning-regulier',
			new DateTimeImmutable('2026-06-01T10:00:00+00:00')
		);

		$voltooid = $this->termService->markTermijnCompleted(
			(string)$instance['id'],
			new DateTimeImmutable('2026-07-15')
		);

		self::assertSame('completed', $voltooid['status']);
		self::assertSame('2026-07-15', $voltooid['voltooiDatum']);
	}

	/**
	 * Scenario 2: pause case (incomplete aanvraag → hersteltermijn → resume).
	 *
	 * @return void
	 */
	public function testScenario2PauseCase(): void {
		$this->termService->getTermijnDefinitie('omgevingsvergunning-regulier');
		$instance = $this->termService->createTermijnInstance(
			'Z/2026/S2',
			'omgevingsvergunning-regulier',
			new DateTimeImmutable('2026-06-01T10:00:00+00:00')
		);
		$id = (string)$instance['id'];

		$paused = $this->pauseService->registerPauze($id, 14, 'Aanvulling vereist');
		self::assertSame('paused', $paused['status']);
		self::assertSame('2026-08-10', $paused['endDateCurrent']);

		// Resume 7 days into the pause (7 days unused).
		$pauseStart = new DateTimeImmutable($paused['pauzeStartDatum']);
		$resumed = $this->pauseService->resumeAfterPauze($id, $pauseStart->modify('+7 days'));
		self::assertSame('lopend', $resumed['status']);
		self::assertSame('2026-08-03', $resumed['endDateCurrent']);
	}

	/**
	 * Scenario 3: extension case (first extension → voltooi after).
	 *
	 * @return void
	 */
	public function testScenario3ExtensionCase(): void {
		$this->termService->getTermijnDefinitie('omgevingsvergunning-regulier');
		$instance = $this->termService->createTermijnInstance(
			'Z/2026/S3',
			'omgevingsvergunning-regulier',
			new DateTimeImmutable('2026-06-01T10:00:00+00:00')
		);
		$id = (string)$instance['id'];

		$extended = $this->extService->requestExtension(
			$id,
			'Aanvullend onderzoek vereist',
			'2026-09-30'
		);
		self::assertSame('verlengd', $extended['status']);
		self::assertSame(1, $extended['countExtensions']);

		$voltooid = $this->termService->markTermijnCompleted($id, new DateTimeImmutable('2026-09-20'));
		self::assertSame('completed', $voltooid['status']);
	}

	/**
	 * Scenario 4: overschrijding + dwangsom + beschikking + payment signal.
	 *
	 * @return void
	 */
	public function testScenario4OverschrijdingAndDwangsom(): void {
		// Seed an overdue but still-lopend instance; the ENGINE's breach
		// rung (slaBreached:0), consumed by the fired-listener, is what
		// flips it to exceeded now that the daily scan is retired.
		$instance = $this->objects->seed('deadlineInstance', [
			'case' => 'Z/2026/S4',
			'deadlineDefinition' => 'td-ov',
			'startDate' => '2026-01-01T10:00:00+00:00',
			'endDateCalculated' => '2026-02-26',
			'endDateCurrent' => '2026-02-26',
			'status' => 'lopend',
			'notificatiesVerstuurd' => [],
			'engineTimerId' => 'timer-s4',
		]);
		$instanceId = (string)$instance['id'];

		$timer = new FlowTimer();
		$timer->setUuid('timer-s4');
		$timer->setAppId('dossiq');
		$timer->setMetadata([
			'source' => 'dossiq-termijn',
			'kind' => 'beslistermijn',
			'termijnInstanceId' => $instanceId,
			'caseId' => 'Z/2026/S4',
			'basis' => 'Wabo 3.9 lid 1',
		]);
		$this->firedListener->handle(new FlowTimerFiredEvent(
			timer: $timer,
			kind: FlowTimerFiredEvent::KIND_RUNG,
			transition: 'escalation:slaBreached:0',
			rungKey: 'slaBreached:0',
			recipients: [],
			priority: 'critical',
			message: 'termijn-overschreden'
		));

		$flipped = $this->termService->getTermijnInstance($instanceId);
		self::assertSame('exceeded', $flipped['status']);

		// Register ingebrekestelling (after deadline).
		$row = $this->ingService->registerNoticeOfDefault(
			$instanceId,
			new DateTimeImmutable('2026-03-15'),
			'email',
			'doc:notice'
		);
		self::assertTrue($row['gevalideerd']);
		self::assertArrayHasKey('penaltyPaymentCalculation', $row);
		$calculationId = (string)$row['penaltyPaymentCalculation']['id'];

		// Settle the accrual 5 days past the (receipt + 14d grace) start:
		// the derivation lands where 5 legacy daily ticks landed.
		$this->calcService->accrueThrough($calculationId, new DateTimeImmutable('2026-04-03'));
		$accrued = $this->objects->store['penaltyPaymentCalculation'][$calculationId];
		self::assertSame(5, $accrued['currentDag']);
		self::assertSame(11500, $accrued['cumulativeAmount']);

		// Beschikking arrives — stop, settling through the stop moment.
		$stopped = $this->calcService->stopForBeschikking($calculationId, new DateTimeImmutable('2026-04-03'));
		self::assertSame('gestopt-wegens-decision', $stopped['status']);
		self::assertSame(11500, $stopped['definitiveAmount']);

		// Prepare payment.
		$uitb = $this->outService->prepareBetaling(
			$calculationId,
			'J. Burger',
			'NL91ABNA0417164300',
			new DateTimeImmutable('2026-03-15')
		);
		self::assertSame(11500, $uitb['amount']);
		self::assertSame('voorbereid', $uitb['status']);

		// Callback arrives confirming payment.
		$paid = $this->outService->handleCallback(
			(string)$uitb['reference'],
			'paid',
			new DateTimeImmutable('2026-04-05'),
			'ERP-S4-001'
		);
		self::assertSame('paid', $paid['status']);
	}

	/**
	 * Scenario 5: bezwaar (dwangsom → bezwaar → resolution with amount change).
	 *
	 * @return void
	 */
	public function testScenario5Bezwaar(): void {
		// Stand up a stopped berekening + linked uitbetaling.
		$this->objects->seed('penaltyPaymentCalculation', [
			'id' => 'b-s5',
			'deadlineInstance' => 'ti-s5',
			'status' => 'gestopt-wegens-decision',
			'definitiveAmount' => 50000,
		]);
		$this->objects->seed('dwangsomUitbetaling', [
			'id' => 'u-s5',
			'penaltyPaymentCalculation' => 'b-s5',
			'amount' => 50000,
			'status' => 'voorbereid',
		]);

		$frozen = $this->bezService->registerBezwaar('b-s5', 'AWB 7:1', 'Bedrag te hoog');
		self::assertSame('objection-bevroren', $frozen['status']);
		$outFrozen = $this->objects->store['dwangsomUitbetaling']['u-s5'];
		self::assertSame('on-hold-objection', $outFrozen['status']);

		// Heroverweging halves the amount.
		$resolved = $this->bezService->resolveBezwaar('b-s5', 25000, 'AWB 7:11');
		self::assertSame(25000, $resolved['definitiveAmount']);
		self::assertSame('completed', $resolved['status']);
		$outResumed = $this->objects->store['dwangsomUitbetaling']['u-s5'];
		self::assertSame(25000, $outResumed['amount']);
		self::assertSame('voorbereid', $outResumed['status']);
	}
}
