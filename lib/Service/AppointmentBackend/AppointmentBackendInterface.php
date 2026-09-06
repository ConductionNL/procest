<?php

/**
 * Dossiq Appointment Backend Interface.
 *
 * Contract for pluggable appointment scheduling backends used by Dossiq.
 *
 * @category Interface
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

/**
 * Interface for appointment scheduling backends.
 *
 * Implementations provide integration with external appointment systems
 * (JCC Afspraken, Qmatic Orchestra) or local storage fallback.
 */
interface AppointmentBackendInterface {
	/**
	 * Get available timeslots for a product at a location on a date.
	 *
	 * @param string $productId The product identifier
	 * @param string $locationId The location identifier
	 * @param string $date The date (YYYY-MM-DD)
	 *
	 * @return array List of available timeslots [{time, duration, available}]
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function getTimeslots(string $productId, string $locationId, string $date): array;

	/**
	 * Book an appointment in the external system.
	 *
	 * @param array $data Appointment data (product, location, dateTime, citizen info)
	 *
	 * @return array Booking result with externalId
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function bookAppointment(array $data): array;

	/**
	 * Cancel an appointment in the external system.
	 *
	 * @param string $externalId The external system appointment ID
	 *
	 * @return bool True if cancellation succeeded
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function cancelAppointment(string $externalId): bool;

	/**
	 * Reschedule an appointment in the external system.
	 *
	 * @param string $externalId The external system appointment ID
	 * @param string $newDateTime The new datetime (ISO 8601)
	 *
	 * @return array Updated booking result
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function rescheduleAppointment(string $externalId, string $newDateTime): array;
}//end interface
