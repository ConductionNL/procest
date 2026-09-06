<?php

/**
 * Dossiq Qmatic Orchestra Backend.
 *
 * Integration with the Qmatic Orchestra REST API for appointment scheduling.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\AppointmentBackend
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

namespace OCA\Dossiq\Service\AppointmentBackend;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Qmatic Orchestra REST API backend for appointment scheduling.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class QmaticBackend implements AppointmentBackendInterface {
	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService The HTTP client service.
	 * @param LoggerInterface $logger The logger.
	 * @param string $apiUrl The Qmatic API base URL.
	 * @param string $apiKey The Qmatic API auth token.
	 */
	public function __construct(
		private IClientService $clientService,
		private LoggerInterface $logger,
		private string $apiUrl,
		private string $apiKey,
	) {
	}//end __construct()

	/**
	 * Fetch available timeslots from the Qmatic API.
	 *
	 * @param string $productId The Qmatic service identifier.
	 * @param string $locationId The Qmatic branch identifier.
	 * @param string $date The date (YYYY-MM-DD).
	 *
	 * @return array<int, array<string, mixed>> List of available timeslots.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getTimeslots(string $productId, string $locationId, string $date): array {
		try {
			$client = $this->clientService->newClient();
			$response = $client->get(
				$this->apiUrl . "/branches/{$locationId}/services/{$productId}/dates/{$date}/times",
				['headers' => ['auth-token' => $this->apiKey]]
			);

			$data = json_decode($response->getBody(), true) ?? [];
			$slots = [];
			foreach (($data['times'] ?? []) as $time) {
				$slots[] = [
					'time' => $time['time'] ?? '',
					'duration' => 30,
					'available' => true,
				];
			}

			return $slots;
		} catch (\Exception $e) {
			$this->logger->error('Qmatic API error: ' . $e->getMessage());
			return [];
		}//end try
	}//end getTimeslots()

	/**
	 * Book an appointment via the Qmatic API.
	 *
	 * @param array<string, mixed> $data Appointment data to POST to Qmatic.
	 *
	 * @return array<string, mixed> Booking result from Qmatic, or error payload.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function bookAppointment(array $data): array {
		try {
			$client = $this->clientService->newClient();
			$response = $client->post(
				$this->apiUrl . '/appointments',
				[
					'json' => $data,
					'headers' => ['auth-token' => $this->apiKey],
				]
			);

			return json_decode($response->getBody(), true) ?? [];
		} catch (\Exception $e) {
			$this->logger->error('Qmatic booking error: ' . $e->getMessage());
			return ['error' => $e->getMessage()];
		}
	}//end bookAppointment()

	/**
	 * Cancel an appointment via the Qmatic API.
	 *
	 * @param string $externalId The Qmatic appointment id.
	 *
	 * @return bool True on success, false on API error.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function cancelAppointment(string $externalId): bool {
		try {
			$client = $this->clientService->newClient();
			$client->delete(
				$this->apiUrl . '/appointments/' . $externalId,
				['headers' => ['auth-token' => $this->apiKey]]
			);
			return true;
		} catch (\Exception $e) {
			$this->logger->error('Qmatic cancel error: ' . $e->getMessage());
			return false;
		}
	}//end cancelAppointment()

	/**
	 * Reschedule an appointment via the Qmatic API (cancel then book).
	 *
	 * @param string $externalId The Qmatic appointment id.
	 * @param string $newDateTime The new datetime (ISO 8601).
	 *
	 * @return array<string, mixed> The new booking result.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function rescheduleAppointment(string $externalId, string $newDateTime): array {
		$this->cancelAppointment(externalId: $externalId);
		return $this->bookAppointment(data: ['dateTime' => $newDateTime]);
	}//end rescheduleAppointment()
}//end class
