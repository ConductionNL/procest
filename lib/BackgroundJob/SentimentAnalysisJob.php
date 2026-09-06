<?php

/**
 * Dossiq KCC Sentiment Analysis Job.
 *
 * Periodically scores unanalysed contactmomenten (those with a transcription
 * but no sentiment record yet), persists a klantSentiment object, and appends a
 * sentiment activity to each related case when escalation is recommended. The
 * job is resilient: a failure on one record is logged and the job continues.
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
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T15
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\ContactMomentService;
use OCA\Dossiq\Service\SentimentService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Timed job that scores contactmoment transcriptions for sentiment.
 *
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T15
 */
class SentimentAnalysisJob extends TimedJob {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param SettingsService $settingsService The settings service.
	 * @param SentimentService $sentimentService The sentiment service.
	 * @param ContactMomentService $contactMomentService The contactmoment service.
	 * @param IAppManager $appManager The app manager.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SettingsService $settingsService,
		private readonly SentimentService $sentimentService,
		private readonly ContactMomentService $contactMomentService,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		// Every 10 minutes.
		$this->setInterval(seconds: 600);
	}//end __construct()

	/**
	 * Run the sentiment analysis pass.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md#requirement-realtime-sentiment-detectie-en-escalatie-aanbeveling
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
		$contactmomentSchema = $this->settingsService->getConfigValue('contactmoment_schema');
		$sentimentSchema = $this->settingsService->getConfigValue('klant_sentiment_schema');
		if ($register === '' || $contactmomentSchema === '' || $sentimentSchema === '') {
			return;
		}

		$triggerWords = $this->triggerWords();

		try {
			$contacts = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $contactmomentSchema,
				filters: ['_limit' => 200],
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: sentiment job could not load contactmomenten: ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			return;
		}

		foreach ((array)$contacts as $contact) {
			try {
				$this->processContact(
					objectService: $objectService,
					register: $register,
					sentimentSchema: $sentimentSchema,
					contact: $this->toArray(result: $contact),
					triggerWords: $triggerWords,
				);
			} catch (Throwable $e) {
				$this->logger->error(
					'Dossiq: sentiment scoring failed for a contactmoment: ' . $e->getMessage(),
					['app' => Application::APP_ID],
				);
			}
		}
	}//end run()

	/**
	 * Score a single contactmoment and persist its sentiment.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register The register id.
	 * @param string $sentimentSchema The sentiment schema id.
	 * @param array<string, mixed> $contact The contactmoment record.
	 * @param array<int, string> $triggerWords The configured trigger words.
	 *
	 * @return void
	 */
	private function processContact($objectService, string $register, string $sentimentSchema, array $contact, array $triggerWords): void {
		$transcript = trim((string)($contact['transcript'] ?? ''));
		if ($transcript === '') {
			return;
		}

		$contactId = (string)($contact['id'] ?? ($contact['uuid'] ?? ''));
		if ($contactId === '') {
			return;
		}

		// Skip when a sentiment record already exists for this contact.
		$existing = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $sentimentSchema,
			filters: ['interactionId' => $contactId, '_limit' => 1],
		);
		if (empty((array)$existing) === false) {
			return;
		}

		$analysis = $this->sentimentService->analyzeSentiment($transcript, $triggerWords);

		$this->saveObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $sentimentSchema,
			object: [
				'interactionId' => $contactId,
				'sentimentScore' => $analysis['score'],
				'sentimentLabel' => $analysis['label'],
				'triggerWoorden' => $analysis['triggers'],
				'transcriptSnippet' => $analysis['snippet'],
				'escalationRecommended' => $analysis['escalationRecommended'],
				'escalationLevel' => $analysis['escalationLevel'],
				'createdAt' => date('c'),
			],
		);

		if ($analysis['escalationRecommended'] === true) {
			foreach ((array)($contact['relatedCases'] ?? []) as $caseId) {
				$this->contactMomentService->recordActivity(
					(string)$caseId,
					$contactId,
					'sentiment_detected',
					'systeem',
					'Sentiment ' . $analysis['label'] . ' (escalatie: ' . $analysis['escalationLevel'] . ')',
				);
			}
		}
	}//end processContact()

	/**
	 * Resolve the configured trigger words.
	 *
	 * @return array<int, string> The trigger words.
	 */
	private function triggerWords(): array {
		$decoded = json_decode($this->settingsService->getKccConfigValue('sentiment_trigger_words'), true);
		if (is_array($decoded) === true) {
			return array_map('strval', $decoded);
		}

		return [];
	}//end triggerWords()

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
