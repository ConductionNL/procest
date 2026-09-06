<?php

/**
 * Dossiq KCC Quick-Action Service.
 *
 * Executes the standard KCC handelingen in one step: status terugkoppelen
 * (render a status text for medewerker confirmation), nieuwe zaak (create a
 * case from the contact context), klacht registreren (create an Awb 9:1 klacht
 * case with a six-week deadline), and bel terug inplannen. Doorverbinden is
 * delegated to DoorverbindingService.
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
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T07
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\AppInfo\Application;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Executes configured KCC quick-actions against the case register.
 *
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T07
 */
class QuickActionService {
	/**
	 * Case type slug used for klacht cases (Awb hoofdstuk 9).
	 */
	private const KLACHT_ZAAKTYPE = 'klacht_ex_artikel_9_1_awb';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service.
	 * @param ContactMomentService $contactMomentService The contactmoment service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ContactMomentService $contactMomentService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Render a "Status terugkoppelen" draft text for medewerker confirmation.
	 *
	 * Returns a draft only; the activity is recorded by the caller after the
	 * medewerker confirms the status was communicated.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array{caseId: string, draftText: string, status: string}
	 *
	 * @throws RuntimeException When the case cannot be loaded.
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T07
	 */
	public function executeStatusTerugkoppelen(string $caseId): array {
		$case = $this->loadCase(caseId: $caseId);
		$status = (string)($case['status'] ?? 'unknown');
		$title = (string)($case['title'] ?? ($case['titel'] ?? 'uw aanvraag'));

		$draft = sprintf(
			'Uw aanvraag "%s" heeft op dit moment de status: %s. Wij houden u op de hoogte van de voortgang.',
			$title,
			$status,
		);

		return ['caseId' => $caseId, 'draftText' => $draft, 'status' => $status];
	}//end executeStatusTerugkoppelen()

	/**
	 * Create a new case from the KCC contact context.
	 *
	 * @param string $caseType The target case type slug.
	 * @param string $burgerId The identified burger reference.
	 * @param array<string, mixed> $details The intake details (location, etc.).
	 *
	 * @return array{caseId: string}
	 *
	 * @throws RuntimeException When input is invalid or the write fails.
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T07
	 */
	public function executeNieuweZaak(string $caseType, string $burgerId, array $details): array {
		$caseType = trim($caseType);
		if ($caseType === '') {
			throw new RuntimeException('zaaktype is required');
		}

		[$objectService, $register, $caseSchema] = $this->resolveCase();

		$record = [
			'caseType' => $caseType,
			'initiator' => $burgerId,
			'sourceChannel' => 'kcc_telefoon',
			'status' => 'intake',
			'startDate' => date('c'),
			'title' => (string)($details['title'] ?? ('Melding via KCC: ' . $caseType)),
			'description' => (string)($details['description'] ?? ''),
		];

		try {
			$created = $this->toArray(result: $objectService->saveObject(object: $record, register: $register, schema: $caseSchema));
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: failed to create case via quick-action: ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			throw new RuntimeException('Could not create case');
		}

		return ['caseId' => (string)($created['id'] ?? ($created['uuid'] ?? ''))];
	}//end executeNieuweZaak()

	/**
	 * Register a klacht as an Awb 9:1 case linked to the original case.
	 *
	 * @param string $caseId The case being complained about (may be empty).
	 * @param string $summary The klacht text.
	 * @param string $burgerId The identified burger reference.
	 *
	 * @return array{klachtCaseId: string, deadline: string}
	 *
	 * @throws RuntimeException When the klacht text is empty or the write fails.
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T07
	 */
	public function executeKlachtRegistreren(string $caseId, string $summary, string $burgerId): array {
		$summary = trim($summary);
		if ($summary === '') {
			throw new RuntimeException('Klacht samenvatting is required');
		}

		[$objectService, $register, $caseSchema] = $this->resolveCase();

		// Awb 9:11: six weeks (42 days) decision term.
		$deadline = (new DateTimeImmutable('today'))->modify('+42 days')->format('Y-m-d');

		$record = [
			'caseType' => self::KLACHT_ZAAKTYPE,
			'initiator' => $burgerId,
			'sourceChannel' => 'kcc_telefoon',
			'status' => 'intake',
			'startDate' => date('c'),
			'deadline' => $deadline,
			'title' => 'Klacht (Awb 9:1)',
			'description' => $summary,
			'gerelateerdeZaak' => $caseId,
		];

		try {
			$created = $this->toArray(result: $objectService->saveObject(object: $record, register: $register, schema: $caseSchema));
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: failed to register klacht: ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			throw new RuntimeException('Could not register klacht');
		}

		$complaintId = (string)($created['id'] ?? ($created['uuid'] ?? ''));

		if ($caseId !== '' && $complaintId !== '') {
			$this->contactMomentService->recordActivity(
				$caseId,
				'',
				'klacht_geregistreerd',
				'KCC',
				'Klacht geregistreerd als zaak ' . $complaintId,
			);
		}

		return ['klachtCaseId' => $complaintId, 'deadline' => $deadline];
	}//end executeKlachtRegistreren()

	/**
	 * Schedule a callback (bel terug inplannen) for the burger.
	 *
	 * @param string $burgerId The identified burger reference.
	 * @param string $window The preferred callback window.
	 *
	 * @return array{burgerId: string, window: string, scheduledAt: string}
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T07
	 */
	public function executeBelTerug(string $burgerId, string $window): array {
		$this->logger->info(
			'Dossiq: callback scheduled',
			[
				'app' => Application::APP_ID,
				'burgerId' => $burgerId,
				'window' => $window,
			],
		);

		return ['burgerId' => $burgerId, 'window' => $window, 'scheduledAt' => date('c')];
	}//end executeBelTerug()

	/**
	 * Load a case record by id.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The case record.
	 *
	 * @throws RuntimeException When the case cannot be loaded.
	 */
	private function loadCase(string $caseId): array {
		if ($caseId === '') {
			throw new RuntimeException('caseId is required');
		}

		[$objectService, $register, $caseSchema] = $this->resolveCase();

		try {
			return $this->toArray(result: $objectService->find($caseId, register: $register, schema: $caseSchema));
		} catch (Throwable $e) {
			throw new RuntimeException('Case not found');
		}
	}//end loadCase()

	/**
	 * Resolve the ObjectService, register and case schema.
	 *
	 * @return array{0: object, 1: string, 2: string}
	 *
	 * @throws RuntimeException When OpenRegister or the case schema is unavailable.
	 */
	private function resolveCase(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$caseSchema = $this->settingsService->getConfigValue('case_schema');
		if ($register === '' || $caseSchema === '') {
			throw new RuntimeException('Case schema is not configured');
		}

		return [$objectService, $register, $caseSchema];
	}//end resolveCase()

	/**
	 * Normalise an ObjectService result into a plain array.
	 *
	 * @param mixed $result The ObjectService result.
	 *
	 * @return array<string, mixed> The normalised record.
	 */
	private function toArray($result): array {
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
	}//end toArray()
}//end class
