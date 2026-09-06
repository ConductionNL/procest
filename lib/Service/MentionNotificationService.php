<?php

/**
 * Dossiq Mention Notification Service.
 *
 * Turns a saved note's `@mention` tokens (nc-vue #207, `CnNotesTab`'s
 * `mention` event) into real Nextcloud notifications for each mentioned
 * user. Built on Nextcloud's IManager with the imperative
 * create/notify/try-catch-warn shape.
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
 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTime;
use OCA\Dossiq\AppInfo\Application;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Service for sending Nextcloud notifications for note `@mention`s.
 *
 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
 */
class MentionNotificationService {
	/**
	 * Constructor.
	 *
	 * @param IManager $notificationManager The Nextcloud notification manager
	 * @param LoggerInterface $logger The logger
	 */
	public function __construct(
		private readonly IManager $notificationManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Notify every mentioned user, skipping the note's own author.
	 *
	 * @param string $actorUserId The note author's user id
	 * @param string $actorDisplayName The note author's display name
	 * @param string $objectId The OpenRegister object UUID the note is attached to
	 * @param string $register The OpenRegister register slug
	 * @param string $schema The OpenRegister schema slug
	 * @param string $noteId The note's id
	 * @param array<string> $mentionedUserIds The mentioned users' NC user ids
	 *
	 * @return int Number of notifications actually dispatched
	 *
	 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
	 */
	public function notifyMention(
		string $actorUserId,
		string $actorDisplayName,
		string $objectId,
		string $register,
		string $schema,
		string $noteId,
		array $mentionedUserIds,
	): int {
		$notified = 0;

		foreach (array_unique($mentionedUserIds) as $mentionedUserId) {
			// Never notify authors about their own mentions (e.g. self-mention,
			// or a duplicate @mention of the same user typed twice).
			if ($mentionedUserId === '' || $mentionedUserId === $actorUserId) {
				continue;
			}

			$objectType = 'note';
			if ($schema !== '') {
				$objectType = $schema;
			}

			try {
				$notification = $this->notificationManager->createNotification();
				$notification->setApp(Application::APP_ID)
					->setUser($mentionedUserId)
					->setDateTime(new DateTime())
					->setObject($objectType, $objectId)
					->setSubject(
						'note_mention',
						[
							'actorUserId' => $actorUserId,
							'actorDisplayName' => $actorDisplayName,
							'register' => $register,
							'schema' => $schema,
							'objectId' => $objectId,
							'noteId' => $noteId,
						]
					);

				$this->notificationManager->notify($notification);
				$notified++;
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Failed to send note mention notification',
					[
						'mentionedUserId' => $mentionedUserId,
						'objectId' => $objectId,
						'exception' => $e->getMessage(),
					]
				);
			}//end try
		}//end foreach

		return $notified;
	}//end notifyMention()
}//end class
