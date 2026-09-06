<?php

/**
 * Dossiq Inbound Email Job
 *
 * Polls the configured SHARED/functional IMAP mailbox, auto-links unread
 * messages to cases via the subject-tag pattern `[ZAAK-YYYY-NNNNNN]`, and
 * triggers archival through {@see EmailArchivalService}. Per the ADR-002
 * exception (case-email-integration), this is the ONLY dossiq-side mail
 * dispatch — manual per-user mail is owned by NC Mail.
 *
 * @category BackgroundJob
 * @package  OCA\Dossiq\BackgroundJob
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
 *
 * @spec openspec/changes/case-email-integration/tasks.md#T08
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\EmailArchivalService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Support\SuppressesWarnings;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Pulls inbound email from the shared mailbox and auto-links to cases.
 *
 * @spec openspec/changes/case-email-integration/tasks.md#T08
 */
class InboundEmailJob extends TimedJob {

	use SuppressesWarnings;

	/**
	 * Subject-tag pattern carrying the case identifier.
	 */
	public const CASE_NUMBER_PATTERN = '/\[([A-Z]+-\d{4}-\d{4,6})\]/';

	/**
	 * Default poll interval when the appconfig key is unset.
	 */
	private const DEFAULT_INTERVAL_SECONDS = 300;

	/**
	 * Default per-run batch size.
	 */
	private const DEFAULT_BATCH_SIZE = 50;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param IAppConfig $appConfig App config.
	 * @param IAppManager $appManager App manager.
	 * @param SettingsService $settingsService Settings service.
	 * @param EmailArchivalService $archivalService Archival service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly IAppConfig $appConfig,
		private readonly IAppManager $appManager,
		private readonly SettingsService $settingsService,
		private readonly EmailArchivalService $archivalService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$interval = (int)$this->appConfig->getValueString(
			Application::APP_ID,
			'email_poll_interval',
			(string)self::DEFAULT_INTERVAL_SECONDS,
		);
		if ($interval < 60) {
			$interval = self::DEFAULT_INTERVAL_SECONDS;
		}

