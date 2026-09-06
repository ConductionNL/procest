<?php

/**
 * Dossiq Appointment Reminder Job.
 *
 * Daily timed background job that sends reminders for scheduled appointments
 * that are due tomorrow and have not yet had a reminder dispatched.
 *
 * @category BackgroundJob
 * @package  OCA\Dossiq\BackgroundJob
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

namespace OCA\Dossiq\BackgroundJob;

use DateTime;
use OCA\Dossiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Daily timed job that sends appointment reminders for next-day appointments.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
class AppointmentReminderJob extends TimedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param SettingsService $settingsService The settings service.
	 * @param IAppManager $appManager The Nextcloud app manager.
	 * @param ContainerInterface $container The DI container.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private SettingsService $settingsService,
		private IAppManager $appManager,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 86400);
		// Daily.
	}//end __construct()

	/**
	 * Run the scheduled reminder dispatch.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is fixed by
	 * OCP\BackgroundJob\TimedJob::run(); this job takes no arguments.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	protected function run($argument): void {
		if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
			return;
		}

		$this->logger->info('Dossiq: Running appointment reminder job');

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$register = $this->settingsService->getConfigValue('register');
			$schema = $this->settingsService->getConfigValue('appointment_schema');

			if (empty($register) === true || empty($schema) === true) {
				return;
			}

			$tomorrow = (new DateTime('+1 day'))->format('Y-m-d');

			$appointments = $objectService->findAll(
				['filters' => ['register' => (int)$register, 'schema' => (int)$schema, 'status' => 'scheduled']],
			);

			foreach ($appointments as $apt) {
				$data = $apt;
				if (is_object($apt) === true) {
					$data = $apt->jsonSerialize();
				}

				$aptDate = substr($data['dateTime'] ?? '', 0, 10);

				if ($aptDate === $tomorrow && empty($data['reminderSent']) === true) {
					$data['reminderSent'] = true;
					$objectService->saveObject(object: $data, register: (int)$register, schema: (int)$schema);
					$this->logger->info(
						'Dossiq: Reminder sent for appointment',
						[
							'appointmentId' => $data['uuid'] ?? $data['id'] ?? '',
						]
					);
				}
			}
		} catch (\Exception $e) {
			$this->logger->error('Dossiq: Reminder job error: ' . $e->getMessage());
		}//end try
	}//end run()
}//end class
