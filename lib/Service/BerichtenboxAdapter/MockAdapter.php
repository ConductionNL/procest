<?php

/**
 * Dossiq Mock Berichtenbox Adapter.
 *
 * Local mock adapter used for development and testing that simulates
 * Berichtenbox message sending and read status without external calls.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\BerichtenboxAdapter
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\BerichtenboxAdapter;

use DateTime;
use Psr\Log\LoggerInterface;

/**
 * Mock Berichtenbox adapter for development and testing.
 *
 * Simulates message sending and read status without external API calls.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class MockAdapter implements BerichtenboxAdapterInterface {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Simulate sending a Berichtenbox message.
	 *
	 * @param string $bsn Citizen BSN.
	 * @param string $subject Message subject.
	 * @param string $body Plain text body.
	 * @param string $typeCode Bericht type code.
	 * @param string|null $attachment Optional attachment content (base64).
	 *
	 * @return array<string, string> Mock send result with a generated messageId.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function sendMessage(
		string $bsn,
		string $subject,
		string $body,
		string $typeCode,
		?string $attachment = null,
	): array {
		$messageId = 'mock-' . bin2hex(random_bytes(8));

		$this->logger->info(
			'MockBerichtenbox: Message sent',
			[
				'messageId' => $messageId,
				'bsn' => substr($bsn, 0, 4) . '*****',
				'subject' => $subject,
				'typeCode' => $typeCode,
				'hasAttachment' => $attachment !== null,
			]
		);

		return [
			'messageId' => $messageId,
			'status' => 'sent',
			'sentAt' => (new DateTime())->format('c'),
		];
	}//end sendMessage()

	/**
	 * Simulate a read-status check.
	 *
	 * @param string $messageId The external message id.
	 *
	 * @return array<string, mixed> Mock read status (always read 1h ago).
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getReadStatus(string $messageId): array {
		// Simulate: messages are "read" after they've existed for a while.
		return [
			'read' => true,
			'readAt' => (new DateTime('-1 hour'))->format('c'),
		];
	}//end getReadStatus()
}//end class
