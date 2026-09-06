<?php

/**
 * Repair step decisions — translate stored Dutch enum VALUES to English.
 *
 * Every predicate the value migration needs, with no database in sight. The
 * step itself reaches storage through ValueMigrationPort, so everything that
 * can be got wrong is exercised by ordinary unit tests.
 *
 * Scope note: the ZGW and StUF adapter layers are deliberately absent from this
 * map. Their vocabulary is the STANDARD's, which is Dutch by statute, and it
 * travels on the wire — translating it would break the mapping the adapter
 * exists to perform. `LoadDefaultZgwMappings` keeps `openbaar`, `zaak` and the
 * rest exactly as ZGW spells them.
 *
 * That note was written and then contradicted by the map below it, for four
 * properties and seventeen values, until dossiq#1841. The rule only became
 * enforceable once `ShippedEnumValueConformanceTest` asked the schemas rather
 * than the docblock: a rewrite is wrong when the schema DECLARES the value it
 * rewrites from and REFUSES the one it rewrites to. All seventeen were, and
 * not one schema in the merged register declared any replacement, so nothing
 * needed them:
 *
 * - `confidentiality` on case, caseType, document and documentType, and
 *   `vertrouwelijkheidaanduiding` on informatieobject and informatieobjecttype.
 *   Both spell the ZGW Vertrouwelijkheidaanduiding. `LoadDefaultZgwMappings`
 *   declares an IDENTITY valueMapping for them in both directions, so the
 *   internal value IS the wire value; `InformatieobjectAccessGuard::HIERARCHY`
 *   and `ZgwAuthMiddleware::CONFIDENTIALITY_ORDER` both rank the Dutch eight.
 *   The guard fails CLOSED on a value it cannot rank, so rewriting `openbaar`
 *   to `public` reclassified the most public documents this app holds as the
 *   most secret, and `isConfidentialityAllowed()` answered false for every ZGW
 *   consumer.
 * - `stufMessage.status`. `StufMessageHandler` writes `verzonden` and
 *   `wacht_op_retry`, `StufAuditLog.vue` filters on the Dutch four, and the
 *   schema declares them. Only this map disagreed.
 * - `zaaksysteemMapping.synchronisationStatus`. `StufCaseMappingStore` and
 *   `ContactBetrokkeneMapper` write `in_sync` against the same Dutch enum.
 *
 * The damage is not theoretical: this map rewrites STORED rows by column on
 * every upgrade, so all 28 shipped cases and every caseType, document and
 * informatieobject on an upgraded instance held a value their own schema
 * refused. `RealignStatutoryVocabulary` moves them back.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

/**
 * Pure predicates for the Dutch-to-English value migration.
 *
 * @spec exclude Predicate of the Dutch-to-English vocabulary migration.
 */
class RenameDutchValueDecisions {

