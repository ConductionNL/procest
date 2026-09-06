<?php

/**
 * Dossiq Email Archival Service
 *
 * Lightweight archival surface that records linked emails as `caseDocument`
 * entries (ZGW informatieobject) and tracks PDF conversion status. The
 * heavy PDF conversion itself is delegated to Docudesk via an adapter; this
 * service owns the registry side of the workflow.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-email-integration/tasks.md#T05
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use InvalidArgumentException;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Archival surface for emails linked to a case.
 *
 * @spec openspec/changes/case-email-integration/tasks.md#T05
 */
class EmailArchivalService {

	use SearchesObjects;

	/**
	 * Maximum bytes processed synchronously; anything larger flips to async.
	 */
	public const SYNC_SIZE_THRESHOLD_BYTES = (5 * 1024 * 1024);

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Shared OR/settings resolver.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record the archival of a single linked email.
	 *
	 * @param string $caseId Owning case UUID.
	 * @param array<string, mixed> $metadata Email metadata (mailMessageId,
	 *                                       from, to, subject, sentAt,
	 *                                       sizeBytes).
	 *
	 * @return array{
	 *     archivalId: string,
	 *     mode: string,
	 *     pdfStatus: string,
	 * }
	 *
	 * @spec openspec/changes/case-email-integration/tasks.md#T05
	 */
	public function archiveLinkedEmail(string $caseId, array $metadata): array {
		if ($caseId === '') {
			throw new InvalidArgumentException('caseId is required');
		}

		$size = (int)($metadata['sizeBytes'] ?? 0);
		$mode = 'sync';
		if ($size > self::SYNC_SIZE_THRESHOLD_BYTES) {
			$mode = 'async';
		}

		$archivalId = uniqid(prefix: 'archival-', more_entropy: true);

		$documentRecord = [
			'archivalId' => $archivalId,
			'case' => $caseId,
			'source' => 'email',
			'mailMessageId' => (string)($metadata['mailMessageId'] ?? ''),
			'subject' => (string)($metadata['subject'] ?? ''),
			'from' => (string)($metadata['from'] ?? ''),
			'to' => (string)($metadata['to'] ?? ''),
			'sentAt' => (string)($metadata['sentAt'] ?? ''),
			'sizeBytes' => $size,
			'pdfStatus' => 'pending',
			'pdfAttempts' => 0,
		];

		$this->persistDocument(payload: $documentRecord);
		$this->appendCaseAudit(
			caseId: $caseId,
			eventType: 'email_linked',
			payload: [
				'mailMessageId' => $documentRecord['mailMessageId'],
				'subject' => $documentRecord['subject'],
				'mode' => $mode,
			]
		);

		return [
			'archivalId' => $archivalId,
			'mode' => $mode,
			'pdfStatus' => 'pending',
		];
	}//end archiveLinkedEmail()

	/*
	 * NO markComplete() HERE — YET.
	 *
	 * It set `pdfStatus: completed` + `pdfFileRef` through `updateArchival()`.
	 * Nothing called it: `EmailPdfRetryJob` only ever calls `markFailed()`,
	 * because the Docudesk PDF adapter it would branch on is not wired. A
	 * success writer for a success that cannot happen is dead code, so it is
	 * removed rather than left waiting — the retry job's own comment carries
	 * the instruction to add it back alongside the adapter, which is the
	 * moment it becomes reachable. `updateArchival()` is unchanged and
	 * `markFailed()` still uses it.
	 */

	/**
	 * Mark an archival attempt as failed and increment retry counter.
	 *
	 * @param string $archivalId Archival identifier.
	 * @param string $errorMessage Error context for the operator.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/case-email-integration/tasks.md#T05
	 */
	public function markFailed(string $archivalId, string $errorMessage): bool {
		$existing = $this->loadArchival(archivalId: $archivalId);
		$attempts = (int)($existing['pdfAttempts'] ?? 0);

		return $this->updateArchival(
			archivalId: $archivalId,
			fields: [
				'pdfStatus' => 'failed',
				'pdfLastError' => $errorMessage,
				'pdfAttempts' => ($attempts + 1),
				'pdfFailedAt' => date(DATE_ATOM),
			]
		);
	}//end markFailed()

