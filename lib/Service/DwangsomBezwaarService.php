<?php

/**
 * Dossiq DwangsomBezwaarService.
 *
 * Handles bezwaar (AWB 7:1) against a DwangsomBerekening:
 *   - registerBezwaar freezes the berekening (status=bezwaar-bevroren),
 *     sets the linked DwangsomUitbetaling to on-hold-bezwaar, and
 *     emits dwangsom-bezwaar-registered.
 *   - resolveBezwaar adjusts definitievBedrag + Uitbetaling.bedrag,
 *     restores Uitbetaling.status to voorbereid, and emits
 *     dwangsom-bezwaar-resolved.
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Bezwaar lifecycle for a DwangsomBerekening.
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
 */
class DwangsomBezwaarService {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings.
	 * @param TermijnService $termService Termijn service for events.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly TermijnService $termService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Register a bezwaar against a DwangsomBerekening.
	 *
	 * Freezes the berekening (status=bezwaar-bevroren) and puts the
	 * linked uitbetaling on hold.
	 *
	 * @param string $calculationId DwangsomBerekening id.
	 * @param string $basis Legal basis citation.
	 * @param string $rationale Reasoning.
	 *
	 * @return array<string, mixed> The frozen berekening row.
	 *
	 * @throws RuntimeException When the berekening is missing.
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	public function registerBezwaar(string $calculationId, string $basis, string $rationale): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$bSchema = (string)$this->settingsService->getConfigValue('dwangsom_berekening_schema');
		$uSchema = (string)$this->settingsService->getConfigValue('dwangsom_uitbetaling_schema');
		$objectService = $this->requirePenaltyPaymentObjectService(
			objectService: $objectService,
			register: $register,
			bSchema: $bSchema,
			uSchema: $uSchema,
		);

