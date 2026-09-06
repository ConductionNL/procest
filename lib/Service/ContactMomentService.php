<?php

/**
 * Dossiq Contactmoment Service.
 *
 * Logs every inbound/outbound KCC contact as a contactmoment object, records
 * immutable activity entries on the related case, and links a contactmoment to
 * an identified burger reference. Wraps the OpenRegister ObjectService through
 * SettingsService following the established Dossiq service convention.
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
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T04
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Service for logging KCC contactmomenten and case activity.
 *
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T04
 */
class ContactMomentService {
	use SearchesObjects;

	/**
	 * Valid contactmoment channels.
	 */
	private const VALID_KANALEN = ['phone', 'email', 'webformulier', 'chat', 'social_media', 'balie'];

	/**
	 * Valid contactmoment natures.
	 */
	private const VALID_AARD = ['informatieverzoek', 'statusverzoek', 'complaint', 'report', 'new_request', 'doorverbinding'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create a contactmoment.
	 *
	 * @param array<string, mixed> $data The contactmoment fields.
	 *
	 * @return array<string, mixed> The created contactmoment record.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable, schema unconfigured, or input invalid.
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T04
	 */
	public function createContactMoment(array $data): array {
		$this->validateInput(data: $data);

		[$objectService, $register, $schema] = $this->resolve(schemaConfigKey: 'contactmoment_schema');

		$now = date('c');

		$record = [
			'notificationChannel' => (string)$data['notificationChannel'],
			'direction' => (string)($data['direction'] ?? 'inbound'),
			'startTime' => (string)($data['startTime'] ?? $now),
			'endTime' => ($data['endTime'] ?? null),
			'callerIdentification' => (string)($data['callerIdentification'] ?? ''),
			'geidentificeerdeBurgerId' => ($data['geidentificeerdeBurgerId'] ?? null),
			'identificationMethod' => (string)($data['identificationMethod'] ?? 'non_geidentificeerd'),
			'identificationScore' => ($data['identificationScore'] ?? null),
			'kccEmployeeId' => trim((string)$data['kccEmployeeId']),
			'relatedCases' => array_values((array)($data['relatedCases'] ?? [])),
			'newCaseIds' => array_values((array)($data['newCaseIds'] ?? [])),
			'nature' => (string)($data['nature'] ?? 'informatieverzoek'),
			'summary' => (string)($data['summary'] ?? ''),
			'accordingToIntent' => (string)($data['accordingToIntent'] ?? ''),
			'firstTimeFix' => (bool)($data['firstTimeFix'] ?? false),
			'transcript' => (string)($data['transcript'] ?? ''),
			'transferTo' => (string)($data['transferTo'] ?? ''),
		];

		$duration = $this->calculateDuration(data: $data);
		if ($duration !== null) {
			$record['durationSeconds'] = $duration;
		}

		try {
			$created = $objectService->saveObject(object: $record, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: failed to create contactmoment: ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			throw new RuntimeException('Could not create contactmoment');
		}

		return $this->normalize(result: $created);
	}//end createContactMoment()

	/**
	 * Validate the required input fields for a new contactmoment.
	 *
	 * @param array<string, mixed> $data The contactmoment fields.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When a required field is missing or invalid.
	 */
	private function validateInput(array $data): void {
		$channel = (string)($data['notificationChannel'] ?? '');
		if (in_array($channel, self::VALID_KANALEN, true) === false) {
			throw new RuntimeException('Invalid kanaal');
		}

		$nature = (string)($data['nature'] ?? 'informatieverzoek');
		if (in_array($nature, self::VALID_AARD, true) === false) {
			throw new RuntimeException('Invalid aard');
		}

		if (trim((string)($data['kccEmployeeId'] ?? '')) === '') {
			throw new RuntimeException('kccMedewerkerId is required');
		}
	}//end validateInput()

	/**
	 * Calculate the contact duration in seconds from start/end timestamps.
	 *
	 * @param array<string, mixed> $data The contactmoment fields.
	 *
	 * @return int|null The duration in seconds, or null when not calculable.
	 */
	private function calculateDuration(array $data): ?int {
		if (isset($data['startTime']) === false || isset($data['endTime']) === false) {
			return null;
		}

		$start = strtotime((string)$data['startTime']);
		$end = strtotime((string)$data['endTime']);
		if ($start === false || $end === false || $end < $start) {
			return null;
		}

		return ($end - $start);
	}//end calculateDuration()

	/**
	 * List contactmomenten for an identified burger, most recent first.
	 *
	 * @param string $burgerId The identified burger reference.
	 * @param int $limit Maximum number of records.
	 *
	 * @return array<int, array<string, mixed>> The contactmoment records.
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T04
	 */
	public function listForBurger(string $burgerId, int $limit = 50): array {
		if ($burgerId === '') {
			return [];
		}

		try {
			[$objectService, $register, $schema] = $this->resolve(schemaConfigKey: 'contactmoment_schema');
		} catch (RuntimeException $e) {
			return [];
		}

		try {
			$results = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['geidentificeerdeBurgerId' => $burgerId, '_limit' => max(1, $limit)],
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: failed to list contactmomenten: ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			return [];
		}

		$records = [];
		foreach ((array)$results as $result) {
			$records[] = $this->normalize(result: $result);
		}

		usort(
			$records,
			static function (array $a, array $b): int {
				return strcmp((string)($b['startTime'] ?? ''), (string)($a['startTime'] ?? ''));
			}
		);

		return $records;
	}//end listForBurger()

	/**
	 * Append an immutable activity entry to a case's activity array.
	 *
	 * Activity entries are append-only: this reads the case's current activity
	 * list, appends a timestamped entry, and writes the merged list back. Prior
	 * entries are never edited or removed.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $interactionId The contactmoment UUID.
	 * @param string $type The activity type.
	 * @param string $employeeName The handling medewerker.
	 * @param string $summary A short summary of the activity.
	 *
	 * @return bool True on success.
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T04
	 */
	public function recordActivity(
		string $caseId,
		string $interactionId,
		string $type,
		string $employeeName,
		string $summary,
	): bool {
		if ($caseId === '') {
			return false;
		}

		try {
			$objectService = $this->settingsService->getObjectService();
			if ($objectService === null) {
				return false;
			}

			$register = $this->settingsService->getConfigValue('register');
			$caseSchema = $this->settingsService->getConfigValue('case_schema');
			if ($register === '' || $caseSchema === '') {
				return false;
			}

			$case = $this->normalize(result: $objectService->find($caseId, register: $register, schema: $caseSchema));

			$activity = array_values((array)($case['activity'] ?? []));
			$activity[] = [
				'type' => $type,
				'interactionId' => $interactionId,
				'employee' => $employeeName,
				'summary' => $summary,
				'timestamp' => date('c'),
			];

			$objectService->saveObject(object: ['activity' => $activity], register: $register, schema: $caseSchema, uuid: $caseId);
			return true;
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: failed to record case activity: ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			return false;
		}//end try
	}//end recordActivity()

	/**
	 * Link an unidentified contactmoment to an identified burger.
	 *
	 * @param string $interactionId The contactmoment UUID.
	 * @param string $burgerId The resolved burger reference.
	 * @param string $method The identification method.
	 * @param float $score The identification confidence score.
	 *
	 * @return array<string, mixed> The updated contactmoment record.
	 *
	 * @throws RuntimeException When the schema is unconfigured or the update fails.
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T04
	 */
	public function linkUnlinkedContactmoment(
		string $interactionId,
		string $burgerId,
		string $method,
		float $score,
	): array {
		[$objectService, $register, $schema] = $this->resolve(schemaConfigKey: 'contactmoment_schema');

		try {
			$updated = $objectService->saveObject(
				object: [
					'geidentificeerdeBurgerId' => $burgerId,
					'identificationMethod' => $method,
					'identificationScore' => round($score, 2),
				],
				register: $register,
				schema: $schema,
				uuid: $interactionId,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: failed to link contactmoment: ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			throw new RuntimeException('Could not link contactmoment');
		}

		return $this->normalize(result: $updated);
	}//end linkUnlinkedContactmoment()

	/**
	 * Resolve the ObjectService, register id and schema id for a config key.
	 *
	 * @param string $schemaConfigKey The schema config key.
	 *
	 * @return array{0: object, 1: string, 2: string}
	 *
	 * @throws RuntimeException When OpenRegister or the schema is unavailable.
	 */
	private function resolve(string $schemaConfigKey): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue($schemaConfigKey);
		if ($register === '' || $schema === '') {
			throw new RuntimeException('KCC schema is not configured');
		}

		return [$objectService, $register, $schema];
	}//end resolve()

	/**
	 * Normalise an ObjectService result into a plain array.
	 *
	 * @param mixed $result The ObjectService result (entity or array).
	 *
	 * @return array<string, mixed> The normalised record.
	 */
	private function normalize($result): array {
		if (is_array($result) === true) {
			return $result;
		}

		if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
			return (array)$result->jsonSerialize();
		}

		if (is_object($result) === true) {
			return (array)$result;
		}

		return [];
	}//end normalize()
}//end class