	/**
	 * Property => stored Dutch value => English replacement.
	 *
	 * Keyed by the PROPERTY that declares the enum, never by the word alone.
	 * The Awb outcome vocabulary is three distinct words and stays three:
	 * `gegrond` (upheld), `ongegrond` (dismissed on the merits) and
	 * `niet-ontvankelijk` (inadmissible — not considered at all).
	 *
	 * @var array<string, array<string, string>>
	 */
	public const VALUE_MAP = [
		'status' => [
			'gepland' => 'planned',
			'aangevraagd' => 'requested',
			'ontvangen' => 'received',
			'verlopen' => 'expired',
			'concept' => 'draft',
			'in_uitvoering' => 'in_execution',
			'ingediend' => 'submitted',
			'gearchiveerd' => 'archived',
			'in_beoordeling' => 'in_assessment',
			'beoordeeld' => 'assessed',
			'beschikking_opgesteld' => 'decision_prepared',
			'verleend' => 'granted',
			'afgewezen' => 'rejected',
			'vastgesteld' => 'determined',
			'in-behandeling' => 'in-handling',
			'afgehandeld' => 'handled',
			'betaald' => 'paid',
			'on-hold-bezwaar' => 'on-hold-objection',
			'vervallen' => 'lapsed',
			'definitief' => 'final',
			'ter_vaststelling' => 'for_determination',
			'Ontvangen' => 'Received',
			'Ontvankelijkheidstoets' => 'AdmissibilityCheck',
			'In behandeling' => 'In handling',
			'Hoorzitting gepland' => 'Hearing planned',
			'Hoorzitting afgerond' => 'Hearing completed',
			'Advies uitgebracht' => 'Advice uitgebracht',
			'Beslissing op bezwaar' => 'Decision on objection',
			'Afgehandeld' => 'Handled',
			'Niet-ontvankelijk' => 'Inadmissible',
			'Ingetrokken' => 'Withdrawn',
			'uitgevoerd' => 'executed',
			'geannuleerd' => 'cancelled',
			'in_behandeling' => 'in_handling',
			'advies_uitgebracht' => 'advice_uitgebracht',
			'afgesloten' => 'closed',
			'niet-ontvankelijk' => 'inadmissible',
			'ontvangst_bevestigd' => 'receipt_confirmed',
			'hoorgesprek_gepland' => 'hoorgesprek_planned',
			'hoorgesprek_afgerond' => 'hoorgesprek_completed',
			'niet_storen' => 'non_storen',
			'melding' => 'report',
			'onderzoek-loopt' => 'investigation-loopt',
			'beschikking-voorbereiding' => 'decision-voorbereiding',
			'beschikking-verleend' => 'decision-granted',
			'uitvoering' => 'execution',
			'evaluatie' => 'evaluation',
			'ondersteuning-gestart' => 'support-gestart',
			'ondersteuning-loopt' => 'support-loopt',
			'verlenging-aangevraagd' => 'extension-requested',
			'aanvraag-ontvangen' => 'request-received',
			'toetsing-afgerond' => 'toetsing-completed',
			'beschikking-gereed' => 'decision-gereed',
			'bijstand-actief' => 'social-assistance-active',
			're-integratie-loopt' => 're-integration-loopt',
			'tussenrapportage_ontvangen' => 'interim_report_received',
			'tussenrapportage_beoordeeld' => 'interim_report_assessed',
			'vaststelling_aangevraagd' => 'determination_requested',
			'terugvordering_gestart' => 'recovery_gestart',
			'afgerond' => 'completed',
			'verwacht' => 'expected',
			'goedgekeurd' => 'approved',
			'afgekeurd' => 'rejected',
			'gedeeltelijk_goedgekeurd' => 'gedeeltelijk_approved',
			'gedeeltelijk_betaald' => 'gedeeltelijk_paid',
			'invordering_afgerond' => 'recovery_completed',
			'gepauzeerd' => 'paused',
			'voltooid' => 'completed',
			'overschreden' => 'exceeded',
			'gestopt-wegens-beschikking' => 'gestopt-wegens-decision',
			'bezwaar-bevroren' => 'objection-bevroren',
			'geaccepteerd' => 'accepted',
			'geweigerd' => 'refused',
		],
		'type' => [
			'motie' => 'motion',
			'amendement' => 'amendment',
			'rapport' => 'report',
			'notulen' => 'minutes',
			'hoorzitting' => 'hearing',
			'dt_advies' => 'dt_advice',
			'advies' => 'advice',
			'waarschuwing' => 'warning',
			'last_onder_dwangsom' => 'last_under_penaltypayment',
			'proces_verbaal' => 'process_verbaal',
			'tekst' => 'text',
			'foto' => 'photo',
			'documentVerzoek' => 'documentRequest',
			'geen' => 'none',
			'pauze' => 'pause',
			'overschreden' => 'exceeded',
			'ingebrekestelling-ontvangen' => 'ingebrekestelling-received',
			'dwangsom-gestart' => 'penaltypayment-gestart',
			'pauze-verlopen' => 'pause-expired',
			'bezwaar-ingediend' => 'objection-submitted',
			'bezwaar-opgelost' => 'objection-resolved',
			'ja_nee_nvt' => 'yes_no_na',
		],
		'stem' => [
			'voor' => 'for',
			'tegen' => 'against',
			'onthouding' => 'abstention',
		],
		'role' => [
			'raadslid' => 'councilMember',
			'wethouder' => 'alderman',
			'burgemeester' => 'mayor',
			'griffier' => 'clerk',
		],
		'objectType' => [
			'zaak' => 'case',
		],
		'cascadeAction' => [
			'reopen_bezwaar' => 'reopen_objection',
		],
		'priority' => [
			'hoog' => 'high',
			'normaal' => 'normal',
			'laag' => 'low',
		],
		'result' => [
			'niet_conform' => 'non_conform',
			'deels_conform' => 'partly_conform',
			'geweigerd-geen-toegang' => 'geweigerd-none-toegang',
		],
		'overallResult' => [
			'niet_conform' => 'non_conform',
			'deels_conform' => 'partly_conform',
		],
		'currentStatus' => [
			'ontwerp' => 'draft',
			'akkoord-mandaat' => 'approved-mandate',
			'ondertekend' => 'signed',
			'verzonden' => 'sent',
			'ontvangen-bevestiging' => 'received-confirmation',
			'gearchiveerd' => 'archived',
		],
		'trigger' => [
			'handmatig' => 'manual',
			'automatisch' => 'automatic',
		],
		'stateAidCategory' => [
			'geen' => 'none',
		],
		'linkedIn' => [
			'aanvraag' => 'request',
			'tussenrapportage' => 'interimReport',
			'vaststelling' => 'determination',
		],
		'dsoStatus' => [
			'ingediend' => 'submitted',
			'in_behandeling' => 'in_handling',
			'verleend' => 'granted',
			'geweigerd' => 'refused',
		],
		'intakeChannel' => [
			'overig' => 'other',
		],
		'archiveStatus' => [
			'gearchiveerd' => 'archived',
			'gearchiveerd_procestermijn_onbekend' => 'archived_retention_period_unknown',
			'actief' => 'active',
		],
		'paymentIndication' => [
			'nog_niet' => 'not_yet',
		],
		'proposalType' => [
			'dt_advies' => 'dt_advice',
		],
		'receivedChannel' => [
			'formulier' => 'form',
		],
		'adviceType' => [
			'deels_gegrond' => 'partly_upheld',
			'niet_ontvankelijk' => 'inadmissible',
			'gegrond' => 'upheld',
			'ongegrond' => 'dismissed',
			'ontvankelijk' => 'admissible',
		],
		'dispositionType' => [
			'deels_gegrond' => 'partly_upheld',
			'niet_ontvankelijk' => 'inadmissible',
			'gegrond' => 'upheld',
			'ongegrond' => 'dismissed',
			'ontvankelijk' => 'admissible',
			'gegrond_handhaven' => 'upheld_maintain',
			'gegrond_herroepen' => 'upheld_revoke',
			'gegrond_wijzigen' => 'upheld_amend',
		],
		'filingMethod' => [
			'beide' => 'both',
		],
		'judgmentOutcome' => [
			'in_stand_gelaten' => 'upheld',
			'niet_ontvankelijk' => 'inadmissible',
			'gegrond_rechtsgevolgen_in_stand' => 'upheld_legal_effects_maintained',
			'gegrond' => 'upheld',
			'ongegrond' => 'dismissed',
			'ontvankelijk' => 'admissible',
		],
		'advies' => [
			'positief_met_voorwaarden' => 'positief_with_terms',
			'negatief' => 'negative',
			'niet_van_toepassing' => 'non_from_application',
			'positief' => 'positive',
		],
		'intervention' => [
			'waarschuwing' => 'warning',
			'last_onder_dwangsom' => 'last_under_penaltypayment',
		],
		// 🔴 `overheid` => `government` IS DELIBERATELY ABSENT (dossiq#1596).
		//
		// actorType is not free vocabulary: it is one AXIS of the Landelijke
		// Handhavingsstrategie matrix, and the matrix's 48 cells are keyed
		// `severity:behaviour:actorType`. Translating one member of that axis
		// and not the cells split the vocabulary in half. The recommendation
		// schema then offered `government` while every cell said `overheid`,
		// so `LhsRecommendationService::recommend()` threw
		// "Geen LHS-cel gevonden" for a quarter of the matrix — twelve of the
		// forty-eight cells were unreachable, and nothing reported it because
		// the throw looks like bad input rather than a broken vocabulary.
		//
		// The other three axis values (burger, bedrijf, recidivist) were never
		// translated, which is the tell: this was a single-word rename applied
		// to a member of a set, not a translation of the set.
		//
		// Restoring it here would re-break the axis on the next upgrade.
		// `RealignLhsActorTypeVocabulary` repairs instances that already ran it.
		'actorType' => [
			'medewerker' => 'employee',
		],
		'recommendedIntervention' => [
			'waarschuwing' => 'warning',
			'last_onder_dwangsom' => 'last_under_penaltypayment',
		],
		'finalIntervention' => [
			'waarschuwing' => 'warning',
			'last_onder_dwangsom' => 'last_under_penaltypayment',
		],
		'responseType' => [
			'tekst' => 'text',
			'foto' => 'photo',
			'ja_nee_nvt' => 'yes_no_na',
		],
		'photoRequired' => [
			'bij_nee' => 'if_no',
		],
		'followUpType' => [
			'documentVerzoek' => 'documentRequest',
			'geen' => 'none',
		],
		'domain' => [
			'algemeen' => 'general',
			'sociaal_domein' => 'social_domain',
		],
		'conclusion' => [
			'niet_ontvankelijk' => 'inadmissible',
			'gegrond' => 'upheld',
			'ongegrond' => 'dismissed',
			'ontvankelijk' => 'admissible',
			'gedeeltelijk_gegrond' => 'partly_upheld',
		],
		'opinion' => [
			'deels_gegrond' => 'partly_upheld',
			'niet_ontvankelijk' => 'inadmissible',
			'gegrond' => 'upheld',
			'ongegrond' => 'dismissed',
			'ontvankelijk' => 'admissible',
		],
		'approvalStatus' => [
			'wacht_op_goedkeuring' => 'awaiting_approval',
			'goedgekeurd' => 'approved',
			'afgekeurd' => 'rejected',
		],
		'decisionType' => [
			'afwijzing' => 'rejection',
			'wijziging' => 'amendment',
			'intrekking' => 'withdrawal',
		],
		'identificationMethod' => [
			'niet_geidentificeerd' => 'non_geidentificeerd',
		],
		'nature' => [
			'klacht' => 'complaint',
			'melding' => 'report',
			'nieuwe_aanvraag' => 'new_request',
		],
		'actionType' => [
			'nieuwe_zaak' => 'new_case',
			'klacht_registreren' => 'complaint_registreren',
		],
		'sentimentLabel' => [
			'neutraal' => 'neutral',
			'negatief' => 'negative',
			'positief' => 'positive',
		],
		'escalationLevel' => [
			'geen' => 'none',
		],
		'gedeeldeGegevens' => [
			'alleen-anonimiseerde-samenvatting' => 'alleen-anonimiseerde-summary',
		],
		'requestKind' => [
			'algemene-bijstand' => 'general-social-assistance',
			'inburgering-gerelateerde-bijstand' => 'inburgering-related-social-assistance',
			'bijstand-werkloze-uitkeringontvangers' => 'social-assistance-werkloze-uitkeringontvangers',
		],
		'distanceToLabourMarket' => [
			'gemiddeld' => 'average',
		],
		'interimReportFrequency' => [
			'geen' => 'none',
			'jaarlijks' => 'annually',
			'op_mijlpaal' => 'on_milestone',
		],
		'substantiveAssessmentOpinion' => [
			'nader_onderzoek' => 'nader_investigation',
		],
		'financialAssessmentOpinion' => [
			'onacceptabel_cofinanciering' => 'onacceptabel_co_financing',
		],
		'evidenceType' => [
			'urenstaat' => 'timesheet',
			'factuur' => 'invoice',
			'accountantsverklaring' => 'auditorsStatement',
		],
		'senderType' => [
			'gemachtigde' => 'authorisedRepresentative',
		],
		'kind' => [
			'subsidie-aanvraag' => 'subsidy-request',
		],
		'submitterType' => [
			'gemachtigde' => 'authorisedRepresentative',
		],
		'notificationChannel' => [
			'portaal' => 'portal',
		],
		'validityStatus' => [
			'onbekend' => 'unknown',
			'geldig' => 'valid',
		],
		'competenceType' => [
			'mandaat' => 'mandate',
		],
		'allocationType' => [
			'waarnemer' => 'observer',
		],
		'escalationReason' => [
			'niet_bevoegd' => 'non_competent',
			'plafond_overschreden' => 'ceiling_exceeded',
			'subdelegatie_niet_toegestaan' => 'subdelegatie_non_permitted',
		],
		'natureRelationshipDisplay' => [
			'Hoort bij, omgekeerd' => 'Hoort at omgekeerd',
		],
		'items' => [
			'financieel' => 'financial',
		],
	];

