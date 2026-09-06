<?php

/**
 * Dossiq NoticeOfDefaultService.
 *
 * Handles AWB 4:17 ingebrekestelling registration: validates the notice
 * against the lapsed TermijnInstance, sets gevalideerd + geldigheidStatus,
 * and (on first valid notice) auto-creates a DwangsomBerekening with
 * startDatum = ontvangstDatum + 14 days grace.
 *
 * One-dwangsom guard: when TermijnInstance.relevantIngbrekes is already
 * set, subsequent notices are recorded but do NOT spawn a second
 * berekening (REQ-TERM-005).
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-05-ingebrekestelling/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * AWB 4:17 ingebrekestelling registration + DwangsomBerekening creation.
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-05-ingebrekestelling/tasks.md
 */
class NoticeOfDefaultService {
	use SearchesObjects;

	public const TARIFF_AWB_PLAFOND = 144200;
	public const TARIFF_AWB_GRACE = 14;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service.
	 * @param TermijnService $termService TermijnService.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly TermijnService $termService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Register an ingebrekestelling against a TermijnInstance.
	 *
	 * @param string $termInstanceId TermijnInstance id.
	 * @param DateTimeImmutable $receiptDate Receipt date.
	 * @param string $channel Receipt channel.
	 * @param string $documentLink Document link.
	 *
	 * @return array<string, mixed> The ingebrekestelling row (with possibly null/created berekening).
	 *
	 * @throws RuntimeException When the instance is missing.
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-05-ingebrekestelling/tasks.md
	 */
	public function registerNoticeOfDefault(
		string $termInstanceId,
		DateTimeImmutable $receiptDate,
		string $channel,
		string $documentLink = '',
	): array {
		$instance = $this->termService->getTermijnInstance($termInstanceId);
		if ($instance === null) {
			throw new RuntimeException('TermijnInstance not found: ' . $termInstanceId);
		}

		$status = (string)($instance['status'] ?? '');
		$deadline = (string)($instance['endDateCurrent'] ?? '');
		$receipt = $receiptDate->format('Y-m-d');

		$isValid = ($status === 'exceeded' && $deadline !== '' && $deadline < $receipt);

		$row = [
			'deadlineInstance' => $termInstanceId,
			'receiptDate' => $receipt,
			'notificationChannel' => $channel,
			'gevalideerd' => $isValid,
			'documentLink' => $documentLink,
		];

		$row['validityStatus'] = 'premaat';
		if ($isValid === true) {
			$row['validityStatus'] = 'valid';
		}

		$saved = $this->saveSchema(schemaConfigKey: 'ingebrekestelling_schema', object: $row);
		$row['id'] = (string)($saved['id'] ?? '');

		if ($isValid === false) {
			$this->logger->info(
				'Premature ingebrekestelling rejected',
				['deadlineInstance' => $termInstanceId, 'receiptDate' => $receipt]
			);
			return $row;
		}

		// One-dwangsom guard: if an earlier valid notice already exists,
		// record the receipt but do NOT spawn a second berekening.
		$existing = (string)($instance['relevantIngbrekes'] ?? '');
		if ($existing !== '') {
			$this->logger->info(
				'Additional ingebrekestelling recorded; first remains the dwangsom basis',
				['deadlineInstance' => $termInstanceId, 'firstNotice' => $existing]
			);
			return $row;
		}

		// First valid notice: link it and start a DwangsomBerekening.
		$row['penaltyPaymentCalculation'] = $this->startPenaltyPaymentCalculation(
			termInstanceId: $termInstanceId,
			instance: $instance,
			ingebrekestellingId: (string)$row['id'],
			receiptDate: $receiptDate,
			channel: $channel,
			documentLink: $documentLink,
		);

		return $row;
	}//end registerNoticeOfDefault()

