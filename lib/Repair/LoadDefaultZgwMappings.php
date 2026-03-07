<?php

/**
 * Procest Load Default ZGW Mappings Repair Step
 *
 * Repair step that loads default ZGW API mapping configurations into IAppConfig.
 * These mappings define how English OpenRegister properties translate to/from
 * Dutch ZGW API properties using Twig templates.
 *
 * @category Repair
 * @package  OCA\Procest\Repair
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Repair;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\ZgwMappingService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step that loads default ZGW API mapping configurations.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 */
class LoadDefaultZgwMappings implements IRepairStep
{
    /**
     * Constructor for LoadDefaultZgwMappings.
     *
     * @param ZgwMappingService $zgwMappingService The ZGW mapping service
     * @param SettingsService   $settingsService   The settings service
     * @param LoggerInterface   $logger            The logger interface
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
     */
    public function getName(): string
    {
        return 'Load default ZGW API mapping configurations for Procest';
    }//end getName()

    /**
     * Run the repair step to load default ZGW mappings.
     *
     * Only loads mappings that do not already exist (does not overwrite).
     *
     * @param IOutput $output The output interface for progress reporting
     *
     * @return void
     */
    public function run(IOutput $output): void
    {
        $output->info('Loading default ZGW API mappings...');

        $registerId = $this->settingsService->getConfigValue(key: 'register', default: '');
        if ($registerId === '') {
            $output->warning('No Procest register configured yet. Skipping ZGW mapping defaults.');
            return;
        }

        $defaults = $this->getDefaultMappings($registerId);
        $loaded   = 0;

        foreach ($defaults as $resourceKey => $config) {
            if ($this->zgwMappingService->hasMapping($resourceKey) === true) {
                continue;
            }

            $this->zgwMappingService->saveMapping(resourceKey: $resourceKey, config: $config);
            $loaded++;
        }

        $output->info("Loaded {$loaded} default ZGW mapping configurations.");

        $this->logger->info(
            'Procest: Default ZGW mappings loaded',
            ['loaded' => $loaded, 'total' => count($defaults)]
        );
    }//end run()

