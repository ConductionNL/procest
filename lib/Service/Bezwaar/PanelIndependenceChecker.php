<?php

/**
 * Dossiq Bezwaar Panel Independence Checker.
 *
 * The Awb Art. 7:13 lid 3 independence check for a bezwaaradviescommissie
 * panel. Split out of AdvisoryCommitteeService so that service keeps only
 * the advice-request lifecycle: the entire question "was any member of
 * this panel involved in the besluit now under bezwaar?" — including the
 * four-hop resolution chain that answers it — lives here and nowhere
 * else.
 *
 * Resolution chain:
 *   bacAdviceRequest.bezwaar -> bezwaar (lifecycle record) -> bezwaar.case
 *   (dossiq case) -> objection (filed on that case) ->
 *   objection.contestedDecision -> decision owner / createdBy / steller.
 *
 * The check FAILS OPEN on infrastructure errors by design: a missing
 * schema or an OpenRegister hiccup must not block a committee from
 * deliberating. Every fail-open path is logged.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Bezwaar
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/specs/bezwaar-advisory-committee/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Bezwaar;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Verifies that no BAC panel member authored the contested primair besluit.
 *
 * @spec openspec/specs/bezwaar-advisory-committee/spec.md
 */
class PanelIndependenceChecker {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema/register bridge.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Member-independence check per Awb Art. 7:13(3).
	 *
	 * Compares each panel member UID against the `createdBy` (steller) of
	 * the contested primair besluit.
	 *
	 * @param string $objectionId The bezwaar (lifecycle) UUID.
	 * @param array<string> $panel Panel member UIDs.
	 *
	 * @return array{ok: bool, member: ?string, reason: ?string} The verdict.
	 *
	 * @spec openspec/specs/bezwaar-advisory-committee/spec.md
	 */
	public function check(string $objectionId, array $panel): array {
		$clear = [
			'ok' => true,
			'member' => null,
			'reason' => null,
		];

		if ($objectionId === '' || $panel === []) {
			return $clear;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return $clear;
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$legacyObjSchema = $this->settingsService->getConfigValue(
			key: 'bezwaar_schema'
		);
		$objectionSchema = $this->settingsService->getConfigValue(
			key: 'objection_schema'
		);
		$decisionSchema = $this->settingsService->getConfigValue(
			key: 'decision_schema'
		);

		if (in_array('', [$objectionSchema, $decisionSchema], true) === true) {
			// Unable to resolve; do not block the transition, but log.
			$this->logger->info(
				'Dossiq BAC: objection/decision schemas not configured; '
				. 'skipping independence check'
			);
			return $clear;
		}

		try {
			$author = $this->resolveContestedDecisionAuthor(
				objectService: $objectService,
				objectionId: $objectionId,
				register: $register,
				legacyObjSchema: $legacyObjSchema,
				objectionSchema: $objectionSchema,
				decisionSchema: $decisionSchema,
			);
			if ($author === '') {
				return $clear;
			}

			$conflicting = $this->findConflictingPanelMember(
				panel: $panel,
				author: $author,
			);
			if ($conflicting !== null) {
				return [
					'ok' => false,
					'member' => $conflicting,
					'reason' => 'Lid was betrokken bij het bestreden '
								. 'besluit (Awb Art. 7:13 lid 3)',
				];
			}
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq BAC: independence check error: ' . $e->getMessage()
			);
			// Fail-open here is intentional: do not block on infra issues.
		}//end try

		return $clear;
	}//end check()

	/**
	 * Resolve the steller (author) of the primair besluit contested by the
	 * objection filed on the bezwaar's underlying dossiq case.
	 *
	 * @param object $objectService OpenRegister object service.
	 * @param string $objectionId The bezwaar (lifecycle) UUID.
	 * @param string $register Register identifier.
	 * @param string $legacyObjSchema Legacy objection schema (config key `bezwaar_schema`), may be ''.
	 * @param string $objectionSchema Objection schema identifier.
	 * @param string $decisionSchema Decision schema identifier.
	 *
	 * @return string The steller UID, or '' when it cannot be resolved.
	 *
	 * @spec openspec/specs/bezwaar-advisory-committee/spec.md
	 */
	private function resolveContestedDecisionAuthor(
		object $objectService,
		string $objectionId,
		string $register,
		string $legacyObjSchema,
		string $objectionSchema,
		string $decisionSchema,
	): string {
		// Resolve the underlying dossiq case via the bezwaar entity
		// when the bezwaar_schema is registered. When unavailable
		// (e.g. legacy callers passing a case UUID directly), fall back
		// to treating the input as the case id.
		$caseId = $objectionId;
		if ($legacyObjSchema !== '') {
			$bezwaar = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $legacyObjSchema,
				id: $objectionId
			);
			if ($bezwaar !== null) {
				$caseId = (string)($bezwaar['case'] ?? $objectionId);
			}
		}

		$objections = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $objectionSchema,
			filters: ['case' => $caseId]
		);
		$objection = null;
		if ($objections !== []) {
			$objection = $objections[0];
		}

		if (is_array($objection) === false) {
			return '';
		}

		$contestedId = (string)($objection['contestedDecision'] ?? '');
		if ($contestedId === '') {
			return '';
		}

		$decision = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $decisionSchema,
			id: $contestedId
		);
		if ($decision === null) {
			return '';
		}

		return (string)(
			$decision['@self']['owner'] ?? ($decision['createdBy'] ?? ($decision['steller'] ?? ''))
		);
	}//end resolveContestedDecisionAuthor()

	/**
	 * Find the first panel member that is not independent from the steller.
	 *
	 * @param array<string> $panel Panel member UIDs.
	 * @param string $author UID of the contested decision's author.
	 *
	 * @return string|null The conflicting member UID, or null when the panel is independent.
	 *
	 * @spec openspec/specs/bezwaar-advisory-committee/spec.md
	 */
	private function findConflictingPanelMember(array $panel, string $author): ?string {
		foreach ($panel as $memberUid) {
			if ((string)$memberUid === $author) {
				return (string)$memberUid;
			}
		}

		return null;
	}//end findConflictingPanelMember()
}//end class
