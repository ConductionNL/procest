<?php

/**
 * Dossiq KCC Callback Service
 *
 * Scheduling, retry and lifecycle management for KCC callback requests.
 * Retry timing (exponential backoff, max attempts) is delegated to the
 * SlaCalculator. Persistence uses the OpenRegister ObjectService.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Kcc
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
 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-05
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Kcc;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use Psr\Log\LoggerInterface;

/**
 * Manages KCC callback requests.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) — $isPrivileged is the standard
 * cross-agent/own-record scoping flag used across the app's controllers.
 *
 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-05
 */
class CallbackService {
	/**
	 * Maximum number of callback attempts before the request fails.
	 */
	public const MAX_ATTEMPTS = 3;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service.
	 * @param SlaCalculator $slaCalculator The SLA / backoff calculator.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private SettingsService $settingsService,
		private SlaCalculator $slaCalculator,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Build a validated callback payload from request data.
	 *
	 * @param array<string, mixed> $data The request data.
	 * @param string $agentId The authenticated agent's user id.
	 *
	 * @return array<string, mixed> The callback payload.
	 *
	 * @throws OCSBadRequestException When validation fails.
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-05
	 */
	public function buildPayload(array $data, string $agentId): array {
		$phone = trim((string)($data['customerPhone'] ?? ''));
		if ($phone === '') {
			throw new OCSBadRequestException('customerPhone is required');
		}

		$scheduledFor = trim((string)($data['scheduledFor'] ?? ''));
		if ($scheduledFor !== '') {
			try {
				$scheduledFor = (new DateTimeImmutable($scheduledFor))->format(DateTimeInterface::ATOM);
			} catch (\Throwable $e) {
				throw new OCSBadRequestException('Invalid scheduledFor');
			}
		}

		$payload = [
			'customerPhone' => $phone,
			'reason' => trim((string)($data['reason'] ?? '')),
			'status' => 'scheduled',
			'attemptCount' => 0,
			'preferredAgent' => trim((string)($data['preferredAgent'] ?? $agentId)),
		];

		if ($scheduledFor !== '') {
			$payload['scheduledFor'] = $scheduledFor;
		}

		if (isset($data['contactMomentRef']) === true && $data['contactMomentRef'] !== '') {
			$payload['contactMomentRef'] = (string)$data['contactMomentRef'];
		}

		return $payload;
	}//end buildPayload()

	/**
	 * Schedule a new callback request.
	 *
	 * @param array<string, mixed> $data The request data.
	 * @param string $agentId The authenticated agent's user id.
	 *
	 * @return array<string, mixed> The saved callback request.
	 *
	 * @throws OCSBadRequestException When validation fails or storage is unavailable.
	 *
	 * @spec openspec/specs/kcc-klantcontact-integratie/spec.md#requirement-callback-scheduling-and-sla-tracking
	 */
	public function schedule(array $data, string $agentId): array {
		$payload = $this->buildPayload(data: $data, agentId: $agentId);

		[$objectService, $register, $schema] = $this->resolve();
		$saved = $objectService->saveObject(object: $payload, register: $register, schema: $schema);

		$this->logger->info('Dossiq KCC: callback scheduled', ['agent' => $agentId]);

		return $this->toArray(value: $saved);
	}//end schedule()

	/**
	 * Apply the outcome of a callback attempt and compute the next state.
	 *
	 * This is pure state-transition logic over a callback record; callers feed
	 * the resulting record back to {@see persist()}. On a missed attempt the
	 * attempt counter is incremented and a backoff retry time is set, unless
	 * the attempt cap is reached (then status becomes 'failed').
	 *
	 * @param array<string, mixed> $callback The current callback record.
	 * @param bool $succeeded Whether the customer was reached.
	 * @param DateTimeImmutable|null $now Reference time.
	 *
	 * @return array<string, mixed> The updated callback record.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) — $succeeded is the attempt outcome.
	 *
	 * @spec openspec/specs/kcc-klantcontact-integratie/spec.md#requirement-callback-scheduling-and-sla-tracking
	 */
	public function applyAttempt(array $callback, bool $succeeded, ?DateTimeImmutable $now = null): array {
		$now = ($now ?? new DateTimeImmutable());
		$attempts = ((int)($callback['attemptCount'] ?? 0)) + 1;

		$callback['attemptCount'] = $attempts;

		if ($succeeded === true) {
			$callback['status'] = 'completed';
			$callback['nextAttemptAt'] = null;
			return $callback;
		}

		if ($attempts >= self::MAX_ATTEMPTS) {
			$callback['status'] = 'failed';
			$callback['nextAttemptAt'] = null;
			return $callback;
		}

		$callback['status'] = 'attempted';
		$callback['nextAttemptAt'] = $this->slaCalculator
			->nextRetryAt(from: $now, attemptCount: $attempts)
			->format(DateTimeInterface::ATOM);

		return $callback;
	}//end applyAttempt()