    /**
     * Get the default mapping configurations for all 12 ZGW resources.
     *
     * @param string $registerId The Procest register ID
     *
     * @return array<string, array> Mapping configurations keyed by resource key
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function getDefaultMappings(string $registerId): array
    {
        $settings = $this->settingsService->getSettings();

        return [
            'zaak'                 => $this->getZaakMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'zaaktype'             => $this->getZaakTypeMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'status'               => $this->getStatusMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'statustype'           => $this->getStatusTypeMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'resultaat'            => $this->getResultaatMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'resultaattype'        => $this->getResultaatTypeMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'rol'                  => $this->getRolMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'roltype'              => $this->getRolTypeMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'eigenschap'           => $this->getEigenschapMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'besluit'              => $this->getBesluitMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'besluittype'          => $this->getBesluitTypeMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'informatieobjecttype' => $this->getInformatieObjectTypeMapping(
                registerId: $registerId,
                settings: $settings
            ),
        ];
    }//end getDefaultMappings()

    /**
     * Get default mapping for Zaak (case).
     *
     * @param string $registerId The register ID
     * @param array  $settings   The Procest settings
     *
     * @return array
     */
    private function getZaakMapping(string $registerId, array $settings): array
    {
        return [
            'zgwResource'           => 'zaak',
            'zgwApiVersion'         => '1',
            'sourceRegister'        => $registerId,
            'sourceSchema'          => ($settings['case_schema'] ?? ''),
            'enabled'               => true,
            'propertyMapping'       => [
                'url'                          => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'                         => '{{ _uuid }}',
                'identificatie'                => '{{ identifier }}',
                'omschrijving'                 => '{{ title }}',
                'toelichting'                  => '{{ description }}',
                'zaaktype'                     => '{{ _baseUrl | replace({"zaken/zaken": "catalogi/zaaktypen"}) }}/{{ caseType }}',
                'registratiedatum'             => '{{ _created }}',
                'startdatum'                   => '{{ startDate }}',
                'einddatum'                    => '{{ endDate }}',
                'einddatumGepland'             => '{{ plannedEndDate }}',
                'uiterlijkeEinddatumAfdoening' => '{{ deadline }}',
                'vertrouwelijkheidaanduiding'  => '{{ confidentiality }}',
                'verantwoordelijkeOrganisatie' => '{{ assignee }}',
            ],
            'reverseMapping'        => [
                'title'           => '{{ omschrijving }}',
                'description'     => '{{ toelichting }}',
                'identifier'      => '{{ identificatie }}',
                'caseType'        => '{{ zaaktype | zgw_extract_uuid }}',
                'startDate'       => '{{ startdatum }}',
                'endDate'         => '{{ einddatum }}',
                'plannedEndDate'  => '{{ einddatumGepland }}',
                'deadline'        => '{{ uiterlijkeEinddatumAfdoening }}',
                'confidentiality' => '{{ vertrouwelijkheidaanduiding }}',
                'assignee'        => '{{ verantwoordelijkeOrganisatie }}',
            ],
            'valueMapping'          => [
                'confidentiality' => [
                    'openbaar'          => 'openbaar',
                    'beperkt_openbaar'  => 'beperkt_openbaar',
                    'intern'            => 'intern',
                    'zaakvertrouwelijk' => 'zaakvertrouwelijk',
                    'vertrouwelijk'     => 'vertrouwelijk',
                    'confidentieel'     => 'confidentieel',
                    'geheim'            => 'geheim',
                    'zeer_geheim'       => 'zeer_geheim',
                ],
            ],
            'queryParameterMapping' => [
                'zaaktype'        => [
                    'field'       => 'caseType',
                    'extractUuid' => true,
                ],
                'identificatie'   => [
                    'field' => 'identifier',
                ],
                'startdatum'      => [
                    'field' => 'startDate',
                ],
                'startdatum__gte' => [
                    'field'    => 'startDate',
                    'operator' => 'gte',
                ],
                'startdatum__lte' => [
                    'field'    => 'startDate',
                    'operator' => 'lte',
                ],
            ],
        ];
    }//end getZaakMapping()

    /**
     * Get default mapping for ZaakType (caseType).
     *
     * @param string $registerId The register ID
     * @param array  $settings   The Procest settings
     *
     * @return array
     */
    private function getZaakTypeMapping(string $registerId, array $settings): array
    {
        return [
            'zgwResource'           => 'zaaktype',
            'zgwApiVersion'         => '1',
            'sourceRegister'        => $registerId,
            'sourceSchema'          => ($settings['case_type_schema'] ?? ''),
            'enabled'               => true,
            'propertyMapping'       => [
                'url'                             => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'                            => '{{ _uuid }}',
                'identificatie'                   => '{{ identifier }}',
                'omschrijving'                    => '{{ title }}',
                'omschrijvingGeneriek'            => '{{ description }}',
                'doel'                            => '{{ purpose }}',
                'aanleiding'                      => '{{ trigger }}',
                'onderwerp'                       => '{{ subject }}',
                'doorlooptijd'                    => '{{ processingDeadline }}',
                'vertrouwelijkheidaanduiding'     => '{{ confidentiality }}',
                'concept'                         => '{{ isDraft }}',
                'beginGeldigheid'                 => '{{ validFrom }}',
                'eindeGeldigheid'                 => '{{ validUntil }}',
                'handelingInitiator'              => '{{ origin }}',
                'opschortingEnAanhoudingMogelijk' => '{{ suspensionAllowed }}',
                'verlengingMogelijk'              => '{{ extensionAllowed }}',
                'verlengingstermijn'              => '{{ extensionPeriod }}',
                'publicatieIndicatie'             => '{{ publicationRequired }}',
            ],
            'reverseMapping'        => [
                'title'               => '{{ omschrijving }}',
                'description'         => '{{ omschrijvingGeneriek }}',
                'identifier'          => '{{ identificatie }}',
                'purpose'             => '{{ doel }}',
                'trigger'             => '{{ aanleiding }}',
                'subject'             => '{{ onderwerp }}',
                'processingDeadline'  => '{{ doorlooptijd }}',
                'confidentiality'     => '{{ vertrouwelijkheidaanduiding }}',
                'isDraft'             => '{{ concept }}',
                'validFrom'           => '{{ beginGeldigheid }}',
                'validUntil'          => '{{ eindeGeldigheid }}',
                'origin'              => '{{ handelingInitiator }}',
                'suspensionAllowed'   => '{{ opschortingEnAanhoudingMogelijk }}',
                'extensionAllowed'    => '{{ verlengingMogelijk }}',
                'extensionPeriod'     => '{{ verlengingstermijn }}',
                'publicationRequired' => '{{ publicatieIndicatie }}',
            ],
            'valueMapping'          => [],
            'queryParameterMapping' => [
                'identificatie' => [
                    'field' => 'identifier',
                ],
            ],
        ];
    }//end getZaakTypeMapping()

