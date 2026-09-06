<?php

/**
 * Dossiq Informatieobject Access Guard
 *
 * Service-layer enforcement of the ZGW DRC `vertrouwelijkheidaanduiding`
 * (confidentiality) hierarchy on every read, share, publish and download
 * of an informatieobject. The guard fails closed: an unknown classification
 * is treated as the most restrictive level and an unresolved user clearance
 * is treated as the baseline (lowest) clearance.
 *
 * The user's clearance is derived from their Nextcloud group membership via
 * the `dossier_clearance_group_map` app-config key (a comma-separated list of
 * `<groupId>:<level>` pairs); administrators always receive the top clearance.
 * Users with no mapped group fall back to `dossier_default_clearance`
 * (default `intern`).
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
 * @spec openspec/changes/document-zaakdossier/tasks.md#T03
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCP\Files\NotPermittedException;
use OCP\IGroupManager;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Enforces vertrouwelijkheidaanduiding-based access control on informatieobjecten.
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T03
 */
class InformatieobjectAccessGuard {
	/**
	 * ZGW confidentiality levels ordered lowest (index 0) to highest.
	 */
	public const HIERARCHY = [
		'openbaar',
		'beperkt_openbaar',
		'intern',
		'zaakvertrouwelijk',
		'vertrouwelijk',
		'confidentieel',
		'geheim',
		'zeer_geheim',
	];

	/**
	 * Classification at or above which a public share is forbidden.
	 */
	public const PUBLISH_THRESHOLD = 'vertrouwelijk';

	/**
	 * Clearance used when no group maps and no default is configured.
	 */
	private const FALLBACK_CLEARANCE = 'intern';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service (config + groups map).
	 * @param IGroupManager $groupManager Nextcloud group manager.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Map a classification string to its ordinal in the hierarchy.
	 *
	 * Fails closed: an unknown or empty classification maps to the highest
	 * ordinal so an unclassified document is never accidentally exposed.
	 *
	 * @param string $level The vertrouwelijkheidaanduiding value.
	 *
	 * @return int Ordinal index (0 = openbaar … 7 = zeer_geheim).
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T03
	 */
	public function ordinalOf(string $level): int {
		$index = array_search($level, self::HIERARCHY, true);
		if ($index === false) {
			return (count(self::HIERARCHY) - 1);
		}

		return (int)$index;
	}//end ordinalOf()

	/**
	 * Resolve a user's clearance ordinal.
	 *
	 * Administrators receive the top clearance. Otherwise the highest level
	 * among the user's mapped groups is used; users without a mapped group
	 * fall back to the configured default clearance, then to `intern`.
	 *
	 * @param IUser $user The user whose clearance is resolved.
	 *
	 * @return int Clearance ordinal.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T03
	 */
	public function getUserClearanceOrdinal(IUser $user): int {
		$uid = $user->getUID();

		if ($this->groupManager->isAdmin($uid) === true) {
			return (count(self::HIERARCHY) - 1);
		}

		$defaultLevel = $this->settingsService->getConfigValue('dossier_default_clearance', self::FALLBACK_CLEARANCE);
		if (in_array($defaultLevel, self::HIERARCHY, true) === false) {
			$defaultLevel = self::FALLBACK_CLEARANCE;
		}

		$clearance = $this->ordinalOf(level: $defaultLevel);

		$groupMap = $this->parseGroupMap(raw: $this->settingsService->getConfigValue('dossier_clearance_group_map', ''));
		if (empty($groupMap) === true) {
			return $clearance;
		}

		$userGroups = $this->groupManager->getUserGroupIds($user);
		foreach ($userGroups as $groupId) {
			if (isset($groupMap[$groupId]) === false) {
				continue;
			}

			$ordinal = $this->ordinalOf(level: $groupMap[$groupId]);
			if ($ordinal > $clearance) {
				$clearance = $ordinal;
			}
		}

		return $clearance;
	}//end getUserClearanceOrdinal()

