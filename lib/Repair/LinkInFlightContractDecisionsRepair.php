<?php

/**
 * Dossiq Link In-Flight Contract Decisions Repair
 *
 * Migration repair step: for each open contract / besluitvorming case that
 * does NOT yet have a `decisionRef`, link it forward to a decidesk Decision
 * via the ContractDecisionDelegationService so its outcome can complete in
 * decidesk. Cases that already have a recorded ZGW `Besluit` are left as the
 * authoritative historical record — no Besluit data is dropped.
 *
 * Safe + idempotent: if the decidesk leaf is unavailable, the step warns and
 * skips (no case data is modified); it does not fail the migration.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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
 * @spec openspec/specs/contract-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Service\ContractDecisionDelegationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\JsonEncodedStringProperties;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\TenantSaasService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Links in-flight contract/besluitvorming cases forward to decidesk Decisions.
 *
 * @spec openspec/specs/contract-decision-delegation/spec.md
 */
class LinkInFlightContractDecisionsRepair implements IRepairStep {

	use SearchesObjects;

	/**
	 * Case types that represent open contract/besluitvorming decisions.
	 *
	 * @var string[]
	 */
	private const CONTRACT_DECISION_CASE_TYPES = [
		'leverancier-contractverlenging-verzoek',
		'besluitvorming-college',
		'besluitvorming-raad',
		'besluitvorming-mandaat',
	];

	/**
	 * Per-case outcomes reported by linkCase(); each value doubles as the
	 * tally key in the run() counter map.
	 */
	private const RESULT_LINKED = 'linked';
	private const RESULT_SKIPPED = 'skipped';
	private const RESULT_ERROR = 'errors';
	private const RESULT_NONE = 'none';

