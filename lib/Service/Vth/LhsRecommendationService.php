<?php

/**
 * Dossiq LHS Recommendation Service
 *
 * Single entry point for the Landelijke Handhavingsstrategie (LHS) lookup:
 *   - `recommend(ernst, gedrag, actorType, lhsVersion?)` returns the prescribed
 *     intervention and persists an `lhsRecommendation` row.
 *
 *     THE LOOKUP IS A DECISION TABLE, evaluated by OpenRegister. The matrix is
 *     a three-axis grid yielding one value, which is exactly that shape, and
 *     the engine already carries one evaluator for it. The matrix is read
 *     directly only where no projection exists on this instance, because
 *     projecting one needs an owner for the table it writes and so cannot
 *     happen unattended on upgrade.
 *
 *     Asking the table first is not a preference. A declared table NAMES its
 *     inputs and refuses what it cannot resolve; the hand-indexed dictionary
 *     answered a vocabulary defect exactly as it answers bad input, which is
 *     how a quarter of the strategy stayed unreachable (dossiq#1596).
 *   - `override(recommendation, intervention, justification, userRole)` applies
 *     an inspector override. Override-up (harsher than recommended) is gated to
 *     the manager role per REQ-LHS-5/6.
 *
 * CRUD over matrices and recommendations themselves is served by the
 * OpenRegister manifest renderer; this service owns the engine actions.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Vth
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Vth;

use OCP\IUserSession;
use RuntimeException;

/**
 * LHS recommendation engine.
 *
 * @spec openspec/changes/enforcement-lhs/tasks.md#T03
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — orchestrates OpenRegister,
 *   user-session, settings bridge, and logger.
 *
 * @psalm-suppress UnusedClass
 */
class LhsRecommendationService {
	/**
	 * Severity ordering of the `interventie` enum, lowest -> highest.
	 *
	 * Used by override() to determine whether an inspector is overriding
	 * "up" (harsher, manager-only) or "down" (lighter, any inspector).
	 *
	 * @var array<string, int>
	 */
	private const INTERVENTIE_SEVERITY = [
		'warning' => 1,
		'herstelactie' => 2,
		'last_under_penaltypayment' => 3,
		'last_plus_pv' => 4,
		'bestuursdwang' => 5,
		'pv_plus_bestuursdwang' => 6,
	];

