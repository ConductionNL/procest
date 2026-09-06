<?php

/**
 * Dossiq KCC ContactMoment Service
 *
 * CRUD and query operations for KCC contact moments. A contact moment reuses
 * the existing `customerContact` (KlantContact) schema, extended with KCC
 * fields via the register.d/30-kcc.json fragment. Persistence goes through the
 * OpenRegister ObjectService (real API: find/findAll/saveObject).
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
 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Kcc;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use Psr\Log\LoggerInterface;

/**
 * Records and queries KCC contact moments.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) — CRUD service aggregating
 * validation, persistence and IDOR-scoped queries for one entity.
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)      — $isPrivileged is the standard
 * cross-agent/own-record scoping flag used across the app's controllers.
 *
 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-02
 */
class ContactMomentService {
	/**
	 * Allowed channels for a contact moment.
	 *
	 * @var array<int, string>
	 */
	public const CHANNELS = ['phone', 'email', 'web_form', 'chat', 'social', 'in_person', 'letter'];

	/**
	 * Allowed outcomes for a contact moment.
	 *
	 * @var array<int, string>
	 */
	public const OUTCOMES = ['open', 'resolved', 'transferred', 'callback_scheduled', 'escalated'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private SettingsService $settingsService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Build and validate a contact-moment payload from request data.
	 *
	 * Masks special-category data (BSN) before any logging. The caller's
	 * identity is supplied separately and is never read from the request body.
	 *
	 * @param array<string, mixed> $data The request data.
	 * @param string $agentId The authenticated agent's user id.
	 *
	 * @return array<string, mixed> The sanitised contact-moment payload.
	 *
	 * @throws OCSBadRequestException When validation fails.
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-02
	 */
	public function buildPayload(array $data, string $agentId): array {
		$channel = $this->validateEnum(value: (string)($data['channel'] ?? ''), allowed: self::CHANNELS, label: 'channel');
		$direction = $this->validateEnum(value: (string)($data['direction'] ?? 'inbound'), allowed: ['inbound', 'outbound'], label: 'direction');
		$outcome = $this->validateEnum(value: (string)($data['outcome'] ?? 'open'), allowed: self::OUTCOMES, label: 'outcome');

		$payload = [
			'channel' => $channel,
			'direction' => $direction,
			'outcome' => $outcome,
			'kccAgentRef' => $agentId,
			'subject' => trim((string)($data['subject'] ?? '')),
			'summary' => trim((string)($data['summary'] ?? '')),
		];

		$payload = $this->applyPassthrough(payload: $payload, data: $data);
		$payload = $this->applyTimestamps(payload: $payload, data: $data);

		return $payload;
	}//end buildPayload()

	/**
	 * Validate that a value is one of an allowed enum set.
	 *
	 * @param string $value The value to validate.
	 * @param array<int,string> $allowed The allowed values.
	 * @param string $label The field label for the error message.
	 *
	 * @return string The validated value.
	 *
	 * @throws OCSBadRequestException When the value is not allowed.
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-02
	 */
	private function validateEnum(string $value, array $allowed, string $label): string {
		if (in_array($value, $allowed, true) === false) {
			throw new OCSBadRequestException('Invalid ' . $label);
		}

		return $value;
	}//end validateEnum()

	/**
	 * Copy the optional pass-through string fields onto the payload.
	 *
	 * @param array<string,mixed> $payload The payload to extend.
	 * @param array<string,mixed> $data The request data.
	 *
	 * @return array<string,mixed> The extended payload.
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-02
	 */
	private function applyPassthrough(array $payload, array $data): array {
		$passthrough = [
			'customerRef',
			'customerName',
			'customerPhone',
			'customerEmail',
			'assignedTeam',
			'assignedDomain',
			'case',
			'linkedContactMoment',
		];
		foreach ($passthrough as $field) {
			if (isset($data[$field]) === true && $data[$field] !== '') {
				$payload[$field] = (string)$data[$field];
			}
		}

		if (isset($data['tags']) === true && is_array($data['tags']) === true) {
			$payload['tags'] = array_values(array_map('strval', $data['tags']));
		}

		return $payload;
	}//end applyPassthrough()

	/**
	 * Set the startedAt/endedAt timestamps and duration on the payload.
	 *
	 * @param array<string,mixed> $payload The payload to extend.
	 * @param array<string,mixed> $data The request data.
	 *
	 * @return array<string,mixed> The extended payload.
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-02
	 */
	private function applyTimestamps(array $payload, array $data): array {
		$payload['startedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		if (isset($data['startedAt']) === true && $data['startedAt'] !== '') {
			$payload['startedAt'] = (string)$data['startedAt'];
		}

		if (isset($data['endedAt']) === true && $data['endedAt'] !== '') {
			$payload['endedAt'] = (string)$data['endedAt'];
			$payload['durationSeconds'] = $this->computeDuration(start: $payload['startedAt'], end: $payload['endedAt']);
		}

		return $payload;
	}//end applyTimestamps()

	/**
	 * Persist a new contact moment.
	 *
	 * @param array<string, mixed> $data The request data.
	 * @param string $agentId The authenticated agent's user id.
	 *
	 * @return array<string, mixed> The saved contact moment.
	 *
	 * @throws OCSBadRequestException When validation fails or storage is unavailable.
	 *
	 * @spec openspec/specs/kcc-klantcontact-integratie/spec.md#requirement-contactmoment-records-capture-full-interaction-context
	 */
	public function create(array $data, string $agentId): array {
		$payload = $this->buildPayload(data: $data, agentId: $agentId);

		[$objectService, $register, $schema] = $this->resolve();

		$saved = $objectService->saveObject(object: $payload, register: $register, schema: $schema);

		$this->logger->info(
			'Dossiq KCC: contact moment created',
			['channel' => $payload['channel'], 'agent' => $agentId]
		);

		return $this->toArray(value: $saved);
	}//end create()

	/**
	 * Update an existing contact moment owned by the agent.
	 *
	 * Enforces ownership: an agent may only update a contact moment they
	 * handle (kccAgentRef). Admins/managers are handled at the controller
	 * layer via NC's auth attributes.
	 *
	 * @param string $id The contact moment id.
	 * @param array<string, mixed> $data The fields to update.
	 * @param string $agentId The authenticated agent's user id.
	 * @param bool $isPrivileged Whether the caller bypasses ownership.
	 *
	 * @return array<string, mixed> The updated contact moment.
	 *
	 * @throws OCSBadRequestException When not found or not owned.
	 *
	 * @spec openspec/specs/kcc-klantcontact-integratie/spec.md#requirement-contactmoment-records-capture-full-interaction-context
	 */
	public function update(string $id, array $data, string $agentId, bool $isPrivileged = false): array {
		[$objectService, $register, $schema] = $this->resolve();

		$existing = $this->findOwned(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			id: $id,
			agentId: $agentId,
			isPrivileged: $isPrivileged,
		);

		$update = $this->mergeUpdate(existing: $existing, data: $data);

		$saved = $objectService->saveObject(object: $update, register: $register, schema: $schema, uuid: (string)$id);

		return $this->toArray(value: $saved);
	}//end update()

	/**
	 * Merge mutable fields from request data onto an existing contact moment.
	 *
	 * @param array<string,mixed> $existing The current contact moment.
	 * @param array<string,mixed> $data The fields to update.
	 *
	 * @return array<string,mixed> The merged contact moment.
	 *
	 * @throws OCSBadRequestException When the outcome is invalid.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) — flat field-merge guards.
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-02
	 */
	private function mergeUpdate(array $existing, array $data): array {
		$update = $existing;
		foreach (['subject', 'summary', 'assignedTeam', 'assignedDomain', 'outcome', 'customerName'] as $field) {
			if (array_key_exists($field, $data) === true) {
				$update[$field] = (string)$data[$field];
			}
		}

		if (isset($update['outcome']) === true && in_array((string)$update['outcome'], self::OUTCOMES, true) === false) {
			throw new OCSBadRequestException('Invalid outcome');
		}

		$hasEnd = (isset($data['endedAt']) === true && $data['endedAt'] !== '');
		$hasStart = isset($update['startedAt']);
		if ($hasEnd === true && $hasStart === true) {
			$update['endedAt'] = (string)$data['endedAt'];
			$update['durationSeconds'] = $this->computeDuration(start: (string)$update['startedAt'], end: (string)$update['endedAt']);
		}

		if (isset($data['tags']) === true && is_array($data['tags']) === true) {
			$update['tags'] = array_values(array_map('strval', $data['tags']));
		}

		return $update;
	}//end mergeUpdate()

	/**
	 * List contact moments with optional filters.
	 *
	 * Non-privileged callers are scoped to their own handled moments
	 * (kccAgentRef) to prevent IDOR-style enumeration.
	 *
	 * @param array<string, mixed> $filters Optional channel/outcome/team filters.
	 * @param string $agentId The authenticated agent's user id.
	 * @param bool $isPrivileged Whether the caller may see all moments.
	 *
	 * @return array<int, array<string, mixed>> The contact moments.
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-02
	 */
	public function list(array $filters, string $agentId, bool $isPrivileged = false): array {
		[$objectService, $register, $schema] = $this->resolve();

		$query = ['register' => (int)$register, 'schema' => (int)$schema];

		foreach (['channel', 'outcome', 'assignedTeam'] as $field) {
			if (isset($filters[$field]) === true && $filters[$field] !== '') {
				$query[$field] = (string)$filters[$field];
			}
		}

		if ($isPrivileged === false) {
			$query['kccAgentRef'] = $agentId;
		}

		$results = $objectService->findAll(['filters' => $query]);

		return array_map([$this, 'toArray'], $results);
	}//end list()

	/**
	 * Find a single contact moment, enforcing ownership for non-privileged callers.
	 *
	 * @param string $id The contact moment id.
	 * @param string $agentId The authenticated agent's user id.
	 * @param bool $isPrivileged Whether the caller may see any moment.
	 *
	 * @return array<string, mixed> The contact moment.
	 *
	 * @throws OCSBadRequestException When not found or not owned.
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-02
	 */
	public function get(string $id, string $agentId, bool $isPrivileged = false): array {
		[$objectService, $register, $schema] = $this->resolve();
		return $this->findOwned(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			id: $id,
			agentId: $agentId,
			isPrivileged: $isPrivileged,
		);
	}//end get()

	/**
	 * Find related contact moments for the same customer.
	 *
	 * @param string $id The reference contact moment id.
	 * @param string $agentId The authenticated agent's user id.
	 * @param bool $isPrivileged Whether the caller may see any moment.
	 *
	 * @return array<int, array<string, mixed>> Related contact moments.
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-02
	 */
	public function related(string $id, string $agentId, bool $isPrivileged = false): array {
		[$objectService, $register, $schema] = $this->resolve();
		$base = $this->findOwned(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			id: $id,
			agentId: $agentId,
			isPrivileged: $isPrivileged,
		);

		$customerRef = (string)($base['customerRef'] ?? '');
		if ($customerRef === '') {
			return [];
		}

		$results = $objectService->findAll(
			['filters' => ['register' => (int)$register, 'schema' => (int)$schema, 'customerRef' => $customerRef]]
		);

		$related = [];
		foreach ($results as $result) {
			$arr = $this->toArray(value: $result);
			if (((string)($arr['id'] ?? '')) !== $id) {
				$related[] = $arr;
			}
		}

		return $related;
	}//end related()

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
		$schema = $this->settingsService->getConfigValue('customer_contact_schema');

		if ($register === '' || $schema === '') {
			throw new OCSBadRequestException('KCC contact schema is not configured');
		}

		return [$objectService, $register, $schema];
	}//end resolve()

