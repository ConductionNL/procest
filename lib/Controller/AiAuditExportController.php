<?php

/**
 * Dossiq AI Audit Export Controller
 *
 * Exposes a single read-only action endpoint that exports the AI audit
 * trail (`aiAuditEntry` objects) as CSV (or JSON), gated to the same
 * auditor/beheerder groups as the parafering audit export. Supports EU AI
 * Act Article 14 human-oversight accountability and Algoritmeregister
 * evidence gathering.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/ai-oversight-log/tasks.md#2.1
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\Ai\AiAuditService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only action controller for the AI audit trail export.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/ai-oversight-log/tasks.md#2.1
 */
class AiAuditExportController extends Controller {
	/**
	 * Groups that may export the AI audit trail (same gate as the
	 * parafering audit export).
	 */
	private const ALLOWED_GROUPS = ['auditors', 'secretariaat', 'beheerders', 'admin'];

	/**
	 * Hard cap on exported rows — bounds memory use for a very large audit
	 * log; documented rather than paginating the download itself.
	 */
	private const MAX_EXPORT_ROWS = 10000;

	/**
	 * Page size used while internally iterating {@see AiAuditService::listAuditEntries()}
	 * to assemble the (uncapped, up to MAX_EXPORT_ROWS) export set.
	 */
	private const PAGE_SIZE = 200;

	/**
	 * CSV column order — the aiAuditEntry schema fields, plus OpenRegister's
	 * own `id`/`created` metadata.
	 *
	 * @var string[]
	 */
	private const CSV_COLUMNS = [
		'id',
		'created',
		'type',
		'action',
		'caseId',
		'documentId',
		'model',
		'prompt',
		'suggestion',
		'confidence',
		'userAction',
		'actualValue',
		'reason',
		'userId',
		'timestamp',
		'responseTimeMs',
	];

	/**
	 * Constructor.
	 *
	 * @param string $appName Nextcloud app id
	 * @param IRequest $request Incoming request
	 * @param IUserSession $userSession Current user session
	 * @param IGroupManager $groupManager Group manager (for RBAC check)
	 * @param AiAuditService $auditService The AI oversight audit service (audit listing)
	 * @param LoggerInterface $logger PSR-3 logger
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly AiAuditService $auditService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Export the AI audit trail as CSV (default) or JSON.
	 *
	 * @return DataDownloadResponse|JSONResponse
	 *
	 * @spec openspec/specs/ai-oversight-log/spec.md
	 */
	#[NoAdminRequired]
	public function export(): DataDownloadResponse|JSONResponse {
		try {
			$user = $this->userSession->getUser();
			if ($user === null) {
				return new JSONResponse(
					['message' => 'Authentication required'],
					Http::STATUS_UNAUTHORIZED,
				);
			}

			$uid = $user->getUID();
			if ($this->isAllowed(uid: $uid) === false) {
				return new JSONResponse(
					['message' => 'Audit export requires auditor role'],
					Http::STATUS_FORBIDDEN,
				);
			}

			$caseId = $this->request->getParam('caseId');
			$type = $this->request->getParam('type');
			$format = strtolower((string)$this->request->getParam('format', 'csv'));

			$entries = $this->collectEntries(
				filters: array_filter(['caseId' => $caseId, 'type' => $type]),
			);

			if ($format === 'json') {
				return new JSONResponse(
					[
						'entries' => $entries,
						'count' => count($entries),
					]
				);
			}

			return new DataDownloadResponse(
				data: $this->buildCsv(entries: $entries),
				filename: 'ai-audit-export.csv',
				contentType: 'text/csv',
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: AI audit export failed',
				['exception' => $e->getMessage()],
			);

			return new JSONResponse(
				['message' => 'Export failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}//end try
	}//end export()

	/**
	 * Check whether the given user id belongs to an allowed group (or is an
	 * NC admin, defensive default).
	 *
	 * @param string $uid The Nextcloud user id.
	 *
	 * @return bool
	 */
	private function isAllowed(string $uid): bool {
		foreach (self::ALLOWED_GROUPS as $group) {
			if ($this->groupManager->isInGroup($uid, $group) === true) {
				return true;
			}
		}

		return $this->groupManager->isAdmin($uid) === true;
	}//end isAllowed()

	/**
	 * Collect audit entries across pages up to {@see self::MAX_EXPORT_ROWS}.
	 *
	 * No pagination cap is applied on the caller-facing filters — this
	 * iterates {@see AiAuditService::listAuditEntries()} internally page by page
	 * (bounded page size) so a single export call never asks OpenRegister
	 * for an unbounded result set in one query.
	 *
	 * @param array<string, mixed> $filters Filters forwarded to listAuditEntries (caseId/type).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function collectEntries(array $filters): array {
		$entries = [];
		$offset = 0;
		$rowCount = self::PAGE_SIZE;
		$totalCount = 0;

		do {
			$page = $this->auditService->listAuditEntries(
				filters: $filters,
				limit: self::PAGE_SIZE,
				offset: $offset,
			);
			$rows = $page['entries'];
			$rowCount = count($rows);
			$entries = array_merge($entries, $rows);
			$totalCount = count($entries);
			$offset += self::PAGE_SIZE;
		} while ($rowCount === self::PAGE_SIZE && $totalCount < self::MAX_EXPORT_ROWS);

		if ($totalCount > self::MAX_EXPORT_ROWS) {
			$entries = array_slice($entries, 0, self::MAX_EXPORT_ROWS);
		}

		return $entries;
	}//end collectEntries()

	/**
	 * Build CSV content (header + one row per entry) via a memory stream.
	 *
	 * Array-valued fields (suggestion, actualValue) are flattened to a JSON
	 * string per cell; fputcsv handles quoting/escaping.
	 *
	 * @param array<int, array<string, mixed>> $entries The audit entries to serialise.
	 *
	 * @return string The CSV content.
	 */
	private function buildCsv(array $entries): string {
		$handle = fopen('php://temp', 'r+');
		if ($handle === false) {
			return '';
		}

		fputcsv($handle, self::CSV_COLUMNS);

		foreach ($entries as $entry) {
			$row = [];
			foreach (self::CSV_COLUMNS as $column) {
				$value = ($entry[$column] ?? '');
				if (is_array($value) === true) {
					$value = (string)json_encode($value);
				} elseif (is_bool($value) === true) {
					$isTrue = ($value === true);
					$value = '0';
					if ($isTrue === true) {
						$value = '1';
					}
				}

				$row[] = (string)$value;
			}

			fputcsv($handle, $row);
		}

		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);

		return $csv;
	}//end buildCsv()
}//end class