	/**
	 * Minimum length of override justification (non-whitespace chars).
	 */
	private const MIN_JUSTIFICATION_LENGTH = 20;

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession Authenticated user session
	 * @param LhsRecommendationStore $store The OpenRegister reads and writes
	 * @param LhsDecisionTableLookup $tableLookup Evaluates the projected decision table
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly LhsRecommendationStore $store,
		private readonly LhsDecisionTableLookup $tableLookup,
	) {
	}//end __construct()

	/**
	 * Recommend an intervention for the given (ernst, gedrag, actorType).
	 *
	 * Loads the matrix (active by default, or the explicitly requested
	 * version), maps cells into an in-memory dictionary keyed
	 * "severity:behaviour:actorType", and persists an `lhsRecommendation` row
	 * carrying the lookup result. Identity is always derived from the
	 * session — never from caller-supplied data.
	 *
	 * @param string $caseId The parent case UUID
	 * @param string $severity Severity axis value
	 * @param string $behaviour Behaviour axis value
	 * @param string $actorType Actor-type axis value
	 * @param int|null $lhsVersion Optional explicit matrix version; null = active
	 * @param string|null $inspection Optional inspection rapport UUID for traceability
	 *
	 * @return array<string, mixed> The persisted recommendation row
	 *
	 * @throws RuntimeException When OpenRegister is unavailable, no matching
	 *                          matrix exists, or no cell matches the triple.
	 *
	 * @spec openspec/specs/enforcement-lhs/spec.md
	 */
	public function recommend(
		string $caseId,
		string $severity,
		string $behaviour,
		string $actorType,
		?int $lhsVersion = null,
		?string $inspection = null,
	): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new RuntimeException('Authenticatie vereist voor LHS-aanbeveling');
		}

		$matrix = $this->store->loadMatrix(version: $lhsVersion);

		// THE DECISION TABLE IS THE LOOKUP. The matrix is a three-axis grid
		// yielding one value, which is a decision table, and OpenRegister
		// carries one evaluator for those. Asking it first is the whole point
		// of the projection: a declared table NAMES its inputs, so it refuses
		// what it cannot resolve instead of missing silently the way a
		// hand-indexed dictionary does (dossiq#1596).
		$intervention = $this->tableLookup->intervention(
			matrixId: (string)($matrix['id'] ?? ''),
			severity: $severity,
			behaviour: $behaviour,
			actorType: $actorType,
		);

		if ($intervention === null) {
			// FALLBACK, and it is not dead code. Projecting a matrix needs an
			// owner for the table it writes, so it is an occ command a person
			// runs and not something an upgrade can do unattended. An instance
			// that has not run it yet still has to be able to enforce.
			$intervention = $this->interventionFromMatrix(
				matrix: $matrix,
				severity: $severity,
				behaviour: $behaviour,
				actorType: $actorType,
			);
		}

		$recommendation = [
			'case' => $caseId,
			'inspection' => $inspection,
			'severity' => $severity,
			'behaviour' => $behaviour,
			'actorType' => $actorType,
			'matrixVersion' => (int)($matrix['version'] ?? 1),
			'recommendedIntervention' => $intervention,
			'finalIntervention' => $intervention,
			'override' => false,
			'recommendedBy' => $user->getUID(),
		];

		if ($inspection === null) {
			unset($recommendation['inspection']);
		}

		return $this->store->persistRecommendation(row: $recommendation);
	}//end recommend()

	/**
	 * Apply an override to an existing LHS recommendation.
	 *
	 * Override-down (selecting an intervention of equal or lower severity than
	 * the recommendation) is allowed for any inspector with a justification of
	 * at least 20 non-whitespace characters. Override-up (harsher than the
	 * recommendation) requires the manager role and is persisted with
	 * `overrideAuthority = "manager"`.
	 *
	 * 🔴 THE STORED ROW IS THE ONLY SOURCE. This method takes an ID and reads
	 * the recommendation back from OpenRegister; it does NOT accept the row
	 * from the caller.
	 *
	 * It used to. The caller passed the whole recommendation array, and the
	 * escalation guard compared the requested intervention against the
	 * `recommendedIntervention` IN THAT ARRAY. So an inspector could post a body
	 * claiming the matrix had already recommended `bestuursdwang`, "override
	 * down" to anything, and never meet the manager gate — while the same
	 * array_merge also let them rewrite `severity`, `behaviour`, `matrixVersion`
	 * and `recommendedBy` on the persisted row. The audit record of what the
	 * matrix said would then agree with them.
	 *
	 * @param string $recommendationId Id of the stored recommendation
	 * @param string $intervention Chosen intervention (enum value)
	 * @param string $justification Mandatory justification (>= 20 chars)
	 * @param string $userRole Caller role: "inspector" or "manager"
	 *
	 * @return array<string, mixed> The updated recommendation row
	 *
	 * @throws RuntimeException When validation fails (HTTP-mapped by controller).
	 *
	 * @spec openspec/specs/enforcement-lhs/spec.md
	 */
	public function override(
		string $recommendationId,
		string $intervention,
		string $justification,
		string $userRole,
	): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new RuntimeException('Authenticatie vereist voor override');
		}

		$trimmed = preg_replace('/\s+/u', '', $justification) ?? '';
		if (mb_strlen($trimmed) < self::MIN_JUSTIFICATION_LENGTH) {
			throw new RuntimeException(
				'Motivatie afwijking moet minimaal 20 tekens bevatten'
			);
		}

		$recommendationId = trim($recommendationId);
		if ($recommendationId === '') {
			throw new RuntimeException('Recommendation ID ontbreekt voor override');
		}

		// Read back, never trust. The escalation guard below compares against
		// what the MATRIX said, so that value has to come from the store.
		$recommendation = $this->store->loadRecommendation(recommendationId: $recommendationId);

		$recommended = (string)($recommendation['recommendedIntervention'] ?? '');
		$recSeverity = self::INTERVENTIE_SEVERITY[$recommended] ?? 0;
		$newSeverity = self::INTERVENTIE_SEVERITY[$intervention] ?? 0;
		if ($newSeverity === 0) {
			throw new RuntimeException(
				'Ongeldige interventie: ' . $intervention
			);
		}

		$overrideUp = ($newSeverity > $recSeverity);
		$authority = 'inspector';
		if ($userRole === 'manager') {
			$authority = 'manager';
		}

		if ($overrideUp === true && $authority !== 'manager') {
			throw new RuntimeException('Verzwaring vereist managerrol');
		}

		// Only the override fields are written. The stored row supplies
		// everything else, so a request cannot rewrite `severity`, `behaviour`,
		// `matrixVersion` or `recommendedBy` on its way past the guard — which
		// would leave the audit record of what the matrix said agreeing with
		// whoever overrode it.
		$updated = array_merge(
			$recommendation,
			[
				'override' => true,
				'overrideJustification' => $justification,
				'overrideBy' => $user->getUID(),
				'overrideAuthority' => $authority,
				'finalIntervention' => $intervention,
			]
		);

		return $this->store->persistRecommendation(row: $updated, id: $recommendationId);
	}//end override()





	/**
	 * Read the intervention straight off the matrix.
	 *
	 * THE COMPATIBILITY PATH, used only when this instance has no projected,
	 * enabled decision table. Projecting one needs an owner for the table it
	 * writes, so it is an occ command a person runs; an instance that has not
	 * run it must still be able to enforce.
	 *
	 * It keeps the dictionary's weakness on purpose rather than papering over
	 * it: a miss throws, and a miss looks exactly like bad input. That is what
	 * made the actorType vocabulary split invisible for as long as it was
	 * (dossiq#1596), and it is the reason the table is asked first.
	 *
	 * @param array<string, mixed> $matrix The stored matrix row.
	 * @param string $severity Severity axis value.
	 * @param string $behaviour Behaviour axis value.
	 * @param string $actorType Actor-type axis value.
	 *
	 * @return string The prescribed intervention.
	 *
	 * @throws RuntimeException When no cell matches the triple.
	 *
	 * @spec openspec/specs/enforcement-lhs/spec.md
	 */
	private function interventionFromMatrix(
		array $matrix,
		string $severity,
		string $behaviour,
		string $actorType,
	): string {
		$cellIndex = $this->indexCells(cells: ($matrix['cells'] ?? []));
		$key = $severity . ':' . $behaviour . ':' . $actorType;

		if (isset($cellIndex[$key]) === false) {
			throw new RuntimeException(
				'Geen LHS-cel gevonden voor combinatie ' . $key
			);
		}

		return (string)($cellIndex[$key]['intervention'] ?? '');
	}//end interventionFromMatrix()

	/**
	 * Build an in-memory dictionary of cells keyed `severity:behaviour:actorType`.
	 *
	 * Accepts both a JSON-encoded string (as stored on some legacy rows)
	 * and a native array.
	 *
	 * @param mixed $cells The cells field of the matrix row
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function indexCells(mixed $cells): array {
		if (is_string($cells) === true) {
			$decoded = json_decode($cells, true);
			$cells = [];
			if (is_array($decoded) === true) {
				$cells = $decoded;
			}
		}

		if (is_array($cells) === false) {
			return [];
		}

		$index = [];
		foreach ($cells as $cell) {
			if (is_array($cell) === false) {
				continue;
			}

			$key = ((string)($cell['severity'] ?? ''))
				. ':' . ((string)($cell['behaviour'] ?? ''))
				. ':' . ((string)($cell['actorType'] ?? ''));
			$index[$key] = $cell;
		}

		return $index;
	}//end indexCells()


}//end class
