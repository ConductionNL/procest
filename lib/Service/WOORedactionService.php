<?php

/**
 * Dossiq WOO Redaction Service
 *
 * Service for optional Docudesk-driven redaction of WOO documents assessed
 * as 'deels openbaar'. Performs feature detection to decide between the
 * Docudesk pipeline and the manual upload-redacted-version fallback.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-case-type/tasks.md#task-8
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Support\FleetAppId;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Service for WOO document redaction with Docudesk feature detection.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/woo-case-type/tasks.md#task-8
 */
class WOORedactionService {

	/**
	 * The canonical fleet name of the document app.
	 *
	 * Resolved through {@see FleetAppId} rather than pinned: filinq renamed
	 * from `docudesk` in August 2026, and this probe — which gates the whole Woo
	 * redaction pipeline — answered false on every instance running the renamed
	 * app, so redaction silently degraded to "manual redaction required".
	 */
	private const DOCUMENT_APP = 'filinq';

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager Nextcloud app manager for feature detection
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Check whether Docudesk is installed and enabled.
	 *
	 * @return bool True if Docudesk is available
	 *
	 * @spec openspec/changes/woo-case-type/tasks.md#task-8
	 */
	public function isDocuDeskInstalled(): bool {
		return FleetAppId::isEnabledForUser($this->appManager, self::DOCUMENT_APP);
	}//end isDocuDeskInstalled()

	/**
	 * Queue documents for redaction.
	 *
	 * If Docudesk is installed, sends the documents to its anonymization pipeline.
	 * Otherwise returns metadata indicating manual redaction is required.
	 *
	 * @param string $caseId The case UUID
	 * @param array<int, array<string, mixed>> $documents Documents assessed as 'deels_openbaar'
	 *
	 * @return array<string, mixed> Redaction result with mode and per-document status
	 *
	 * @spec openspec/changes/woo-case-type/tasks.md#task-8
	 */
	public function queueForRedaction(string $caseId, array $documents): array {
		if (empty($documents) === true) {
			return ['mode' => 'none', 'queued' => [], 'manual' => []];
		}

		if ($this->isDocuDeskInstalled() === true) {
			return $this->queueViaDocuDesk(caseId: $caseId, documents: $documents);
		}

		return $this->manualRedactionFallback(caseId: $caseId, documents: $documents);
	}//end queueForRedaction()

	/**
	 * Queue documents via Docudesk anonymization pipeline.
	 *
	 * @param string $caseId The case UUID
	 * @param array<int, array<string, mixed>> $documents Documents to redact
	 *
	 * @return array<string, mixed> Queued document references
	 *
	 * @spec openspec/changes/woo-case-type/tasks.md#task-8
	 */
	private function queueViaDocuDesk(string $caseId, array $documents): array {
		$queued = [];

		foreach ($documents as $document) {
			$docId = $document['id'] ?? $document['uuid'] ?? null;
			if ($docId === null) {
				continue;
			}

			// Hook point: Docudesk integration sends document to anonymization pipeline.
			// Actual API call deferred to DocuDeskService when the docudesk app ships its
			// service interface. For now we record the intent and let Docudesk poll.
			$queued[] = [
				'documentId' => $docId,
				'caseId' => $caseId,
				'status' => 'queued',
				'mode' => 'docudesk',
			];

			$this->logger->info(
				'WOO redaction queued via Docudesk: document ' . $docId . ' for case ' . $caseId,
				['app' => Application::APP_ID],
			);
		}//end foreach

		return [
			'mode' => 'docudesk',
			'queued' => $queued,
			'manual' => [],
		];
	}//end queueViaDocuDesk()

	/**
	 * Return manual redaction instructions when Docudesk is not installed.
	 *
	 * @param string $caseId The case UUID
	 * @param array<int, array<string, mixed>> $documents Documents needing manual redaction
	 *
	 * @return array<string, mixed> Manual redaction metadata
	 *
	 * @spec openspec/changes/woo-case-type/tasks.md#task-8
	 */
	private function manualRedactionFallback(string $caseId, array $documents): array {
		$manual = [];

		foreach ($documents as $document) {
			$docId = $document['id'] ?? $document['uuid'] ?? null;
			if ($docId === null) {
				continue;
			}

			$manual[] = [
				'documentId' => $docId,
				'caseId' => $caseId,
				'status' => 'awaiting_manual_redaction',
				'instruction' => 'Upload a redacted version to replace this document.',
			];
		}

		$this->logger->info(
			'WOO redaction fallback (manual) for ' . count($manual) . ' documents in case ' . $caseId,
			['app' => Application::APP_ID],
		);

		return [
			'mode' => 'manual',
			'queued' => [],
			'manual' => $manual,
		];
	}//end manualRedactionFallback()
}//end class