		try {
			$calculation = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $bSchema,
				id: $calculationId
			);
		} catch (\Throwable $e) {
			throw new RuntimeException('DwangsomBerekening lookup failed: ' . $e->getMessage());
		}

		if ($calculation === null) {
			throw new RuntimeException('DwangsomBerekening not found: ' . $calculationId);
		}

		$calculation['status'] = 'objection-bevroren';
		try {
			$calculation = ($this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $bSchema,
				object: $calculation
			) ?? $calculation);
		} catch (\Throwable $e) {
			throw new RuntimeException('DwangsomBerekening persist failed: ' . $e->getMessage());
		}

		// Move all linked uitbetalingen to on-hold-bezwaar.
		$uitbetalingen = $this->findUitbetalingen(
			objectService: $objectService,
			register: $register,
			uSchema: $uSchema,
			calculationId: $calculationId,
		);

		foreach ($uitbetalingen as $u) {
			$u['status'] = 'on-hold-objection';
			try {
				$this->saveObjectAsArray(objectService: $objectService, register: $register, schema: $uSchema, object: $u);
			} catch (\Throwable $e) {
				$this->logger->warning('Bezwaar freeze on uitbetaling failed', ['id' => $u['id'] ?? '', 'error' => $e->getMessage()]);
			}
		}

		// Record event on termijn.
		$instanceId = (string)($calculation['deadlineInstance'] ?? '');
		if ($instanceId !== '') {
			$this->termService->recordEvent(
				termInstanceId: $instanceId,
				type: 'objection-submitted',
				basis: $basis,
				rationale: $rationale,
				daysImpact: 0,
			);
		}

		$this->logger->info('Dwangsom bezwaar registered', ['berekening' => $calculationId]);
		return $calculation;
	}//end registerBezwaar()

	/**
	 * Resolve a bezwaar with a corrected amount.
	 *
	 * @param string $calculationId Berekening id.
	 * @param int $newAmountCents Corrected amount in EUR cents.
	 * @param string $basis Legal basis.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When berekening missing or amount invalid.
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	public function resolveBezwaar(string $calculationId, int $newAmountCents, string $basis): array {
		if ($newAmountCents < 0) {
			throw new RuntimeException('newBedragCents must be >= 0');
		}

		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$bSchema = (string)$this->settingsService->getConfigValue('dwangsom_berekening_schema');
		$uSchema = (string)$this->settingsService->getConfigValue('dwangsom_uitbetaling_schema');
		$objectService = $this->requirePenaltyPaymentObjectService(
			objectService: $objectService,
			register: $register,
			bSchema: $bSchema,
			uSchema: $uSchema,
		);

		try {
			$calculation = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $bSchema,
				id: $calculationId
			);
		} catch (\Throwable $e) {
			throw new RuntimeException('DwangsomBerekening lookup failed: ' . $e->getMessage());
		}

		if ($calculation === null) {
			throw new RuntimeException('DwangsomBerekening not found: ' . $calculationId);
		}

		$calculation['definitiveAmount'] = $newAmountCents;
		$calculation['status'] = 'completed';
		try {
			$calculation = ($this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $bSchema,
				object: $calculation
			) ?? $calculation);
		} catch (\Throwable $e) {
			throw new RuntimeException('DwangsomBerekening persist failed: ' . $e->getMessage());
		}

		$uitbetalingen = $this->findUitbetalingen(
			objectService: $objectService,
			register: $register,
			uSchema: $uSchema,
			calculationId: $calculationId,
		);

		foreach ($uitbetalingen as $u) {
			$u['amount'] = $newAmountCents;
			$u['status'] = 'voorbereid';
			try {
				$this->saveObjectAsArray(objectService: $objectService, register: $register, schema: $uSchema, object: $u);
			} catch (\Throwable $e) {
				$this->logger->warning('Bezwaar resolve on uitbetaling failed', ['id' => $u['id'] ?? '', 'error' => $e->getMessage()]);
			}
		}

		$instanceId = (string)($calculation['deadlineInstance'] ?? '');
		if ($instanceId !== '') {
			$this->termService->recordEvent(
				termInstanceId: $instanceId,
				type: 'objection-resolved',
				basis: $basis,
				rationale: 'Bezwaar opgelost; bedrag herzien',
				daysImpact: 0,
			);
		}

		$this->logger->info('Dwangsom bezwaar resolved', ['berekening' => $calculationId, 'newBedrag' => $newAmountCents]);
		return $calculation;
	}//end resolveBezwaar()

	/**
	 * Assert the dwangsom register/schemas are configured and OpenRegister is
	 * available, narrowing the object service to a non-null value.
	 *
	 * @param object|null $objectService Resolved OpenRegister object service.
	 * @param string $register Register identifier.
	 * @param string $bSchema DwangsomBerekening schema identifier.
	 * @param string $uSchema DwangsomUitbetaling schema identifier.
	 *
	 * @return object The available object service.
	 *
	 * @throws RuntimeException When any part of the configuration is missing.
	 */
	private function requirePenaltyPaymentObjectService(
		?object $objectService,
		string $register,
		string $bSchema,
		string $uSchema,
	): object {
		if ($objectService === null || $register === '' || $bSchema === '' || $uSchema === '') {
			throw new RuntimeException('Dwangsom services not configured');
		}

		return $objectService;
	}//end requireDwangsomObjectService()

	/**
	 * Load the uitbetalingen linked to a berekening, tolerating lookup failures.
	 *
	 * @param object $objectService OpenRegister object service.
	 * @param string $register Register identifier.
	 * @param string $uSchema DwangsomUitbetaling schema identifier.
	 * @param string $calculationId DwangsomBerekening id.
	 *
	 * @return array<int, array<string, mixed>> The linked uitbetalingen.
	 */
	private function findUitbetalingen(
		object $objectService,
		string $register,
		string $uSchema,
		string $calculationId,
	): array {
		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $uSchema,
				filters: ['penaltyPaymentCalculation' => $calculationId]
			);
		} catch (\Throwable $e) {
			// Lookup failures must not block the bezwaar transition.
			return [];
		}
	}//end findUitbetalingen()
}//end class
