<?php

/**
 * Dossiq Notifier.
 *
 * Renders Dossiq's Nextcloud notifications for the bell menu.
 * MentionNotificationService raises `note_mention` notifications when a
 * saved note contains an `@mention` (nc-vue #207, CnNotesTab); this
 * INotifier turns the stored subject key + parameters into localised
 * text with an icon.
 *
 * @category Notification
 * @package  OCA\Dossiq\Notification
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

namespace OCA\Dossiq\Notification;

use OCA\Dossiq\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Parses Dossiq notifications into localised, rendered form.
 *
 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
 */
class Notifier implements INotifier {

	/**
	 * Every subject key this notifier can render.
	 *
	 * @var array<int, string>
	 */
	private const KNOWN_SUBJECTS = [
		'note_mention',
	];

	/**
	 * Constructor.
	 *
	 * @param IFactory $l10nFactory Resolves the localisation for the recipient's language.
	 * @param IURLGenerator $urlGenerator Builds the notification icon URL.
	 */
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
	) {
	}//end __construct()

	/**
	 * Identifier of the notifier, only use [a-z0-9_].
	 *
	 * @return string
	 *
	 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
	 */
	public function getID(): string {
		return Application::APP_ID;
	}//end getID()

	/**
	 * Human-readable name describing the notifier.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
	 */
	public function getName(): string {
		return 'Dossiq';
	}//end getName()

	/**
	 * Prepare a Dossiq notification for display.
	 *
	 * @param INotification $notification The raw notification.
	 * @param string $languageCode The recipient's language code.
	 *
	 * @return INotification The prepared notification.
	 *
	 * @throws UnknownNotificationException When the notification is not a Dossiq one.
	 *
	 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException('Notification not handled by Dossiq');
		}

		$subjectKey = $notification->getSubject();
		if (in_array($subjectKey, self::KNOWN_SUBJECTS, true) === false) {
			throw new UnknownNotificationException('Unknown Dossiq notification subject');
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$subjectRaw = $notification->getSubjectParameters();

		[$subject, $message] = $this->noteMentionText(subjectRaw: $subjectRaw, l: $l);

		$notification->setParsedSubject($subject);
		$notification->setParsedMessage($message);
		$notification->setIcon(
			$this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'))
		);

		return $notification;
	}//end prepare()

	/**
	 * The `note_mention` wording.
	 *
	 * @param array<string,mixed> $subjectRaw The stored subject parameters
	 *                                        (`actorDisplayName`, `register`, `schema`, `objectId`, `noteId`).
	 * @param \OCP\IL10N $l The recipient-language localisation.
	 *
	 * @return array{0:string,1:string} The [subject, message] pair.
	 *
	 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
	 */
	private function noteMentionText(array $subjectRaw, \OCP\IL10N $l): array {
		$actorDisplayName = (string)($subjectRaw['actorDisplayName'] ?? '');

		$subject = $l->t('You were mentioned in a note');
		if ($actorDisplayName !== '') {
			$subject = $l->t('%s mentioned you in a note', [$actorDisplayName]);
		}

		return [$subject, $l->t('Open the record to see the full note.')];
	}//end noteMentionText()
}//end class
