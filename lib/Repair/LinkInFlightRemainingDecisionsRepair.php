<?php

/**
 * Dossiq Link In-Flight Remaining Decisions Repair
 *
 * Migration repair step for `dossiq-delegate-remaining-decisions-to-decidesk`:
 * for each open beslissing-op-bezwaar / advies / consultatie object
 * that does NOT yet carry a `decisionRef`, link it forward to a decidesk
 * Decision (of the appropriate decisionType) so its outcome can complete in
 * decidesk. Objects that already carry a recorded `decisionRef`, a ZGW
 * `besluitRef`, or that are in a terminal/decided status are left as the
 * authoritative historical record — no decision/advice data is dropped.
 *
 * Safe + idempotent: re-running links nothing already linked; if the decidesk
 * leaf is unavailable, the step warns and skips (no object data is modified)
 * and does not fail the migration.
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
 * @spec openspec/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-006-in-flight-remaining-decision-cases-are-migrated-without-data-loss
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Service\AdviceDelegationService;
use OCA\Dossiq\Service\BezwaarDecisionDelegationService;
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
 * Links in-flight bezwaar-decision / advies / consultatie objects
 * forward to decidesk Decisions without dropping any recorded data.
 *
 * @spec openspec/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-006-in-flight-remaining-decision-cases-are-migrated-without-data-loss
 */
class LinkInFlightRemainingDecisionsRepair implements IRepairStep {

	/**
	 * Each surface's schema SLUG, keyed by the config key that provisions it.
	 *
	 * 🔴 THE SLUG IS WHAT THE CALLS TAKE, NOT THE CONFIG VALUE. The config
	 * value is the schema IDENTIFIER, a numeric id on a live install, and this
	 * step used to pass it to `searchObjectsAsArrays()` alongside the register
	 * SLUG. That mixture picks the slug branch, because the branch is chosen
	 * on "is either side non-numeric": the bridge then asked OpenRegister for
	 * a schema whose slug is `4711`, which resolves to nothing, and every
	 * surface came back as `Could not list objects for schema <number>`. Four
	 * warnings per run and not one object examined, on a step whose whole job
	 * is to examine them.
	 *
	 * Both sides are slugs now, which is the shape the sibling
	 * {@see LinkInFlightContractDecisionsRepair} has always used. The config
	 * key stays as the provisioning guard: an unconfigured surface is skipped
	 * silently, which is different from a configured one that cannot be read.
	 *
	 * The slug is also the key {@see JsonEncodedStringProperties} needs to
	 * restore the declared string shape of a JSON-encoded property.
	 *
	 * @var array<string, string>
	 */
	private const SURFACE_SCHEMA_SLUGS = [
		'bezwaar_decision_schema' => 'bezwaarDecision',
		'advies_aanvraag_schema' => 'adviesAanvraag',
		'consultation_schema' => 'consultation',
	];

	use SearchesObjects;

	/**
	 * Statuses considered terminal / already-decided — skipped (historical).
	 *
	 * @var string[]
	 */
	private const TERMINAL_STATUSES = [
		'published',
		'advice-issued',
		'inadmissible',
		'received',
		'expired',
		'received',
		'cancelled',
		'advice_uitgebracht',
		'closed',
		'withdrawn',
		'besloten',
		'closed',
		'handled',
		'archived',
	];

	/**
	 * Constructor.
	 *
	 * @param BezwaarDecisionDelegationService $objectionDelegation Bezwaar decision delegation service.
	 * @param AdviceDelegationService $adviceDelegation Advice delegation service.
	 * @param SettingsService $settingsService Settings / ObjectService resolver.
	 * @param JsonEncodedStringProperties $jsonProperties Restores the declared string shape of JSON-encoded properties.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly BezwaarDecisionDelegationService $objectionDelegation,
		private readonly AdviceDelegationService $adviceDelegation,
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
	 * @spec openspec/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-006-in-flight-remaining-decision-cases-are-migrated-without-data-loss
	 */
	public function getName(): string {
		return 'Link in-flight Dossiq bezwaar/advies/consultatie objects to decidesk Decisions';
	}//end getName()