	/**
	 * Convert a property name to the column MagicMapper materialised.
	 *
	 * Mirrors `MagicMapper::sanitizeColumnName()`, which applies ONLY the
	 * ([a-z0-9])([A-Z]) boundary — there is no acronym rule. Spell it any other
	 * way and every UPDATE matches nothing while the step reports success.
	 *
	 * @param string $name Property name.
	 *
	 * @return string Column name.
	 *
	 * @spec exclude Predicate of the Dutch-to-English vocabulary migration.
	 */
	public function columnFor(string $name): string {
		$column = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name);
		$column = strtolower((string)$column);
		$column = preg_replace('/[^a-z0-9_]/', '_', $column);
		$column = preg_replace('/_+/', '_', (string)$column);

		return rtrim((string)$column, '_');
	}//end columnFor()

	/**
	 * Work out which value rewrites a table actually needs.
	 *
	 * A property whose column the table does not have is skipped: shard tables
	 * are per-schema, so most carry only a few of the mapped columns, and an
	 * UPDATE against a missing column is an error rather than a no-op.
	 *
	 * @param array<string, array<string, string>> $valueMap Property => old => new.
	 * @param array<int, string> $columns Columns the table has.
	 *
	 * @return array<int, array{column: string, old: string, new: string}>
	 *
	 * @spec exclude Predicate of the Dutch-to-English vocabulary migration.
	 */
	public function plannedRewrites(array $valueMap, array $columns): array {
		$planned = [];

		foreach ($valueMap as $property => $values) {
			$column = $this->columnFor(name: $property);
			if (in_array($column, $columns, true) === false) {
				continue;
			}

			foreach ($values as $old => $new) {
				$planned[] = [
					'column' => $column,
					'old' => (string)$old,
					'new' => $new,
				];
			}
		}

		return $planned;
	}//end plannedRewrites()

	/**
	 * Pull a single column out of information_schema rows.
	 *
	 * Defensive: a null cell yields an empty string rather than a TypeError
	 * inside a repair step, where an exception aborts the upgrade.
	 *
	 * @param array<int, array<string, mixed>> $rows Result rows.
	 * @param string $key Column to read.
	 *
	 * @return array<int, string>
	 *
	 * @spec exclude Predicate of the Dutch-to-English vocabulary migration.
	 */
	public function column(array $rows, string $key): array {
		return array_map(static fn (array $row): string => (string)($row[$key] ?? ''), $rows);
	}//end column()

	/**
	 * The line the step reports when there is nothing to migrate.
	 *
	 * @return string
	 *
	 * @spec exclude Operator-facing text of the vocabulary migration.
	 */
	public function nothingToDoMessage(): string {
		return 'RenameDutchValues: no dossiq shard tables on this install; nothing to do.';
	}//end nothingToDoMessage()

	/**
	 * The line the step reports after migrating.
	 *
	 * @param int $updated Rows rewritten.
	 *
	 * @return string
	 *
	 * @spec exclude Operator-facing text of the vocabulary migration.
	 */
	public function summaryMessage(int $updated): string {
		return sprintf('RenameDutchValues: %d row value(s) translated.', $updated);
	}//end summaryMessage()

	/**
	 * Map entries whose replacement differs from the source by CASE alone.
	 *
	 * Such an entry translates nothing while still producing a diff, so it reads
	 * as a translation that was made; where the source is an identifier it
	 * renames the identifier instead. Returned rather than thrown so the caller
	 * decides; the test asserts empty.
	 *
	 * @param array<string, array<string, string>> $valueMap Property => old => new.
	 *
	 * @return array<int, string> One "property: old -> new" line per offender.
	 *
	 * @spec exclude Self-check on the vocabulary migration's own map.
	 */
	public function caseOnlyEntries(array $valueMap): array {
		$offenders = [];

		foreach ($valueMap as $property => $values) {
			foreach ($values as $old => $new) {
				$normalise = static fn (string $value): string
					=> strtolower((string)preg_replace('/[^a-z0-9]/i', '', $value));
				if ($normalise((string)$old) !== $normalise($new)) {
					continue;
				}

				$offenders[] = sprintf('%s: %s -> %s', (string)$property, (string)$old, $new);
			}
		}

		return $offenders;
	}//end caseOnlyEntries()
}//end class
