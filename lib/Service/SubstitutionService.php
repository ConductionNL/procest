<?php

/**
 * Dossiq SubstitutionService.
 *
 * Vervanging/waarneming (handler absence/substitution) domain logic on top of
 * OpenRegister RBAC. Substitution records describe who covers whom, when, and
 * for what scope; creating one grants nothing by itself. Resolution answers
 * "whose work should this user also see right now?" and is consumed by the My
 * Work query layer and the notification fan-out. All resolved work items are
 * filtered through the substitute's own OpenRegister RBAC effective
 * permissions — the service never elevates.
 *
 * Decision/besluit authority during absence is explicitly out of scope and
 * remains owned by the mandaat-matrix (waarnemer on the decision date); this
 * service handles workload routing/visibility only.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\Substitution\SubstitutedWorkResolver;
use OCA\Dossiq\Service\Substitution\SubstitutionValidator;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Resolves vervanging/waarneming substitutions and the workload they route.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
class SubstitutionService {
	use SearchesObjects;

	/**
	 * Per-request cache of active substitutions keyed by "userId|date".
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $activeCache = [];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings/config + ObjectService bridge.
	 * @param LoggerInterface $logger The logger.
	 * @param SubstitutionValidator $validator Create-input validation + overlap detection.
	 * @param SubstitutedWorkResolver $workResolver Resolver for the work a substitution routes.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly SubstitutionValidator $validator,
		private readonly SubstitutedWorkResolver $workResolver,
	) {
	}//end __construct()

	/**
	 * Create a substitution after validation.
	 *
	 * Rejects self-substitution, missing/invalid period, and a same-period
	 * overlapping full-scope substitution for the same absentee. A disjoint
	 * scope for the same period is accepted.
	 *
	 * @param string $absentee Handler being covered (user id).
	 * @param string $substitute Waarnemer (user id).
	 * @param string $startDate Inclusive start (Y-m-d).
	 * @param string $endDate Inclusive end (Y-m-d), required.
	 * @param string $scope One of all|caseTypes|cases.
	 * @param array<int, string> $scopeRefs caseType/case UUIDs when narrowed.
	 * @param string $reason One of verlof|ziekte|anders.
	 * @param string $createdBy Creating user id (self or coordinator).
	 * @param string $comment Optional free-text comment.
	 *
	 * @return array<string, mixed> The created substitution object.
	 *
	 * @throws \InvalidArgumentException On any validation failure.
	 * @throws \RuntimeException When OpenRegister is unavailable.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function create(
		string $absentee,
		string $substitute,
		string $startDate,
		string $endDate,
		string $scope = 'all',
		array $scopeRefs = [],
		string $reason = 'verlof',
		string $createdBy = '',
		string $comment = '',
	): array {
		$absentee = trim($absentee);
		$substitute = trim($substitute);

		[$start, $end] = $this->validator->validateCreate(
			absentee: $absentee,
			substitute: $substitute,
			startDate: $startDate,
			endDate: $endDate,
			scope: $scope,
			scopeRefs: $scopeRefs,
			reason: $reason
		);

		$this->validator->assertNoOverlappingFullScope(
			absentee: $absentee,
			scope: $scope,
			start: $start,
			end: $end
		);

		$createdByValue = $absentee;
		if ($createdBy !== '') {
			$createdByValue = $createdBy;
		}

		$row = [
			'absentee' => $absentee,
			'substitute' => $substitute,
			'startDate' => $start->format('Y-m-d'),
			'endDate' => $end->format('Y-m-d'),
			'scope' => $scope,
			'scopeRefs' => array_values($scopeRefs),
			'reason' => $reason,
			'comment' => $comment,
			'status' => 'active',
			'createdBy' => $createdByValue,
		];

		[$objectService, $register, $schema] = $this->requireContext();
		$saved = $this->saveObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			object: $row
		);
		$this->activeCache = [];

		return ($saved ?? $row);
	}//end create()

	/**
	 * Revoke a substitution immediately (status -> revoked).
	 *
	 * @param string $id The substitution UUID.
	 *
	 * @return array<string, mixed>|null The updated object, or null when not found.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function revoke(string $id): ?array {
		[$objectService, $register, $schema] = $this->requireContext();
		$existing = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $id);
		if ($existing === null) {
			return null;
		}

		$existing['status'] = 'revoked';
		$saved = $objectService->updateObject($register, $schema, $id, $existing);
		$this->activeCache = [];

		if (is_array($saved) === true) {
			return $saved;
		}

		return $existing;
	}//end revoke()

	/**
	 * Resolve the substitutions that are active for a substitute on a date.
	 *
	 * A substitution is active when status == active AND start <= date <= end.
	 * Records whose endDate has passed are lazily marked `ended` (best-effort
	 * persistence) and excluded. Results are cached per request.
	 *
	 * @param string $userId The waarnemer (substitute) user id.
	 * @param DateTimeImmutable|null $date Reference date; defaults to today.
	 *
	 * @return array<int, array<string, mixed>> The active substitution records.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function getActiveSubstitutionsFor(string $userId, ?DateTimeImmutable $date = null): array {
		$userId = trim($userId);
		if ($userId === '') {
			return [];
		}

		$ref = ($date ?? new DateTimeImmutable('today'));
		$refDay = $ref->format('Y-m-d');
		$cacheKey = $userId . '|' . $refDay;
		if (isset($this->activeCache[$cacheKey]) === true) {
			return $this->activeCache[$cacheKey];
		}

		[$objectService, $register, $schema] = $this->resolveContext();
		if ($objectService === null) {
			return [];
		}

		$rows = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['substitute' => $userId]
		);

		$active = [];
		foreach ($rows as $row) {
			$isActive = $this->isRowActiveOn(
				row: $row,
				refDay: $refDay,
				objectService: $objectService,
				register: $register,
				schema: $schema
			);
			if ($isActive === true) {
				$active[] = $row;
			}
		}

		$this->activeCache[$cacheKey] = $active;

		return $active;
	}//end getActiveSubstitutionsFor()

	/**
	 * Whether one substitution row is active on the reference day.
	 *
	 * Applies the lazy-expiry side effect: a row whose endDate has passed while
	 * still marked `active` is best-effort persisted as `ended` and excluded.
	 *
	 * @param array<string, mixed> $row The substitution row.
	 * @param string $refDay Reference day (Y-m-d).
	 * @param object $objectService The ObjectService.
	 * @param string $register Register id.
	 * @param string $schema Substitution schema id.
	 *
	 * @return bool True when the row is active on the reference day.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	private function isRowActiveOn(
		array $row,
		string $refDay,
		object $objectService,
		string $register,
		string $schema,
	): bool {
		$status = (string)($row['status'] ?? '');
		if ($status === 'revoked') {
			return false;
		}

		$start = (string)($row['startDate'] ?? '');
		$end = (string)($row['endDate'] ?? '');

		// Lazy expiry: past endDate -> ended, excluded.
		if ($end !== '' && $refDay > $end) {
			if ($status === 'active') {
				$this->markEnded(
					objectService: $objectService,
					register: $register,
					schema: $schema,
					row: $row
				);
			}

			return false;
		}

		if ($status !== 'active') {
			return false;
		}

		if ($start !== '' && $refDay < $start) {
			return false;
		}

		return true;
	}//end isRowActiveOn()

	/**
	 * Resolve the substituted open cases and tasks routed to a waarnemer.
	 *
	 * For each active substitution the absentee's open cases/tasks within the
	 * substitution scope are gathered. Because the OpenRegister ObjectService
	 * search runs in the calling user's (the substitute's) RBAC context, items
	 * the substitute cannot read are already excluded — the service never
	 * elevates. Each returned item is annotated with the substitution context
	 * so the UI can render the "waargenomen voor {naam}" badge.
	 *
	 * @param string $userId The waarnemer (substitute) user id.
	 * @param DateTimeImmutable|null $date Reference date; defaults to today.
	 *
	 * @return array{cases: array<int, array<string, mixed>>, tasks: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function getSubstitutedWorkFor(string $userId, ?DateTimeImmutable $date = null): array {
		return $this->workResolver->resolve(
			subs: $this->getActiveSubstitutionsFor(userId: $userId, date: $date)
		);
	}//end getSubstitutedWorkFor()

	/**
	 * Resolve the active substitution under which the given user may act on an
	 * item assigned to a different absentee — used for capacity stamping.
	 *
	 * Returns the matching substitution (so the caller can stamp
	 * actedOnBehalfOf + substitutionId) or null when the user is acting on
	 * their own work / no active substitution covers the item.
	 *
	 * @param string $actorId The acting user id.
	 * @param string $absentee The item's current assignee.
	 * @param string $caseId The case id (for scope checks).
	 * @param string|null $caseType The case's caseType (for scope checks).
	 * @param DateTimeImmutable|null $date Reference date; defaults to today.
	 *
	 * @return array<string, mixed>|null The covering substitution, or null.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function resolveActingCapacity(
		string $actorId,
		string $absentee,
		string $caseId = '',
		?string $caseType = null,
		?DateTimeImmutable $date = null,
	): ?array {
		if ($actorId === '' || $absentee === '' || $actorId === $absentee) {
			return null;
		}

		foreach ($this->getActiveSubstitutionsFor(userId: $actorId, date: $date) as $sub) {
			if ((string)($sub['absentee'] ?? '') !== $absentee) {
				continue;
			}

			$scope = (string)($sub['scope'] ?? 'all');
			$scopeRefs = array_map('strval', (array)($sub['scopeRefs'] ?? []));
			$probe = ['caseType' => $caseType, 'id' => $caseId];
			if ($this->workResolver->caseInScope(case: $probe, scope: $scope, scopeRefs: $scopeRefs) === true) {
				return $sub;
			}
		}

		return null;
	}//end resolveActingCapacity()

	/**
	 * Best-effort lazy persistence of the `ended` status.
	 *
	 * @param object $objectService The ObjectService.
	 * @param string $register Register id.
	 * @param string $schema Schema id.
	 * @param array<string, mixed> $row The expired substitution row.
	 *
	 * @return void
	 */
	private function markEnded(object $objectService, string $register, string $schema, array $row): void {
		$id = (string)($row['id'] ?? ($row['uuid'] ?? ''));
		if ($id === '') {
			return;
		}

		try {
			$row['status'] = 'ended';
			$objectService->updateObject($register, $schema, $id, $row);
		} catch (\Throwable $e) {
			$this->logger->warning('Substitution lazy-ended persistence failed', ['id' => $id, 'error' => $e->getMessage()]);
		}
	}//end markEnded()

	/**
	 * Resolve the ObjectService + register + substitution schema context,
	 * tolerating a missing/unconfigured OpenRegister (the caller decides).
	 *
	 * @return array{0: object|null, 1: string, 2: string}
	 */
	private function resolveContext(): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('substitution_schema');

		return [$objectService, $register, $schema];
	}//end resolveContext()

	/**
	 * Resolve the context, insisting every piece is present.
	 *
	 * @return array{0: object, 1: string, 2: string}
	 *
	 * @throws RuntimeException When OpenRegister or the substitution schema is unavailable.
	 */
	private function requireContext(): array {
		[$objectService, $register, $schema] = $this->resolveContext();
		if ($objectService === null || $register === '' || $schema === '') {
			throw new RuntimeException('OpenRegister is not available or the substitution schema is not configured');
		}

		return [$objectService, $register, $schema];
	}//end requireContext()
}//end class