    /**
     * Get default mapping for Status.
     *
     * @param string $registerId The register ID
     * @param array  $settings   The Procest settings
     *
     * @return array
     */
    private function getStatusMapping(string $registerId, array $settings): array
    {
        return [
            'zgwResource'           => 'status',
            'zgwApiVersion'         => '1',
            'sourceRegister'        => $registerId,
            'sourceSchema'          => ($settings['status_schema'] ?? ''),
            'enabled'               => true,
            'propertyMapping'       => [
                'url'               => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'              => '{{ _uuid }}',
                'zaak'              => '{{ _baseUrl | replace({"zaken/statussen": "zaken/zaken"}) }}/{{ case }}',
                'statustype'        => '{{ _baseUrl | replace({"zaken/statussen": "catalogi/statustypen"}) }}/{{ statusType }}',
                'datumStatusGezet'  => '{{ _created }}',
                'statustoelichting' => '{{ description }}',
            ],
            'reverseMapping'        => [
                'case'        => '{{ zaak | zgw_extract_uuid }}',
                'statusType'  => '{{ statustype | zgw_extract_uuid }}',
                'description' => '{{ statustoelichting }}',
            ],
            'valueMapping'          => [],
            'queryParameterMapping' => [
                'zaak' => [
                    'field'       => 'case',
                    'extractUuid' => true,
                ],
            ],
        ];
    }//end getStatusMapping()

    /**
     * Get default mapping for StatusType (statusType).
     *
     * @param string $registerId The register ID
     * @param array  $settings   The Procest settings
     *
     * @return array
     */
    private function getStatusTypeMapping(string $registerId, array $settings): array
    {
        return [
            'zgwResource'           => 'statustype',
            'zgwApiVersion'         => '1',
            'sourceRegister'        => $registerId,
            'sourceSchema'          => ($settings['status_type_schema'] ?? ''),
            'enabled'               => true,
            'propertyMapping'       => [
                'url'                  => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'                 => '{{ _uuid }}',
                'omschrijving'         => '{{ name }}',
                'omschrijvingGeneriek' => '{{ description }}',
                'zaaktype'             => '{{ _baseUrl | replace({"catalogi/statustypen": "catalogi/zaaktypen"}) }}/{{ caseType }}',
                'volgnummer'           => '{{ order }}',
                'isEindstatus'         => '{{ isFinal }}',
            ],
            'reverseMapping'        => [
                'name'        => '{{ omschrijving }}',
                'description' => '{{ omschrijvingGeneriek }}',
                'caseType'    => '{{ zaaktype | zgw_extract_uuid }}',
                'order'       => '{{ volgnummer }}',
                'isFinal'     => '{{ isEindstatus }}',
            ],
            'valueMapping'          => [],
            'queryParameterMapping' => [
                'zaaktype' => [
                    'field'       => 'caseType',
                    'extractUuid' => true,
                ],
            ],
        ];
    }//end getStatusTypeMapping()

