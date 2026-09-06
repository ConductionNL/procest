<?php

/**
 * Dossiq Bezwaar Creation Hook.
 *
 * Targeted extension that runs when a bezwaar case is created. It wires
 * the bezwaar case to the primair besluit it contests:
 *
 *  - reads the `contestedDecision` UUID supplied at creation time;
 *  - resolves the primair besluit `case` via the contested decision's
 *    own `case` reference;
 *  - adds that primair besluit case UUID to the bezwaar `case.relatedCases`
 *    list (without dropping any caller-supplied relations);
 *  - creates the formal `objection` (bezwaarschrift) record linking
 *    `case` (the bezwaar case) and `contestedDecision`.
 *
 * No state-machine transition logic lives here — the AWB lifecycle is
 * driven by the workflowTemplate. This hook only establishes the
 * cross-case reference and the objection record that the workflow's
 * guards depend on. Identity is always derived from IUserSession; the
 * caller cannot impersonate another actor.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Bezwaar
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Bezwaar;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Establishes primair-besluit linking and the objection record for a
 * newly created bezwaar case.
 *
 * @spec openspec/specs/bezwaar-beroep-workflow/spec.md
 */
class BezwaarCreationHook {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema/register + OR bridge.
	 * @param IUserSession $userSession Acting user resolver.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Link a bezwaar case to its primair besluit and create the objection.
	 *
	 * @param string $objectionCaseId UUID of the bezwaar case.
	 * @param string $contestedDecisionId UUID of the contested
	 *                                    primair besluit decision.
	 * @param array<string, mixed> $objectionPayload Optional extra objection
	 *                                               fields (grounds,
	 *                                               requestedRelief,
	 *                                               receivedDate, ...).
	 *
	 * @return array<string, mixed> The created objection record.
	 *
	 * @throws RuntimeException When OpenRegister or schemas are unavailable,
	 *                          or the contested decision cannot be resolved.
	 *
	 * @spec openspec/specs/bezwaar-beroep-workflow/spec.md
	 */
	public function onBezwaarCreated(
		string $objectionCaseId,
		string $contestedDecisionId,
		array $objectionPayload = [],
	): array {
		if (trim($objectionCaseId) === '' || trim($contestedDecisionId) === '') {
			throw new RuntimeException(
				'bezwaar case id and contested decision id are required'
			);
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$schemas = $this->resolveSchemas();

		$decision = $this->findObjectAsArray(
			objectService: $objectService,
			register: $schemas['register'],
			schema: $schemas['decision'],
			id: $contestedDecisionId
		);
		if ($decision === null) {
			throw new RuntimeException('Contested decision not found');
		}

		$primaryDecisionCase = $this->extractUuid(value: ($decision['case'] ?? ''));

		$this->linkPrimairDecision(
			objectService: $objectService,
			register: $schemas['register'],
			caseSchema: $schemas['case'],
			objectionCaseId: $objectionCaseId,
			primaryDecisionCase: $primaryDecisionCase,
			contestedDecisionId: $contestedDecisionId
		);

		$objection = $this->buildObjection(
			objectionCaseId: $objectionCaseId,
			contestedDecisionId: $contestedDecisionId,
			payload: $objectionPayload
		);

		$created = $objectService->saveObject(object: $objection, register: $schemas['register'], schema: $schemas['objection']);

		return $this->toArray(value: $created) ?? $objection;
	}//end onBezwaarCreated()

	/**
	 * Resolve and validate the register + schema ids needed by the hook.
	 *
	 * @return array{register: string, case: string, decision: string, objection: string}
	 *
	 * @throws RuntimeException When any required id is unconfigured.
	 */
	private function resolveSchemas(): array {
		$schemas = [
			'register' => $this->settingsService->getConfigValue(key: 'register'),
			'case' => $this->settingsService->getConfigValue(key: 'case_schema'),
			'decision' => $this->settingsService->getConfigValue(key: 'decision_schema'),
			'objection' => $this->settingsService->getConfigValue(key: 'objection_schema'),
		];

		foreach ($schemas as $value) {
			if ($value === '') {
				throw new RuntimeException('Case, decision or objection schema is not configured');
			}
		}

		return $schemas;
	}//end resolveSchemas()

	/**
	 * Link the primair besluit case into relatedCases when one exists.
	 *
	 * When the contested decision has no parent case nothing is linked;
	 * the absence is logged for operational visibility.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register Register id.
	 * @param string $caseSchema case schema id.
	 * @param string $objectionCaseId The bezwaar case to update.
	 * @param string $primaryDecisionCase The primair besluit case (may be '').
	 * @param string $contestedDecisionId The contested decision (for logging).
	 *
	 * @return void
	 */
	private function linkPrimairDecision(
		object $objectService,
		string $register,
		string $caseSchema,
		string $objectionCaseId,
		string $primaryDecisionCase,
		string $contestedDecisionId,
	): void {
		if ($primaryDecisionCase === '') {
			$this->logger->info(
				'BezwaarCreationHook: contested decision has no parent case; '
				. 'skipping relatedCases link',
				['decision' => $contestedDecisionId]
			);
			return;
		}

		$this->linkRelatedCase(
			objectService: $objectService,
			register: $register,
			caseSchema: $caseSchema,
			objectionCaseId: $objectionCaseId,
			relatedCaseId: $primaryDecisionCase
		);
	}//end linkPrimairBesluit()

	/**
	 * Add a related case UUID to a bezwaar case's relatedCases list.
	 *
	 * Existing relations are preserved; the new UUID is appended only when
	 * it is not already present (idempotent).
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register Register id.
	 * @param string $caseSchema case schema id.
	 * @param string $objectionCaseId The bezwaar case to update.
	 * @param string $relatedCaseId The primair besluit case to link.
	 *
	 * @return void
	 */
	private function linkRelatedCase(
		object $objectService,
		string $register,
		string $caseSchema,
		string $objectionCaseId,
		string $relatedCaseId,
	): void {
		$case = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $caseSchema,
			id: $objectionCaseId
		);
		if ($case === null) {
			throw new RuntimeException('Bezwaar case not found');
		}

		$related = [];
		$existing = ($case['relatedCases'] ?? []);
		if (is_array($existing) === true) {
			foreach ($existing as $entry) {
				$uuid = $this->extractUuid(value: $entry);
				if ($uuid !== '') {
					$related[$uuid] = $uuid;
				}
			}
		}

		if (isset($related[$relatedCaseId]) === true) {
			// Already linked — nothing to do.
			return;
		}

		$related[$relatedCaseId] = $relatedCaseId;
		$case['relatedCases'] = array_values($related);

		$objectService->saveObject(object: $case, register: $register, schema: $caseSchema);
	}//end linkRelatedCase()

