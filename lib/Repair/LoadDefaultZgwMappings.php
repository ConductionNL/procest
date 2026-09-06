<?php

/**
 * Dossiq Load Default ZGW Mappings Repair Step
 *
 * Repair step that loads default ZGW API mapping configurations into IAppConfig.
 * These mappings define how English OpenRegister properties translate to/from
 * Dutch ZGW API properties using Twig templates.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use DateTime;
use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\ZgwMappingService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step that loads default ZGW API mapping configurations.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
class LoadDefaultZgwMappings implements IRepairStep {
	use RunsUnderSystemIdentity;

	/**
	 * Twig template prefix: replace path segment and append UUID variable.
	 *
	 * Used to build cross-resource URL references in ZGW property mappings.
	 * Pattern: {{ _baseUrl | replace({"<from>": "<to>"}) }}/{{ <var> }}
	 */
	private const TPL_PREFIX = '{{ _baseUrl | replace({"%s": "%s"}) }}/{{ %s }}';

	/**
	 * Constructor for LoadDefaultZgwMappings.
	 *
	 * @param ZgwMappingService $zgwMappingService The ZGW mapping service
	 * @param SettingsService $settingsService The settings service
	 * @param LoggerInterface $logger The logger interface
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ZgwMappingService $zgwMappingService,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function getName(): string {
		return 'Load default ZGW API mapping configurations for Dossiq';
	}//end getName()

	/**
	 * Run the repair step to load default ZGW mappings.
	 *
	 * Only loads mappings that do not already exist (does not overwrite).
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function run(IOutput $output): void {
		$output->info('Loading default ZGW API mappings...');

		$registerId = $this->settingsService->getConfigValue(key: 'register', default: '');
		if ($registerId === '') {
			$output->warning('No Dossiq register configured yet. Skipping ZGW mapping defaults.');
			return;
		}

		$defaults = $this->getDefaultMappings(registerId: $registerId);
		$loaded = 0;

		foreach ($defaults as $resourceKey => $config) {
			if ($this->zgwMappingService->hasMapping($resourceKey) === true) {
				continue;
			}

			$this->zgwMappingService->saveMapping(resourceKey: $resourceKey, config: $config);
			$loaded++;
		}

		$output->info("Loaded {$loaded} default ZGW mapping configurations.");

		// Patch existing mappings that have known bugs (e.g., Twig renders false as "").
		$this->patchExistingMappings(defaults: $defaults, output: $output);

		// The two seeding phases below are conveniences, and a repair step
		// that THROWS aborts the whole install. On a fresh install the schema
		// settings this register was configured with can still be empty, and a
		// lookup against an empty schema context throws — so each phase warns
		// and continues instead of taking the install down with it.
		try {
			// Create default test applicaties via ConsumerMapper.
			$this->createDefaultApplicaties(output: $output);
		} catch (\Throwable $e) {
			$output->warning('Could not create default applicaties: ' . $e->getMessage());
			$this->logger->warning('Dossiq: default applicaties seed failed', ['exception' => $e->getMessage()]);
		}

		try {
			// Create default notification channels.
			$this->createDefaultKanalen(output: $output);
		} catch (\Throwable $e) {
			$output->warning('Could not create default notification channels: ' . $e->getMessage());
			$this->logger->warning('Dossiq: default kanalen seed failed', ['exception' => $e->getMessage()]);
		}

		$this->logger->info(
			'Dossiq: Default ZGW mappings loaded',
			['loaded' => $loaded, 'total' => count(value: $defaults)]
		);
	}//end run()

	/**
	 * Patch existing mappings that contain known bugs.
	 *
	 * Some Twig templates don't handle boolean false correctly (Twig renders
	 * false as empty string "", which nullable casts then turn into null).
	 * This method checks existing mappings and updates them if they contain
	 * the buggy template.
	 *
	 * @param array $defaults The default mapping configurations
	 * @param IOutput $output The repair output
	 *
	 * @return void
	 */
	private function patchExistingMappings(array $defaults, IOutput $output): void {
		// Patch: enkelvoudiginformatieobject indicatieGebruiksrecht Twig template.
		// Old: '{{ usageRightsIndication }}' renders false as "" → ?bool → null.
		// New: uses is same as() to distinguish false from null.
		$eioKey = 'enkelvoudiginformatieobject';
		if ($this->zgwMappingService->hasMapping($eioKey) === true) {
			$existing = $this->zgwMappingService->getMapping($eioKey);
			$oldTpl = '{{ usageRightsIndication }}';
			$current = $existing['propertyMapping']['indicatieGebruiksrecht'] ?? '';
			if ($current === $oldTpl && isset($defaults[$eioKey]) === true) {
				$existing['propertyMapping']['indicatieGebruiksrecht']
					= $defaults[$eioKey]['propertyMapping']['indicatieGebruiksrecht'];
				$this->zgwMappingService->saveMapping(resourceKey: $eioKey, config: $existing);
				$output->info('Patched enkelvoudiginformatieobject mapping: indicatieGebruiksrecht template.');
			}
		}
	}//end patchExistingMappings()

	/**
	 * Build a Twig URL-replacement template string.
	 *
	 * Generates: {{ _baseUrl | replace({"<from>": "<to>"}) }}/{{ <var> }}
	 *
	 * @param string $from The path segment to replace
	 * @param string $to The replacement path segment
	 * @param string $varName The Twig variable to append
	 *
	 * @return string The Twig template string
	 */
	private function tplUrl(string $from, string $to, string $varName): string {
		// Insert /v1/ between API group and resource (e.g. "zaken/zaken" → "zaken/v1/zaken").
		$fromParts = explode('/', $from);
		$toParts = explode('/', $to);
		$fromPath = $fromParts[0] . '/v1/' . $fromParts[1];
		$toPath = $toParts[0] . '/v1/' . $toParts[1];

		return sprintf(self::TPL_PREFIX, $fromPath, $toPath, $varName);
	}//end tplUrl()

	/**
	 * Get the default mapping configurations for all 12 ZGW resources.
	 *
	 * @param string $registerId The Dossiq register ID
	 *
	 * @return array<string, array> Mapping configurations keyed by resource key
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getDefaultMappings(string $registerId): array {
		$settings = $this->settingsService->getSettings();

		return [
			'catalogus' => $this->getCatalogusMapping(
				registerId: $registerId,
				settings: $settings
			),
			'zaak' => $this->getCaseMapping(
				registerId: $registerId,
				settings: $settings
			),
			'caseType' => $this->getCaseTypeMapping(
				registerId: $registerId,
				settings: $settings
			),
			'status' => $this->getStatusMapping(
				registerId: $registerId,
				settings: $settings
			),
			'statustype' => $this->getStatusTypeMapping(
				registerId: $registerId,
				settings: $settings
			),
			'result' => $this->getResultMapping(
				registerId: $registerId,
				settings: $settings
			),
			'resultaattype' => $this->getResultTypeMapping(
				registerId: $registerId,
				settings: $settings
			),
			'role' => $this->getRoleMapping(
				registerId: $registerId,
				settings: $settings
			),
			'roltype' => $this->getRoleTypeMapping(
				registerId: $registerId,
				settings: $settings
			),
			'eigenschap' => $this->getAttributeMapping(
				registerId: $registerId,
				settings: $settings
			),
			'decision' => $this->getDecisionMapping(
				registerId: $registerId,
				settings: $settings
			),
			'besluittype' => $this->getDecisionTypeMapping(
				registerId: $registerId,
				settings: $settings
			),
			'informatieobjecttype' => $this->getInformatieObjectTypeMapping(
				registerId: $registerId,
				settings: $settings
			),
			'zaaktypeinformatieobjecttype' => $this->getCaseTypeInformatieobjecttypeMapping(
				registerId: $registerId,
				settings: $settings
			),
			'enkelvoudiginformatieobject' => $this->getEnkelvoudigInformatieObjectMapping(
				registerId: $registerId,
				settings: $settings
			),
			'objectinformatieobject' => $this->getObjectInformatieObjectMapping(
				registerId: $registerId,
				settings: $settings
			),
			'gebruiksrechten' => $this->getGebruiksrechtenMapping(
				registerId: $registerId,
				settings: $settings
			),
			'zaakeigenschap' => $this->getZaakeigenschapMapping(
				registerId: $registerId,
				settings: $settings
			),
			'zaakinformatieobject' => $this->getZaakinformatieobjectMapping(
				registerId: $registerId,
				settings: $settings
			),
			'zaakobject' => $this->getZaakobjectMapping(
				registerId: $registerId,
				settings: $settings
			),
			'klantcontact' => $this->getKlantcontactMapping(
				registerId: $registerId,
				settings: $settings
			),
			'besluitinformatieobject' => $this->getBesluitinformatieobjectMapping(
				registerId: $registerId,
				settings: $settings
			),
			'verzending' => $this->getVerzendingMapping(
				registerId: $registerId,
				settings: $settings
			),
			'applicatie' => $this->getApplicatieMapping(),
			'kanaal' => $this->getChannelMapping(
				registerId: $registerId,
				settings: $settings
			),
			'abonnement' => $this->getAbonnementMapping(
				registerId: $registerId,
				settings: $settings
			),
		];
	}//end getDefaultMappings()

	/**
	 * Get default mapping for Zaak (case).
	 *
	 * @param string $registerId The register ID
	 * @param array $settings The Dossiq settings
	 *
	 * @return array
	 */
	private function getCaseMapping(string $registerId, array $settings): array {
		return [
			'zgwResource' => 'zaak',
			'zgwApiVersion' => '1',
			'sourceRegister' => $registerId,
			'sourceSchema' => ($settings['case_schema'] ?? ''),
			'enabled' => true,
			'propertyMapping' => [
				'url' => '{{ _baseUrl }}/{{ _uuid }}',
				'uuid' => '{{ _uuid }}',
				'identificatie' => '{{ identifier }}',
				'bronorganisatie' => '{{ sourceOrganisation }}',
				'omschrijving' => '{{ title }}',
				'notes' => '{{ description }}',
				'caseType' => $this->tplUrl(
					from: 'zaken/zaken',
					to: 'catalogi/zaaktypen',
					varName: 'caseType'
				),
				'registrationDate' => '{{ _created }}',
				'startdatum' => '{{ startDate }}',
				'endDate' => '{{ endDate }}',
				'einddatumGepland' => '{{ plannedEndDate }}',
				'uiterlijkeEinddatumAfdoening' => '{{ deadline }}',
				'vertrouwelijkheidaanduiding' => '{{ confidentiality }}',
				'verantwoordelijkeOrganisatie' => '{{ assignee }}',
				'archiefnominatie' => '{{ archiveNomination }}',
				'archiefactiedatum' => '{{ archiveActionDate }}',
				'archiefstatus' => '{{ archiveStatus }}',
				'betalingsindicatie' => '{{ paymentIndication }}',
				'laatsteBetaaldatum' => '{{ lastPaymentDate }}',
				'hoofdzaak' => '{% if parentCase %}{{ _baseUrl }}/{{ parentCase }}{% endif %}',
			],
			'reverseMapping' => [
				'title' => '{{ omschrijving }}',
				'description' => '{{ toelichting }}',
				'identifier' => '{{ identificatie }}',
				'sourceOrganisation' => '{{ bronorganisatie }}',
				'caseType' => '{{ zaaktype | zgw_extract_uuid }}',
				'startDate' => '{{ startdatum }}',
				'endDate' => '{{ einddatum }}',
				'plannedEndDate' => '{{ einddatumGepland }}',
				'deadline' => '{{ uiterlijkeEinddatumAfdoening }}',
				'confidentiality' => '{{ vertrouwelijkheidaanduiding }}',
				'assignee' => '{{ verantwoordelijkeOrganisatie }}',
				'archiveNomination' => '{{ archiefnominatie }}',
				'archiveActionDate' => '{{ archiefactiedatum }}',
				'archiveStatus' => '{{ archiefstatus }}',
				'paymentIndication' => '{{ betalingsindicatie }}',
				'lastPaymentDate' => '{{ laatsteBetaaldatum }}',
				'parentCase' => '{{ hoofdzaak | zgw_extract_uuid }}',
			],
			'valueMapping' => [
				'confidentiality' => [
					'openbaar' => 'openbaar',
					'beperkt_openbaar' => 'beperkt_openbaar',
					'intern' => 'intern',
					'zaakvertrouwelijk' => 'zaakvertrouwelijk',
					'vertrouwelijk' => 'vertrouwelijk',
					'confidentieel' => 'confidentieel',
					'geheim' => 'geheim',
					'zeer_geheim' => 'zeer_geheim',
				],
			],
			'nullableFields' => [
				'endDate',
				'einddatumGepland',
				'uiterlijkeEinddatumAfdoening',
				'archiefnominatie',
				'archiefactiedatum',
				'archiefstatus',
				'betalingsindicatie',
				'laatsteBetaaldatum',
				'hoofdzaak',
			],
			'queryParameterMapping' => [
				'caseType' => [
					'field' => 'caseType',
					'extractUuid' => true,
				],
				'identificatie' => [
					'field' => 'identifier',
				],
				'bronorganisatie' => [
					'field' => 'sourceOrganisation',
				],
				'startdatum' => [
					'field' => 'startDate',
				],
				'startdatum__gte' => [
					'field' => 'startDate',
					'operator' => 'gte',
				],
				'startdatum__lte' => [
					'field' => 'startDate',
					'operator' => 'lte',
				],
				'endDate' => [
					'field' => 'endDate',
				],
				'einddatum__isnull' => [
					'field' => 'endDate',
					'operator' => 'isnull',
				],
				'archiefnominatie' => [
					'field' => 'archiveNomination',
				],
				'archiefactiedatum__lt' => [
					'field' => 'archiveActionDate',
					'operator' => 'lt',
				],
				'archiefstatus' => [
					'field' => 'archiveStatus',
				],
			],
		];
	}//end getZaakMapping()

	/**
	 * Get default mapping for ZaakType (caseType).
	 *
	 * @param string $registerId The register ID
	 * @param array $settings The Dossiq settings
	 *
	 * @return array
	 */
	private function getCaseTypeMapping(string $registerId, array $settings): array {
		return [
			'zgwResource' => 'caseType',
			'zgwApiVersion' => '1',
			'sourceRegister' => $registerId,
			'sourceSchema' => ($settings['case_type_schema'] ?? ''),
			'enabled' => true,
			'propertyMapping' => [
				'url' => '{{ _baseUrl }}/{{ _uuid }}',
				'uuid' => '{{ _uuid }}',
				'identificatie' => '{{ identifier }}',
				'omschrijving' => '{{ title }}',
				'omschrijvingGeneriek' => '{{ description }}',
				'catalogus' => $this->tplUrl(
					from: 'catalogi/zaaktypen',
					to: 'catalogi/catalogussen',
					varName: 'catalogus'
				),
				'doel' => '{{ purpose }}',
				'aanleiding' => '{{ trigger }}',
				'subject' => '{{ subject }}',
				'doorlooptijd' => '{{ processingDeadline }}',
				'vertrouwelijkheidaanduiding' => '{{ confidentiality }}',
				'concept' => '{{ isDraft }}',
				'startValidity' => '{{ validFrom }}',
				'endValidity' => '{{ validUntil }}',
				'handelingInitiator' => '{{ origin }}',
				'indicatieInternOfExtern' => '{{ internalOrExternal }}',
				'handelingBehandelaar' => '{{ handlerAction }}',
				'opschortingEnAanhoudingMogelijk' => '{{ suspensionAllowed }}',
				'extensionPossible' => '{{ extensionAllowed }}',
				'verlengingstermijn' => '{{ extensionPeriod }}',
				'publicatieIndicatie' => '{{ publicationRequired }}',
				'productenOfDiensten' => '{{ productsOrServices | json_encode }}',
				'selectielijstDossiqype' => '{{ selectionListProcessType }}',
				'referentieproces' => '{{ referenceProcess | json_encode }}',
				'responsible' => '{{ responsible }}',
				'gerelateerdeZaaktypen' => '{{ relatedCaseTypes | json_encode }}',
				'besluittypen' => 'decisionTypes',
				'informatieobjecttypen' => '[]',
			],
			'reverseMapping' => [
				'title' => '{{ omschrijving }}',
				'description' => '{{ omschrijvingGeneriek }}',
				'identifier' => '{{ identificatie }}',
				'catalogus' => '{{ catalogus | zgw_extract_uuid }}',
				'purpose' => '{{ doel }}',
				'trigger' => '{{ aanleiding }}',
				'subject' => '{{ onderwerp }}',
				'processingDeadline' => '{{ doorlooptijd }}',
				'confidentiality' => '{{ vertrouwelijkheidaanduiding }}',
				'isDraft' => '{{ concept }}',
				'validFrom' => '{{ beginGeldigheid }}',
				'validUntil' => '{{ eindeGeldigheid }}',
				'origin' => '{{ handelingInitiator }}',
				'internalOrExternal' => '{{ indicatieInternOfExtern }}',
				'handlerAction' => '{{ handelingBehandelaar }}',
				'suspensionAllowed' => '{{ opschortingEnAanhoudingMogelijk }}',
				'extensionAllowed' => '{{ verlengingMogelijk }}',
				'extensionPeriod' => '{{ verlengingstermijn }}',
				'publicationRequired' => '{{ publicatieIndicatie }}',
				'selectionListProcessType' => '{{ selectielijstDossiqype }}',
				'responsible' => '{{ verantwoordelijke }}',
				'productsOrServices' => '{{ productenOfDiensten | json_encode }}',
				'referenceProcess' => '{{ referentieproces | json_encode }}',
				'relatedCaseTypes' => '{{ gerelateerdeZaaktypen | json_encode }}',
				'versionDate' => '{{ versiedatum }}',
			],
			'reverseCast' => [
				'isDraft' => 'bool',
				'suspensionAllowed' => 'bool',
				'extensionAllowed' => 'bool',
				'publicationRequired' => 'bool',
			],
			'cast' => [
				'concept' => 'bool',
				'opschortingEnAanhoudingMogelijk' => 'bool',
				'extensionPossible' => 'bool',
				'publicatieIndicatie' => 'bool',
				'productenOfDiensten' => 'jsonToArray',
				'gerelateerdeZaaktypen' => 'jsonToArray',
				'informatieobjecttypen' => 'jsonToArray',
				'referentieproces' => 'jsonToArray',
			],
			'valueMapping' => [],
			'queryParameterMapping' => [
				'identificatie' => [
					'field' => 'identifier',
				],
				'catalogus' => [
					'field' => 'catalogus',
					'extractUuid' => true,
				],
			],
		];
	}//end getZaakTypeMapping()

	/**
	 * Get default mapping for Status.
	 *
	 * @param string $registerId The register ID
	 * @param array $settings The Dossiq settings
	 *
	 * @return array
	 */
	private function getStatusMapping(string $registerId, array $settings): array {
		return [
			'zgwResource' => 'status',
			'zgwApiVersion' => '1',
			'sourceRegister' => $registerId,
			'sourceSchema' => ($settings['status_record_schema'] ?? ''),
			'enabled' => true,
			'propertyMapping' => [
				'url' => '{{ _baseUrl }}/{{ _uuid }}',
				'uuid' => '{{ _uuid }}',
				'zaak' => $this->tplUrl(
					from: 'zaken/statussen',
					to: 'zaken/zaken',
					varName: 'case'
				),
				'statustype' => $this->tplUrl(
					from: 'zaken/statussen',
					to: 'catalogi/statustypen',
					varName: 'statusType'
				),
				'datumStatusGezet' => '{{ _created }}',
				'statustoelichting' => '{{ description }}',
			],
			'reverseMapping' => [
				'case' => '{{ zaak | zgw_extract_uuid }}',
				'statusType' => '{{ statustype | zgw_extract_uuid }}',
				'description' => '{{ statustoelichting }}',
			],
			'valueMapping' => [],
			'queryParameterMapping' => [
				'zaak' => [
					'field' => 'case',
					'extractUuid' => true,
				],
			],
		];
	}//end getStatusMapping()

	/**
	 * Get default mapping for StatusType (statusType).
	 *
	 * @param string $registerId The register ID
	 * @param array $settings The Dossiq settings
	 *
	 * @return array
	 */
	private function getStatusTypeMapping(string $registerId, array $settings): array {
		return [
			'zgwResource' => 'statustype',
			'zgwApiVersion' => '1',
			'sourceRegister' => $registerId,
			'sourceSchema' => ($settings['status_type_schema'] ?? ''),
			'enabled' => true,
			'propertyMapping' => [
				'url' => '{{ _baseUrl }}/{{ _uuid }}',
				'uuid' => '{{ _uuid }}',
				'omschrijving' => '{{ name }}',
				'omschrijvingGeneriek' => '{{ description }}',
				'caseType' => $this->tplUrl(
					from: 'catalogi/statustypen',
					to: 'catalogi/zaaktypen',
					varName: 'caseType'
				),
				'sequenceNumber' => '{{ order }}',
				'isEindstatus' => '{{ isFinal }}',
			],
			'reverseMapping' => [
				'name' => '{{ omschrijving }}',
				'description' => '{{ omschrijvingGeneriek }}',
				'caseType' => '{{ zaaktype | zgw_extract_uuid }}',
				'order' => '{{ volgnummer }}',
				'isFinal' => '{{ isEindstatus }}',
			],
			'reverseCast' => [
				'order' => 'int',
				'isFinal' => 'bool',
			],
			'cast' => [
				'sequenceNumber' => 'int',
				'isEindstatus' => 'bool',
			],
			'valueMapping' => [],
			'queryParameterMapping' => [
				'caseType' => [
					'field' => 'caseType',
					'extractUuid' => true,
				],
			],
		];
	}//end getStatusTypeMapping()

	/**
	 * Get default mapping for Resultaat (result).
	 *
	 * @param string $registerId The register ID
	 * @param array $settings The Dossiq settings
	 *
	 * @return array
	 */
	private function getResultMapping(string $registerId, array $settings): array {
		return [
			'zgwResource' => 'result',
			'zgwApiVersion' => '1',
			'sourceRegister' => $registerId,
			'sourceSchema' => ($settings['result_schema'] ?? ''),
			'enabled' => true,
			'propertyMapping' => [
				'url' => '{{ _baseUrl }}/{{ _uuid }}',
				'uuid' => '{{ _uuid }}',
				'zaak' => $this->tplUrl(
					from: 'zaken/resultaten',
					to: 'zaken/zaken',
					varName: 'case'
				),
				'resultaattype' => $this->tplUrl(
					from: 'zaken/resultaten',
					to: 'catalogi/resultaattypen',
					varName: 'resultType'
				),
				'notes' => '{{ description }}',
			],
			'reverseMapping' => [
				'name' => '{{ toelichting }}',
				'case' => '{{ zaak | zgw_extract_uuid }}',
				'resultType' => '{{ resultaattype | zgw_extract_uuid }}',
				'description' => '{{ toelichting }}',
			],
			'valueMapping' => [],
			'queryParameterMapping' => [
				'zaak' => [
					'field' => 'case',
					'extractUuid' => true,
				],
			],
		];
	}//end getResultaatMapping()

	/**
	 * Get default mapping for ResultaatType (resultType).
	 *
	 * @param string $registerId The register ID
	 * @param array $settings The Dossiq settings
	 *
	 * @return array
	 */
	private function getResultTypeMapping(string $registerId, array $settings): array {
		return [
			'zgwResource' => 'resultaattype',
			'zgwApiVersion' => '1',
			'sourceRegister' => $registerId,
			'sourceSchema' => ($settings['result_type_schema'] ?? ''),
			'enabled' => true,
			'propertyMapping' => [
				'url' => '{{ _baseUrl }}/{{ _uuid }}',
				'uuid' => '{{ _uuid }}',
				'omschrijving' => '{{ name }}',
				'omschrijvingGeneriek' => '{{ genericDescription }}',
				'notes' => '{{ description }}',
				'caseType' => $this->tplUrl(
					from: 'catalogi/resultaattypen',
					to: 'catalogi/zaaktypen',
					varName: 'caseType'
				),
				'archiefnominatie' => '{{ archivalAction }}',
				'archiefactietermijn' => '{{ archivalPeriod }}',
				'brondatumArchiefprocedure' => '{{ sourceDateArchiveProcedure | json_encode }}',
				'selectielijstklasse' => '{{ selectionListClass }}',
			],
			'reverseMapping' => [
				'name' => '{{ omschrijving }}',
				'genericDescription' => '{{ omschrijvingGeneriek }}',
				'description' => '{{ toelichting }}',
				'caseType' => '{{ zaaktype | zgw_extract_uuid }}',
				'archivalAction' => '{{ archiefnominatie }}',
				'archivalPeriod' => '{{ archiefactietermijn }}',
				'sourceDateArchiveProcedure' => '{{ brondatumArchiefprocedure | json_encode }}',
				'selectionListClass' => '{{ selectielijstklasse }}',
			],
			'valueMapping' => [
				'archivalAction' => [
					'bewaren' => 'bewaren',
					'vernietigen' => 'vernietigen',
					'blijvend_bewaren' => 'blijvend_bewaren',
				],
			],
			'cast' => [
				'sourceDateArchiveProcedure' => 'jsonToArray',
			],
			'reverseCast' => [],
			'nullableFields' => [
				'archiefactietermijn',
				'omschrijvingGeneriek',
				'brondatumArchiefprocedure',
				'selectielijstklasse',
			],
			'queryParameterMapping' => [
				'caseType' => [
					'field' => 'caseType',
					'extractUuid' => true,
				],
			],
		];
	}//end getResultaatTypeMapping()

	/**
	 * Get default mapping for Rol (role).
	 *
	 * @param string $registerId The register ID
	 * @param array $settings The Dossiq settings
	 *
	 * @return array
	 */
	private function getRoleMapping(string $registerId, array $settings): array {
		return [
			'zgwResource' => 'role',
			'zgwApiVersion' => '1',
			'sourceRegister' => $registerId,
			'sourceSchema' => ($settings['role_schema'] ?? ''),
			'enabled' => true,
			'propertyMapping' => [
				'url' => '{{ _baseUrl }}/{{ _uuid }}',
				'uuid' => '{{ _uuid }}',
				'zaak' => $this->tplUrl(
					from: 'zaken/rollen',
					to: 'zaken/zaken',
					varName: 'case'
				),
				'roltype' => $this->tplUrl(
					from: 'zaken/rollen',
					to: 'catalogi/roltypen',
					varName: 'roleType'
				),
				'omschrijving' => '{{ name }}',
				'omschrijvingGeneriek' => '{{ description }}',
				'betrokkeneIdentificatie' => '{{ participant }}',
			],
			'reverseMapping' => [
				'name' => '{{ omschrijving }}',
				'description' => '{{ omschrijvingGeneriek }}',
				'case' => '{{ zaak | zgw_extract_uuid }}',
				'roleType' => '{{ roltype | zgw_extract_uuid }}',
				'participant' => '{{ betrokkeneIdentificatie }}',
			],
			'valueMapping' => [],
			'queryParameterMapping' => [
				'zaak' => [
					'field' => 'case',
					'extractUuid' => true,
				],
			],
		];
	}//end getRolMapping()

	/**
	 * Get default mapping for RolType (roleType).
	 *
	 * @param string $registerId The register ID
	 * @param array $settings The Dossiq settings
	 *
	 * @return array
	 */
	private function getRoleTypeMapping(string $registerId, array $settings): array {
		return [
			'zgwResource' => 'roltype',
			'zgwApiVersion' => '1',
			'sourceRegister' => $registerId,
			'sourceSchema' => ($settings['role_type_schema'] ?? ''),
			'enabled' => true,
			'propertyMapping' => [
				'url' => '{{ _baseUrl }}/{{ _uuid }}',
				'uuid' => '{{ _uuid }}',
				'omschrijving' => '{{ name }}',
				'omschrijvingGeneriek' => '{{ description }}',
				'caseType' => $this->tplUrl(
					from: 'catalogi/roltypen',
					to: 'catalogi/zaaktypen',
					varName: 'caseType'
				),
			],
			'reverseMapping' => [
				'name' => '{{ omschrijving }}',
				'description' => '{{ omschrijvingGeneriek }}',
				'caseType' => '{{ zaaktype | zgw_extract_uuid }}',
			],
			'valueMapping' => [],
			'queryParameterMapping' => [
				'caseType' => [
					'field' => 'caseType',
					'extractUuid' => true,
				],
			],
		];
	}//end getRolTypeMapping()

	/**
	 * Get default mapping for Eigenschap (propertyDefinition).
	 *
	 * @param string $registerId The register ID
	 * @param array $settings The Dossiq settings
	 *
	 * @return array
	 */
	private function getAttributeMapping(string $registerId, array $settings): array {
		return [
			'zgwResource' => 'eigenschap',
			'zgwApiVersion' => '1',
			'sourceRegister' => $registerId,
			'sourceSchema' => ($settings['property_definition_schema'] ?? ''),
			'enabled' => true,
			'propertyMapping' => [
				'url' => '{{ _baseUrl }}/{{ _uuid }}',
				'uuid' => '{{ _uuid }}',
				'name' => '{{ name }}',
				'definitie' => '{{ definition }}',
				'notes' => '{{ description }}',
				'caseType' => $this->tplUrl(
					from: 'catalogi/eigenschappen',
					to: 'catalogi/zaaktypen',
					varName: 'caseType'
				),
				'specificatie' => '{{ {formaat: propertyType ?: "tekst"} | json_encode }}',
			],
			'reverseMapping' => [
				'name' => '{{ naam }}',
				'definition' => '{{ definitie }}',
				'description' => '{{ toelichting }}',
				'caseType' => '{{ zaaktype | zgw_extract_uuid }}',
			],
			'valueMapping' => [],
			'queryParameterMapping' => [
				'caseType' => [
					'field' => 'caseType',
					'extractUuid' => true,
				],
			],
		];
	}//end getEigenschapMapping()

	/**
	 * Get default mapping for Besluit (decision).
	 *
	 * @param string $registerId The register ID
	 * @param array $settings The Dossiq settings
	 *
	 * @return array
	 */
	private function getDecisionMapping(string $registerId, array $settings): array {
		return [
			'zgwResource' => 'decision',
			'zgwApiVersion' => '1',
			'sourceRegister' => $registerId,
			'sourceSchema' => ($settings['decision_schema'] ?? ''),
			'enabled' => true,
			'propertyMapping' => [
				'url' => '{{ _baseUrl }}/{{ _uuid }}',
				'uuid' => '{{ _uuid }}',
				'identificatie' => '{{ title }}',
				'notes' => '{{ explanation }}',
				'zaak' => $this->tplUrl(
					from: 'besluiten/besluiten',
					to: 'zaken/zaken',
					varName: 'case'
				),
				'besluittype' => $this->tplUrl(
					from: 'besluiten/besluiten',
					to: 'catalogi/besluittypen',
					varName: 'decisionType'
				),
				'verantwoordelijkeOrganisatie' => '{{ responsibleOrganisation }}',
				'bestuursorgaan' => '{{ governingBody }}',
				'date' => '{{ decisionDate }}',
				'effectiveDate' => '{{ effectiveDate }}',
				'vervaldatum' => '{{ expiryDate }}',
				'publicationDate' => '{{ publicationDate }}',
				'verzenddatum' => '{{ deliveryDate }}',
			],
			'reverseMapping' => [
				'title' => '{{ identificatie }}',
				'explanation' => '{{ toelichting }}',
				'case' => '{% if zaak is defined and zaak %}{{ zaak | zgw_extract_uuid }}{% endif %}',
				'decisionType' => '{{ besluittype | zgw_extract_uuid }}',
				'responsibleOrganisation' => '{{ verantwoordelijkeOrganisatie }}',
				'governingBody' => '{{ bestuursorgaan }}',
				'decisionDate' => '{{ datum }}',
				'effectiveDate' => '{{ ingangsdatum }}',
				'expiryDate' => '{{ vervaldatum }}',
				'publicationDate' => '{{ publicatiedatum }}',
				'deliveryDate' => '{{ verzenddatum }}',
			],
			'valueMapping' => [],
			'queryParameterMapping' => [
				'zaak' => [
					'field' => 'case',
					'extractUuid' => true,
				],
			],
		];
	}//end getBesluitMapping()

	/**
	 * Get default mapping for BesluitType (decisionType).
	 *
	 * @param string $registerId The register ID
	 * @param array $settings The Dossiq settings
	 *
	 * @return array
	 */
	private function getDecisionTypeMapping(string $registerId, array $settings): array {
		return [
			'zgwResource' => 'besluittype',
			'zgwApiVersion' => '1',
			'sourceRegister' => $registerId,
			'sourceSchema' => ($settings['decision_type_schema'] ?? ''),
			'enabled' => true,
			'propertyMapping' => [
				'url' => '{{ _baseUrl }}/{{ _uuid }}',
				'uuid' => '{{ _uuid }}',
				'omschrijving' => '{{ name }}',
				'notes' => '{{ description }}',
				'catalogus' => $this->tplUrl(
					from: 'catalogi/besluittypen',
					to: 'catalogi/catalogussen',
					varName: 'catalogus'
				),
				'zaaktypen' => 'caseTypes',
				'concept' => '{{ isDraft }}',
				'publicatieIndicatie' => '{{ publicationRequired }}',
				'informatieobjecttypen' => 'documentTypes',
				'startValidity' => '{{ validFrom }}',
				'endValidity' => '{{ validUntil }}',
			],
			'reverseMapping' => [
				'name' => '{{ omschrijving }}',
				'description' => '{{ toelichting }}',
				'catalogus' => '{{ catalogus | zgw_extract_uuid }}',
				'isDraft' => '{{ concept }}',
				'publicationRequired' => '{{ publicatieIndicatie }}',
				'caseTypes' => 'zaaktypen',
				'documentTypes' => 'informatieobjecttypen',
				'validFrom' => '{{ beginGeldigheid }}',
				'validUntil' => '{{ eindeGeldigheid }}',
			],
			'reverseCast' => [
				'isDraft' => 'bool',
				'publicationRequired' => 'bool',
			],
			'cast' => [
				'concept' => 'bool',
				'publicatieIndicatie' => 'bool',
			],
			'valueMapping' => [],
			'queryParameterMapping' => [
				'zaaktypen' => [
					'field' => 'caseType',
					'extractUuid' => true,
				],
				'catalogus' => [
					'field' => 'catalogus',
					'extractUuid' => true,
				],
				'omschrijving' => [
					'field' => 'name',
				],
			],
		];
	}//end getBesluitTypeMapping()

	/**
	 * Get default mapping for InformatieObjectType (documentType).
	 *
	 * @param string $registerId The register ID
	 * @param array $settings The Dossiq settings
	 *
	 * @return array
	 */
	private function getInformatieObjectTypeMapping(string $registerId, array $settings): array {
		return [
			'zgwResource' => 'informatieobjecttype',
			'zgwApiVersion' => '1',
			'sourceRegister' => $registerId,
			'sourceSchema' => ($settings['document_type_schema'] ?? ''),
			'enabled' => true,
			'propertyMapping' => [
				'url' => '{{ _baseUrl }}/{{ _uuid }}',
				'uuid' => '{{ _uuid }}',
				'omschrijving' => '{{ name }}',
				'notes' => '{{ description }}',
				'catalogus' => $this->tplUrl(
					from: 'catalogi/informatieobjecttypen',
					to: 'catalogi/catalogussen',
					varName: 'catalogus'
				),
				'concept' => '{{ isDraft }}',
				'vertrouwelijkheidaanduiding' => '{{ confidentiality }}',
				'informatieobjectcategorie' => '{{ category }}',
				'startValidity' => '{{ validFrom }}',
				'endValidity' => '{{ validUntil }}',
				'verplicht' => '{{ isRequired }}',
			],
			'reverseMapping' => [
				'name' => '{{ omschrijving }}',
				'description' => '{{ toelichting }}',
				'catalogus' => '{{ catalogus | zgw_extract_uuid }}',
				'isDraft' => '{{ concept }}',
				'confidentiality' => '{{ vertrouwelijkheidaanduiding }}',
				'category' => '{{ informatieobjectcategorie }}',
				'validFrom' => '{{ beginGeldigheid }}',
				'validUntil' => '{{ eindeGeldigheid }}',
				'isRequired' => '{{ verplicht }}',
			],
			'reverseCast' => [
				'isDraft' => 'bool',
				'isRequired' => 'bool',
			],
			'cast' => [
				'concept' => 'bool',
				'verplicht' => 'bool',
			],
			'valueMapping' => [],
			'queryParameterMapping' => [
				'catalogus' => [
					'field' => 'catalogus',
					'extractUuid' => true,
				],
			],
		];
	}//end getInformatieObjectTypeMapping()

	/**
	 * Get default mapping for EnkelvoudigInformatieObject (document).
	 *
	 * @param string $registerId The register ID
	 * @param array $settings The Dossiq settings
	 *
	 * @return array
	 */
	private function getEnkelvoudigInformatieObjectMapping(
		string $registerId,
		array $settings,
	): array {
		return [
			'zgwResource' => 'enkelvoudiginformatieobject',
			'zgwApiVersion' => '1',
			'sourceRegister' => $registerId,
			'sourceSchema' => ($settings['document_schema'] ?? ''),
			'enabled' => true,
			'propertyMapping' => [
				'url' => '{{ _baseUrl }}/{{ _uuid }}',
				'uuid' => '{{ _uuid }}',
				'identificatie' => '{{ identifier }}',
				'bronorganisatie' => '{{ sourceOrganisation }}',
				'creatiedatum' => '{{ creationDate }}',
				'titel' => '{{ title }}',
				'vertrouwelijkheidaanduiding' => '{{ confidentiality }}',
				'auteur' => '{{ author }}',
				'status' => '{{ status }}',
				'format' => '{{ format }}',
				'taal' => '{{ language }}',
				'fileName' => '{{ fileName }}',
				'bestandsomvang' => '{{ fileSize }}',
				'inhoud' => '{{ _downloadUrl }}',
				'link' => '{{ link }}',
				'beschrijving' => '{{ description }}',
				'informatieobjecttype' => $this->tplUrl(
					from: 'documenten/enkelvoudiginformatieobjecten',
					to: 'catalogi/informatieobjecttypen',
					varName: 'documentType'
				),
				'locked' => '{{ locked }}',
				'registrationDate' => '{{ _created }}',
				// phpcs:ignore Generic.Files.LineLength.MaxExceeded
				'indicatieGebruiksrecht' => '{{ usageRightsIndication is same as(true) ? "true" : (usageRightsIndication is same as(false) ? "false" : "") }}',
			],
			'reverseMapping' => [
				'identifier' => '{{ identificatie }}',
				'sourceOrganisation' => '{{ bronorganisatie }}',
				'creationDate' => '{{ creatiedatum }}',
				'title' => '{{ titel }}',
				'confidentiality' => '{{ vertrouwelijkheidaanduiding }}',
				'author' => '{{ auteur }}',
				'status' => '{{ status }}',
				'format' => '{{ formaat }}',
				'language' => '{{ taal }}',
				'fileName' => '{{ bestandsnaam }}',
				'fileSize' => '{{ bestandsomvang }}',
				'link' => '{{ link }}',
				'description' => '{{ beschrijving }}',
				'documentType' => '{{ informatieobjecttype | zgw_extract_uuid }}',
				'usageRightsIndication' => '{{ indicatieGebruiksrecht }}',
			],
			'valueMapping' => [
				'confidentiality' => [
					'openbaar' => 'openbaar',
					'beperkt_openbaar' => 'beperkt_openbaar',
					'intern' => 'intern',
					'zaakvertrouwelijk' => 'zaakvertrouwelijk',
					'vertrouwelijk' => 'vertrouwelijk',
					'confidentieel' => 'confidentieel',
					'geheim' => 'geheim',
					'zeer_geheim' => 'zeer_geheim',
				],
				'status' => [
					'in_bewerking' => 'in_bewerking',
					'ter_vaststelling' => 'ter_vaststelling',
					'definitief' => 'definitief',
					'gearchiveerd' => 'gearchiveerd',
				],
			],
			'reverseCast' => [
				'fileSize' => 'int',
			],
			'cast' => [
				'bestandsomvang' => 'int',
				'locked' => 'bool',
				'indicatieGebruiksrecht' => '?bool',
			],
			'queryParameterMapping' => [
				'informatieobjecttype' => [
					'field' => 'documentType',
					'extractUuid' => true,
				],
				'bronorganisatie' => [
					'field' => 'sourceOrganisation',
				],
			],
		];
	}//end getEnkelvoudigInformatieObjectMapping()

	/**
	 * Get default mapping for ObjectInformatieObject (documentLink).
	 *
	 * @param string $registerId The register ID
	 * @param array $settings The Dossiq settings
	 *
	 * @return array
	 */
	private function getObjectInformatieObjectMapping(
		string $registerId,
		array $settings,
	): array {
		return [
			'zgwResource' => 'objectinformatieobject',
			'zgwApiVersion' => '1',
			'sourceRegister' => $registerId,
			'sourceSchema' => ($settings['document_link_schema'] ?? ''),
			'enabled' => true,
			'propertyMapping' => [
				'url' => '{{ _baseUrl }}/{{ _uuid }}',
				'uuid' => '{{ _uuid }}',
				'informatieobject' => '{{ document }}',
				'object' => '{{ object }}',
				'objectType' => '{{ objectType }}',
			],
			'reverseMapping' => [
				'document' => '{{ informatieobject }}',
				'object' => '{{ object }}',
				'objectType' => '{{ objectType }}',
			],
			'valueMapping' => [
				'objectType' => [
					'zaak' => 'zaak',
					'decision' => 'decision',
				],
			],
			'queryParameterMapping' => [
				'informatieobject' => [
					'field' => 'document',
				],
				'object' => [
					'field' => 'object',
				],
			],
		];
	}//end getObjectInformatieObjectMapping()

	/**
	 * Get default mapping for GebruiksRechten (usageRights).
	 *
	 * @param string $registerId The register ID
	 * @param array $settings The Dossiq settings
	 *
	 * @return array
	 */
	private function getGebruiksrechtenMapping(
		string $registerId,
		array $settings,
	): array {
		return [
			'zgwResource' => 'gebruiksrechten',
			'zgwApiVersion' => '1',
			'sourceRegister' => $registerId,
			'sourceSchema' => ($settings['usage_rights_schema'] ?? ''),
			'enabled' => true,
			'propertyMapping' => [
				'url' => '{{ _baseUrl }}/{{ _uuid }}',
				'uuid' => '{{ _uuid }}',
				'informatieobject' => '{{ document }}',
				'startdatum' => '{{ startDate }}',
				'endDate' => '{{ endDate }}',
				'omschrijvingVoorwaarden' => '{{ conditionsDescription }}',
			],
			'reverseMapping' => [
				'document' => '{{ informatieobject }}',
				'startDate' => '{{ startdatum }}',
				'endDate' => '{{ einddatum }}',
				'conditionsDescription' => '{{ omschrijvingVoorwaarden }}',
			],
			'valueMapping' => [],
			'queryParameterMapping' => [
				'informatieobject' => [
					'field' => 'document',
				],
			],
		];
	}//end getGebruiksrechtenMapping()

	/**
	 * Get default mapping for Kanaal (notification channel).
	 *
	 * @param string $registerId The register ID
	 * @param array $settings The Dossiq settings
	 *
	 * @return array
	 */
	private function getChannelMapping(string $registerId, array $settings): array {
		return [
			'zgwResource' => 'kanaal',
			'zgwApiVersion' => '1',
			'sourceRegister' => $registerId,
			'sourceSchema' => ($settings['kanaal_schema'] ?? ''),
			'enabled' => true,
			'propertyMapping' => [
				'url' => '{{ _baseUrl }}/{{ _uuid }}',
				'uuid' => '{{ _uuid }}',
				'name' => '{{ naam }}',
				'documentationLink' => '{{ documentatieLink }}',
				'filters' => '{{ filters }}',
			],
			'cast' => [
				'filters' => 'jsonToArray',
			],
			'reverseMapping' => [
				'name' => '{{ naam }}',
				'documentationLink' => '{{ documentatieLink }}',
				'filters' => '{{ filters|json_encode|raw }}',
			],
			'valueMapping' => [],
			'queryParameterMapping' => [
				'name' => [
					'field' => 'name',
				],
			],
		];
	}//end getKanaalMapping()

	/**
	 * Get default mapping for Abonnement (notification subscription).
	 *
	 * @param string $registerId The register ID
	 * @param array $settings The Dossiq settings
	 *
	 * @return array
	 */
	private function getAbonnementMapping(string $registerId, array $settings): array {
		return [
			'zgwResource' => 'abonnement',
			'zgwApiVersion' => '1',
			'sourceRegister' => $registerId,
			'sourceSchema' => ($settings['abonnement_schema'] ?? ''),
			'enabled' => true,
			'propertyMapping' => [
				'url' => '{{ _baseUrl }}/{{ _uuid }}',
				'uuid' => '{{ _uuid }}',
				'callbackUrl' => '{{ callbackUrl }}',
				'auth' => '{{ auth }}',
				'kanalen' => '{{ kanalen }}',
			],
			'cast' => [
				'kanalen' => 'jsonToArray',
			],
			'reverseMapping' => [
				'callbackUrl' => '{{ callbackUrl }}',
				'auth' => '{{ auth }}',
				'kanalen' => '{{ kanalen|json_encode|raw }}',
			],
			'valueMapping' => [],
			'queryParameterMapping' => [],
		];
	}//end getAbonnementMapping()

	/**
	 * Get default mapping for Catalogus.
	 *
	 * @param string $registerId The register ID
	 * @param array $settings The Dossiq settings
	 *
	 * @return array
	 */
	private function getCatalogusMapping(string $registerId, array $settings): array {
		return [
			'zgwResource' => 'catalogus',
			'zgwApiVersion' => '1',
			'sourceRegister' => $registerId,
			'sourceSchema' => ($settings['catalogus_schema'] ?? ''),
			'enabled' => true,
			'propertyMapping' => [
				'url' => '{{ _baseUrl }}/{{ _uuid }}',
				'uuid' => '{{ _uuid }}',
				'domein' => '{{ domein }}',
				'rsin' => '{{ rsin }}',
				'contactPersonManagementName' => '{{ contactpersoonBeheerNaam }}',
				'contactPersonManagementPhoneNumber' => '{{ contactpersoonBeheerTelefoonnummer }}',
				'contactPersonManagementEmailAddress' => '{{ contactpersoonBeheerEmailadres }}',
				'zaaktypen' => '[]',
				'besluittypen' => '[]',
				'informatieobjecttypen' => '[]',
			],
			'cast' => [
				'zaaktypen' => 'jsonToArray',
				'besluittypen' => 'jsonToArray',
				'informatieobjecttypen' => 'jsonToArray',
			],
			'reverseMapping' => [
				'domein' => '{{ domein }}',
				'rsin' => '{{ rsin }}',
				'contactPersonManagementName' => '{{ contactpersoonBeheerNaam }}',
				'contactPersonManagementPhoneNumber' => '{{ contactpersoonBeheerTelefoonnummer }}',
				'contactPersonManagementEmailAddress' => '{{ contactpersoonBeheerEmailadres }}',
			],
			'valueMapping' => [],
			'queryParameterMapping' => [
				'domein' => [
					'field' => 'domein',
				],
				'rsin' => [
					'field' => 'rsin',
				],
			],
		];
	}//end getCatalogusMapping()

	/**
	 * Get default mapping for ZaaktypeInformatieobjecttype.
	 *
	 * @param string $registerId The register ID
	 * @param array $settings The Dossiq settings
	 *
	 * @return array
	 */
	private function getCaseTypeInformatieobjecttypeMapping(string $registerId, array $settings): array {
		return [
			'zgwResource' => 'zaaktypeinformatieobjecttype',
			'zgwApiVersion' => '1',
			'sourceRegister' => $registerId,
			'sourceSchema' => ($settings['zaaktype_informatieobjecttype_schema'] ?? ''),
			'enabled' => true,
			'propertyMapping' => [
				'url' => '{{ _baseUrl }}/{{ _uuid }}',
				'uuid' => '{{ _uuid }}',
				'caseType' => $this->tplUrl(
					from: 'catalogi/zaaktype-informatieobjecttypen',
					to: 'catalogi/zaaktypen',
					varName: 'caseType'
				),
				'informatieobjecttype' => $this->tplUrl(
					from: 'catalogi/zaaktype-informatieobjecttypen',
					to: 'catalogi/informatieobjecttypen',
					varName: 'informatieobjecttype'
				),
				'sequenceNumber' => '{{ volgnummer }}',
				'direction' => '{{ richting }}',
				'statustype' => '{{ statustype }}',
			],
			'reverseMapping' => [
				'caseType' => '{{ zaaktype | zgw_extract_uuid }}',
				'informatieobjecttype' => '{{ informatieobjecttype | zgw_extract_uuid }}',
				'sequenceNumber' => '{{ volgnummer }}',
				'direction' => '{{ richting }}',
				'statustype' => '{{ statustype }}',
			],
			'reverseCast' => [
				'sequenceNumber' => 'int',
			],
			'cast' => [
				'sequenceNumber' => 'int',
			],
			'valueMapping' => [],
			'queryParameterMapping' => [
				'caseType' => [
					'field' => 'caseType',
					'extractUuid' => true,
				],
				'informatieobjecttype' => [
					'field' => 'informatieobjecttype',
					'extractUuid' => true,
				],
			],
		];
	}//end getZaaktypeInformatieobjecttypeMapping()

	/**
	 * Get the mapping metadata for Applicatie (Consumer entity).
	 *
	 * This mapping does not use Twig templates because Applicatie maps to
	 * OpenRegister's Consumer entity rather than to register objects.
	 * The field correspondence is handled in ZgwController directly.
	 *
	 * @return array
	 */
	private function getApplicatieMapping(): array {
		return [
			'zgwResource' => 'applicatie',
			'zgwApiVersion' => '1',
			'enabled' => true,
			'fieldMapping' => [
				'clientIds[0]' => 'name',
				'label' => 'description',
				'uuid' => 'uuid',
				'heeftAlleAutorisaties' => 'authorizationConfiguration.superuser',
				'autorisaties' => 'authorizationConfiguration.scopes',
			],
		];
	}//end getApplicatieMapping()

	/**
	 * Create default test applicaties via OpenRegister's ConsumerMapper.
	 *
	 * Creates a superuser applicatie for dev/testing and a limited-scope
	 * applicatie for testing scope enforcement.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 *
	 * @psalm-suppress UnusedParam
	 */
	private function createDefaultApplicaties(IOutput $output): void {
		try {
			$container = \OC::$server;
			$consumerMapper = $container->get('OCA\OpenRegister\Db\ConsumerMapper');
		} catch (\Throwable $e) {
			$output->info('OpenRegister ConsumerMapper not available. Skipping default applicaties.');
			return;
		}

		$defaults = $this->getDefaultApplicaties();
		$created = 0;

		$updated = 0;

		foreach ($defaults as $applicatie) {
			$existing = $consumerMapper->findAll(filters: ['name' => $applicatie['name']]);
			if (count(value: $existing) > 0) {
				// Update existing consumer's authorization configuration
				// to ensure new scopes are applied.
				$consumer = $existing[0];
				$newConfig = $applicatie['authorizationConfiguration'] ?? [];
				$consumer->setAuthorizationConfiguration($newConfig);
				$consumer->setUpdated(new DateTime());
				$consumerMapper->update($consumer);
				$updated++;
				continue;
			}

			$applicatie['created'] = new DateTime();
			$applicatie['updated'] = new DateTime();
			$consumerMapper->createFromArray(object: $applicatie);
			$created++;
		}

		$output->info("Created {$created}, updated {$updated} default test applicaties.");
	}//end createDefaultApplicaties()

	/**
	 * Get default test applicatie configurations.
	 *
	 * @return array[] The default applicatie data
	 */
	private function getDefaultApplicaties(): array {
		return [
			[
				// FROZEN client ids + secrets below. These are ZGW consumer
				// credentials registered in the ZGW consumer table and referenced
				// by name from tests/zgw/seed-consumers.sh and the Postman
				// environments. A renamed id here makes the seed script look up a
				// consumer that does not exist — a KeyError that aborts the whole
				// e2e job before a single test runs. `procest-admin` is also a
				// Nextcloud GROUP id (see TransitionAuthorizer::ADMIN_GROUP_ID).
				'name' => 'procest-admin',
				'description' => 'Dossiq Admin (development)',
				'authorizationType' => 'jwt-zgw',
				'userId' => 'admin',
				'authorizationConfiguration' => [
					'publicKey' => 'procest-admin-secret-key-for-testing',
					'algorithm' => 'HS256',
					'superuser' => true,
					'scopes' => [],
				],
			],
			[
				'name' => 'procest-limited',
				'description' => 'Dossiq Limited (testing)',
				'authorizationType' => 'jwt-zgw',
				'userId' => 'admin',
				'authorizationConfiguration' => [
					'publicKey' => 'procest-limited-secret-key-for-test',
					'algorithm' => 'HS256',
					'superuser' => false,
					'scopes' => [
						[
							'component' => 'ztc',
							'scopes' => [
								'zaaktypen.lezen',
							],
						],
						[
							'component' => 'zrc',
							'scopes' => [
								'zaken.lezen',
							],
							'maxVertrouwelijkheidaanduiding' => 'openbaar',
						],
					],
				],
			],
		];
	}//end getDefaultApplicaties()

	/**
	 * Create default notification channels (kanalen).
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 */
	private function createDefaultKanalen(IOutput $output): void {
		$channelMapping = $this->zgwMappingService->getMapping(
			resourceKey: 'kanaal'
		);
		if ($channelMapping === null) {
			$output->info('Kanaal mapping not configured. Skipping default channels.');
			return;
		}

		// On a fresh install the schema settings can still be empty when this
		// step runs; a search against an empty schema context throws and would
		// abort the install. Skip by name instead.
		if ((string)($channelMapping['sourceRegister'] ?? '') === ''
			|| (string)($channelMapping['sourceSchema'] ?? '') === ''
		) {
			$output->info('Kanaal mapping has no register/schema configured yet. Skipping default channels.');
			return;
		}

		try {
			$container = \OC::$server;
			$objectService = $container->get(
				'OCA\OpenRegister\Service\ObjectService'
			);
		} catch (\Throwable $e) {
			$output->info('OpenRegister ObjectService not available. Skipping default channels.');
			return;
		}

		$defaults = $this->getDefaultKanalen();
		$created = 0;
		$refused = [];

		// A REPAIR STEP HAS NO SESSION, SO OPENREGISTER SEES 'Anonymous'.
		// This loop is what the acceptance proof caught: every fresh install
		// logged `User 'Anonymous' does not have permission to 'create' objects
		// in schema 'Notification Channel'`, the FIRST channel threw, run()'s
		// try/catch turned it into one warning, and the remaining three were
		// never attempted. So the whole ZGW notification surface shipped with
		// no channels while the step's own line never printed a zero.
		//
		// The elevation is the same one every other writing step already uses;
		// the per-row try is the other half, so one refused channel no longer
		// takes the other three with it and the count names what is missing.
		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($objectService, $channelMapping, $defaults, &$created, &$refused): void {
				foreach ($defaults as $channel) {
					try {
						// Check if kanaal already exists.
						$query = $objectService->buildSearchQuery(
							requestParams: ['name' => $channel['name']],
							register: $channelMapping['sourceRegister'],
							schema: $channelMapping['sourceSchema']
						);
						$existing = $objectService->searchObjectsPaginated(query: $query);
						if (($existing['total'] ?? 0) > 0) {
							continue;
						}

						$objectService->saveObject(
							register: $channelMapping['sourceRegister'],
							schema: $channelMapping['sourceSchema'],
							object: $channel
						);
						$created++;
					} catch (\Throwable $e) {
						$refused[] = (string)$channel['name'];
						$this->logger->error(
							'Dossiq: default notification channel refused',
							['channel' => $channel['name'], 'exception' => $e->getMessage()]
						);
					}//end try
				}//end foreach
			}
		);

		if ($refused !== []) {
			$output->warning(
				sprintf(
					'Created %d default notification channels; %d REFUSED (%s). ZGW notifications on those channels cannot be delivered.',
					$created,
					count($refused),
					implode(', ', $refused)
				)
			);
			return;
		}

		$output->info("Created {$created} default notification channels.");
	}//end createDefaultKanalen()

	/**
	 * Get default notification channel configurations.
	 *
	 * @return array[] The default kanaal data
	 */
	private function getDefaultKanalen(): array {
		return [
			[
				'name' => 'zaken',
				'filters' => [
					'bronorganisatie',
					'caseType',
					'vertrouwelijkheidaanduiding',
				],
			],
			[
				'name' => 'documenten',
				'filters' => [
					'bronorganisatie',
					'informatieobjecttype',
					'vertrouwelijkheidaanduiding',
				],
			],
			[
				'name' => 'besluiten',
				'filters' => [
					'verantwoordelijkeOrganisatie',
					'besluittype',
				],
			],
			[
				'name' => 'catalogi',
				'filters' => [],
			],
			[
				'name' => 'autorisaties',
				'filters' => [],
			],
		];
	}//end getDefaultKanalen()

	/**
	 * Get default mapping for ZaakEigenschap (case property).
	 *
	 * @param string $registerId The register ID
	 * @param array $settings The Dossiq settings
	 *
	 * @return array
	 */
	private function getZaakeigenschapMapping(string $registerId, array $settings): array {
		return [
			'zgwResource' => 'zaakeigenschap',
			'zgwApiVersion' => '1',
			'sourceRegister' => $registerId,
			'sourceSchema' => ($settings['case_property_schema'] ?? ''),
			'enabled' => true,
			'propertyMapping' => [
				'url' => '{{ _baseUrl }}/{{ _uuid }}',
				'uuid' => '{{ _uuid }}',
				'zaak' => $this->tplUrl(
					from: 'zaken/zaakeigenschappen',
					to: 'zaken/zaken',
					varName: 'case'
				),
				'eigenschap' => $this->tplUrl(
					from: 'zaken/zaakeigenschappen',
					to: 'catalogi/eigenschappen',
					varName: 'propertyDefinition'
				),
				'value' => '{{ value }}',
			],
			'reverseMapping' => [
				'case' => '{{ zaak | zgw_extract_uuid }}',
				'propertyDefinition' => '{{ eigenschap | zgw_extract_uuid }}',
				'value' => '{{ waarde }}',
			],
			'valueMapping' => [],
			'queryParameterMapping' => [
				'zaak' => [
					'field' => 'case',
					'extractUuid' => true,
				],
			],
		];
	}//end getZaakeigenschapMapping()

	/**
	 * Get default mapping for ZaakInformatieObject (case document link).
	 *
	 * @param string $registerId The register ID
	 * @param array $settings The Dossiq settings
	 *
	 * @return array
	 */
	private function getZaakinformatieobjectMapping(string $registerId, array $settings): array {
		return [
			'zgwResource' => 'zaakinformatieobject',
			'zgwApiVersion' => '1',
			'sourceRegister' => $registerId,
			'sourceSchema' => ($settings['case_document_schema'] ?? ''),
			'enabled' => true,
			'propertyMapping' => [
				'url' => '{{ _baseUrl }}/{{ _uuid }}',
				'uuid' => '{{ _uuid }}',
				'zaak' => $this->tplUrl(
					from: 'zaken/zaakinformatieobjecten',
					to: 'zaken/zaken',
					varName: 'case'
				),
				'informatieobject' => '{{ document }}',
				'titel' => '{{ title }}',
				'beschrijving' => '{{ description }}',
				'registrationDate' => '{{ registrationDate }}',
			],
			'reverseMapping' => [
				'case' => '{{ zaak | zgw_extract_uuid }}',
				'document' => '{{ informatieobject }}',
				'title' => '{{ titel }}',
				'description' => '{{ beschrijving }}',
				'registrationDate' => '{{ registratiedatum }}',
			],
			'valueMapping' => [],
			'queryParameterMapping' => [
				'zaak' => [
					'field' => 'case',
					'extractUuid' => true,
				],
				'informatieobject' => [
					'field' => 'document',
				],
			],
		];
	}//end getZaakinformatieobjectMapping()

	/**
	 * Get default mapping for ZaakObject (case object).
	 *
	 * @param string $registerId The register ID
	 * @param array $settings The Dossiq settings
	 *
	 * @return array
	 */
	private function getZaakobjectMapping(string $registerId, array $settings): array {
		return [
			'zgwResource' => 'zaakobject',
			'zgwApiVersion' => '1',
			'sourceRegister' => $registerId,
			'sourceSchema' => ($settings['case_object_schema'] ?? ''),
			'enabled' => true,
			'propertyMapping' => [
				'url' => '{{ _baseUrl }}/{{ _uuid }}',
				'uuid' => '{{ _uuid }}',
				'zaak' => $this->tplUrl(
					from: 'zaken/zaakobjecten',
					to: 'zaken/zaken',
					varName: 'case'
				),
				'object' => '{{ objectUrl }}',
				'objectType' => '{{ objectType }}',
				'objectIdentificatie' => '{{ objectIdentification }}',
				'relatieomschrijving' => '{{ description }}',
			],
			'cast' => [
				'objectIdentificatie' => 'jsonToArray',
			],
			'reverseMapping' => [
				'case' => '{{ zaak | zgw_extract_uuid }}',
				'objectUrl' => '{{ object }}',
				'objectType' => '{{ objectType }}',
				'objectIdentification' => '{{ objectIdentificatie | json_encode | raw }}',
				'description' => '{{ relatieomschrijving }}',
			],
			'valueMapping' => [],
			'queryParameterMapping' => [
				'zaak' => [
					'field' => 'case',
					'extractUuid' => true,
				],
			],
		];
	}//end getZaakobjectMapping()

	/**
	 * Get default mapping for KlantContact (customer contact).
	 *
	 * @param string $registerId The register ID
	 * @param array $settings The Dossiq settings
	 *
	 * @return array
	 */
	private function getKlantcontactMapping(string $registerId, array $settings): array {
		return [
			'zgwResource' => 'klantcontact',
			'zgwApiVersion' => '1',
			'sourceRegister' => $registerId,
			'sourceSchema' => ($settings['customer_contact_schema'] ?? ''),
			'enabled' => true,
			'propertyMapping' => [
				'url' => '{{ _baseUrl }}/{{ _uuid }}',
				'uuid' => '{{ _uuid }}',
				'zaak' => $this->tplUrl(
					from: 'zaken/klantcontacten',
					to: 'zaken/zaken',
					varName: 'case'
				),
				'datumtijd' => '{{ contactDateTime }}',
				'kanaal' => '{{ channel }}',
				'subject' => '{{ subject }}',
				'initiator' => '{{ initiator }}',
			],
			'reverseMapping' => [
				'case' => '{{ zaak | zgw_extract_uuid }}',
				'contactDateTime' => '{{ datumtijd }}',
				'channel' => '{{ kanaal }}',
				'subject' => '{{ onderwerp }}',
				'initiator' => '{{ initiator }}',
			],
			'valueMapping' => [],
			'queryParameterMapping' => [
				'zaak' => [
					'field' => 'case',
					'extractUuid' => true,
				],
			],
		];
	}//end getKlantcontactMapping()

	/**
	 * Get default mapping for BesluitInformatieObject (decision document link).
	 *
	 * @param string $registerId The register ID
	 * @param array $settings The Dossiq settings
	 *
	 * @return array
	 */
	private function getBesluitinformatieobjectMapping(
		string $registerId,
		array $settings,
	): array {
		return [
			'zgwResource' => 'besluitinformatieobject',
			'zgwApiVersion' => '1',
			'sourceRegister' => $registerId,
			'sourceSchema' => ($settings['decision_document_schema'] ?? ''),
			'enabled' => true,
			'propertyMapping' => [
				'url' => '{{ _baseUrl }}/{{ _uuid }}',
				'uuid' => '{{ _uuid }}',
				'decision' => $this->tplUrl(
					from: 'besluiten/besluitinformatieobjecten',
					to: 'besluiten/besluiten',
					varName: 'decision'
				),
				'informatieobject' => '{{ document }}',
			],
			'reverseMapping' => [
				'decision' => '{{ besluit | zgw_extract_uuid }}',
				'document' => '{{ informatieobject }}',
			],
			'valueMapping' => [],
			'queryParameterMapping' => [
				'decision' => [
					'field' => 'decision',
					'extractUuid' => true,
				],
				'informatieobject' => [
					'field' => 'document',
				],
			],
		];
	}//end getBesluitinformatieobjectMapping()

	/**
	 * Get default mapping for Verzending (dispatch).
	 *
	 * @param string $registerId The register ID
	 * @param array $settings The Dossiq settings
	 *
	 * @return array
	 */
	private function getVerzendingMapping(string $registerId, array $settings): array {
		return [
			'zgwResource' => 'verzending',
			'zgwApiVersion' => '1',
			'sourceRegister' => $registerId,
			'sourceSchema' => ($settings['dispatch_schema'] ?? ''),
			'enabled' => true,
			'propertyMapping' => [
				'url' => '{{ _baseUrl }}/{{ _uuid }}',
				'uuid' => '{{ _uuid }}',
				'informatieobject' => '{{ document }}',
				'betrokkene' => '{{ involvedParty }}',
				'aardRelatie' => '{{ relationshipType }}',
				'notes' => '{{ description }}',
				'receiptDate' => '{{ receiveDate }}',
				'verzenddatum' => '{{ sendDate }}',
				'contactPersoon' => '{{ contactPerson }}',
				'contactpersoonnaam' => '{{ contactPersonName }}',
			],
			'reverseMapping' => [
				'document' => '{{ informatieobject }}',
				'involvedParty' => '{{ betrokkene }}',
				'relationshipType' => '{{ aardRelatie }}',
				'description' => '{{ toelichting }}',
				'receiveDate' => '{{ ontvangstdatum }}',
				'sendDate' => '{{ verzenddatum }}',
				'contactPerson' => '{{ contactPersoon }}',
				'contactPersonName' => '{{ contactpersoonnaam }}',
			],
			'valueMapping' => [],
			'queryParameterMapping' => [
				'informatieobject' => [
					'field' => 'document',
				],
			],
		];
	}//end getVerzendingMapping()
}//end class