    /**
     * Get default mapping for Resultaat (result).
     *
     * @param string $registerId The register ID
     * @param array  $settings   The Procest settings
     *
     * @return array
     */
    private function getResultaatMapping(string $registerId, array $settings): array
    {
        return [
            'zgwResource'           => 'resultaat',
            'zgwApiVersion'         => '1',
            'sourceRegister'        => $registerId,
            'sourceSchema'          => ($settings['result_schema'] ?? ''),
            'enabled'               => true,
            'propertyMapping'       => [
                'url'           => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'          => '{{ _uuid }}',
                'zaak'          => '{{ _baseUrl | replace({"zaken/resultaten": "zaken/zaken"}) }}/{{ case }}',
                'resultaattype' => '{{ _baseUrl | replace({"zaken/resultaten": "catalogi/resultaattypen"}) }}/{{ resultType }}',
                'toelichting'   => '{{ description }}',
            ],
            'reverseMapping'        => [
                'name'        => '{{ toelichting }}',
                'case'        => '{{ zaak | zgw_extract_uuid }}',
                'resultType'  => '{{ resultaattype | zgw_extract_uuid }}',
                'description' => '{{ toelichting }}',
            ],
            'valueMapping'          => [],
            'queryParameterMapping' => [
                'zaak' => [
                    'field'       => 'case',
                    'extractUuid' => true,
                ],
            ],
        ];
    }//end getResultaatMapping()

    /**
     * Get default mapping for ResultaatType (resultType).
     *
     * @param string $registerId The register ID
     * @param array  $settings   The Procest settings
     *
     * @return array
     */
    private function getResultaatTypeMapping(string $registerId, array $settings): array
    {
        return [
            'zgwResource'           => 'resultaattype',
            'zgwApiVersion'         => '1',
            'sourceRegister'        => $registerId,
            'sourceSchema'          => ($settings['result_type_schema'] ?? ''),
            'enabled'               => true,
            'propertyMapping'       => [
                'url'                 => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'                => '{{ _uuid }}',
                'omschrijving'        => '{{ name }}',
                'toelichting'         => '{{ description }}',
                'zaaktype'            => '{{ _baseUrl | replace({"catalogi/resultaattypen": "catalogi/zaaktypen"}) }}/{{ caseType }}',
                'archiefnominatie'    => '{{ archivalAction }}',
                'archiefactietermijn' => '{{ archivalPeriod }}',
            ],
            'reverseMapping'        => [
                'name'           => '{{ omschrijving }}',
                'description'    => '{{ toelichting }}',
                'caseType'       => '{{ zaaktype | zgw_extract_uuid }}',
                'archivalAction' => '{{ archiefnominatie }}',
                'archivalPeriod' => '{{ archiefactietermijn }}',
            ],
            'valueMapping'          => [
                'archivalAction' => [
                    'bewaren'     => 'bewaren',
                    'vernietigen' => 'vernietigen',
                ],
            ],
            'queryParameterMapping' => [
                'zaaktype' => [
                    'field'       => 'caseType',
                    'extractUuid' => true,
                ],
            ],
        ];
    }//end getResultaatTypeMapping()

    /**
     * Get default mapping for Rol (role).
     *
     * @param string $registerId The register ID
     * @param array  $settings   The Procest settings
     *
     * @return array
     */
    private function getRolMapping(string $registerId, array $settings): array
    {
        return [
            'zgwResource'           => 'rol',
            'zgwApiVersion'         => '1',
            'sourceRegister'        => $registerId,
            'sourceSchema'          => ($settings['role_schema'] ?? ''),
            'enabled'               => true,
            'propertyMapping'       => [
                'url'                     => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'                    => '{{ _uuid }}',
                'zaak'                    => '{{ _baseUrl | replace({"zaken/rollen": "zaken/zaken"}) }}/{{ case }}',
                'roltype'                 => '{{ _baseUrl | replace({"zaken/rollen": "catalogi/roltypen"}) }}/{{ roleType }}',
                'omschrijving'            => '{{ name }}',
                'omschrijvingGeneriek'    => '{{ description }}',
                'betrokkeneIdentificatie' => '{{ participant }}',
            ],
            'reverseMapping'        => [
                'name'        => '{{ omschrijving }}',
                'description' => '{{ omschrijvingGeneriek }}',
                'case'        => '{{ zaak | zgw_extract_uuid }}',
                'roleType'    => '{{ roltype | zgw_extract_uuid }}',
                'participant' => '{{ betrokkeneIdentificatie }}',
            ],
            'valueMapping'          => [],
            'queryParameterMapping' => [
                'zaak' => [
                    'field'       => 'case',
                    'extractUuid' => true,
                ],
            ],
        ];
    }//end getRolMapping()

