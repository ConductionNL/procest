<?php

/**
 * Dossiq Bezwaar Hearing Service.
 *
 * Domain service for the bezwaar-hearing (hoorzitting) capability under
 * Awb Art. 7:2 – 7:7. Owns the legitimate domain operations that cannot
 * be expressed by the manifest-driven CRUD path:
 *
 *  - schedule()           — Create a gepland hearingSession with the
 *                           Awb art. 7:4 lid 2 inspection-of-file floor
 *                           (≥ 7 days before scheduledDate) and an
 *                           awb-art-7:2 audit entry.
 *  - waive()              — Record an art. 7:3 waiver (afzien van het
 *                           hoorrecht) with a non-empty reason and an
 *                           awb-art-7:3 audit entry.
 *  - recordAttendance()   — Append-only attendance capture with a
 *                           one-hour grace window after the hearing
 *                           concludes; later corrections require a
 *                           documented correctionReason and write an
 *                           awb-art-7:7 audit entry.
 *  - addMinutes()         — Promote the session to uitgevoerd when
 *                           minutesSummary or minutesDocument is set;
 *                           audioRecording is only accepted when
 *                           recordingConsent = granted (AVG art. 6).
 *
 * Identity is ALWAYS derived from IUserSession. Per the per-app
 * convention every mutation goes through OpenRegister via the manifest
 * renderer; this service composes those calls and writes the
 * append-only `auditTrail` entries tagged with the applicable Awb
 * article so that beroep dossier export can demonstrate compliance.
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
use DateTimeInterface;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\OwningCaseResolver;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Hearing service: scheduling, waiver, attendance and minutes capture.
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */
class HearingService {

	use SearchesObjects;

	/**
	 * Grace window after `scheduledDate` during which the attendance
	 * array remains append-only. Past this window, mutations require a
	 * documented correctionReason.
	 */
	private const ATTENDANCE_GRACE_HOURS = 1;