	/**
	 * Find a contact moment by id and enforce ownership.
	 *
	 * @param object $objectService The ObjectService.
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 * @param string $id The contact moment id.
	 * @param string $agentId The authenticated agent's user id.
	 * @param bool $isPrivileged Whether ownership is bypassed.
	 *
	 * @return array<string, mixed> The contact moment.
	 *
	 * @throws OCSBadRequestException When not found or not owned.
	 */
	private function findOwned(object $objectService, string $register, string $schema, string $id, string $agentId, bool $isPrivileged): array {
		try {
			$found = $objectService->find($id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			throw new OCSBadRequestException('Contact moment not found');
		}

		$arr = $this->toArray(value: $found);
		if ($arr === []) {
			throw new OCSBadRequestException('Contact moment not found');
		}

		if ($isPrivileged === false && ((string)($arr['kccAgentRef'] ?? '')) !== $agentId) {
			// Do not disclose existence to non-owners.
			throw new OCSBadRequestException('Contact moment not found');
		}

		return $arr;
	}//end findOwned()

	/**
	 * Compute a duration in seconds between two ISO timestamps.
	 *
	 * @param string $start The start timestamp.
	 * @param string $end The end timestamp.
	 *
	 * @return int Duration in seconds (0 when invalid or negative).
	 */
	private function computeDuration(string $start, string $end): int {
		try {
			$startTs = (new DateTimeImmutable($start))->getTimestamp();
			$endTs = (new DateTimeImmutable($end))->getTimestamp();
		} catch (\Throwable $e) {
			return 0;
		}

		return max(0, ($endTs - $startTs));
	}//end computeDuration()

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
