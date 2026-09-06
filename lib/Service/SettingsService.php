<?php

/**
 * Dossiq Settings Service
 *
 * Service for managing Dossiq application configuration and settings.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/admin-settings/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCA\Dossiq\Service\Settings\SchemaAnnotationReconciler;
use OCA\Dossiq\Service\Settings\SchemaKeyReconciler;
use OCA\Dossiq\Service\Settings\SchemaSlugResolver;
use OCA\Dossiq\Service\Settings\SchemaSlugMap;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing Dossiq application configuration and settings.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
class SettingsService {
	/**
	 * Configuration keys that contain secrets and must be redacted for non-admin callers.
	 *
	 * Any key matching one of these suffixes is masked with '***' in public responses.
	 *
	 * @var string[]
	 */
	private const SECRET_KEYS = [
		'ai_api_key',
		'appointment_backend_api_key',
		// AI model URL reveals internal infrastructure topology; redact for non-admins.
		'ai_model_url',
		// Dwangsom callback HMAC secret — never expose to non-admin callers.
		'dwangsom_callback_secret',
	];

	private const CONFIG_KEYS = [
		'register',
		'catalogus_schema',
		'case_schema',
		'task_schema',
		'status_schema',
		'status_record_schema',
		'role_schema',
		'result_schema',
		'decision_schema',
		'case_type_schema',
		'status_type_schema',
		'result_type_schema',
		'role_type_schema',
		'property_definition_schema',
		'document_type_schema',
		'decision_type_schema',
		'zaaktype_informatieobjecttype_schema',
		'case_property_schema',
		'case_document_schema',
		'case_object_schema',
		'customer_contact_schema',
		'decision_document_schema',
		'dispatch_schema',
		'document_schema',
		'document_link_schema',
		'usage_rights_schema',
		'kanaal_schema',
		'abonnement_schema',
		'map_layer_schema',
		// WMS/WFS overlay layers (wms-wfs-layers spec REQ-WMS-1).
		'wms_layer_schema',
		'workflow_template_schema',
		// Stable alias for consumer specs (status-transition-engine,
		// role-based-step-routing) that refer to the workflow definition
		// independent of the legacy schema slug.
		'workflow_definition_schema',
		'objection_schema',
		'hearing_session_schema',
		'advisory_report_schema',
		'appeal_decision_schema',
		'default_case_type',
		'inspectie_checklist_schema',
		'inspectie_rapport_schema',
		'handhavingsactie_schema',
		'advies_aanvraag_schema',
		'advice_reminder_days',
		'tenant_schema',
		'appointment_schema',
		'appointment_product_schema',
		'appointment_location_schema',
		'appointment_backend',
		'appointment_backend_url',
		'appointment_backend_api_key',
		'appointment_reminder_days',
		'case_share_schema',
		'partner_organization_schema',
		'share_permission_level_schema',
		'case_transfer_schema',
		// Federated case collaboration (OCM, via OpenRegister's federation leaf).
		'case_federated_share_schema',
		'case_federated_activity_schema',
		'automatic_action_schema',
		'location_schema',
		// Bezwaar (lifecycle) — Awb Hoofdstuk 7.
		'bezwaar_schema',
		// Bezwaar advisory committee (BAC) — Awb Art. 7:13.
		'bezwaaradviescommissie_schema',
		'bac_advice_request_schema',
		'bac_default_committee',
		// Beroep escalation (beroep-escalation spec) — Awb hoofdstuk 8.
		'beroep_schema',
		// Bezwaar decision (bezwaar-decision spec) — Awb art. 7:11/7:12.
		'bezwaar_decision_schema',
		// KCC klantcontact-integratie (kcc-klantcontact-integratie spec).
		// contactMoment reuses the existing customer_contact_schema; only the
		// KCC-specific operational schemas get new config keys here.
		'routing_rule_schema',
		'kcc_agent_schema',
		'callback_request_schema',
		// DMN decision tables (dmn-decision-tables spec).
		'decision_table_schema',
		// Subsidieverlening-keten (subsidieverlening-keten spec) — AWB titel 4.2.
		'subsidie_regeling_schema',
		'subsidie_aanvraag_schema',
		'subsidie_beoordeling_schema',
		'subsidie_beschikking_schema',
		'subsidie_uitvoering_schema',
		'tussenrapportage_schema',
		'subsidie_vaststelling_schema',
		'terugvordering_schema',
		'bewijsstuk_schema',
		'lhsMatrix',
		'lhs_matrix_schema',
		'lhs_recommendation_schema',
		// AI-Assisted Processing settings.
		'ai_audit_entry_schema',
		'ai_enabled',
		'ai_model_type',
		'ai_model_url',
		'ai_model_name',
		'ai_api_key',
		'ai_feature_classification',
		'ai_feature_extraction',
		'ai_feature_qa',
		'ai_feature_summary',
		'ai_feature_routing',
		'ai_feature_decision_support',
		'ai_dpia_acknowledged',
		'ai_pii_stripping',
		// PDOK integration settings (pdok-integration spec).
		// Endpoint overrides — empty falls back to PDOK service defaults.
		'pdok_locatieserver_endpoint',
		'pdok_bag_endpoint',
		'pdok_kadaster_endpoint',
		// OpenConnector source slugs — empty = call PDOK directly.
		'pdok_locatieserver_source',
		'pdok_bag_source',
		'pdok_kadaster_source',
		// Cache TTLs (seconds).
		'pdok_cache_lookup_ttl_seconds',
		'pdok_cache_suggest_ttl_seconds',
		// Per-service rate ceiling (requests / second).
		'pdok_rate_ceiling_rps',
		// Outage banner copy (nl + en).
		'pdok_outage_banner_nl',
		'pdok_outage_banner_en',
		// KCC-werkplek bridge schema config keys (kcc-werkplek-zaaksysteem-bridge).
		'contactmoment_schema',
		'kcc_quick_action_schema',
		'belplan_schema',
		'specialist_beschikbaarheid_schema',
		'doorverbinding_schema',
		'klant_sentiment_schema',
		// KCC-werkplek bridge behaviour settings.
		'identification_method',
		'identification_score_threshold',
		'sentiment_polling_interval',
		'specialist_availability_polling_interval',
		'max_zaken_voorblad',
		'max_contactmomenten_history',
		'quick_action_templates',
		'belplan_overflow_threshold_wachttijd',
		'belplan_overflow_threshold_wachtrij_lengte',
		'sentiment_trigger_words',
		// Complaint management (klachtafhandeling) — Awb chapter 9.
		'complaint_schema',
		'hearing_schema',
		'complaint_disposition_schema',
		'complaint_category_schema',
		// Zaakportaal "Mijn gemeente" citizen portal (zaakportaal-mijngemeente).
		'portaal_bericht_schema',
		'portaal_verzoek_schema',
		'portaal_notificatie_voorkeur_schema',
		// Termijnbewaking + dwangsom engine (AWB 4:13/4:14/4:17).
		'termijn_definitie_schema',
		'termijn_instance_schema',
		'termijn_gebeurtenis_schema',
		'ingebrekestelling_schema',
		'dwangsom_berekening_schema',
		'dwangsom_uitbetaling_schema',
		// Shared secret validating the X-Procest-Signature HMAC-SHA256 header
		// on the public dwangsom payment-confirmation callback (ADR-005;
		// enforce-dwangsom-callback-signature spec). Empty = callback fails
		// closed (401) rather than treated as an implicit pass.
		'dwangsom_callback_secret',
		// Mandaat-matrix authorization engine.
		'mandaterings_besluit_schema',
		'mandaat_schema',
		'organisatie_rol_schema',
		'medewerker_rol_toewijzing_schema',
		'mandaat_gebruik_schema',
		'mandaat_escalatie_schema',
		// Mandate-matrix behaviour knobs edited by MandaatMatrixSettingsTab.vue.
		// ⚠️ Same measured caveat as the consultation_* block above: nothing
		// reads these three yet. They are registered so the admin's save
		// persists rather than being dropped by the CONFIG_KEYS allowlist.
		'mandaat_decidesk_connection',
		'mandaat_default_extension_days',
		'mandaat_auto_finalize_approved',
		// Handler vervanging/waarneming (handler-vervanging-waarneming spec).
		'substitution_schema',
		// Archief / e-Depot SIP handover engine.
		'bewaar_termijn_regel_schema',
		'overdracht_trigger_schema',
		'sip_bundel_schema',
		'overdracht_transactie_schema',
		'archief_bewijs_schema',
		'overdracht_audit_log_schema',
		// Case-email integration (case-email-integration spec).
		// emailTemplate is the only net-new schema; sending/threading live in NC Mail.
		'email_template_schema',
		// Shared-mailbox poller / IMAP-side config (ADR-022 exception).
		'email_imap_host',
		'email_imap_port',
		'email_imap_encryption',
		'email_imap_username',
		'email_imap_password',
		'email_imap_folder',
		'email_transport',
		'email_poll_interval',
		'email_poll_batch_size',
		'email_max_attachment_size',
		// Consultation management (consultation-management spec).
		'consultation_schema',
		'advice_response_schema',
		'advisory_body_schema',
		// Consultation behaviour knobs edited by ConsultationSettingsTab.vue.
		// ⚠️ Registered here because updateSettings() is an ALLOWLIST: a key
		// absent from CONFIG_KEYS is silently dropped on save — the same
		// silent-discard failure as the dead route these fields used to POST to
		// (procest#794), just one layer deeper.
		// ⚠️ MEASURED, not assumed: as of this commit NOTHING reads these four
		// values. A repo-wide grep finds them only in the Vue tab and in this
		// list; the n8n consultation workflows (n8n/consultation-deadline-
		// monitor.json, n8n/consultation-bottleneck-detection.json) do not
		// reference them either. Registering them makes the admin's save
		// round-trip honestly instead of vanishing; wiring a consumer is
		// tracked separately and must not be inferred from their presence here.
		'consultation_default_deadline_days',
		'consultation_warning_offset_days',
		'consultation_external_response_url',
		'consultation_bottleneck_threshold',
		// Besluitvorming workflow integration endpoints (besluitvorming-workflow spec).
		// Official publication (DROP / LVBB) — empty disables dispatch.
		'drop_lvbb_endpoint',
		'drop_lvbb_token',
		// Mandaatregister authority validation — empty falls back to manual confirmation.
		'mandaatregister_endpoint',
		'mandaatregister_token',
		// ZGW DRC case dossier (document-zaakdossier spec).
		'dossier_informatieobject_schema',
		'dossier_zaakinformatieobject_schema',
		'dossier_besluitinformatieobject_schema',
		'dossier_informatieobjecttype_schema',
		// Maximum upload size in bytes (0 = no app-level limit, NC limit applies).
		'dossier_max_file_size',
		// Toggle: organise ZIP export into per-informatieobjecttype sub-folders.
		'dossier_subfolder_per_type',
		// Comma-separated map of NC group ids to clearance levels, e.g.
		// "vertrouwelijk-cleared:vertrouwelijk,geheim-cleared:geheim". Empty
		// means every authenticated user has the baseline clearance below.
		'dossier_clearance_group_map',
		// Baseline clearance for any authenticated user lacking a mapped group.
		'dossier_default_clearance',
		// GIS / geo viewer settings (gis-integration spec).
		// Map library used by the frontend viewer ('leaflet' or 'openlayers').
		'geo_map_library',
		// Default map centre + zoom (Netherlands) for the cases-on-map view.
		'geo_default_center_lat',
		'geo_default_center_lon',
		'geo_default_zoom',
		// Pixel radius for client-side marker clustering.
		'geo_max_cluster_radius',
		// Toggle: expose the public /wfs/cases OGC WFS endpoint.
		'geo_wfs_endpoint_enabled',
		// PDOK Locatieserver cache TTL (seconds) + endpoint override.
		'pdok_locatieserver_cache_ttl',
		'pdok_locatieserver_url',
	];

	/**
	 * Default values for KCC-werkplek bridge behaviour settings.
	 *
	 * Used by getKccConfigValue() so that an unset app-config key resolves to
	 * the documented default rather than an empty string.
	 */
	public const KCC_DEFAULTS = [
		'identification_method' => 'both',
		'identification_score_threshold' => '0.8',
		'sentiment_polling_interval' => '5',
		'specialist_availability_polling_interval' => '30',
		'max_zaken_voorblad' => '10',
		'max_contactmomenten_history' => '5',
		'belplan_overflow_threshold_wachttijd' => '180',
		'belplan_overflow_threshold_wachtrij_lengte' => '5',
		'sentiment_trigger_words' => '["ongelooflijk","complaint","alderman","advocaat","media","rechtszaak"]',
		'quick_action_templates' => '{}',
	];

	/**
	 * Default values for the WOO-publication-via-OpenCatalogi bridge.
	 *
	 * Match OpenCatalogi's own shipped bundle (`lib/Settings/publication_register.json`
	 * in the opencatalogi repo, register slug `publication`, schemas
	 * `publication`/`document`) so publishing works out of the box on a
	 * default install; overridable per instance via getWooPublicationConfigValue().
	 *
	 * @spec openspec/changes/woo-publication-via-opencatalogi/design.md#d1
	 */
	public const WOO_PUBLICATION_DEFAULTS = [
		'woo_publication_register' => 'publication',
		'woo_publication_schema' => 'publication',
		'woo_publication_document_schema' => 'document',
	];

	private const OPENREGISTER_APP_ID = 'openregister';

	/**
	 * The ADR-037 register-fragment merger.
	 *
	 * @var RegisterFragmentMerger
	 */
	private RegisterFragmentMerger $fragments;

	/**
	 * Reconciles `*_schema` appconfig keys against live OpenRegister schema ids.
	 *
	 * @var SchemaKeyReconciler
	 */
	private SchemaKeyReconciler $schemaKeys;

	/**
	 * Reconciles declarative `x-openregister-*` blocks onto live schemas.
	 *
	 * @var SchemaAnnotationReconciler
	 */
	private SchemaAnnotationReconciler $schemaAnnotations;

	/**
	 * The app-config key holding the Besluit schema id.
	 *
	 * @var string
	 */
	private const DECISION_SCHEMA_KEY = 'decision_schema';

	/**
	 * The app that owns the `decision` slug fleet-wide.
	 *
	 * @var string
	 */
	private const DECIDIQ_APP_ID = 'decidiq';

	/**
	 * decidiq's slug for it.
	 *
	 * @var string
	 */
	private const DECISION_SLUG = 'decision';

	/**
	 * Constructor for the SettingsService.
	 *
	 * The three collaborators are constructed here rather than injected so the
	 * container-facing signature stays `(appConfig, appManager, container,
	 * logger)` — the shape the bespoke factory in
	 * {@see \OCA\Dossiq\AppInfo\Registrar\BespokeServiceRegistrar} and ~180
	 * injection sites already use.
	 *
	 * @param IAppConfig $appConfig The app configuration service
	 * @param IAppManager $appManager The app manager service
	 * @param ContainerInterface $container The DI container
	 * @param LoggerInterface $logger The logger interface
	 *
	 * @return void
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IAppManager $appManager,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
		$this->fragments = new RegisterFragmentMerger();

		// One resolver, shared by both reconcilers. They must agree on which
		// schema a slug means: when they disagreed, the config keys pointed at
		// one `task` schema while the calculations were merged onto another.
		$slugResolver = new SchemaSlugResolver(
			appConfig: $appConfig,
			container: $container,
			logger: $logger
		);

		$this->schemaKeys = new SchemaKeyReconciler(
			appConfig: $appConfig,
			container: $container,
			logger: $logger,
			slugResolver: $slugResolver
		);
		$this->schemaAnnotations = new SchemaAnnotationReconciler(
			container: $container,
			fragments: $this->fragments,
			logger: $logger,
			slugResolver: $slugResolver
		);
	}//end __construct()

	/**
	 * Check if OpenRegister is installed and enabled.
	 *
	 * The isEnabledForUser() check resolves against the current user session
	 * and returns false in session-less contexts (occ commands, repair steps,
	 * background jobs) even when OpenRegister is enabled globally — which
	 * silently skipped the bezwaar/beroep seed during install/repair. Fall back
	 * to the session-less isInstalled() check so CLI/background callers see it.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function isOpenRegisterAvailable(): bool {
		return $this->appManager->isEnabledForUser(self::OPENREGISTER_APP_ID) === true
			|| $this->appManager->isInstalled(self::OPENREGISTER_APP_ID) === true;
	}//end isOpenRegisterAvailable()

	/**
	 * Resolve the OpenRegister ObjectService from the DI container.
	 *
	 * Returns null when OpenRegister is not installed/enabled, or when the
	 * container cannot resolve the service (e.g. on a fresh install before
	 * configuration). Callers are expected to handle the null case.
	 *
	 * Mirrors the lazy-resolve pattern already used for ConfigurationService
	 * in loadConfiguration() — OpenRegister is an optional runtime dependency
	 * so we cannot type-hint the class directly in the constructor.
	 *
	 * @return object|null The OpenRegister ObjectService or null when unavailable
	 *
	 * @psalm-suppress MixedReturnStatement
	 * @psalm-suppress MixedInferredReturnType
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getObjectService(): ?object {
		if ($this->isOpenRegisterAvailable() === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Exception $e) {
			$this->logger->error(
				'Dossiq: Could not access OpenRegister ObjectService',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end getObjectService()

	/**
	 * Lazily resolve OpenRegister's FileService for in-process file attachment.
	 *
	 * ADR-084 publishes `ObjectServiceInterface` — 25 methods — and **none of
	 * them attaches a file**. OpenRegister's own `files#create` route runs
	 * `FileService::addFile()`, and `FileService` is not a published contract,
	 * so an app that must attach bytes to an OpenRegister object in process has
	 * exactly this one route. Recorded as a contract gap in
	 * `openspec/changes/woo-publication-in-process-object-writes/proposal.md`
	 * rather than worked around with a self-addressed HTTP call, which is what
	 * ADR-080 D2/D3 forbids.
	 *
	 * Same lazy-resolve contract as {@see self::getObjectService()} and
	 * {@see self::getApprovalService()}: OpenRegister is an optional runtime
	 * dependency, so the class is resolved through the container at call time
	 * rather than type-hinted in the constructor, and callers MUST handle null.
	 *
	 * @return object|null The OpenRegister FileService or null when unavailable
	 *
	 * @psalm-suppress MixedReturnStatement
	 * @psalm-suppress MixedInferredReturnType
	 *
	 * @spec openspec/changes/woo-publication-in-process-object-writes/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function getFileService(): ?object {
		if ($this->isOpenRegisterAvailable() === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\FileService');
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: Could not access OpenRegister FileService',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end getFileService()

	/**
	 * Lazily resolve OpenRegister's ApprovalService for parafering chain delegation.
	 *
	 * Per ADR-022 (apps consume OpenRegister abstractions) the parafering
	 * (sign-off routing) chain-state backend is OpenRegister's
	 * `approval-workflow` capability, exposed through
	 * `OCA\OpenRegister\Service\ApprovalService`. OpenRegister is an optional
	 * runtime dependency, so — exactly like getObjectService() — the class is
	 * resolved through the container at call time rather than type-hinted in the
	 * constructor. Callers MUST handle the null case (graceful degradation to
	 * the legacy in-array path during the migration window).
	 *
	 * @return object|null The OpenRegister ApprovalService or null when unavailable
	 *
	 * @psalm-suppress MixedReturnStatement
	 * @psalm-suppress MixedInferredReturnType
	 *
	 * @spec openspec/changes/migrate-parafering-to-or-approval-workflow/tasks.md#P0.1
	 */
	public function getApprovalService(): ?object {
		if ($this->isOpenRegisterAvailable() === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\ApprovalService');
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: Could not access OpenRegister ApprovalService',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end getApprovalService()

	/**
	 * Lazily resolve an OpenRegister DI class by fully-qualified name.
	 *
	 * Generic helper for the parafering approval bridge to reach OpenRegister's
	 * ApprovalChainMapper / ApprovalStepMapper without a hard constructor
	 * dependency on the optional OpenRegister app.
	 *
	 * @param string $class Fully-qualified OpenRegister class name
	 *
	 * @return object|null The resolved service, or null when unavailable
	 *
	 * @psalm-suppress MixedReturnStatement
	 * @psalm-suppress MixedInferredReturnType
	 *
	 * @spec openspec/changes/migrate-parafering-to-or-approval-workflow/tasks.md#P0.1
	 */
	public function getOpenRegisterClass(string $class): ?object {
		if ($this->isOpenRegisterAvailable() === false) {
			return null;
		}

		try {
			return $this->container->get($class);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: Could not access OpenRegister class',
				['class' => $class, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end getOpenRegisterClass()

	/**
	 * Load the register configuration from dossiq_register.json via ConfigurationService.
	 *
	 * @param bool $force Whether to force re-import regardless of version
	 *
	 * @return array Import result
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) — $force is a simple re-import toggle
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function loadConfiguration(bool $force = false): array {
		if ($this->isOpenRegisterAvailable() === false) {
			return [
				'success' => false,
				'message' => 'OpenRegister is not installed or enabled',
			];
		}

		try {
			$configurationService = $this->container->get(
				'OCA\OpenRegister\Service\ConfigurationService'
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Dossiq: Could not access ConfigurationService',
				['exception' => $e->getMessage()]
			);
			return [
				'success' => false,
				'message' => 'Could not access ConfigurationService: ' . $e->getMessage(),
			];
		}

		$effective = $this->readEffectiveConfiguration();
		if (isset($effective['error']) === true) {
			return $effective['error'];
		}

		$configData = $effective['data'];
		$configVersion = ($configData['info']['version'] ?? '0.0.0');

		try {
			$importResult = $configurationService->importFromApp(
				appId: Application::APP_ID,
				data: $configData,
				version: $configVersion,
				force: $force,
			);

			$configuredCount = $this->schemaKeys->autoConfigureAfterImport(importResult: $importResult);
			$this->reconcileSchemaConfig();

			// 🔴 THE IMPORT DOES NOT CARRY THE DECLARATIVE ANNOTATION BLOCKS, SO
			// MERGE THEM HERE. Importing the register creates the schemas, but the
			// `x-openregister-*` blocks declared alongside them in
			// dossiq_register.json do not survive onto the live schema. Without
			// this call a FRESH instance never gets them: `isTerminalStatus` never
			// materialises, so every completed task keeps reading false and the
			// widgets filtering on it keep showing finished work, and
			// `daysUntilDue` does not exist to extend, so due-date columns render
			// blank. Both failures are silent. The e2e suite caught it on a clean
			// CI install after passing on a dev box where the reconcile had been
			// run by hand. Idempotent, so it is safe on every import.
			$this->reconcileSchemaDeclarativeConfig();

			$this->logger->info(
				'Dossiq: Configuration imported and reconciled',
				['version' => $configVersion, 'configured' => $configuredCount]
			);

			return [
				'success' => true,
				'message' => 'Configuration imported and auto-configured (' . $configuredCount . ' schemas mapped)',
				'version' => $configVersion,
				'configured' => $configuredCount,
				'result' => $importResult,
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Dossiq: Configuration import failed',
				['exception' => $e->getMessage()]
			);
			return [
				'success' => false,
				'message' => 'Import failed: ' . $e->getMessage(),
			];
		}//end try
	}//end loadConfiguration()

	/**
	 * Read dossiq_register.json and deep-merge the ADR-037 register fragments
	 * on top of it, producing the effective register configuration to import.
	 *
	 * Returns either `['data' => array]` on success or `['error' => array]`
	 * carrying the caller-facing failure shape, so {@see loadConfiguration()}
	 * stays a single import flow rather than also being a file reader.
	 *
	 * @return array{data?: array, error?: array}
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	private function readEffectiveConfiguration(): array {
		$configPath = __DIR__ . '/../Settings/dossiq_register.json';
		if (file_exists($configPath) === false) {
			$this->logger->error(
				'Dossiq: Configuration file not found at ' . $configPath
			);
			return [
				'error' => [
					'success' => false,
					'message' => 'Configuration file not found',
				],
			];
		}

		$configContent = file_get_contents($configPath);
		$configData = json_decode($configContent, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->logger->error('Dossiq: Invalid JSON in configuration file');
			return [
				'error' => [
					'success' => false,
					'message' => 'Invalid JSON in configuration file',
				],
			];
		}

		// ADR-037: deep-merge any modular register fragments from
		// lib/Settings/register.d/*.json on top of the monolith. This lets
		// concurrent same-app builds add registers/schemas via isolated
		// fragment files instead of all editing dossiq_register.json and
		// conflicting. Fragments are applied in sorted filename order.
		// The merge also returns a hash of the fragment set. It is deliberately
		// not captured: it used to be folded into the version so that adding or
		// changing a fragment forced a re-import, but OpenRegister gates with
		// version_compare, which treats `+…` as further version parts and
		// compares them LEXICALLY rather than as semver build metadata — so
		// whether the gate fired depended on how two md5 hashes happened to
		// sort. Unchanged content re-imported about half the time; a real
		// change was skipped the other half. OpenRegister now hashes the merged
		// configuration itself and skips on hash equality, which detects a
		// changed fragment from the data. The version stays a version.
		[$configData] = $this->fragments->merge(
			base: $configData,
			fragmentDir: __DIR__ . '/../Settings/register.d'
		);

		return ['data' => $configData];
	}//end readEffectiveConfiguration()

	/**
	 * Get all current settings as an associative array.
	 *
	 * Returns full (unredacted) settings including secrets. Callers MUST
	 * ensure only admin users receive this response.
	 *
	 * @return array
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getSettings(): array {
		$config = [];
		foreach (self::CONFIG_KEYS as $key) {
			$config[$key] = $this->appConfig->getValueString(Application::APP_ID, $key, '');
		}

		return $config;
	}//end getSettings()

	/**
	 * Get settings safe for non-admin callers.
	 *
	 * Identical to getSettings() but replaces every SECRET_KEYS entry
	 * with '***' so that bearer tokens and API keys are never exposed
	 * to ordinary authenticated users.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function getPublicSettings(): array {
		$config = $this->getSettings();
		foreach (self::SECRET_KEYS as $secretKey) {
			if (isset($config[$secretKey]) === true && $config[$secretKey] !== '') {
				$config[$secretKey] = '***';
			}
		}

		return $config;
	}//end getPublicSettings()

	/**
	 * Update settings with the provided data.
	 *
	 * @param array $data The settings data to update
	 *
	 * @return array
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function updateSettings(array $data): array {
		foreach (self::CONFIG_KEYS as $key) {
			if (isset($data[$key]) === true) {
				$this->appConfig->setValueString(Application::APP_ID, $key, (string)$data[$key]);
			}
		}

		$this->logger->info('Dossiq settings updated', ['keys' => array_keys($data)]);

		return $this->getSettings();
	}//end updateSettings()

	/**
	 * Get a single configuration value by key.
	 *
	 * @param string $key The configuration key
	 * @param string $default The default value if key not found
	 *
	 * @return string
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function getConfigValue(string $key, string $default = ''): string {
		$value = $this->appConfig->getValueString(Application::APP_ID, $key, $default);

		// LAST, and only when nothing local answered.
		//
		// A schema slug is global per organisation, and two apps declared a
		// `decision`: decidiq's is the governance decision (motion, voting,
		// adoption, repeals) and this app's was the VNG Besluit behind the BRC.
		// `SchemaMapper::find()` matches `LOWER(slug)`, so whichever row it
		// reached first answered for both. decidiq's Decision now carries the
		// four BRC fields it lacked (decidiq#1161), so it can hold the record,
		// and the BrcController stays here — the standard belongs where it is
		// served from — reading decidiq's schema instead of a second one.
		//
		// Resolving LAST is what makes this safe to ship before any migration.
		// An instance that still has its own `decision_schema` configured keeps
		// using it, because its besluiten are in that schema; a fresh install
		// has no such key and lands on decidiq's. Preferring decidiq
		// unconditionally would have pointed every existing instance at an empty
		// schema, and the BRC would have answered 404 for every besluit it had.
		if ($value === '' && $key === self::DECISION_SCHEMA_KEY) {
			return $this->decidiqDecisionSchemaId();
		}

		return $value;
	}//end getConfigValue()

	/**
	 * The id of decidiq's `decision` schema, or '' when it cannot be resolved.
	 *
	 * Looked up by the `(application, slug)` PAIR rather than by slug alone.
	 * Slug alone is exactly the ambiguity this exists to end: it would match
	 * this app's own row as readily as decidiq's, and which one it returned
	 * would depend on insertion order.
	 *
	 * Fails to '' rather than throwing. decidiq is an optional peer, and a
	 * caller that gets '' behaves as it always did when the key was unset.
	 *
	 * @return string The schema id, or '' when decidiq or its schema is absent.
	 *
	 * @spec openspec/changes/the-besluit-resolves-to-decidiqs-decision/specs/zgw-brc/spec.md#requirement-the-besluit-resolves-to-decidiqs-decision-req-brc-020
	 */
	private function decidiqDecisionSchemaId(): string {
		if ($this->appManager->isInstalled(self::DECIDIQ_APP_ID) === false) {
			return '';
		}

		try {
			$schemaMapper = $this->container->get('OCA\\OpenRegister\\Db\\SchemaMapper');
			if (method_exists($schemaMapper, 'findByApplicationAndSlug') === false) {
				return '';
			}

			$schema = $schemaMapper->findByApplicationAndSlug(self::DECISION_SLUG, self::DECIDIQ_APP_ID);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Dossiq: decidiq\'s decision schema is unavailable',
				['error' => $e->getMessage()]
			);
			return '';
		}

		if ($schema === null) {
			return '';
		}

		return (string)$schema->getId();
	}//end decidiqDecisionSchemaId()

	/**
	 * Get a KCC-werkplek behaviour setting, falling back to its documented default.
	 *
	 * Unlike getConfigValue(), an unset key resolves to the value declared in
	 * self::KCC_DEFAULTS rather than an empty string. This keeps the KCC bridge
	 * functional out-of-the-box before an administrator visits the settings form.
	 *
	 * @param string $key The configuration key (must exist in self::KCC_DEFAULTS).
	 *
	 * @return string The configured value, or the documented default.
	 *
	 * @spec openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md
	 */
	public function getKccConfigValue(string $key): string {
		$default = (self::KCC_DEFAULTS[$key] ?? '');
		$value = $this->appConfig->getValueString(Application::APP_ID, $key, $default);
		if ($value === '') {
			return $default;
		}

		return $value;
	}//end getKccConfigValue()

	/**
	 * Get a WOO-publication-via-OpenCatalogi bridge setting, falling back to
	 * its documented default.
	 *
	 * Mirrors getKccConfigValue(): an unset key resolves to the value
	 * declared in self::WOO_PUBLICATION_DEFAULTS (OpenCatalogi's own shipped
	 * register/schema slugs) rather than an empty string, so publishing works
	 * out of the box before an administrator visits the settings form.
	 *
	 * @param string $key The configuration key (must exist in self::WOO_PUBLICATION_DEFAULTS).
	 *
	 * @return string The configured value, or the documented default.
	 *
	 * @spec openspec/changes/woo-publication-via-opencatalogi/design.md#d1
	 */
	public function getWooPublicationConfigValue(string $key): string {
		$default = (self::WOO_PUBLICATION_DEFAULTS[$key] ?? '');
		$value = $this->appConfig->getValueString(Application::APP_ID, $key, $default);
		if ($value === '') {
			return $default;
		}

		return $value;
	}//end getWooPublicationConfigValue()

	/**
	 * Set a single configuration value.
	 *
	 * @param string $key The configuration key
	 * @param string $value The value to set
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function setConfigValue(string $key, string $value): void {
		$this->appConfig->setValueString(Application::APP_ID, $key, $value);
	}//end setConfigValue()

	/**
	 * Reconcile every `*_schema` appconfig key directly from OpenRegister.
	 *
	 * `autoConfigureAfterImport()` only persists schema IDs that appear in the
	 * ConfigurationService import RESULT. On an already-imported instance an
	 * idempotent re-import returns an empty `schemas` list, so the per-schema
	 * config keys (case_type_schema, status_type_schema, status_record_schema,
	 * workflow_template_schema, …) were never written — the status-name lookup
	 * and the WorkflowBoard then silently broke on a fresh deploy.
	 *
	 * This method closes that gap: for each schema slug Dossiq knows about it
	 * resolves the LIVE schema ID via OpenRegister's SchemaMapper (slug-aware
	 * `find()`) and writes the matching appconfig key. It is fully idempotent —
	 * a key that already holds the correct ID is left untouched — so it is safe
	 * to call on every install/upgrade and after every import.
	 *
	 * @return int The number of schema config keys (re)written.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function reconcileSchemaConfig(): int {
		if ($this->isOpenRegisterAvailable() === false) {
			return 0;
		}

		return $this->schemaKeys->reconcile();
	}//end reconcileSchemaConfig()

	/**
	 * Reconcile each schema's declarative `x-openregister-*` annotation blocks
	 * (calculations, references, lifecycle, …) from dossiq_register.json onto
	 * the LIVE OpenRegister schema's `configuration` column.
	 *
	 * OpenRegister's app-config import maps a schema's `properties` but does not
	 * reliably round-trip the schema-level `configuration` annotation blocks on
	 * an already-imported instance (the per-schema version gate plus the import
	 * pipeline can drop the nested `x-openregister-*` keys). The status engine,
	 * the declarative calculation engine and the reference resolver all read
	 * those blocks from `Schema::getConfiguration()`, so a dropped block silently
	 * disables auto-deadline / auto-identifier / initial-status on create.
	 *
	 * The reconcile itself lives in {@see SchemaAnnotationReconciler}: for every
	 * schema defined in the (fragment-merged) register JSON it reads the
	 * annotation keys listed in {@see SchemaSlugMap::SCHEMA_ANNOTATION_KEYS} and
	 * writes them onto the live schema's configuration via the SchemaMapper,
	 * MERGING (never replacing) so existing keys such as `objectNameField` are
	 * preserved. Fully idempotent: a schema whose live configuration already
	 * matches is left untouched.
	 *
	 * @return int The number of schemas whose configuration was (re)written.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function reconcileSchemaDeclarativeConfig(): int {
		if ($this->isOpenRegisterAvailable() === false) {
			return 0;
		}

		return $this->schemaAnnotations->reconcile();
	}//end reconcileSchemaDeclarativeConfig()
}//end class