		$this->setInterval(seconds: $interval);
	}//end __construct()

	/**
	 * Run a single poll batch.
	 *
	 * @param mixed $argument Job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/case-email-integration/tasks.md#T08
	 */
	protected function run($argument): void {
		try {
			if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
				return;
			}

			$host = $this->appConfig->getValueString(Application::APP_ID, 'email_imap_host', '');
			if ($host === '') {
				return;
			}

			$messages = $this->fetchUnreadBatch();
			if ($messages === []) {
				return;
			}

			$linkedCount = 0;
			foreach ($messages as $message) {
				if ($this->isAlreadyLinked(messageId: (string)($message['mailMessageId'] ?? '')) === true) {
					continue;
				}

				$caseId = $this->matchCaseFromSubject(subject: (string)($message['subject'] ?? ''));
				if ($caseId === null) {
					// Unmatched — leave in the mailbox for manual linking via the leaf.
					continue;
				}

				$this->archivalService->archiveLinkedEmail(caseId: $caseId, metadata: $message);
				$this->markProcessed(messageId: (string)($message['mailMessageId'] ?? ''));
				$linkedCount++;
			}//end foreach

			if ($linkedCount > 0) {
				$this->logger->info(
					'InboundEmailJob: linked {count} messages',
					['count' => $linkedCount, 'app' => Application::APP_ID]
				);
			}
		} catch (\Throwable $e) {
			$this->logger->error(
				'InboundEmailJob failed',
				['error' => $e->getMessage(), 'app' => Application::APP_ID]
			);
		}//end try
	}//end run()

	/**
	 * Match a `[ZAAK-2026-000142]` style tag in the subject.
	 *
	 * @param string $subject Subject header.
	 *
	 * @return string|null Matched identifier or null when no tag present.
	 *
	 * @spec openspec/changes/case-email-integration/tasks.md#T08
	 */
	public function matchCaseFromSubject(string $subject): ?string {
		if (preg_match(self::CASE_NUMBER_PATTERN, $subject, $matches) === 1) {
			return $matches[1];
		}

		return null;
	}//end matchCaseFromSubject()

	/**
	 * Fetch a batch of unread messages from the shared mailbox.
	 *
	 * Real IMAP retrieval depends on `imap_open()` which is not guaranteed
	 * to be installed in every deployment; when missing, the job simply
	 * returns an empty batch — never throws.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @psalm-suppress UnusedFunctionCall
	 */
	private function fetchUnreadBatch(): array {
		if (function_exists('imap_open') === false) {
			$this->logger->debug('imap_open() not available — skipping inbound poll');
			return [];
		}

		$host = $this->appConfig->getValueString(Application::APP_ID, 'email_imap_host', '');
		$port = (int)$this->appConfig->getValueString(Application::APP_ID, 'email_imap_port', '993');
		$encryption = $this->appConfig->getValueString(Application::APP_ID, 'email_imap_encryption', 'ssl');
		$username = $this->appConfig->getValueString(Application::APP_ID, 'email_imap_username', '');
		$password = $this->appConfig->getValueString(Application::APP_ID, 'email_imap_password', '');
		$folder = $this->appConfig->getValueString(Application::APP_ID, 'email_imap_folder', 'INBOX');
		$batchSize = (int)$this->appConfig->getValueString(
			Application::APP_ID,
			'email_poll_batch_size',
			(string)self::DEFAULT_BATCH_SIZE,
		);
		if ($batchSize <= 0) {
			$batchSize = self::DEFAULT_BATCH_SIZE;
		}

		$mailbox = '{' . $host . ':' . $port . '/imap/' . $encryption . '}' . $folder;

		$connection = $this->withoutWarnings(
			operation: static function () use ($mailbox, $username, $password): mixed {
				return imap_open($mailbox, $username, $password);
			}
		);
		if ($connection === false) {
			$this->logger->warning(
				'IMAP connection failed',
				['host' => $host, 'detail' => $this->lastSuppressedWarning()]
			);
			return [];
		}

		$messages = [];
		try {
			$ids = $this->withoutWarnings(
				operation: static function () use ($connection): mixed {
					return imap_search($connection, 'UNSEEN');
				}
			);
			if (is_array($ids) === false || $ids === []) {
				return [];
			}

			$ids = array_slice($ids, 0, $batchSize);
			foreach ($ids as $id) {
				$headers = $this->withoutWarnings(
					operation: static function () use ($connection, $id): mixed {
						return imap_headerinfo($connection, (int)$id);
					}
				);
				if ($headers === false) {
					continue;
				}

				$messages[] = $this->mapHeaderToMessage(headers: $headers, imapUid: (int)$id);
			}//end foreach
		} finally {
			$this->withoutWarnings(
				operation: static function () use ($connection): mixed {
					return imap_close($connection);
				}
			);
		}//end try

		return $messages;
	}//end fetchUnreadBatch()

	/**
	 * Flatten one `imap_headerinfo()` result into the message array the rest
	 * of the job works with.
	 *
	 * @param object $headers The stdClass returned by imap_headerinfo().
	 * @param int $imapUid The IMAP UID the headers were read from.
	 *
	 * @return array<string, mixed> The normalised message row.
	 */
	private function mapHeaderToMessage(object $headers, int $imapUid): array {
		return [
			// phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- imap_headerinfo() returns a stdClass whose property names are fixed by the PHP IMAP extension.
			'mailMessageId' => (string)($headers->message_id ?? ''),
			'subject' => (string)($headers->subject ?? ''),
			'from' => (string)($headers->fromaddress ?? ''),
			'to' => (string)($headers->toaddress ?? ''),
			'sentAt' => (string)($headers->date ?? ''),
			'imapUid' => $imapUid,
			// phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- imap_headerinfo() returns a stdClass whose property names are fixed by the PHP IMAP extension.
			'sizeBytes' => (int)($headers->Size ?? 0),
		];
	}//end mapHeaderToMessage()

	/**
	 * Check whether a mailMessageId is already linked to a case.
	 *
	 * @param string $messageId RFC822 Message-ID header.
	 *
	 * @return bool
	 */
	private function isAlreadyLinked(string $messageId): bool {
		if ($messageId === '') {
			return false;
		}

		try {
			$objectService = $this->settingsService->getObjectService();
			if ($objectService === null) {
				return false;
			}

			$register = $this->settingsService->getConfigValue('register');
			$schema = $this->settingsService->getConfigValue('case_document_schema');
			if (empty($register) === true || empty($schema) === true) {
				return false;
			}

			if (method_exists($objectService, 'searchObjectsBySlug') === true) {
				// Positional: the narrowed duck-typed object has no parameter
				// names for static analysis. Order matches OpenRegister's
				// searchObjectsBySlug(registerSlug, schemaSlug, filters).
				$rows = $objectService->searchObjectsBySlug(
					(string)$register,
					(string)$schema,
					['mailMessageId' => $messageId, '_limit' => 1]
				);
				return (is_array($rows) === true && $rows !== []);
			}
		} catch (\Throwable $e) {
			$this->logger->debug('isAlreadyLinked check failed', ['error' => $e->getMessage()]);
		}//end try

		return false;
	}//end isAlreadyLinked()

	/**
	 * Best-effort mark the message as "processed" so the next poll skips it.
	 *
	 * Implementation is a no-op when IMAP control is unavailable; the
	 * already-linked check above is still authoritative.
	 *
	 * @param string $messageId Message ID.
	 *
	 * @return void
	 */
	private function markProcessed(string $messageId): void {
		// Real IMAP flag-set / folder-move requires an open connection; future
		// work can plumb that through fetchUnreadBatch's connection scope. The
		// dedup guarantee comes from {@see isAlreadyLinked()}.
		unset($messageId);
	}//end markProcessed()
}//end class