	/**
	 * Cancel a callback request owned by the agent.
	 *
	 * @param string $id The callback id.
	 * @param string $agentId The authenticated agent's user id.
	 * @param bool $isPrivileged Whether ownership is bypassed.
	 *
	 * @return array<string, mixed> The cancelled callback record.
	 *
	 * @throws OCSBadRequestException When not found or not owned.
	 *
	 * @spec openspec/specs/kcc-klantcontact-integratie/spec.md#requirement-callback-scheduling-and-sla-tracking
	 */
	public function cancel(string $id, string $agentId, bool $isPrivileged = false): array {
		[$objectService, $register, $schema] = $this->resolve();
		$existing = $this->findOwned(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			id: $id,
			agentId: $agentId,
			isPrivileged: $isPrivileged,
		);

		$existing['status'] = 'cancelled';
		$existing['nextAttemptAt'] = null;

		$saved = $objectService->saveObject(object: $existing, register: $register, schema: $schema, uuid: (string)$id);
		return $this->toArray(value: $saved);
	}//end cancel()

	/**
	 * List callback requests, scoped to the agent unless privileged.
	 *
	 * @param array<string, mixed> $filters Optional status filter.
	 * @param string $agentId The authenticated agent's user id.
	 * @param bool $isPrivileged Whether the caller may see all callbacks.
	 *
	 * @return array<int, array<string, mixed>> The callback requests.
	 *
	 * @spec openspec/specs/kcc-klantcontact-integratie/spec.md#requirement-callback-scheduling-and-sla-tracking
	 */
	public function list(array $filters, string $agentId, bool $isPrivileged = false): array {
		[$objectService, $register, $schema] = $this->resolve();

		$query = ['register' => (int)$register, 'schema' => (int)$schema];
		if (isset($filters['status']) === true && $filters['status'] !== '') {
			$query['status'] = (string)$filters['status'];
		}

		if ($isPrivileged === false) {
			$query['preferredAgent'] = $agentId;
		}

		$results = $objectService->findAll(['filters' => $query]);
		return array_map([$this, 'toArray'], $results);
	}//end list()

	/**
	 * Persist an updated callback record (e.g. after applyAttempt).
	 *
	 * @param string $id The callback id.
	 * @param array<string, mixed> $callback The callback record.
	 *
	 * @return array<string, mixed> The saved record.
	 *
	 * @spec openspec/specs/kcc-klantcontact-integratie/spec.md#requirement-callback-scheduling-and-sla-tracking
	 */
	public function persist(string $id, array $callback): array {
		[$objectService, $register, $schema] = $this->resolve();
		$saved = $objectService->saveObject(object: $callback, register: $register, schema: $schema, uuid: (string)$id);
		return $this->toArray(value: $saved);
	}//end persist()

	/**
	 * Resolve the ObjectService and register/schema identifiers.
	 *
	 * @return array{0: object, 1: string, 2: string} ObjectService, register, schema.
	 *
	 * @throws OCSBadRequestException When OpenRegister is unavailable or unconfigured.
	 */
	private function resolve(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new OCSBadRequestException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('callback_request_schema');

		if ($register === '' || $schema === '') {
			throw new OCSBadRequestException('KCC callback schema is not configured');
		}

		return [$objectService, $register, $schema];
	}//end resolve()

	/**
	 * Find a callback by id and enforce ownership.
	 *
	 * @param object $objectService The ObjectService.
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 * @param string $id The callback id.
	 * @param string $agentId The authenticated agent's user id.
	 * @param bool $isPrivileged Whether ownership is bypassed.
	 *
	 * @return array<string, mixed> The callback record.
	 *
	 * @throws OCSBadRequestException When not found or not owned.
	 */
	private function findOwned(object $objectService, string $register, string $schema, string $id, string $agentId, bool $isPrivileged): array {
		try {
			$found = $objectService->find($id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			throw new OCSBadRequestException('Callback request not found');
		}

		$arr = $this->toArray(value: $found);
		if ($arr === []) {
			throw new OCSBadRequestException('Callback request not found');
		}

		if ($isPrivileged === false && ((string)($arr['preferredAgent'] ?? '')) !== $agentId) {
			throw new OCSBadRequestException('Callback request not found');
		}

		return $arr;
	}//end findOwned()

	/**
	 * Normalise an ObjectService result to a plain array.
	 *
	 * @param mixed $value The value to normalise.
	 *
	 * @return array<string, mixed> The normalised array.
	 */
	private function toArray(mixed $value): array {
		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialized = $value->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}

			return [];
		}

		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end toArray()
}//end class
