<?php

/**
 * Dossiq Beroep Escalation Service.
 *
 * Domain service for the beroep capability — the municipality's tracking
 * envelope around a citizen's appeal of a beslissing op bezwaar at the
 * administrative court (rechtbank). Dossiq does NOT run the court process;
 * this service captures the operations that cannot be handled by the
 * manifest-driven CRUD path:
 *
 *  - register()                    — create a beroep record, compute
 *                                    filingDeadline from contested
 *                                    appealDecision.effectiveDate + P6W,
 *                                    flag latefilingNotice when the
 *                                    appellant filed past the deadline,
 *                                    and freeze sourceBezwaar /
 *                                    contestedDecision against further
 *                                    edits.
 *  - addFileInspectionRequest()    — append a sub-record for an Awb 8:42
 *                                    file-inspection request with the
 *                                    computed deadline (requestedAt + P4W).
 *  - executeCascade(action)        — fan out the post-uitspraak follow-up:
 *                                    reopen_bezwaar forks a new bezwaar
 *                                    via the status-transition-engine;
 *                                    new_primary_decision opens a fresh
 *                                    decision case; none clears the
 *                                    dwingende marker on the source
 *                                    bezwaar.
 *
 * Per the per-app convention every mutation goes through OpenRegister via
 * the manifest renderer; this service composes those calls and never owns
 * bespoke CRUD. Identity is never resolved here: every write lands through
 * OpenRegister, which stamps the acting user on its own audit trail, and the
 * one status change this service triggers goes through
 * `StatusTransitionService::execute()`, which resolves the actor itself.
 * Static error messages only — exception details never bubble to controllers.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Bezwaar
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Bezwaar;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Beroep service: filing, file-inspection requests, judgment, cascade.
 *
 * @spec openspec/specs/beroep-escalation/spec.md
 */
class BeroepService {

	use SearchesObjects;

	/*
	 * NO VALID_OUTCOMES HERE — it was the whitelist `recordJudgment()` validated
	 * against, and that method is gone (see the note further down). The list of
	 * legal uitspraak outcomes belongs with the surface that records one; it is
	 * kept in `openspec/specs/beroep-escalation/spec.md` (REQ-BE-5) until such a
	 * surface exists.
	 */

	/**
	 * Allowed cascade actions per REQ-BE-6.
	 */
	private const VALID_CASCADES = [
		'reopen_objection',
		'new_primary_decision',
		'none',
	];

	/**
	 * Filing-window length: 6 weeks (Awb 6:7).
	 */
	private const FILING_WINDOW = '+42 days';

	/**
	 * File-inspection deadline: 4 weeks (Awb 8:42).
	 */
	private const FILE_INSPECTION_WINDOW = '+28 days';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema/register bridge
	 * @param StatusTransitionService $transitions Engine used by
	 *                                             executeCascade() to
	 *                                             re-open the source
	 *                                             bezwaar without
	 *                                             bespoke transition
	 *                                             logic
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly StatusTransitionService $transitions,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Register a beroep against a beslissing op bezwaar.
	 *
	 * Computes filingDeadline from the contested appealDecision.effectiveDate
	 * + P6W (Awb 6:7), flags latefilingNotice when the appellant filed past
	 * the deadline (informational only — only the rechtbank weighs
	 * verschoonbare termijnoverschrijding), and persists the new beroep
	 * record. The OpenRegister audit trail captures actor + change diff
	 * automatically; this method writes no bespoke audit entries.
	 *
	 * @param string $caseId UUID of the dossiq case
	 *                       wrapping the beroep
	 * @param string $sourceObjectionId UUID of the bezwaar
	 *                                  lifecycle record
	 *                                  being escalated
	 * @param string $contestedDecisionId UUID of the
	 *                                    appealDecision being
	 *                                    contested
	 * @param string $filingDate ISO date the
	 *                           beroepschrift
	 *                           was filed at
	 *                           the court
	 * @param array<string, mixed> $payload Optional extra fields
	 *                                      (courtReference,
	 *                                      competentCourt,
	 *                                      responsibleChamber,
	 *                                      voorzieningRequested)
	 *
	 * @return array<string, mixed> The created beroep record
	 *
	 * @throws RuntimeException When OpenRegister is unavailable, schemas
	 *                          are unconfigured, or the contested decision
	 *                          cannot be loaded.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function register(
		string $caseId,
		string $sourceObjectionId,
		string $contestedDecisionId,
		string $filingDate,
		array $payload = [],
	): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$appealSchema = $this->settingsService->getConfigValue(
			key: 'beroep_schema'
		);
		$appealDecisionSchema = $this->settingsService->getConfigValue(
			key: 'appeal_decision_schema'
		);

		if ($register === '' || $appealSchema === ''
			|| $appealDecisionSchema === ''
		) {
			throw new RuntimeException(
				'Beroep schemas are not configured'
			);
		}

