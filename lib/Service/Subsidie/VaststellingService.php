<?php

/**
 * Dossiq Vaststelling Service.
 *
 * Final-settlement (vaststelling) handling under AWB 4:46. Owns the
 * settlement-form math: comparing werkelijke kosten against the granted
 * amount, the accountantsverklaring requirement check, final-bedrag
 * calculation, overpayment detection, and the automatic terugvordering
 * trigger (REQ-SUB-005). The math is pure and unit-tested; finalisation
 * delegates clawback-case creation to TerugvorderingService.
 *
 * It no longer copies the settled amount onto the linked case's `kosten`
 * array. That denormalisation existed to feed dossiq's own IV3 report, and
 * both are gone under ADR-081 — a domain app MUST NOT hold a ledger-shaped
 * array, and Shillinq is the fleet's only general ledger. The amount stays
 * authoritative where it always was, on the vaststelling itself.
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

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\AppFramework\OCS\OCSBadRequestException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Settlement math and terugvordering trigger.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/subsidieverlening-keten/specs.md
 */
class VaststellingService {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema/register bridge.
	 * @param TerugvorderingService $terugvordering Clawback factory.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly TerugvorderingService $terugvordering,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether an accountantsverklaring is mandatory for a granted amount.
	 *
	 * @param float $grantedAmount The granted amount.
	 * @param float $threshold The regeling threshold.
	 *
	 * @return bool True when an accountant declaration is required.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function accountantsverklaringVereist(float $grantedAmount, float $threshold): bool {
		return $grantedAmount > $threshold;
	}//end accountantsverklaringVereist()

	/**
	 * Compute the final vaststelling amount: capped at the granted amount,
	 * never above the actual costs, never negative.
	 *
	 * @param float $grantedAmount The granted amount.
	 * @param float $actualCost The total actual costs.
	 *
	 * @return float The final settled amount.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function computeVastgesteldBedrag(float $grantedAmount, float $actualCost): float {
		$amount = min($grantedAmount, $actualCost);
		return round(max(0.0, $amount), 2);
	}//end computeVastgesteldBedrag()

	/**
	 * Compute the overpayment to be reclaimed: positive when the disbursed
	 * advances exceed the final settled amount (REQ-SUB-005).
	 *
	 * @param float $totalAdvances The cumulative disbursed advances.
	 * @param float $determinedAmount The final settled amount.
	 *
	 * @return float The overpayment (0.0 when none).
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function computeOverpayment(float $totalAdvances, float $determinedAmount): float {
		$diff = ($totalAdvances - $determinedAmount);
		if ($diff < 0.01) {
			return 0.0;
		}

		return round($diff, 2);
	}//end computeOverpayment()

	/**
	 * Whether a recovery must be triggered for these figures.
	 *
	 * @param float $totalAdvances The cumulative disbursed advances.
	 * @param float $determinedAmount The final settled amount.
	 *
	 * @return bool True when a clawback is required.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function recoveryTrigger(float $totalAdvances, float $determinedAmount): bool {
		return $this->computeOverpayment(totalAdvances: $totalAdvances, determinedAmount: $determinedAmount) > 0.0;
	}//end recoveryTrigger()

	/**
	 * Finalise a settlement: persist the vastgesteld bedrag and, when the
	 * advances exceed it, open a clawback case for the difference. The
	 * clawback case itself is created in "draft" awaiting manager
	 * approval — this method never publishes it.
	 *
	 * @param string $determinationId The settlement id.
	 * @param float $grantedAmount The granted amount.
	 * @param float $actualCost The total actual costs.
	 * @param float $totalAdvances The cumulative disbursed advances.
	 *
	 * @return array<string, mixed> The finalisation result with optional clawback.
	 *
	 * @throws OCSBadRequestException When OpenRegister is unavailable/unconfigured.
	 *
	 * @spec openspec/specs/subsidie-settlement-case-costs/spec.md
	 */
	public function finalize(
		string $determinationId,
		float $grantedAmount,
		float $actualCost,
		float $totalAdvances,
	): array {
		[$objectService, $register, $schema] = $this->resolve();

		$determined = $this->computeVastgesteldBedrag(grantedAmount: $grantedAmount, actualCost: $actualCost);
		$overpayment = $this->computeOverpayment(totalAdvances: $totalAdvances, determinedAmount: $determined);
		$trigger = ($overpayment > 0.0);

		$patch = [
			'determinedAmount' => $determined,
			'recoveryTrigger' => $trigger,
			'vaststellingsbeschikkingGenerated' => true,
			'status' => 'determined',
		];

		try {
			$current = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $determinationId);
			if ($current === null) {
				throw new OCSBadRequestException('Vaststelling niet gevonden');
			}

			$saved = ($this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				object: $patch,
				uuid: (string)$determinationId
			) ?? array_merge($current, $patch));
		} catch (OCSBadRequestException $e) {
			throw $e;
		} catch (Throwable $e) {
			$this->logger->error('Dossiq subsidie: vaststelling finalize failed: ' . $e->getMessage());
			throw new OCSBadRequestException('Kon vaststelling niet vaststellen');
		}

		$clawback = null;
		$uitvoeringId = (string)($current['subsidieuitvoering'] ?? '');
		if ($trigger === true && $uitvoeringId !== '') {
			$clawback = $this->terugvordering->createClawbackCase(uitvoeringId: $uitvoeringId, amount: $overpayment);
		}

		// The settled amount used to be appended to the linked case's `kosten`
		// array, which fed dossiq's own IV3 report. Both are gone under
		// ADR-081: a domain app MUST NOT hold a ledger-shaped array, and
		// Shillinq is the only general ledger. A disbursed grant is real
		// municipal expenditure and still belongs in the books — it reaches
		// them as a Shillinq cost allocation, not as a field on a case.
		// Until that dispatch exists the amount is recorded on the
		// vaststelling itself (`vastgesteldBedrag`, saved above), which is
		// where it was always authoritative; the `kosten` copy was a
		// denormalisation for a report that no longer exists.
		return [
			'determination' => $saved,
			'terugvordering' => $clawback,
		];
	}//end finalize()

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
		$schema = $this->settingsService->getConfigValue('subsidie_vaststelling_schema');
		if ($register === '' || $schema === '') {
			throw new OCSBadRequestException('Vaststelling-schema is niet geconfigureerd');
		}

		return [$objectService, $register, $schema];
	}//end resolve()
}//end class
