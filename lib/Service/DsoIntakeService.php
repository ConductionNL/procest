<?php

/**
 * Dossiq DSO Intake Service
 *
 * Service for receiving and processing vergunningaanvragen from the
 * Digitaal Stelsel Omgevingswet (DSO/Omgevingsloket).
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dso-omgevingsloket-client/spec.md
 * @spec openspec/specs/dso-omgevingsloket-client/spec.md
 * @spec openspec/specs/dso-omgevingsloket-client/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for DSO/Omgevingsloket intake processing.
 *
 * Creates permit cases from DSO vergunningaanvraag messages.
 * Supports multiple activities per application and calculates
 * deadlines based on procedure type (regulier: 8 weeks, uitgebreid: 26 weeks).
 *
 * @spec openspec/specs/dso-omgevingsloket-client/spec.md
 */
class DsoIntakeService {

	/**
	 * Deadline durations per procedure type (ISO 8601).
	 */
	private const DEADLINE_DURATIONS = [
		'regulier' => 'P56D',
		'uitgebreid' => 'P182D',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Process a DSO vergunningaanvraag and create a case.
	 *
	 * @param array<string, mixed> $dsoMessage The DSO message payload
	 *
	 * @return array<string, mixed> Created case data with ID
	 *
	 * @throws \RuntimeException If OpenRegister is unavailable or configuration missing
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function processAanvraag(array $dsoMessage): array {
		return $this->createCase(mappedData: $this->map(dsoMessage: $dsoMessage));
	}//end processAanvraag()

	/**
	 * Reduce the DSO activiteiten list to a flat list of activity names.
	 *
	 * A structured activity contributes its `naam`; a scalar one is used as-is.
	 *
	 * @param mixed $activiteiten The raw activiteiten entry from the DSO payload
	 *
	 * @return array<int|string, mixed> The activity names, in payload order
	 */
	private function extractActivityNames(mixed $activiteiten): array {
		return array_map(
			static function ($act) {
				if (is_array($act) === true) {
					return $act['name'] ?? '';
				}

				return (string)$act;
			},
			$activiteiten,
		);
	}//end extractActivityNames()

	/**
	 * Persist the DSO-specific case properties as case property objects.
	 *
	 * Properties with an empty value are skipped rather than written as blanks.
	 *
	 * @param object $objectService The OpenRegister object service
	 * @param string $register Register slug
	 * @param string $schema Case property schema slug
	 * @param string $caseId UUID of the case the properties belong to
	 * @param array<string, mixed> $properties Property name to value map
	 *
	 * @return void
	 */
	private function storeCaseProperties(
		object $objectService,
		string $register,
		string $schema,
		string $caseId,
		array $properties,
	): void {
		foreach ($properties as $name => $value) {
			if ($value === '') {
				continue;
			}

			$objectService->saveObject(
				object: [
					'case' => $caseId,
					'name' => $name,
					'value' => $value,
				],
				register: $register,
				schema: $schema
			);
		}
	}//end storeCaseProperties()

	/**
	 * Get the processing deadline duration for a procedure type.
	 *
	 * @param string $procedureType The procedure type (regulier or uitgebreid)
	 *
	 * @return string ISO 8601 duration
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getDeadlineDuration(string $procedureType): string {
		return self::DEADLINE_DURATIONS[$procedureType] ?? self::DEADLINE_DURATIONS['regulier'];
	}//end getDeadlineDuration()

	/**
	 * Map a raw DSO payload to a structured case array.
	 *
	 * @param array<string, mixed> $dsoMessage The DSO vergunningaanvraag payload
	 *
	 * @return array<string, mixed> Structured case data ready for createCase()
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-3
	 */
	public function map(array $dsoMessage): array {
		$activiteiten = $dsoMessage['activiteiten'] ?? [];
		$location = $dsoMessage['location'] ?? '';
		$applicant = $dsoMessage['applicant'] ?? [];
		$bouwkosten = $dsoMessage['bouwkosten'] ?? 0;
		$procedureType = $dsoMessage['procedureType'] ?? 'regulier';
		$dsoZaaknummer = $dsoMessage['zaaknummer'] ?? '';
		$attachments = $dsoMessage['attachments'] ?? [];

		$activityNames = $this->extractActivityNames(activiteiten: $activiteiten);
		$activityStr = implode(', ', array_filter($activityNames));

		$deadline = self::DEADLINE_DURATIONS[$procedureType] ?? self::DEADLINE_DURATIONS['regulier'];

		$title = 'Omgevingsvergunning';
		if ($activityStr !== '') {
			$title .= ': ' . $activityStr;
		}

		$description = 'Vergunningaanvraag ontvangen via DSO/Omgevingsloket';
		if ($dsoZaaknummer !== '') {
			$description .= ' (DSO: ' . $dsoZaaknummer . ')';
		}

		// Cast only after the array case has been JSON-encoded, so an array
		// value never reaches the string cast (which would warn).
		$locationRaw = $location;
		if (is_array($location) === true) {
			$locationRaw = json_encode($location);
		}

		$locationStr = (string)$locationRaw;

		return [
			'title' => $title,
			'description' => $description,
			'startDate' => date('Y-m-d'),
			'priority' => 'normal',
			'dsoZaaknummer' => $dsoZaaknummer,
			'activiteiten' => $activityStr,
			'activityNames' => $activityNames,
			'location' => $locationStr,
			'bouwkosten' => (string)$bouwkosten,
			'procedureType' => $procedureType,
			'aanvragerNaam' => $applicant['name'] ?? '',
			'deadline' => $deadline,
			'attachments' => $attachments,
		];
	}//end map()

	/**
	 * Create a case from pre-mapped DSO data.
	 *
	 * @param array<string, mixed> $mappedData Structured case data from map()
	 *
	 * @return array<string, mixed> Created case data with ID
	 *
	 * @throws \RuntimeException If OpenRegister is unavailable or configuration missing.
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-3
	 */
	public function createCase(array $mappedData): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		if (empty($register) === true) {
			throw new RuntimeException('Dossiq register not configured');
		}

		$caseSchema = $this->settingsService->getConfigValue('case_schema');
		$caseData = [
			'title' => $mappedData['title'] ?? 'Omgevingsvergunning',
			'description' => $mappedData['description'] ?? '',
			'startDate' => $mappedData['startDate'] ?? date('Y-m-d'),
			'priority' => $mappedData['priority'] ?? 'normal',
		];

		// OpenRegister's signature is
		// saveObject(array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null, ...)
		// so the register/schema slugs MUST be passed by name — positionally
		// they would land in $object and $extend and raise a TypeError.
		$caseObj = $objectService->saveObject(object: $caseData, register: $register, schema: $caseSchema);
		$caseId = $caseObj->getUuid();

		$dsoZaaknummer = $mappedData['dsoZaaknummer'] ?? '';
		$propertySchema = $this->settingsService->getConfigValue('case_property_schema');

		$this->storeCaseProperties(
			objectService: $objectService,
			register: $register,
			schema: $propertySchema,
			caseId: $caseId,
			properties: [
				'dsoZaaknummer' => $dsoZaaknummer,
				'activiteiten' => $mappedData['activiteiten'] ?? '',
				'location' => $mappedData['location'] ?? '',
				'bouwkosten' => $mappedData['bouwkosten'] ?? '',
				'procedureType' => $mappedData['procedureType'] ?? '',
				'aanvragerNaam' => $mappedData['aanvragerNaam'] ?? '',
			],
		);

		$this->logger->info(
			'DSO intake processed: case ' . $caseId . ' (DSO: ' . $dsoZaaknummer . ')',
			['app' => Application::APP_ID],
		);

		return [
			'caseId' => $caseId,
			'dsoZaaknummer' => $dsoZaaknummer,
			'activiteiten' => $mappedData['activityNames'] ?? [],
			'procedureType' => $mappedData['procedureType'] ?? '',
			'deadline' => $mappedData['deadline'] ?? '',
		];
	}//end createCase()
}//end class