	/**
	 * Determine whether a user may read an informatieobject.
	 *
	 * @param IUser $user The requesting user.
	 * @param array<string, mixed> $informatieobject The informatieobject record.
	 *
	 * @return bool True when the user's clearance meets or exceeds the document's classification.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T03
	 */
	public function canRead(IUser $user, array $informatieobject): bool {
		$docOrdinal = $this->ordinalOf(level: (string)($informatieobject['vertrouwelijkheidaanduiding'] ?? ''));
		$userOrdinal = $this->getUserClearanceOrdinal(user: $user);

		return $userOrdinal >= $docOrdinal;
	}//end canRead()

	/**
	 * Assert that a user may read an informatieobject, throwing on denial.
	 *
	 * @param IUser $user The requesting user.
	 * @param array<string, mixed> $informatieobject The informatieobject record.
	 *
	 * @return void
	 *
	 * @throws NotPermittedException When the user lacks sufficient clearance.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T03
	 */
	public function assertCanRead(IUser $user, array $informatieobject): void {
		if ($this->canRead(user: $user, informatieobject: $informatieobject) === false) {
			$this->logger->warning(
				'Dossiq dossier: read denied on informatieobject for user ' . $user->getUID(),
				['classification' => ($informatieobject['vertrouwelijkheidaanduiding'] ?? 'unknown')],
			);
			throw new NotPermittedException('Insufficient clearance for this document');
		}
	}//end assertCanRead()

	/**
	 * Determine whether an informatieobject may be published via a public share.
	 *
	 * Documents classified at or above the publish threshold (vertrouwelijk)
	 * may never be exposed through a public share link.
	 *
	 * @param array<string, mixed> $informatieobject The informatieobject record.
	 *
	 * @return bool True when public publication is allowed.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T03
	 */
	public function canPublish(array $informatieobject): bool {
		$docOrdinal = $this->ordinalOf(level: (string)($informatieobject['vertrouwelijkheidaanduiding'] ?? ''));
		$thresholdOrdinal = $this->ordinalOf(level: self::PUBLISH_THRESHOLD);

		return $docOrdinal < $thresholdOrdinal;
	}//end canPublish()

	/**
	 * Remove informatieobjecten the user is not cleared to see.
	 *
	 * @param IUser $user The requesting user.
	 * @param array<int, array<string, mixed>> $informatieobjecten The candidate records.
	 *
	 * @return array<int, array<string, mixed>> Records the user may read (re-indexed).
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T03
	 */
	public function filterDossierForUser(IUser $user, array $informatieobjecten): array {
		$userOrdinal = $this->getUserClearanceOrdinal(user: $user);

		$allowed = [];
		foreach ($informatieobjecten as $record) {
			$docOrdinal = $this->ordinalOf(level: (string)($record['vertrouwelijkheidaanduiding'] ?? ''));
			if ($userOrdinal >= $docOrdinal) {
				$allowed[] = $record;
			}
		}

		return $allowed;
	}//end filterDossierForUser()

	/**
	 * Reject an attempt to lower a classification below a type's default.
	 *
	 * Per REQ-ZAK-003d a user may override the default classification of an
	 * informatieobjecttype to a MORE restrictive level but never to a LESS
	 * restrictive one.
	 *
	 * @param string $defaultLevel The informatieobjecttype default classification.
	 * @param string $requestedLevel The level the user requested.
	 *
	 * @return bool True when the requested level is allowed (equal or more restrictive).
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T03
	 */
	public function isClassificationAllowed(string $defaultLevel, string $requestedLevel): bool {
		if ($requestedLevel === '') {
			return true;
		}

		return $this->ordinalOf(level: $requestedLevel) >= $this->ordinalOf(level: $defaultLevel);
	}//end isClassificationAllowed()

	/**
	 * Parse the `<groupId>:<level>` comma-separated group clearance map.
	 *
	 * @param string $raw The raw config value.
	 *
	 * @return array<string, string> Map of group id to clearance level.
	 */
	private function parseGroupMap(string $raw): array {
		$map = [];
		if (trim($raw) === '') {
			return $map;
		}

		foreach (explode(',', $raw) as $pair) {
			$parts = explode(':', trim($pair), 2);
			if (count($parts) !== 2) {
				continue;
			}

			$groupId = trim($parts[0]);
			$level = trim($parts[1]);
			if ($groupId !== '' && in_array($level, self::HIERARCHY, true) === true) {
				$map[$groupId] = $level;
			}
		}

		return $map;
	}//end parseGroupMap()
}//end class
