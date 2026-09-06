<?php

/**
 * Dossiq schema slug map.
 *
 * The two declarative tables the schema reconcilers drive from: which
 * OpenRegister schema slug backs which dossiq appconfig key, and which
 * `x-openregister-*` annotation blocks dossiq owns on a schema's configuration.
 *
 * Split out of {@see \OCA\Dossiq\Service\SettingsService} — these are data, not
 * behaviour, and both reconcilers plus the post-import auto-configure step read
 * from them.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Settings
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
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Settings;

/**
 * Schema slug to appconfig key mapping, plus the owned annotation block names.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class SchemaSlugMap {
	/**
	 * Mapping of schema slugs (from dossiq_register.json) to app config keys.
	 *
	 * @var array<string, string>
	 */
	public const SLUG_TO_CONFIG_KEY = [
		'catalog' => 'catalogus_schema',
		'case' => 'case_schema',
		'caseTask' => 'task_schema',
		'status' => 'status_schema',
		'statusRecord' => 'status_record_schema',
		'role' => 'role_schema',
		'result' => 'result_schema',
		'decision' => 'decision_schema',
		'caseType' => 'case_type_schema',
		'statusType' => 'status_type_schema',
		'resultType' => 'result_type_schema',
		'roleType' => 'role_type_schema',
		'propertyDefinition' => 'property_definition_schema',
		'documentType' => 'document_type_schema',
		'decisionType' => 'decision_type_schema',
		'zaaktypeInformatieobjecttype' => 'zaaktype_informatieobjecttype_schema',
		'caseProperty' => 'case_property_schema',
		'caseDocument' => 'case_document_schema',
		'caseObject' => 'case_object_schema',
		'customerContact' => 'customer_contact_schema',
		'decisionDocument' => 'decision_document_schema',
		'dispatch' => 'dispatch_schema',
		'document' => 'document_schema',
		'documentLink' => 'document_link_schema',
		'usageRights' => 'usage_rights_schema',
		'notificationChannel' => 'kanaal_schema',
		'abonnement' => 'abonnement_schema',
		'inspectieChecklist' => 'inspectie_checklist_schema',
		'inspectieRapport' => 'inspectie_rapport_schema',
		'inspection' => 'inspection_schema',
		'inspectionChecklistTemplate' => 'inspection_checklist_template_schema',
		'inspectionChecklistRun' => 'inspection_checklist_run_schema',
		'handhavingsactie' => 'handhavingsactie_schema',
		'adviesAanvraag' => 'advies_aanvraag_schema',
		'mapLayer' => 'map_layer_schema',
		'wmsLayer' => 'wms_layer_schema',
		'workflowTemplate' => 'workflow_template_schema',
		'objection' => 'objection_schema',
		'hearingSession' => 'hearing_session_schema',
		'advisoryReport' => 'advisory_report_schema',
		'appealDecision' => 'appeal_decision_schema',
		'tenant' => 'tenant_schema',
		'aiAuditEntry' => 'ai_audit_entry_schema',
		'appointment' => 'appointment_schema',
		'appointmentProduct' => 'appointment_product_schema',
		'appointmentLocation' => 'appointment_location_schema',
		'caseShare' => 'case_share_schema',
		'partnerOrganization' => 'partner_organization_schema',
		'sharePermissionLevel' => 'share_permission_level_schema',
		'casetransfer' => 'case_transfer_schema',
		'caseFederatedShare' => 'case_federated_share_schema',
		'caseFederatedActivity' => 'case_federated_activity_schema',
		'automaticAction' => 'automatic_action_schema',
		'lhsMatrix' => 'lhs_matrix_schema',
		'lhsRecommendation' => 'lhs_recommendation_schema',
		'location' => 'location_schema',
		'objectionProceeding' => 'bezwaar_schema',
		'bezwaaradviescommissie' => 'bezwaaradviescommissie_schema',
		'bacAdviceRequest' => 'bac_advice_request_schema',
		'beroep' => 'beroep_schema',
		'bezwaarDecision' => 'bezwaar_decision_schema',
		'routingRule' => 'routing_rule_schema',
		'kccAgent' => 'kcc_agent_schema',
		'decisionTable' => 'decision_table_schema',
		'callbackRequest' => 'callback_request_schema',
		'subsidieRegeling' => 'subsidie_regeling_schema',
		'subsidieAanvraag' => 'subsidie_aanvraag_schema',
		'subsidieBeoordeling' => 'subsidie_beoordeling_schema',
		'subsidieBeschikking' => 'subsidie_beschikking_schema',
		'subsidieUitvoering' => 'subsidie_uitvoering_schema',
		'interimReport' => 'tussenrapportage_schema',
		'subsidieVaststelling' => 'subsidie_vaststelling_schema',
		'terugvordering' => 'terugvordering_schema',
		'bewijsstuk' => 'bewijsstuk_schema',
		// KCC-werkplek bridge schemas (kcc-werkplek-zaaksysteem-bridge).
		'contactmoment' => 'contactmoment_schema',
		'kccQuickAction' => 'kcc_quick_action_schema',
		'belplan' => 'belplan_schema',
		'specialistBeschikbaarheid' => 'specialist_beschikbaarheid_schema',
		'doorverbinding' => 'doorverbinding_schema',
		'klantSentiment' => 'klant_sentiment_schema',
		// Complaint management (klachtafhandeling) — Awb chapter 9.
		'complaint' => 'complaint_schema',
		'hearing' => 'hearing_schema',
		'complaintDisposition' => 'complaint_disposition_schema',
		'complaintCategory' => 'complaint_category_schema',
		// Zaakportaal "Mijn gemeente" citizen portal (zaakportaal-mijngemeente).
		'portaalBericht' => 'portaal_bericht_schema',
		'portaalVerzoek' => 'portaal_verzoek_schema',
		'portaalNotificatieVoorkeur' => 'portaal_notificatie_voorkeur_schema',
		// Termijnbewaking + dwangsom (AWB 4:13/4:14/4:17).
		'deadlineDefinition' => 'termijn_definitie_schema',
		'deadlineInstance' => 'termijn_instance_schema',
		'termijnGebeurtenis' => 'termijn_gebeurtenis_schema',
		'noticeOfDefault' => 'ingebrekestelling_schema',
		'penaltyPaymentCalculation' => 'dwangsom_berekening_schema',
		'dwangsomUitbetaling' => 'dwangsom_uitbetaling_schema',
		// Mandaat-matrix authorization engine.
		// KEY renamed with the schema slug; VALUE deliberately left as-is. The
		// value is the app-config key under which this schema's numeric id is
		// already stored on every existing install — renaming it would orphan
		// that id and the schema would silently resolve to nothing.
		'mandateDecision' => 'mandaterings_besluit_schema',
		// KEYS follow the renamed slugs; VALUES are deliberately unchanged.
		// Each value is the app-config key under which that schema's numeric id
		// is already stored on every existing install — renaming it orphans the
		// id and the schema silently resolves to nothing.
		'mandate' => 'mandaat_schema',
		'organisatieRol' => 'organisatie_rol_schema',
		'medewerkerRolToewijzing' => 'medewerker_rol_toewijzing_schema',
		'mandateUsage' => 'mandaat_gebruik_schema',
		'mandateEscalation' => 'mandaat_escalatie_schema',
		'substitution' => 'substitution_schema',
		// Archief / e-Depot SIP handover engine.
		'bewaarTermijnRegel' => 'bewaar_termijn_regel_schema',
		'overdrachtTrigger' => 'overdracht_trigger_schema',
		'sipBundel' => 'sip_bundel_schema',
		'overdrachtTransactie' => 'overdracht_transactie_schema',
		'archiefBewijs' => 'archief_bewijs_schema',
		'overdrachtAuditLog' => 'overdracht_audit_log_schema',
		// Case-email integration (case-email-integration spec).
		'emailTemplate' => 'email_template_schema',
		// Consultation management (consultation-management spec).
		'consultation' => 'consultation_schema',
		'adviceResponse' => 'advice_response_schema',
		'advisoryBody' => 'advisory_body_schema',
		// Milestone tracking (milestone-tracking spec).
		'milestoneDefinition' => 'milestone_definition_schema',
		'milestoneRecord' => 'milestone_record_schema',
		// ZGW DRC case dossier (document-zaakdossier spec).
		'informatieobject' => 'dossier_informatieobject_schema',
		'zaakinformatieobject' => 'dossier_zaakinformatieobject_schema',
		'besluitinformatieobject' => 'dossier_besluitinformatieobject_schema',
		'informatieobjecttype' => 'dossier_informatieobjecttype_schema',
		// CMMN adaptive case-plan definitions (cmmn-adaptive-case spec).
		'caseModel' => 'case_model_schema',
	];

	/**
	 * Declarative `x-openregister-*` annotation blocks (declared inside a
	 * schema's `configuration` in dossiq_register.json) that Dossiq
	 * reconciles directly onto the live OpenRegister schema configuration.
	 *
	 * OpenRegister's app-config import does not reliably round-trip these
	 * schema-level annotation blocks on an already-imported instance, so
	 * {@see SchemaAnnotationReconciler::reconcile()} merges them back in.
	 *
	 * @var string[]
	 */
	public const SCHEMA_ANNOTATION_KEYS = [
		'x-openregister-calculations',
		'x-openregister-references',
		'x-openregister-lifecycle',
		'x-openregister-aggregations',
		'x-openregister-object-source',
	];

	/**
	 * The stable alias key mirrored alongside the `workflowTemplate` schema id.
	 *
	 * Consumer specs (status-transition-engine, role-based-step-routing) resolve
	 * the workflow definition through this key rather than the legacy slug.
	 */
	public const WORKFLOW_DEFINITION_ALIAS = 'workflow_definition_schema';

	/**
	 * The schema slug whose id is mirrored under {@see self::WORKFLOW_DEFINITION_ALIAS}.
	 */
	public const WORKFLOW_TEMPLATE_SLUG = 'workflowTemplate';
}//end class