	/**
	 * Find all archival records still in `failed` state.
	 *
	 * Used by `EmailPdfRetryJob` to retry archival; limit cap prevents the
	 * job from re-attempting an unbounded number of items in one pass.
	 *
	 * @param int $limit Hard upper bound on returned rows.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/case-email-integration/tasks.md#T09
	 */
	public function listFailedArchivals(int $limit = 50): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_document_schema');
		if (empty($register) === true || empty($schema) === true) {
			return [];
		}

		$rows = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: [
				'source' => 'email',
				'pdfStatus' => 'failed',
				'_limit' => $limit,
			],
		);

		// Cap further by retry-count so we never thrash on a permanently failed row.
		return array_values(
			array_filter(
				$rows,
				static fn (array $row): bool => ((int)($row['pdfAttempts'] ?? 0) < 3)
			)
		);
	}//end listFailedArchivals()

	/**
	 * Persist the archival object.
	 *
	 * @param array<string, mixed> $payload Document payload.
	 *
	 * @return void
	 */
	private function persistDocument(array $payload): void {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_document_schema');
		if (empty($register) === true || empty($schema) === true) {
			$this->logger->warning(
				'case_document_schema unconfigured — skipping archival persistence',
				['archivalId' => $payload['archivalId'] ?? '']
			);
			return;
		}

		try {
			$objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $schema,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Failed to persist email archival document',
				['archivalId' => $payload['archivalId'] ?? '', 'error' => $e->getMessage()]
			);
		}
	}//end persistDocument()

	/**
	 * Load an archival record by archivalId.
	 *
	 * @param string $archivalId Archival identifier.
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadArchival(string $archivalId): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_document_schema');
		if (empty($register) === true || empty($schema) === true) {
			return null;
		}

		$rows = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['archivalId' => $archivalId, '_limit' => 1],
		);

		return $rows[0] ?? null;
	}//end loadArchival()

	/**
	 * Update fields on an existing archival record.
	 *
	 * @param string $archivalId Archival identifier.
	 * @param array<string, mixed> $fields Fields to merge.
	 *
	 * @return bool
	 */
	private function updateArchival(string $archivalId, array $fields): bool {
		$existing = $this->loadArchival(archivalId: $archivalId);
		if ($existing === null) {
			return false;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return false;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_document_schema');
		if (empty($register) === true || empty($schema) === true) {
			return false;
		}

		$payload = array_merge($existing, $fields);
		try {
			$objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $schema,
			);
			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'Failed to update archival record',
				['archivalId' => $archivalId, 'error' => $e->getMessage()]
			);
			return false;
		}
	}//end updateArchival()

	/**
	 * Append an audit event to the case audit trail (OR-managed).
	 *
	 * @param string $caseId Case UUID.
	 * @param string $eventType Audit event name.
	 * @param array<string, mixed> $payload Event metadata.
	 *
	 * @return void
	 */
	private function appendCaseAudit(string $caseId, string $eventType, array $payload): void {
		// OR audit trails are append-only — best-effort write through the
		// ObjectService audit hook. Failures here are non-fatal.
		try {
			$objectService = $this->settingsService->getObjectService();
			if ($objectService === null) {
				return;
			}

			if (method_exists($objectService, 'logEvent') === true) {
				$objectService->logEvent($caseId, $eventType, $payload);
				return;
			}

			$this->logger->info(
				'Audit hook unavailable on ObjectService — falling back to logger',
				['caseId' => $caseId, 'eventType' => $eventType, 'payload' => $payload]
			);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Audit append failed (non-fatal)',
				['caseId' => $caseId, 'eventType' => $eventType, 'error' => $e->getMessage()]
			);
		}//end try
	}//end appendCaseAudit()
}//end class