	/**
	 * Link the first valid notice to its instance and open the DwangsomBerekening.
	 *
	 * @param string $termInstanceId TermijnInstance id.
	 * @param array<string, mixed> $instance TermijnInstance row.
	 * @param string $ingebrekestellingId Id of the saved ingebrekestelling.
	 * @param DateTimeImmutable $receiptDate Receipt date.
	 * @param string $channel Receipt channel.
	 * @param string $documentLink Document link.
	 *
	 * @return array<string, mixed> The created DwangsomBerekening row.
	 */
	private function startPenaltyPaymentCalculation(
		string $termInstanceId,
		array $instance,
		string $ingebrekestellingId,
		DateTimeImmutable $receiptDate,
		string $channel,
		string $documentLink,
	): array {
		$this->termService->updateTermijnInstance(
			$termInstanceId,
			['relevantIngbrekes' => $ingebrekestellingId]
		);

		$regime = $this->resolveRegime(instance: $instance);
		$startAt = $receiptDate->modify('+' . ((int)$regime['grace']) . ' days')->format('Y-m-d');

		$regimeLabel = 'awb-default';
		if ($regime['custom'] === true) {
			$regimeLabel = 'afwijkend';
		}

		$calculation = $this->saveSchema(
			schemaConfigKey: 'dwangsom_berekening_schema',
			object: [
				'noticeOfDefault' => $ingebrekestellingId,
				'deadlineInstance' => $termInstanceId,
				'startDate' => $startAt,
				'currentDag' => 0,
				'dailyRate' => 0,
				'cumulativeAmount' => 0,
				'plafondCalculated' => (int)$regime['plafond'],
				'plafondBereikt' => false,
				'status' => 'lopend',
				'regime' => $regimeLabel,
			]
		);

		$this->termService->recordEvent(
			termInstanceId: $termInstanceId,
			type: 'ingebrekestelling-received',
			basis: 'AWB 4:17',
			rationale: 'Ingebrekestelling ontvangen via ' . $channel,
			daysImpact: 0,
			moment: $receiptDate,
			documentLink: $documentLink,
		);

		$this->termService->recordEvent(
			termInstanceId: $termInstanceId,
			type: 'penaltypayment-gestart',
			basis: 'AWB 4:17',
			rationale: 'Dwangsom-berekening gestart na grace period',
			daysImpact: 0,
			moment: $receiptDate,
		);

		return $calculation;
	}//end startDwangsomBerekening()

	/**
	 * Resolve the dwangsom regime (AWB-default or custom from definition).
	 *
	 * @param array<string, mixed> $instance TermijnInstance row.
	 *
	 * @return array{plafond:int,grace:int,custom:bool,dailyTariff?:int}
	 */
	private function resolveRegime(array $instance): array {
		$defId = (string)($instance['deadlineDefinition'] ?? '');
		if ($defId === '') {
			return ['plafond' => self::TARIFF_AWB_PLAFOND, 'grace' => self::TARIFF_AWB_GRACE, 'custom' => false];
		}

		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('termijn_definitie_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return ['plafond' => self::TARIFF_AWB_PLAFOND, 'grace' => self::TARIFF_AWB_GRACE, 'custom' => false];
		}

		try {
			$def = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $defId);
		} catch (\Throwable $e) {
			return ['plafond' => self::TARIFF_AWB_PLAFOND, 'grace' => self::TARIFF_AWB_GRACE, 'custom' => false];
		}

		if ($def === null) {
			return ['plafond' => self::TARIFF_AWB_PLAFOND, 'grace' => self::TARIFF_AWB_GRACE, 'custom' => false];
		}

		$regime = $def['deviatingPenaltyPaymentRegime'] ?? null;
		if (is_array($regime) === false) {
			return ['plafond' => self::TARIFF_AWB_PLAFOND, 'grace' => self::TARIFF_AWB_GRACE, 'custom' => false];
		}

		return [
			'plafond' => (int)($regime['plafond'] ?? self::TARIFF_AWB_PLAFOND),
			'grace' => (int)($regime['grace'] ?? self::TARIFF_AWB_GRACE),
			'dailyTariff' => (int)($regime['dailyTariff'] ?? 0),
			'custom' => true,
		];
	}//end resolveRegime()

	/**
	 * Save to a configured schema.
	 *
	 * @param string $schemaConfigKey Config key.
	 * @param array<string, mixed> $object Payload.
	 *
	 * @return array<string, mixed>
	 */
	private function saveSchema(string $schemaConfigKey, array $object): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue($schemaConfigKey);
		if ($objectService === null || $register === '' || $schema === '') {
			return $object;
		}

		try {
			$saved = $this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				object: $object
			);
			return ($saved ?? $object);
		} catch (\Throwable $e) {
			$this->logger->error(
				'NoticeOfDefaultService persist failed',
				['schemaConfigKey' => $schemaConfigKey, 'error' => $e->getMessage()]
			);
			return $object;
		}
	}//end saveSchema()
}//end class