    /**
     * Get default mapping for RolType (roleType).
     *
     * @param string $registerId The register ID
     * @param array  $settings   The Procest settings
     *
     * @return array
     */
    private function getRolTypeMapping(string $registerId, array $settings): array
    {
        return [
            'zgwResource'           => 'roltype',
            'zgwApiVersion'         => '1',
            'sourceRegister'        => $registerId,
            'sourceSchema'          => ($settings['role_type_schema'] ?? ''),
            'enabled'               => true,
            'propertyMapping'       => [
                'url'                  => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'                 => '{{ _uuid }}',
                'omschrijving'         => '{{ name }}',
                'omschrijvingGeneriek' => '{{ description }}',
                'zaaktype'             => '{{ _baseUrl | replace({"catalogi/roltypen": "catalogi/zaaktypen"}) }}/{{ caseType }}',
            ],
            'reverseMapping'        => [
                'name'        => '{{ omschrijving }}',
                'description' => '{{ omschrijvingGeneriek }}',
                'caseType'    => '{{ zaaktype | zgw_extract_uuid }}',
            ],
            'valueMapping'          => [],
            'queryParameterMapping' => [
                'zaaktype' => [
                    'field'       => 'caseType',
                    'extractUuid' => true,
                ],
            ],
        ];
    }//end getRolTypeMapping()

    /**
     * Get default mapping for Eigenschap (propertyDefinition).
     *
     * @param string $registerId The register ID
     * @param array  $settings   The Procest settings
     *
     * @return array
     */
    private function getEigenschapMapping(string $registerId, array $settings): array
    {
        return [
            'zgwResource'           => 'eigenschap',
            'zgwApiVersion'         => '1',
            'sourceRegister'        => $registerId,
            'sourceSchema'          => ($settings['property_definition_schema'] ?? ''),
            'enabled'               => true,
            'propertyMapping'       => [
                'url'         => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'        => '{{ _uuid }}',
                'naam'        => '{{ name }}',
                'toelichting' => '{{ description }}',
                'zaaktype'    => '{{ _baseUrl | replace({"catalogi/eigenschappen": "catalogi/zaaktypen"}) }}/{{ caseType }}',
            ],
            'reverseMapping'        => [
                'name'        => '{{ naam }}',
                'description' => '{{ toelichting }}',
                'caseType'    => '{{ zaaktype | zgw_extract_uuid }}',
            ],
            'valueMapping'          => [],
            'queryParameterMapping' => [
                'zaaktype' => [
                    'field'       => 'caseType',
                    'extractUuid' => true,
                ],
            ],
        ];
    }//end getEigenschapMapping()