	/**
	 * Build the objection record payload.
	 *
	 * @param string $objectionCaseId The bezwaar case UUID.
	 * @param string $contestedDecisionId The contested decision UUID.
	 * @param array<string, mixed> $payload Caller-supplied fields.
	 *
	 * @return array<string, mixed> The objection record.
	 */
	private function buildObjection(
		string $objectionCaseId,
		string $contestedDecisionId,
		array $payload,
	): array {
		// Caller fields first, then enforce the canonical references and the
		// server-derived registrar so a caller can never point the objection
		// at a different case/decision nor forge who registered it.
		$objection = $payload;
		$objection['case'] = $objectionCaseId;
		$objection['contestedDecision'] = $contestedDecisionId;
		$objection['registeredBy'] = $this->resolveUserId();

		return $objection;
	}//end buildObjection()

	/**
	 * Resolve the acting user id from the session (server-authoritative).
	 *
	 * @return string The acting user id, or 'system' when no user is set.
	 */
	private function resolveUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return 'system';
		}

		return $user->getUID();
	}//end resolveUserId()

	/**
	 * Extract a UUID from a reference value (string or object/array).
	 *
	 * @param mixed $value The reference value.
	 *
	 * @return string The UUID, or '' when none could be derived.
	 */
	private function extractUuid(mixed $value): string {
		if (is_string($value) === true) {
			return trim($value);
		}

		if (is_array($value) === true) {
			foreach (['id', 'uuid', '@self.uuid', 'case', 'target'] as $key) {
				if (isset($value[$key]) === true && is_string($value[$key]) === true) {
					return trim($value[$key]);
				}
			}
		}

		return '';
	}//end extractUuid()

	/**
	 * Normalise an OpenRegister save result to an array.
	 *
	 * @param mixed $value The save result.
	 *
	 * @return array<string, mixed>|null The array form, or null.
	 */
	private function toArray(mixed $value): ?array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return null;
	}//end toArray()
}//end class
