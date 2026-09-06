<?php

/**
 * Dossiq Appointment Service.
 *
 * Orchestrates citizen appointments against EXTERNAL municipal scheduling
 * systems (JCC Afspraken, Qmatic Orchestra) and persists the resulting
 * appointment records — plus their zaak-specific metadata — in OpenRegister.
 *
 * The former in-app `LocalBackend` scheduling path has been removed: internal
 * (non-external) case appointments are now scheduled and surfaced through
 * OpenRegister's `calendar` integration leaf on the case detail page (ADR-022).
 * External Qmatic/JCC timeslot booking is an ADR-022 exception the leaf cannot
 * host (see docs/adr/0001-external-appointment-backends-exception.md).
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\AppointmentBackend\AppointmentBackendInterface;
use OCA\Dossiq\Service\AppointmentBackend\JccBackend;
use OCA\Dossiq\Service\AppointmentBackend\QmaticBackend;
use OCA\Dossiq\Service\Support\OwningCaseResolver;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for managing appointments linked to cases.
 *
 * Dispatches to the configured EXTERNAL backend (JCC or Qmatic) and stores
 * appointment records in OpenRegister. There is no local fallback — internal
 * scheduling lives in the OR calendar leaf.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class AppointmentService {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service.
	 * @param IAppManager $appManager The Nextcloud app manager.
	 * @param IClientService $clientService The HTTP client service.
	 * @param ContainerInterface $container The DI container.
	 * @param LoggerInterface $logger The logger.
	 * @param OwningCaseResolver $owningCase Resolves an appointment's owning case.
	 */
	public function __construct(
		private SettingsService $settingsService,
		private IAppManager $appManager,
		private IClientService $clientService,
		private ContainerInterface $container,
		private LoggerInterface $logger,
		private readonly OwningCaseResolver $owningCase,
	) {
	}//end __construct()

	/**
	 * Get available timeslots via the configured backend.
	 *
	 * @param string $productId The product identifier.
	 * @param string $locationId The location identifier.
	 * @param string $date The date (YYYY-MM-DD).
	 *
	 * @return array<int, array<string, mixed>> List of available timeslots.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function getTimeslots(string $productId, string $locationId, string $date): array {
		return $this->getBackend()->getTimeslots($productId, $locationId, $date);
	}//end getTimeslots()

	/**
	 * Book an appointment linked to a case.
	 *
	 * @param string $caseId The case UUID.
	 * @param array<string, mixed> $data Appointment data (product, location, dateTime, citizen info).
	 *
	 * @return array<string, mixed> The stored appointment record or an error payload.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function bookAppointment(string $caseId, array $data): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return ['error' => 'OpenRegister is not available'];
		}

		// Book in external backend.
		$backendResult = $this->getBackend()->bookAppointment($data);

		// Store in OpenRegister.
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('appointment_schema');

		$appointmentData = array_merge(
			$data,
			[
				'caseId' => $caseId,
				'status' => 'scheduled',
				'externalId' => $backendResult['externalId'] ?? null,
				'cancelToken' => bin2hex(random_bytes(16)),
				'reminderSent' => false,
			]
		);

		$result = $objectService->saveObject(
			object: $appointmentData,
			register: (int)$register,
			schema: (int)$schema,
		);

		$this->logger->info(
			'Dossiq: Appointment booked',
			[
				'caseId' => $caseId,
				'appointmentId' => $result->getUuid(),
			]
		);

		return $result->jsonSerialize();
	}//end bookAppointment()

	/**
	 * Resolve the case an appointment belongs to.
	 *
	 * `cancel()` and `noShow()` carry only an appointment id, so there is
	 * nothing in their signature to authorise against. This resolves the owning
	 * case so the controller can apply the same per-case guard as the rest of
	 * the file. An unresolvable appointment returns null, which the caller
	 * treats as DENY — so an unknown id is not an existence oracle.
	 *
	 * @param string $appointmentId The appointment UUID.
	 *
	 * @return string|null The owning case UUID, or null when unresolvable.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function getCaseIdForAppointment(string $appointmentId): ?string {
		return $this->owningCase->resolve(
			objectId: $appointmentId,
			schemaKey: 'appointment_schema',
			caseField: 'caseId',
		);
	}//end getCaseIdForAppointment()

	/**
	 * Cancel an appointment.
	 *
	 * @param string $appointmentId The appointment UUID.
	 *
	 * @return array<string, mixed> The updated appointment record.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function cancelAppointment(string $appointmentId): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return ['error' => 'OpenRegister is not available'];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('appointment_schema');

		$appointment = $objectService->find($appointmentId, register: (int)$register, schema: (int)$schema);
		$data = $appointment->jsonSerialize();

		// Cancel in backend.
		if (empty($data['externalId']) === false) {
			$this->getBackend()->cancelAppointment($data['externalId']);
		}

		$data['status'] = 'cancelled';
		$result = $objectService->saveObject(object: $data, register: (int)$register, schema: (int)$schema);

		return $result->jsonSerialize();
	}//end cancelAppointment()

	/**
	 * Mark an appointment as no-show.
	 *
	 * @param string $appointmentId The appointment UUID.
	 *
	 * @return array<string, mixed> The updated appointment record.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function markNoShow(string $appointmentId): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return ['error' => 'OpenRegister is not available'];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('appointment_schema');

		$appointment = $objectService->find($appointmentId, register: (int)$register, schema: (int)$schema);
		$data = $appointment->jsonSerialize();
		$data['status'] = 'no_show';

		$result = $objectService->saveObject(object: $data, register: (int)$register, schema: (int)$schema);
		return $result->jsonSerialize();
	}//end markNoShow()

	/**
	 * Get appointments for a case.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<int, mixed> List of appointments for the case.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getAppointmentsForCase(string $caseId): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('appointment_schema');

		return $objectService->findAll(
			['filters' => ['register' => (int)$register, 'schema' => (int)$schema, 'caseId' => $caseId]],
		);
	}//end getAppointmentsForCase()

	/**
	 * Validate cancel token and return appointment.
	 *
	 * @param string $token The appointment public token.
	 *
	 * @return array<string, mixed>|null The appointment data, or null if not found.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getAppointmentByToken(string $token): ?array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('appointment_schema');

		$appointments = $objectService->findAll(
			['filters' => ['register' => (int)$register, 'schema' => (int)$schema, 'cancelToken' => $token]],
		);
		if (empty($appointments) === true) {
			return null;
		}

		$apt = reset($appointments);
		if (is_object($apt) === true) {
			return $apt->jsonSerialize();
		}

		return $apt;
	}//end getAppointmentByToken()

	/**
	 * Get the configured EXTERNAL appointment backend (JCC or Qmatic).
	 *
	 * The in-app `LocalBackend` fallback was removed when internal scheduling
	 * moved to the OR calendar leaf (ADR-022). Only external municipal-system
	 * backends remain; an unconfigured or unknown backend is a configuration
	 * error rather than a silent local fallback.
	 *
	 * @return AppointmentBackendInterface The external backend instance.
	 *
	 * @throws RuntimeException When no supported external backend is configured.
	 */
	private function getBackend(): AppointmentBackendInterface {
		$backendType = $this->settingsService->getConfigValue('appointment_backend');

		$apiUrl = $this->settingsService->getConfigValue('appointment_backend_url');
		$apiKey = $this->settingsService->getConfigValue('appointment_backend_api_key');

		switch ($backendType) {
			case 'jcc':
				return new JccBackend(
					clientService: $this->clientService,
					logger: $this->logger,
					apiUrl: $apiUrl,
					apiKey: $apiKey
				);
			case 'qmatic':
				return new QmaticBackend(
					clientService: $this->clientService,
					logger: $this->logger,
					apiUrl: $apiUrl,
					apiKey: $apiKey
				);
			default:
				throw new RuntimeException(
					'No external appointment backend configured. Configure JCC or Qmatic, '
					. 'or schedule internal appointments through the OpenRegister calendar leaf.'
				);
		}//end switch
	}//end getBackend()

	/**
	 * Resolve the OpenRegister ObjectService if OpenRegister is installed.
	 *
	 * @return \OCA\OpenRegister\Contract\ObjectServiceInterface|null The object service or null.
	 */
	private function getObjectService(): ?\OCA\OpenRegister\Contract\ObjectServiceInterface {
		if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Exception $e) {
			$this->logger->error('Dossiq: Could not get ObjectService', ['exception' => $e->getMessage()]);
			return null;
		}
	}//end getObjectService()
}//end class