	/**
	 * Audit-tag catalogue covering the legally relevant events on a
	 * hearingSession (REQ-BH-8). Values are the canonical tags every
	 * downstream consumer (beroep export, accessibility report) reads.
	 * They are declared once on {@see BezwaarAuditTrail} — the writer
	 * that stamps them — and re-exported here for backwards
	 * compatibility with existing consumers of `HearingService::TAG_*`.
	 */
	public const TAG_SCHEDULED = BezwaarAuditTrail::TAG_SCHEDULED;
	public const TAG_INVITATION_SENT = BezwaarAuditTrail::TAG_INVITATION_SENT;
	public const TAG_WAIVER = BezwaarAuditTrail::TAG_WAIVER;
	public const TAG_INSPECTION = BezwaarAuditTrail::TAG_INSPECTION;
	public const TAG_CONFIDENTIAL_WITHELD = BezwaarAuditTrail::TAG_CONFIDENTIAL_WITHELD;
	public const TAG_VERSLAG = BezwaarAuditTrail::TAG_VERSLAG;
	public const TAG_BAC_REFERRAL = BezwaarAuditTrail::TAG_BAC_REFERRAL;
	public const TAG_RECORDING_CONSENT = BezwaarAuditTrail::TAG_RECORDING_CONSENT;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema/register bridge
	 * @param LoggerInterface $logger Logger
	 * @param BezwaarAuditTrail $auditTrail Shared append-only audit writer
	 * @param HearingSchedulePlanner $planner Awb art. 7:4 date arithmetic
	 * @param HearingMinutesRecorder $minutes Awb art. 7:7 verslag assembly + consent gate
	 * @param OwningCaseResolver $owningCase Resolves a session's parent bezwaar case
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly BezwaarAuditTrail $auditTrail,
		private readonly HearingSchedulePlanner $planner,
		private readonly HearingMinutesRecorder $minutes,
		private readonly OwningCaseResolver $owningCase,
	) {
	}//end __construct()

	/**
	 * Schedule a hearing for a bezwaar case (REQ-BH-2 happy path).
	 *
	 * Creates a hearingSession with status = gepland, computes the
	 * art. 7:4 inspection deadline as scheduledDate − 7 days, and
	 * appends an awb-art-7:2 audit entry to the new session.
	 *
	 * @param string $caseId UUID of the parent bezwaar case
	 * @param string $scheduledDate ISO-8601 date-time of the hearing
	 * @param string $chairpersonId UUID of the voorzitter role
	 * @param array<int, array> $invitees Invitee objects (see schema)
	 * @param array<string, mixed> $payload Optional extras (location, videoCallUrl, members, inspectionAvailableFrom, ...)
	 *
	 * @return array<string, mixed> The persisted hearingSession record
	 *
	 * @throws RuntimeException When OpenRegister is unavailable, the
	 *                          inspection-of-file floor is violated, or
	 *                          schemas are unconfigured.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function schedule(
		string $caseId,
		string $scheduledDate,
		string $chairpersonId,
		array $invitees,
		array $payload = [],
	): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(
			key: 'hearing_session_schema'
		);

		if ($register === '' || $schema === '') {
			throw new RuntimeException('Hearing schema is not configured');
		}

		if ($caseId === '' || $chairpersonId === '' || $invitees === []) {
			throw new RuntimeException(
				'case, chairperson and at least one invitee are required'
			);
		}

		$scheduled = $this->planner->parseDateTime(value: $scheduledDate);
		$deadline = $this->planner->computeInspectionDeadline(scheduled: $scheduled);
		$now = new DateTimeImmutable();

		$this->planner->guardInspectionFloor(
			scheduled: $scheduled,
			today: $now,
		);

		$available = $this->planner->resolveAvailableFrom(
			payload: $payload,
			deadline: $deadline,
			now: $now,
		);

		$record = array_merge(
			[
				'location' => null,
				'videoCallUrl' => null,
				'members' => [],
			],
			$payload,
			[
				'case' => $caseId,
				'scheduledDate' => $scheduled->format(
					DateTimeInterface::ATOM
				),
				'chairperson' => $chairpersonId,
				'invitees' => $this->planner->stampInvitees(
					invitees: $invitees,
					when: $now,
				),
				'inspectionAvailableFrom' => $available->format('Y-m-d'),
				'inspectionDeadline' => $deadline->format('Y-m-d'),
				'status' => 'planned',
				'hearingWaived' => false,
				'recordingConsent' => $payload['recordingConsent'] ?? 'not_requested',
			]
		);

		$record['auditTrail'] = $this->auditTrail->append(
			existing: [],
			event: 'hearing-scheduled',
			payload: [
				'case' => $caseId,
				'scheduledDate' => $record['scheduledDate'],
				'inspectionDeadline' => $record['inspectionDeadline'],
			],
			tag: self::TAG_SCHEDULED,
		);

		try {
			return ($this->saveObjectAsArray(objectService: $objectService, register: $register, schema: $schema, object: $record) ?? $record);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq hearing: failed to schedule hearing: ' . $e->getMessage()
			);
			throw new RuntimeException('Could not schedule hearing');
		}
	}//end schedule()

	/**
	 * Record an art. 7:3 waiver: bezwaarmaker afziet van het hoorrecht.
	 *
	 * Creates a hearingSession with status = afzien, hearingWaived = true
	 * and the supplied reason. Writes an awb-art-7:3 audit entry.
	 *
	 * @param string $caseId UUID of the parent bezwaar case
	 * @param string $reason Non-empty waiver reason
	 * @param array<string, mixed> $payload Optional extras
	 *
	 * @return array<string, mixed> The persisted hearingSession record
	 *
	 * @throws RuntimeException When the reason is empty or persistence fails.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function waive(
		string $caseId,
		string $reason,
		array $payload = [],
	): array {
		$reason = trim($reason);
		if ($reason === '') {
			throw new RuntimeException(
				'Waiver reason is required (Awb art. 7:3)'
			);
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(
			key: 'hearing_session_schema'
		);

		if ($register === '' || $schema === '') {
			throw new RuntimeException('Hearing schema is not configured');
		}

		$now = new DateTimeImmutable();

		$record = array_merge(
			$payload,
			[
				'case' => $caseId,
				'scheduledDate' => $now->format(DateTimeInterface::ATOM),
				'chairperson' => $payload['chairperson'] ?? ($payload['chairpersonId'] ?? 'system'),
				'invitees' => $payload['invitees'] ?? [],
				'inspectionAvailableFrom' => $now->format('Y-m-d'),
				'inspectionDeadline' => $now->format('Y-m-d'),
				'status' => 'afgezien',
				'hearingWaived' => true,
				'waiverReason' => $reason,
			]
		);

		$record['auditTrail'] = $this->auditTrail->append(
			existing: [],
			event: 'hearing-waived',
			payload: [
				'case' => $caseId,
				'reason' => $reason,
			],
			tag: self::TAG_WAIVER,
		);

		try {
			return ($this->saveObjectAsArray(objectService: $objectService, register: $register, schema: $schema, object: $record) ?? $record);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq hearing: failed to record waiver: ' . $e->getMessage()
			);
			throw new RuntimeException('Could not record waiver');
		}
	}//end waive()

	/**
	 * Resolve the bezwaar case a hearing session belongs to.
	 *
	 * `recordAttendance()` carries only a session id, so there is nothing in
	 * its signature to authorise against. This resolves the parent case so the
	 * controller can apply the ordinary per-case guard. Returns null — which
	 * the caller treats as DENY — whenever the session cannot be resolved, so
	 * an unknown id is not an existence oracle.
	 *
	 * @param string $sessionId UUID of the hearingSession
	 *
	 * @return string|null The parent case UUID, or null when unresolvable.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function getCaseIdForSession(string $sessionId): ?string {
		return $this->owningCase->resolve(
			objectId: $sessionId,
			schemaKey: 'hearing_session_schema',
			caseField: 'case',
		);
	}//end getCaseIdForSession()

	/**
	 * Record attendance on a hearingSession (REQ-BH-5).
	 *
	 * Within the one-hour grace window after the hearing concludes
	 * attendance entries SHALL be appendable freely; after the window
	 * any correction SHALL carry a non-empty correctionReason that is
	 * logged as an awb-art-7:7 audit entry.
	 *
	 * @param string $sessionId UUID of the hearingSession
	 * @param array<int, array<string, mixed>> $entries Attendance entries: each {invitee, present, arrivalTime?, correctionReason?}
	 *
	 * @return array<string, mixed> The updated hearingSession record
	 *
	 * @throws RuntimeException When the session is not found, persistence fails, or a late correction lacks a reason.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function recordAttendance(
		string $sessionId,
		array $entries,
	): array {
		if ($entries === []) {
			throw new RuntimeException(
				'At least one attendance entry is required'
			);
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(
			key: 'hearing_session_schema'
		);

		$current = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			id: $sessionId
		);
		if ($current === null) {
			throw new RuntimeException('Hearing session not found');
		}

		$now = new DateTimeImmutable();
		$scheduledRaw = (string)($current['scheduledDate'] ?? '');
		$scheduled = $now;
		if ($scheduledRaw !== '') {
			$scheduled = $this->planner->parseDateTime(value: $scheduledRaw);
		}

		$freezeAt = $scheduled->modify(
			'+' . self::ATTENDANCE_GRACE_HOURS . ' hour'
		);
		$isFrozen = $now > $freezeAt;

		$existing = (array)($current['attendance'] ?? []);
		$merged = $existing;
		$audit = (array)($current['auditTrail'] ?? []);

		foreach ($entries as $entry) {
			if ($isFrozen === true) {
				$audit = $this->minutes->appendLateCorrectionAudit(
					audit: $audit,
					entry: $entry,
				);
			}

			$merged[] = $entry;
		}//end foreach

		$update = [
			'attendance' => $merged,
			'attendanceFrozenAt' => $freezeAt->format(DateTimeInterface::ATOM),
			'auditTrail' => $audit,
		];

		try {
			return ($this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				object: $update,
				uuid: (string)$sessionId
			) ?? array_merge($current, $update));
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq hearing: failed to record attendance: ' . $e->getMessage()
			);
			throw new RuntimeException('Could not record attendance');
		}
	}//end recordAttendance()

	/**
	 * Attach minutes (verslag) to a hearingSession and promote it to
	 * uitgevoerd when at least one of minutesSummary or minutesDocument
	 * is provided (REQ-BH-6). When audioRecording is supplied it SHALL
	 * only be accepted if recordingConsent = granted.
	 *
	 * @param string $sessionId UUID of the hearingSession
	 * @param array<string, mixed> $payload Minutes payload {minutesSummary?, minutesDocument?, audioRecording?, recordingConsent?}
	 *
	 * @return array<string, mixed> The updated hearingSession record
	 *
	 * @throws RuntimeException When verslag is missing, audio consent is
	 *                          denied, or persistence fails.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function addMinutes(
		string $sessionId,
		array $payload,
	): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(
			key: 'hearing_session_schema'
		);

		$current = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			id: $sessionId
		);
		if ($current === null) {
			throw new RuntimeException('Hearing session not found');
		}

		$summary = (string)(
			$payload['minutesSummary'] ?? ($current['minutesSummary'] ?? '')
		);
		$document = (string)(
			$payload['minutesDocument'] ?? ($current['minutesDocument'] ?? '')
		);

		if (trim($summary) === '' && trim($document) === '') {
			throw new RuntimeException(
				'Verslag (art. 7:7) ontbreekt — vul minutesSummary of upload minutesDocument'
			);
		}

		$audit = (array)($current['auditTrail'] ?? []);

		// Audio recording handling: gated by explicit consent.
		$audit = $this->minutes->guardRecordingConsent(
			objectService: $objectService,
			sessionId: $sessionId,
			payload: $payload,
			current: $current,
			audit: $audit,
			register: $register,
			schema: $schema,
		);

		$update = $this->minutes->buildMinutesUpdate(
			payload: $payload,
			summary: $summary,
			document: $document,
		);

		$update['auditTrail'] = $this->auditTrail->append(
			existing: $audit,
			event: 'verslag-recorded',
			payload: [
				'hasSummary' => trim($summary) !== '',
				'hasDocument' => trim($document) !== '',
			],
			tag: self::TAG_VERSLAG,
		);

		try {
			return ($this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				object: $update,
				uuid: (string)$sessionId
			) ?? array_merge($current, $update));
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq hearing: failed to add minutes: ' . $e->getMessage()
			);
			throw new RuntimeException('Could not add minutes');
		}
	}//end addMinutes()

	/**
	 * Listener entry-point: seed a default hearing session for a bezwaar
	 * that has just transitioned to "Hearing planned" (REQ-BH-2
	 * scheduling). The seed is intentionally minimal — invitees are
	 * empty, scheduledDate is fourteen days out — and only fires when
	 * no hearing session already exists for the case.
	 *
	 * @param string $objectionId The bezwaar (lifecycle) UUID
	 *
	 * @return array<string, mixed>|null Created hearing session, or null when one already exists / infra unavailable.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function seedDefaultHearing(string $objectionId): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(
			key: 'hearing_session_schema'
		);

		if ($register === '' || $schema === '') {
			return null;
		}

		$caseId = $this->resolveCaseIdFromObjection(objectionId: $objectionId);
		if ($caseId === '') {
			return null;
		}

		try {
			$existing = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['case' => $caseId]
			);
			if ($existing !== []) {
				return null;
			}
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Dossiq hearing: lookup for existing sessions failed: '
				. $e->getMessage()
			);
			return null;
		}

		$scheduled = (new DateTimeImmutable())
			->modify('+14 days')
			->setTime(10, 0, 0);

		try {
			return $this->schedule(
				caseId: $caseId,
				scheduledDate: $scheduled->format(DateTimeInterface::ATOM),
				chairpersonId: 'system',
				invitees: [
					[
						'role' => 'bezwaarmaker',
						'channel' => 'email',
						'accessibilityNeeds' => [],
					],
				],
			);
		} catch (\Throwable $e) {
			$this->logger->info(
				'Dossiq hearing: seedDefaultHearing skipped for bezwaar '
				. $objectionId . ': ' . $e->getMessage()
			);
			return null;
		}
	}//end seedDefaultHearing()

	/**
	 * Resolve the underlying dossiq case UUID from a bezwaar
	 * (lifecycle) UUID. Falls back to the input when bezwaar_schema is
	 * not configured so the listener can still seed against case-keyed
	 * inputs.
	 *
	 * @param string $objectionId Bezwaar UUID
	 *
	 * @return string The resolved case UUID, or empty when unresolvable.
	 */
	private function resolveCaseIdFromObjection(string $objectionId): string {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return $objectionId;
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$objectionSchema = $this->settingsService->getConfigValue(
			key: 'bezwaar_schema'
		);

		if ($register === '' || $objectionSchema === '') {
			return $objectionId;
		}

		try {
			$objection = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $objectionSchema,
				id: $objectionId
			);
			if ($objection !== null) {
				$candidate = (string)($objection['case'] ?? '');
				if ($candidate !== '') {
					return $candidate;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Dossiq hearing: bezwaar lookup failed: ' . $e->getMessage()
			);
		}

		return $objectionId;
	}//end resolveCaseIdFromBezwaar()
}//end class
