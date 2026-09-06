<?php

/**
 * Dossiq TranscriptionService
 *
 * Manages voice-memo transcription for the mobiel-inspectie-offline PWA.
 * Voice memos are captured client-side and uploaded as FieldEvidence
 * objects with transcriptionStatus=pending; this service queues them for
 * transcription, polls completion, and persists the resulting text back
 * to the evidence record.
 *
 * The actual LLM call (qwen-3.5 via openconnector) is left abstract: the
 * service uses a `TranscriberInterface` injectable so the test suite can
 * exercise the queue/persist logic without a live LLM endpoint. Production
 * wiring binds the OpenConnector-backed implementation; tests bind a
 * deterministic stub.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#Task-9
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use InvalidArgumentException;
use OCA\Dossiq\AppInfo\Application;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Transcription orchestrator for voice-memo FieldEvidence records.
 *
 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#Task-9
 */
class TranscriptionService {
	/**
	 * Recognised transcriptionStatus values.
	 */
	public const STATUS_PENDING = 'pending';
	public const STATUS_QUEUED = 'queued';
	public const STATUS_RUNNING = 'running';
	public const STATUS_DONE = 'done';
	public const STATUS_FAILED = 'failed';
	public const STATUS_FALLBACK = 'manual';

	/**
	 * Maximum allowed voice-memo duration in seconds (spec: 5 min).
	 */
	public const MAX_DURATION_SECONDS = 300;

	/**
	 * Maximum retries before falling back to manual transcription.
	 */
	public const MAX_RETRIES = 3;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings + register/schema resolver.
	 * @param LoggerInterface $logger Logger.
	 * @param TranscriberInterface|null $transcriber Optional concrete transcriber; pass null
	 *                                               to defer to a manual transcription flow.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ?TranscriberInterface $transcriber = null,
	) {
	}//end __construct()

	/**
	 * Queue a FieldEvidence voice-memo for transcription.
	 *
	 * Idempotent: re-queueing a record already in queued/running/done state
	 * is a no-op and returns the existing record.
	 *
	 * @param array<string, mixed> $evidence The FieldEvidence record (must be a voice_memo).
	 *
	 * @return array<string, mixed> The updated record with transcriptionStatus.
	 *
	 * @throws \InvalidArgumentException When the record is not a voice memo.
	 *
	 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#Task-9
	 */
	public function queue(array $evidence): array {
		if (($evidence['type'] ?? null) !== 'voice_memo') {
			throw new InvalidArgumentException('Only voice_memo evidence can be queued for transcription');
		}

		$durationSec = (int)($evidence['durationSeconds'] ?? 0);
		if ($durationSec > self::MAX_DURATION_SECONDS) {
			throw new InvalidArgumentException(
				sprintf(
					'Voice memo too long: %ds > max %ds',
					$durationSec,
					self::MAX_DURATION_SECONDS
				)
			);
		}

		$current = (string)($evidence['transcriptionStatus'] ?? self::STATUS_PENDING);
		if (in_array($current, [self::STATUS_QUEUED, self::STATUS_RUNNING, self::STATUS_DONE], true) === true) {
			return $evidence;
		}

		$evidence['transcriptionStatus'] = self::STATUS_QUEUED;
		$evidence['transcriptionQueuedAt'] = date(format: 'c');
		$evidence['transcriptionAttempts'] = (int)($evidence['transcriptionAttempts'] ?? 0);

		return $this->persist(evidence: $evidence);
	}//end queue()

	/**
	 * Run transcription on a queued evidence record. Returns the updated
	 * record. Errors are logged and the record is left in queued state with
	 * incremented attempts; after MAX_RETRIES the record falls back to
	 * manual transcription status.
	 *
	 * @param array<string, mixed> $evidence A queued FieldEvidence record.
	 *
	 * @return array<string, mixed> The updated record.
	 *
	 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#Task-9
	 */
	public function process(array $evidence): array {
		if (($evidence['transcriptionStatus'] ?? '') !== self::STATUS_QUEUED) {
			// Idempotent: nothing to do if not queued.
			return $evidence;
		}

		if ($this->transcriber === null) {
			$evidence['transcriptionStatus'] = self::STATUS_FALLBACK;
			$evidence['transcriptionNote'] = 'No transcriber configured; manual transcription required.';
			return $this->persist(evidence: $evidence);
		}

		$evidence['transcriptionStatus'] = self::STATUS_RUNNING;
		$evidence['transcriptionAttempts'] = ((int)($evidence['transcriptionAttempts'] ?? 0)) + 1;

		try {
			$text = $this->transcriber->transcribe(
				blobRef: (string)($evidence['localBlobRef'] ?? ''),
				language: (string)($evidence['language'] ?? 'nl')
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'TranscriptionService::process transcription failed: ' . $e->getMessage(),
				['app' => Application::APP_ID, 'evidenceId' => (string)($evidence['id'] ?? '')]
			);
			if ($evidence['transcriptionAttempts'] >= self::MAX_RETRIES) {
				$evidence['transcriptionStatus'] = self::STATUS_FALLBACK;
				$evidence['transcriptionNote'] = 'Auto-transcription failed after '
					. self::MAX_RETRIES
					. ' attempts; manual transcription required.';

				return $this->persist(evidence: $evidence);
			}

			$evidence['transcriptionStatus'] = self::STATUS_QUEUED;
			$evidence['transcriptionLastError'] = $e->getMessage();

			return $this->persist(evidence: $evidence);
		}//end try

		$evidence['transcription'] = $text;
		$evidence['transcriptionStatus'] = self::STATUS_DONE;
		$evidence['transcriptionCompletedAt'] = date(format: 'c');

		return $this->persist(evidence: $evidence);
	}//end process()

	/**
	 * Mark a record as manually transcribed (operator-supplied text).
	 *
	 * @param array<string, mixed> $evidence The evidence record.
	 * @param string $text The manual transcription.
	 *
	 * @return array<string, mixed> The updated record.
	 *
	 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#Task-9
	 */
	public function manualTranscribe(array $evidence, string $text): array {
		$evidence['transcription'] = $text;
		$evidence['transcriptionStatus'] = self::STATUS_DONE;
		$evidence['transcriptionNote'] = 'Manual transcription.';
		$evidence['transcriptionCompletedAt'] = date(format: 'c');
		return $this->persist(evidence: $evidence);
	}//end manualTranscribe()

	/**
	 * Persist an evidence record back through OpenRegister.
	 *
	 * Returns the in-memory record unchanged when OR is unavailable (test
	 * harness friendly).
	 *
	 * @param array<string, mixed> $evidence The evidence record.
	 *
	 * @return array<string, mixed> The persisted record.
	 */
	private function persist(array $evidence): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return $evidence;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('field_evidence_schema');
		if ($register === '' || $schema === '') {
			return $evidence;
		}

		try {
			$objectService->saveObject(
				object: $evidence,
				register: $register,
				schema: $schema,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'TranscriptionService::persist failed: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
		}

		return $evidence;
	}//end persist()
}//end class