	/**
	 * Run the migration: link open objects forward without dropping data.
	 *
	 * @param IOutput $output The migration output interface.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-006-in-flight-remaining-decision-cases-are-migrated-without-data-loss
	 */
	public function run(IOutput $output): void {
		$output->info('Linking in-flight bezwaar/advies/consultatie objects to decidesk Decisions...');

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->warning('OpenRegister unavailable — skipping in-flight remaining-decision link.');
			return;
		}

		$linked = 0;
		$skipped = 0;
		$errors = 0;

		// Each surface: [config-key for schema slug, raise-callback].
		$surfaces = $this->buildSurfaceRaisers();

		// This repair step runs without a Nextcloud user session — anonymous
		// callers are fail-closed by OpenRegister RBAC (#1955) on every
		// boot, so the list/save calls below run inside runAsSystem().
		$this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: function () use ($objectService, $output, $surfaces, &$linked, &$skipped, &$errors): void {
				foreach ($surfaces as $configKey => $raise) {
					$counts = $this->linkSurface(
						objectService: $objectService,
						output: $output,
						configKey: $configKey,
						raise: $raise,
					);
					$linked += $counts['linked'];
					$skipped += $counts['skipped'];
					$errors += $counts['errors'];
				}
			}
		);

		$output->info(
			sprintf(
				'Remaining-decision link complete: %d linked, %d skipped (already decided/historical), %d errors (leaf unavailable).',
				$linked,
				$skipped,
				$errors
			)
		);
	}//end run()

	/**
	 * Build the surface map: schema config-key => decidesk raise-callback.
	 *
	 * @return array<string, callable(array<string, mixed>): string>
	 */
	private function buildSurfaceRaisers(): array {
		return [
			'bezwaar_decision_schema' => function (array $obj): string {
				return $this->objectionDelegation->raiseBezwaarDecision(
					objectionId: (string)($obj['objectionProceeding'] ?? ($obj['uuid'] ?? ($obj['id'] ?? ''))),
					payload: [
						'subjectSchema' => 'bezwaarDecision',
						'subjectId' => (string)($obj['uuid'] ?? ($obj['id'] ?? '')),
						'subjectLabel' => (string)($obj['title'] ?? ''),
						'dispositionType' => (string)($obj['dispositionType'] ?? ''),
						'reasoning' => (string)($obj['reasoning'] ?? ''),
						'legalBasis' => (string)($obj['legalBasis'] ?? ''),
					],
				);
			},
			'advies_aanvraag_schema' => function (array $obj): string {
				return $this->adviceDelegation->raiseAdviceDecision(
					subjectSchema: 'adviesAanvraag',
					subjectId: (string)($obj['uuid'] ?? ($obj['id'] ?? '')),
					payload: [
						'externalReference' => (string)($obj['caseRef'] ?? ($obj['case'] ?? '')),
						'subjectLabel' => (string)($obj['question'] ?? 'Adviesaanvraag'),
						'question' => (string)($obj['question'] ?? ''),
					],
				);
			},
			'consultation_schema' => function (array $obj): string {
				return $this->adviceDelegation->raiseAdviceDecision(
					subjectSchema: 'consultation',
					subjectId: (string)($obj['uuid'] ?? ($obj['id'] ?? '')),
					payload: [
						'externalReference' => (string)($obj['parentCase'] ?? ''),
						'subjectLabel' => (string)($obj['consultationNumber'] ?? 'Consultatie'),
						'question' => (string)($obj['questionFormulation'] ?? ''),
					],
				);
			},
		];
	}//end buildSurfaceRaisers()

	/**
	 * Link every in-flight object of one surface to a decidesk Decision.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param IOutput $output The migration output interface.
	 * @param string $configKey Config key that provisions the surface's schema.
	 * @param callable $raise Callback raising the decidesk Decision.
	 *
	 * @return array{linked: int, skipped: int, errors: int} Per-surface counters.
	 */
	private function linkSurface(
		object $objectService,
		IOutput $output,
		string $configKey,
		callable $raise,
	): array {
		$counts = [
			'linked' => 0,
			'skipped' => 0,
			'errors' => 0,
		];

		// The config key is the PROVISIONING guard, not the identifier the
		// calls take: an empty value means this surface's schema was never
		// configured, which is a silent skip rather than a failure to read.
		if ($this->settingsService->getConfigValue(key: $configKey) === '') {
			return $counts;
		}

		$schemaSlug = (self::SURFACE_SCHEMA_SLUGS[$configKey] ?? '');
		if ($schemaSlug === '') {
			return $counts;
		}

		try {
			// ObjectService::findAll() takes a single $config array — the
			// previous named-argument call (register:/schema:/limit:) threw
			// "Unknown named parameter" on every run. Use the shared
			// slug-aware search bridge, which also normalises the rows to
			// the associative arrays this loop expects. BOTH identifiers are
			// slugs: see SURFACE_SCHEMA_SLUGS for what mixing them cost.
			$objects = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: TenantSaasService::REGISTER,
				schema: $schemaSlug,
				filters: ['_limit' => 500],
			);
		} catch (Throwable $e) {
			$output->warning('Could not list objects for schema ' . $schemaSlug . ': ' . $e->getMessage());
			$this->logger->warning(
				'LinkInFlightRemainingDecisionsRepair: list failed',
				['schema' => $schemaSlug, 'error' => $e->getMessage()]
			);
			return $counts;
		}//end try

		foreach ($objects as $obj) {
			$outcome = $this->linkObject(
				objectService: $objectService,
				output: $output,
				schemaSlug: $schemaSlug,
				raise: $raise,
				obj: $obj,
			);
			if ($outcome !== '') {
				$counts[$outcome]++;
			}
		}//end foreach

		return $counts;
	}//end linkSurface()

	/**
	 * Link a single in-flight object forward to a decidesk Decision.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param IOutput $output The migration output interface.
	 * @param string $schemaSlug The surface schema's slug in dossiq's register.
	 * @param callable $raise Callback raising the decidesk Decision.
	 * @param array<string, mixed> $obj The object row to link.
	 *
	 * @return string The counter to increment: 'linked', 'skipped', 'errors',
	 *                or '' when the row is not countable.
	 */
	private function linkObject(
		object $objectService,
		IOutput $output,
		string $schemaSlug,
		callable $raise,
		array $obj,
	): string {
		$objUuid = (string)($obj['uuid'] ?? ($obj['id'] ?? ''));
		$decisionRef = (string)($obj['decisionRef'] ?? '');
		$besluitRef = (string)($obj['besluitRef'] ?? '');
		$status = (string)($obj['status'] ?? '');

		if ($objUuid === '') {
			return '';
		}

		// REQ-PDRD-006: keep already-linked / already-decided /
		// historical records as the authoritative record — no relink.
		if ($decisionRef !== '' || $besluitRef !== '' || in_array($status, self::TERMINAL_STATUSES, true) === true) {
			return 'skipped';
		}

		try {
			$newRef = $raise($obj);

			// Persist the decisionRef so the outcome can complete in
			// decidesk. Merge the existing object — no field is dropped.
			// A loaded object carries a JSON-encoded property DECODED while
			// the schema still declares it a string, so a bare array_merge
			// writes an array into it and OpenRegister refuses the save.
			$objectService->saveObject(
				object: $this->jsonProperties->mergeForWrite(
					stored: $obj,
					updates: ['decisionRef' => $newRef],
					schemaSlug: $schemaSlug,
				),
				register: TenantSaasService::REGISTER,
				schema: $schemaSlug,
				uuid: $objUuid,
			);
			$output->info('Linked ' . $schemaSlug . ' ' . $objUuid . ' → decidesk Decision ' . $newRef);
			return 'linked';
		} catch (RuntimeException $e) {
			// Decidesk leaf unavailable — warn + skip; never fail the migration.
			$output->warning('Could not link ' . $schemaSlug . ' ' . $objUuid . ': ' . $e->getMessage() . ' — skipping.');
			$this->logger->warning(
				'LinkInFlightRemainingDecisionsRepair: could not link object',
				['schema' => $schemaSlug, 'uuid' => $objUuid, 'error' => $e->getMessage()]
			);
			return 'errors';
		}//end try
	}//end linkObject()
}//end class
