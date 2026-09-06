<?php

/**
 * Dossiq Berichtenbox Adapter Interface.
 *
 * Contract for Mijn Overheid Berichtenbox API adapter implementations.
 *
 * @category Interface
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

/**
 * Interface for Mijn Overheid Berichtenbox API adapters.
 */
interface BerichtenboxAdapterInterface {
	/**
	 * Send a message to the Berichtenbox.
	 *
	 * @param string $bsn Citizen BSN
	 * @param string $subject Message subject
	 * @param string $body Plain text message body
	 * @param string $typeCode Bericht type code
	 * @param string|null $attachment PDF attachment content (base64)
	 *
	 * @return array Result with messageId, status
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function sendMessage(
		string $bsn,
		string $subject,
		string $body,
		string $typeCode,
		?string $attachment = null,
	): array;

	/**
	 * Get the read status of a sent message.
	 *
	 * @param string $messageId The external message ID
	 *
	 * @return array Status with read (bool), readAt (datetime|null)
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getReadStatus(string $messageId): array;
}//end interface