    /**
     * Get default mapping for Besluit (decision).
     *
     * @param string $registerId The register ID
     * @param array  $settings   The Procest settings
     *
     * @return array
     */
    private function getBesluitMapping(string $registerId, array $settings): array
    {
        return [
            'zgwResource'           => 'besluit',
            'zgwApiVersion'         => '1',
            'sourceRegister'        => $registerId,
            'sourceSchema'          => ($settings['decision_schema'] ?? ''),
            'enabled'               => true,
            'propertyMapping'       => [
                'url'                          => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'                         => '{{ _uuid }}',
                'identificatie'                => '{{ title }}',
                'toelichting'                  => '{{ description }}',
                'zaak'                         => '{{ _baseUrl | replace({"besluiten/besluiten": "zaken/zaken"}) }}/{{ case }}',
                'besluittype'                  => '{{ _baseUrl | replace({"besluiten/besluiten": "catalogi/besluittypen"}) }}/{{ decisionType }}',
                'verantwoordelijkeOrganisatie' => '{{ decidedBy }}',
                'datum'                        => '{{ decidedAt }}',
                'ingangsdatum'                 => '{{ effectiveDate }}',
                'vervaldatum'                  => '{{ expiryDate }}',
            ],
            'reverseMapping'        => [
                'title'         => '{{ identificatie }}',
                'description'   => '{{ toelichting }}',
                'case'          => '{{ zaak | zgw_extract_uuid }}',
                'decisionType'  => '{{ besluittype | zgw_extract_uuid }}',
                'decidedBy'     => '{{ verantwoordelijkeOrganisatie }}',
                'decidedAt'     => '{{ datum }}',
                'effectiveDate' => '{{ ingangsdatum }}',
                'expiryDate'    => '{{ vervaldatum }}',
            ],
            'valueMapping'          => [],
            'queryParameterMapping' => [
                'zaak' => [
                    'field'       => 'case',
                    'extractUuid' => true,
                ],
            ],
        ];
    }//end getBesluitMapping()

    /**
     * Get default mapping for BesluitType (decisionType).
     *
     * @param string $registerId The register ID
     * @param array  $settings   The Procest settings
     *
     * @return array
     */
    private function getBesluitTypeMapping(string $registerId, array $settings): array
    {
        return [
            'zgwResource'           => 'besluittype',
            'zgwApiVersion'         => '1',
            'sourceRegister'        => $registerId,
            'sourceSchema'          => ($settings['decision_type_schema'] ?? ''),
            'enabled'               => true,
            'propertyMapping'       => [
                'url'                 => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'                => '{{ _uuid }}',
                'omschrijving'        => '{{ name }}',
                'toelichting'         => '{{ description }}',
                'zaaktypen'           => '{{ _baseUrl | replace({"catalogi/besluittypen": "catalogi/zaaktypen"}) }}/{{ caseType }}',
                'publicatieIndicatie' => '{{ publicationRequired }}',
            ],
            'reverseMapping'        => [
                'name'                => '{{ omschrijving }}',
                'description'         => '{{ toelichting }}',
                'caseType'            => '{{ zaaktypen | zgw_extract_uuid }}',
                'publicationRequired' => '{{ publicatieIndicatie }}',
            ],
            'valueMapping'          => [],
            'queryParameterMapping' => [
                'zaaktypen' => [
                    'field'       => 'caseType',
                    'extractUuid' => true,
                ],
            ],
        ];
    }//end getBesluitTypeMapping()

    /**
     * Get default mapping for InformatieObjectType (documentType).
     *
     * @param string $registerId The register ID
     * @param array  $settings   The Procest settings
     *
     * @return array
     */
    private function getInformatieObjectTypeMapping(string $registerId, array $settings): array
    {
        return [
            'zgwResource'           => 'informatieobjecttype',
            'zgwApiVersion'         => '1',
            'sourceRegister'        => $registerId,
            'sourceSchema'          => ($settings['document_type_schema'] ?? ''),
            'enabled'               => true,
            'propertyMapping'       => [
                'url'          => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'         => '{{ _uuid }}',
                'omschrijving' => '{{ name }}',
                'toelichting'  => '{{ description }}',
                'zaaktypen'    => '{{ _baseUrl | replace({"catalogi/informatieobjecttypen": "catalogi/zaaktypen"}) }}/{{ caseType }}',
                'verplicht'    => '{{ required }}',
            ],
            'reverseMapping'        => [
                'name'        => '{{ omschrijving }}',
                'description' => '{{ toelichting }}',
                'caseType'    => '{{ zaaktypen | zgw_extract_uuid }}',
                'required'    => '{{ verplicht }}',
            ],
            'valueMapping'          => [],
            'queryParameterMapping' => [
                'zaaktypen' => [
                    'field'       => 'caseType',
                    'extractUuid' => true,
                ],
            ],
        ];
    }//end getInformatieObjectTypeMapping()
}//end class