	/**
	 * Constructor.
	 *
	 * @param ContractDecisionDelegationService $delegationService Decision delegation service.
	 * @param SettingsService $settingsService Settings / ObjectService resolver.
	 * @param JsonEncodedStringProperties $jsonProperties Restores the declared string shape of JSON-encoded properties.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContractDecisionDelegationService $delegationService,
		private readonly SettingsService $settingsService,
		private readonly JsonEncodedStringProperties $jsonProperties,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	public function getName(): string {
		return 'Link in-flight Dossiq contract/besluitvorming cases to decidesk Decisions';
	}//end getName()

	/**
	 * Run the migration: link open cases forward without dropping Besluit data.
	 *
	 * @param IOutput $output The migration output interface.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	public function run(IOutput $output): void {
		$output->info('Linking in-flight contract/besluitvorming cases to decidesk Decisions...');

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->warning('OpenRegister unavailable — skipping in-flight contract decision link.');
			return;
		}

		$counts = [
			self::RESULT_LINKED => 0,
			self::RESULT_SKIPPED => 0,
			self::RESULT_ERROR => 0,
			self::RESULT_NONE => 0,
		];

		// This repair step runs without a Nextcloud user session — anonymous
		// callers are fail-closed by OpenRegister RBAC (#1955) on every
		// boot, so the list/save calls below run inside runAsSystem().
		$this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: function () use ($objectService, $output, &$counts): void {
				foreach (self::CONTRACT_DECISION_CASE_TYPES as $caseTypeSlug) {
					try {
						// ObjectService::findAll() takes a single $config array — the
						// previous named-argument call (register:/schema:/limit:) threw
						// "Unknown named parameter" on every run. Use the shared
						// slug-aware search bridge, which also normalises the rows to
						// the associative arrays this loop expects.
						$cases = $this->searchObjectsAsArrays(
							objectService: $objectService,
							register: TenantSaasService::REGISTER,
							schema: 'case',
							filters: [
								'caseTypeSlug' => $caseTypeSlug,
								'_limit' => 500,
							],
						);
					} catch (Throwable $e) {
						$output->warning('Could not list cases for type ' . $caseTypeSlug . ': ' . $e->getMessage());
						$this->logger->warning(
							'LinkInFlightContractDecisionsRepair: list failed',
							['caseTypeSlug' => $caseTypeSlug, 'error' => $e->getMessage()]
						);
						continue;
					}//end try

					foreach ($cases as $case) {
						$outcome = $this->linkCase(
							objectService: $objectService,
							case: $case,
							caseTypeSlug: $caseTypeSlug,
							output: $output,
						);

						$counts[$outcome]++;
					}//end foreach
				}//end foreach
			}
		);

		$output->info(
			sprintf(
				'Contract decision link complete: %d linked, %d skipped (already decided/historical), %d errors (leaf unavailable).',
				$counts[self::RESULT_LINKED],
				$counts[self::RESULT_SKIPPED],
				$counts[self::RESULT_ERROR]
			)
		);
	}//end run()

	/**
	 * Link a single in-flight case forward to a decidesk Decision.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param array<string, mixed> $case The case row.
	 * @param string $caseTypeSlug The dossiq case type slug.
	 * @param IOutput $output The migration output interface.
	 *
	 * @return string One of the self::RESULT_* constants.
	 */
	private function linkCase(
		object $objectService,
		array $case,
		string $caseTypeSlug,
		IOutput $output,
	): string {
		$caseUuid = (string)($case['uuid'] ?? $case['id'] ?? '');
		$besluitRef = (string)($case['besluitRef'] ?? '');
		$decisionRef = (string)($case['decisionRef'] ?? '');
		$status = (string)($case['status'] ?? '');
		$isClosed = in_array($status, ['closed', 'handled', 'archived', 'closed'], true);

		if ($caseUuid === '') {
			return self::RESULT_NONE;
		}

		// REQ-PDCD-007: if a Besluit is already recorded, keep it as
		// the authoritative historical record — no link needed.
		if ($besluitRef !== '') {
			return self::RESULT_SKIPPED;
		}

		// Already linked to a decidesk Decision.
		if ($decisionRef !== '') {
			return self::RESULT_SKIPPED;
		}

		// Skip closed cases without a Besluit — they are historical,
		// do not create dangling Decisions in decidesk.
		if ($isClosed === true) {
			return self::RESULT_SKIPPED;
		}

		// Open case with no decision yet — link forward to decidesk.
		try {
			$newDecisionRef = $this->delegationService->raiseContractDecision(
				caseRef: $caseUuid,
				contractRef: (string)($case['contractRef'] ?? ''),
				decisionType: $this->mapCaseTypeToDecisionType(caseTypeSlug: $caseTypeSlug),
				subject: [
					'subjectRegister' => TenantSaasService::REGISTER,
					'subjectSchema' => 'case',
					'subjectId' => $caseUuid,
					'subjectLabel' => (string)($case['title'] ?? $caseTypeSlug),
				],
				mandateContext: [],
			);

			// Persist the decisionRef on the case (does not alter the case outcome).
			// A loaded case carries `statusHistory`, `activity`, `geometry`
			// and `relatedCases` DECODED, and the schema still declares each
			// of them a string — a bare array_merge writes arrays into them
			// and OpenRegister refuses the whole save.
			$objectService->saveObject(
				object: $this->jsonProperties->mergeForWrite(
					stored: $case,
					updates: ['decisionRef' => $newDecisionRef],
					schemaSlug: 'case',
				),
				register: TenantSaasService::REGISTER,
				schema: 'case',
				uuid: $caseUuid,
			);
			$output->info('Linked case ' . $caseUuid . ' → decidesk Decision ' . $newDecisionRef);
		} catch (RuntimeException $e) {
			// Decidesk leaf unavailable — warn + skip this case; do NOT fail the migration.
			$output->warning('Could not link case ' . $caseUuid . ': ' . $e->getMessage() . ' — skipping.');
			$this->logger->warning(
				'LinkInFlightContractDecisionsRepair: could not link case',
				['caseUuid' => $caseUuid, 'error' => $e->getMessage()]
			);
			return self::RESULT_ERROR;
		}//end try

		return self::RESULT_LINKED;
	}//end linkCase()

	/**
	 * Map a dossiq case type slug to a decidesk decisionType.
	 *
	 * @param string $caseTypeSlug The dossiq case type slug.
	 *
	 * @return string The decidesk decisionType.
	 */
	private function mapCaseTypeToDecisionType(string $caseTypeSlug): string {
		return match ($caseTypeSlug) {
			'leverancier-contractverlenging-verzoek' => ContractDecisionDelegationService::DECISION_TYPE_CONTRACT_RENEWAL,
			default => ContractDecisionDelegationService::DECISION_TYPE_REPORT_ADOPTION,
		};
	}//end mapCaseTypeToDecisionType()
}//end class