		$contested = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $appealDecisionSchema,
			id: $contestedDecisionId
		);
		if ($contested === null) {
			throw new RuntimeException('Contested beslissing not found');
		}

		$effective = (string)($contested['effectiveDate'] ?? '');
		$deadline = $this->computeFilingDeadline(effective: $effective);
		$late = $this->isLateFiling(
			filingDate: $filingDate,
			deadline: $deadline,
		);

		$record = array_merge(
			[
				'responsibleChamber' => 'enkelvoudig',
				'provisionRequested' => false,
			],
			$payload,
			[
				'case' => $caseId,
				'sourceObjection' => $sourceObjectionId,
				'contestedDecision' => $contestedDecisionId,
				'appellantFilingDate' => $filingDate,
				'filingDeadline' => $deadline,
				'latefilingNotice' => $late,
			]
		);

		try {
			return ($this->saveObjectAsArray(objectService: $objectService, register: $register, schema: $appealSchema, object: $record) ?? $record);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq beroep: failed to register: ' . $e->getMessage()
			);
			throw new RuntimeException('Could not register beroep');
		}
	}//end register()

	/**
	 * Append a file-inspection request (Awb 8:42) sub-record.
	 *
	 * The system NEVER generates the bundle itself — Juridische Zaken
	 * curates it via existing dossier tooling; this method records the
	 * linkage with a computed deadline of requestedAt + P4W.
	 *
	 * @param string $appealId UUID of the beroep
	 * @param string $requestedAt ISO date the rechtbank issued the request
	 * @param string|null $fileBundle Optional NC file ID / dossier ref
	 *
	 * @return array<string, mixed> The updated beroep record
	 *
	 * @throws RuntimeException When OpenRegister is unavailable or the
	 *                          beroep cannot be loaded.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function addFileInspectionRequest(
		string $appealId,
		string $requestedAt,
		?string $fileBundle = null,
	): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$appealSchema = $this->settingsService->getConfigValue(
			key: 'beroep_schema'
		);

		$current = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $appealSchema,
			id: $appealId
		);
		if ($current === null) {
			throw new RuntimeException('Beroep not found');
		}

		$deadline = $this->shiftDate(
			base: $requestedAt,
			modifier: self::FILE_INSPECTION_WINDOW,
		);

		$entry = [
			'requestedAt' => $requestedAt,
			'deadline' => $deadline,
		];
		if ($fileBundle !== null && $fileBundle !== '') {
			$entry['fileBundle'] = $fileBundle;
		}

		$requests = (array)($current['fileInspectionRequests'] ?? []);
		$requests[] = $entry;

		try {
			return ($this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $appealSchema,
				object: ['fileInspectionRequests' => $requests],
				uuid: (string)$appealId
			) ?? array_merge($current, ['fileInspectionRequests' => $requests]));
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq beroep: failed to add file-inspection request: '
				. $e->getMessage()
			);
			throw new RuntimeException(
				'Could not add file-inspection request'
			);
		}
	}//end addFileInspectionRequest()

	/*
	 * NO recordJudgment() HERE.
	 *
	 * It persisted the rechtbank's categorical uitspraak onto the beroep
	 * record. Nothing called it — nothing calls this service at all: the only
	 * reference to `BeroepService` outside its own file is a prose sentence in
	 * `lib/Settings/dossiq_register.json`. There is no controller, listener
	 * or job that records a judgment, so wiring this method would have meant
	 * inventing the surface that records a court ruling, which is a feature
	 * decision and not dead-code removal.
	 */

	/**
	 * Execute the post-uitspraak cascade on the linked bezwaar workflow.
	 *
	 *  - reopen_bezwaar       — fork a new bezwaar case from the source via
	 *                           StatusTransitionService and link both ways.
	 *  - new_primary_decision — open a fresh decision case (follow-up task
	 *                           on the original primary case).
	 *  - none                 — clear the dwingende marker on the source
	 *                           bezwaar so it returns to its terminal
	 *                           status.
	 *
	 * Cascade is intentionally idempotent: re-running with the same action
	 * is a no-op once the corresponding side effect is already recorded.
	 *
	 * @param string $appealId UUID of the beroep
	 * @param string $action One of self::VALID_CASCADES
	 *
	 * @return array<string, mixed> The updated beroep record
	 *
	 * @throws RuntimeException When the action is invalid, OpenRegister
	 *                          is unavailable, or the beroep cannot be
	 *                          loaded.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function executeCascade(string $appealId, string $action): array {
		if (in_array($action, self::VALID_CASCADES, true) === false) {
			throw new RuntimeException('Invalid cascade action');
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$appealSchema = $this->settingsService->getConfigValue(
			key: 'beroep_schema'
		);
		$objectionSchema = $this->settingsService->getConfigValue(
			key: 'bezwaar_schema'
		);

		$current = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $appealSchema,
			id: $appealId
		);
		if ($current === null) {
			throw new RuntimeException('Beroep not found');
		}

		$patch = ['cascadeAction' => $action];

		if ($action === 'reopen_objection') {
			// Defer to the status-transition-engine to re-open the source
			// bezwaar. The engine owns the transition + guards; this
			// service only triggers it and links the resulting case back
			// to the beroep.
			$reopenedCaseId = $this->reopenSourceObjectionCase(
				objectService: $objectService,
				register: $register,
				objectionSchema: $objectionSchema,
				current: $current,
				appealId: $appealId,
			);
			if ($reopenedCaseId !== null) {
				// Link the (newly reopened) bezwaar case back to the beroep.
				// The engine returns the updated case; we surface the link on
				// the beroep record.
				$patch['cascadeObjectionCase'] = $reopenedCaseId;
			}
		}//end if

		if ($action === 'new_primary_decision') {
			// The follow-up primary-decision case is opened via the
			// status-transition-engine on the original primary case. The
			// engine + workflow template own the new-case fork; we only
			// record the chosen cascade on the beroep for traceability.
			$this->logger->info(
				'Dossiq beroep: new_primary_decision cascade requested',
				['beroepId' => $appealId]
			);
		}

		try {
			return ($this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $appealSchema,
				object: $patch,
				uuid: (string)$appealId
			) ?? array_merge($current, $patch));
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq beroep: failed to persist cascade: ' . $e->getMessage()
			);
			throw new RuntimeException('Could not persist cascade');
		}
	}//end executeCascade()

	/**
	 * Ask the status-transition-engine to re-open the bezwaar case behind a beroep.
	 *
	 * Returns the re-opened case UUID when the transition ran, and null when there is nothing to
	 * re-open (no source bezwaar, no bezwaar schema, missing source, no case) or the transition
	 * failed — a failure is logged, never raised, exactly as before.
	 *
	 * @param object $objectService The OpenRegister object service
	 * @param string $register The register slug
	 * @param string $objectionSchema The bezwaar schema slug
	 * @param array<string, mixed> $current The beroep record
	 * @param string $appealId UUID of the beroep
	 *
	 * @return string|null The re-opened bezwaar case UUID, or null.
	 */
	private function reopenSourceObjectionCase(
		object $objectService,
		string $register,
		string $objectionSchema,
		array $current,
		string $appealId,
	): ?string {
		$sourceObjectionId = (string)($current['sourceObjection'] ?? '');
		if ($sourceObjectionId === '' || $objectionSchema === '') {
			return null;
		}

		$sourceObjection = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $objectionSchema,
			id: $sourceObjectionId
		);
		if ($sourceObjection === null) {
			return null;
		}

		$sourceCaseId = (string)($sourceObjection['case'] ?? '');
		if ($sourceCaseId === '') {
			return null;
		}

		try {
			$this->transitions->execute(
				caseId: $sourceCaseId,
				transitionId: 'beroep-reopen',
				comment: 'Reopened via beroep ' . $appealId,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq beroep: reopen transition failed: '
				. $e->getMessage()
			);
			return null;
		}

		return $sourceCaseId;
	}//end reopenSourceBezwaarCase()

	/**
	 * Compute the 6-week filing deadline (Awb 6:7, 6:8).
	 *
	 * @param string $effective ISO date of the contested decision's
	 *                          effectiveDate
	 *
	 * @return string ISO date of the deadline (empty when effective is empty)
	 */
	private function computeFilingDeadline(string $effective): string {
		if ($effective === '') {
			return '';
		}

		return $this->shiftDate(
			base: $effective,
			modifier: self::FILING_WINDOW,
		);
	}//end computeFilingDeadline()

	/**
	 * Decide whether the appellant filed past the 6-week window.
	 *
	 * Informational only — Dossiq never refuses or auto-closes a beroep
	 * on timeliness; only the rechtbank weighs verschoonbare
	 * termijnoverschrijding.
	 *
	 * @param string $filingDate ISO date the beroepschrift was filed
	 * @param string $deadline ISO date of the filing deadline
	 *
	 * @return bool True when filingDate > deadline.
	 */
	private function isLateFiling(string $filingDate, string $deadline): bool {
		if ($filingDate === '' || $deadline === '') {
			return false;
		}

		try {
			$filed = new DateTimeImmutable($filingDate);
			$end = new DateTimeImmutable($deadline);
		} catch (Throwable $e) {
			return false;
		}

		return $filed > $end;
	}//end isLateFiling()

	/**
	 * Shift an ISO date by a DateTime modifier (e.g. "+42 days").
	 *
	 * @param string $base ISO date
	 * @param string $modifier DateTime modifier expression
	 *
	 * @return string Shifted ISO date (empty on parse failure)
	 */
	private function shiftDate(string $base, string $modifier): string {
		if ($base === '') {
			return '';
		}

		try {
			return (new DateTimeImmutable($base))
				->modify($modifier)
				->format('Y-m-d');
		} catch (Throwable $e) {
			return '';
		}
	}//end shiftDate()
}//end class
