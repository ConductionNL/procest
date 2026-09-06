<?php

/**
 * Dossiq Terugvordering Service.
 *
 * Clawback (terugvordering) management for over-disbursed advances under
 * AWB 4:57. Owns clawback-case creation on vaststelling, betaaltermijn /
 * bezwaartermijn binding, payment recording, and invorderingsrente accrual
 * per AWB 4:97. The rente and termijn math are pure and fully unit-tested;
 * persistence delegates to OpenRegister via SettingsService.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Subsidie
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Subsidie;

use DateInterval;
use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\AppFramework\OCS\OCSBadRequestException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Clawback lifecycle and invorderingsrente service.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/subsidieverlening-keten/specs.md
 */
class TerugvorderingService {

	use SearchesObjects;

	/**
	 * Default bezwaartermijn (objection window) in weeks (AWB 6:7).
	 */
	public const BEZWAARTERMIJN_WEKEN = 6;

	/**
	 * Default betaaltermijn (payment window) in weeks.
	 */
	public const BETAALTERMIJN_WEKEN = 4;

	/**
	 * Statutory invorderingsrente, annual fraction (wettelijke rente,
	 * AWB 4:97). Expressed as a fraction (0.06 == 6 % p/a).
	 */
	public const WETTELIJKE_RENTE_FRACTIE = 0.06;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema/register bridge.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Compute the bezwaartermijn end date from a publication date.
	 *
	 * @param DateTimeImmutable $publication The publication date.
	 *
	 * @return DateTimeImmutable The bezwaartermijn end.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function computeBezwaartermijn(DateTimeImmutable $publication): DateTimeImmutable {
		return $publication->add(new DateInterval('P' . (self::BEZWAARTERMIJN_WEKEN * 7) . 'D'));
	}//end computeBezwaartermijn()

	/**
	 * Compute the betaaltermijn end date from a publication date.
	 *
	 * @param DateTimeImmutable $publication The publication date.
	 *
	 * @return DateTimeImmutable The betaaltermijn end.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function computeBetaaltermijn(DateTimeImmutable $publication): DateTimeImmutable {
		return $publication->add(new DateInterval('P' . (self::BETAALTERMIJN_WEKEN * 7) . 'D'));
	}//end computeBetaaltermijn()

	/**
	 * Compute the invorderingsrente accrued on an unpaid clawback amount
	 * between two dates (AWB 4:97). Returns 0.0 when the end date is on or
	 * before the start date. The result is rounded to whole eurocents.
	 *
	 * @param float $openstaandBedrag The outstanding amount.
	 * @param DateTimeImmutable $from Accrual start (original payment date).
	 * @param DateTimeImmutable $tot Accrual end.
	 * @param float|null $yearFaction Annual rate fraction; defaults to the wettelijke rente.
	 *
	 * @return float The accrued rente in EUR.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function computeInvorderingsrente(
		float $openstaandBedrag,
		DateTimeImmutable $from,
		DateTimeImmutable $tot,
		?float $yearFaction = null,
	): float {
		if ($openstaandBedrag <= 0.0 || $tot <= $from) {
			return 0.0;
		}

		$yearFaction = ($yearFaction ?? self::WETTELIJKE_RENTE_FRACTIE);
		$days = (int)$from->diff($tot)->days;
		$rente = ($openstaandBedrag * $yearFaction * ($days / 365));

		return round($rente, 2);
	}//end computeInvorderingsrente()

	/**
	 * Determine the clawback status after a (partial) payment is recorded.
	 *
	 * @param float $amount The total amount owed.
	 * @param float $paid The cumulative amount paid.
	 *
	 * @return string The resulting status.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function statusAfterPayment(float $amount, float $paid): string {
		if ($paid <= 0.0) {
			return 'opgelegd';
		}

		if (($amount - $paid) < 0.01) {
			return 'paid';
		}

		return 'gedeeltelijk_paid';
	}//end statusAfterPayment()

	/**
	 * Open a clawback case for an overpayment (REQ-SUB-005). The case is
	 * created in status "draft" and requires manager approval before it
	 * may be published — never auto-published.
	 *
	 * @param string $uitvoeringId The execution id.
	 * @param float $amount The overpayment to recover.
	 * @param DateTimeImmutable|null $publication Publication date (clock injection).
	 *
	 * @return array<string, mixed> The created clawback record.
	 *
	 * @throws OCSBadRequestException When the amount is non-positive or persistence fails.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-005-vaststelling-with-optional-terugvordering
	 */
	public function createClawbackCase(string $uitvoeringId, float $amount, ?DateTimeImmutable $publication = null): array {
		if ($amount <= 0.0) {
			throw new OCSBadRequestException('Terugvorderingsbedrag moet positief zijn');
		}

		[$objectService, $register, $schema] = $this->resolve();

		$publication = ($publication ?? new DateTimeImmutable());
		$record = [
			'subsidieuitvoering' => $uitvoeringId,
			'amount' => round($amount, 2),
			'legalBasis' => 'AWB 4:57',
			'objectionPeriodEnd' => $this->computeBezwaartermijn(publication: $publication)->format('Y-m-d'),
			'paymentTermEnd' => $this->computeBetaaltermijn(publication: $publication)->format('Y-m-d'),
			'paidAmount' => 0,
			'managerGoedgekeurd' => false,
			'status' => 'draft',
		];

		try {
			return ($this->saveObjectAsArray(objectService: $objectService, register: $register, schema: $schema, object: $record) ?? $record);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq subsidie: createClawbackCase failed: ' . $e->getMessage());
			throw new OCSBadRequestException('Kon terugvordering niet aanmaken');
		}
	}//end createClawbackCase()

	/**
	 * Resolve the ObjectService and register/schema ids.
	 *
	 * @return array{0: object, 1: string, 2: string} ObjectService, register, schema.
	 *
	 * @throws OCSBadRequestException When OpenRegister is unavailable or unconfigured.
	 */
	private function resolve(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new OCSBadRequestException('OpenRegister is niet beschikbaar');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('terugvordering_schema');
		if ($register === '' || $schema === '') {
			throw new OCSBadRequestException('Terugvordering-schema is niet geconfigureerd');
		}

		return [$objectService, $register, $schema];
	}//end resolve()
}//end class
