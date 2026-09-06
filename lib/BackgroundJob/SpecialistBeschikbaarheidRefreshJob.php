<?php

/**
 * Dossiq Specialist Beschikbaarheid Refresh Job.
 *
 * Periodically refreshes the cached specialist availability records. The
 * authoritative source (pipelinq telephony / HR system) pushes status through
 * the contactmoment API; when that push is stale this job marks records whose
 * laatsteUpdate is older than the configured polling interval as `afwezig` so
 * routing never sends a call to a specialist that has gone silent. The job is
 * resilient: when OpenRegister or the source is unreachable it logs and returns,
 * leaving the existing (stale) cache in place.
 *
 * @category BackgroundJob
 * @package  OCA\Dossiq\BackgroundJob
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
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T16
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Timed job that ages out stale specialist availability records.
 *
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T16
 */
class SpecialistBeschikbaarheidRefreshJob extends TimedJob {
	use SearchesObjects;

	/**
	 * Multiplier applied to the poll interval before a record is deemed stale.
	 */
	private const STALE_FACTOR = 4;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param SettingsService $settingsService The settings service.
	 * @param IAppManager $appManager The app manager.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SettingsService $settingsService,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		// Every 30 seconds (matches specialist_availability_polling_interval default).
		$this->setInterval(seconds: 30);
	}//end __construct()

	/**
	 * Run the availability refresh pass.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md#requirement-specialist-beschikbaarheid-cache-stays-fresh
	 */
	protected function run($argument): void {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('specialist_beschikbaarheid_schema');
		if ($register === '' || $schema === '') {
			return;
		}

		$pollInterval = max(5, (int)$this->settingsService->getKccConfigValue('specialist_availability_polling_interval'));
		$staleSeconds = ($pollInterval * self::STALE_FACTOR);
		$now = time();

		try {
			$records = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: ['_limit' => 500]);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: specialist availability refresh could not read records (keeping cache): ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			return;
		}

		foreach ((array)$records as $record) {
			$this->ageOut(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				record: $this->toArray(result: $record),
				now: $now,
				staleSeconds: $staleSeconds,
			);
		}
	}//end run()

	/**
	 * Mark a single record as afwezig when its last update is stale.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 * @param array<string, mixed> $record The availability record.
	 * @param int $now The current unix timestamp.
	 * @param int $staleSeconds The staleness threshold in seconds.
	 *
	 * @return void
	 */
	private function ageOut($objectService, string $register, string $schema, array $record, int $now, int $staleSeconds): void {
		$status = (string)($record['status'] ?? '');
		if ($status === 'afwezig') {
			return;
		}

		$lastUpdate = strtotime((string)($record['lastUpdate'] ?? ''));
		if ($lastUpdate === false) {
			return;
		}

		if (($now - $lastUpdate) < $staleSeconds) {
			return;
		}

		$id = (string)($record['id'] ?? ($record['uuid'] ?? ''));
		if ($id === '') {
			return;
		}

		try {
			$this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				object: [
					'status' => 'afwezig',
					'lastUpdate' => date('c'),
				],
				uuid: $id,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not age out stale specialist record: ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
		}
	}//end ageOut()

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
