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

use DateTime;
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

        $defaults = $this->getDefaultMappings(registerId: $registerId);
        $loaded   = 0;

        foreach ($defaults as $resourceKey => $config) {
            if ($this->zgwMappingService->hasMapping($resourceKey) === true) {
                continue;
            }

            $this->zgwMappingService->saveMapping(resourceKey: $resourceKey, config: $config);
            $loaded++;
        }

        $output->info("Loaded {$loaded} default ZGW mapping configurations.");

        // Create default test applicaties via ConsumerMapper.
        $this->createDefaultApplicaties(output: $output);

        // Create default notification channels.
        $this->createDefaultKanalen(output: $output);

        $this->logger->info(
            'Procest: Default ZGW mappings loaded',
            ['loaded' => $loaded, 'total' => count(value: $defaults)]
        );
    }//end run()

    /**
     * Build a Twig URL-replacement template string.
     *
     * Generates: {{ _baseUrl | replace({"<from>": "<to>"}) }}/{{ <var> }}
     *
     * @param string $from    The path segment to replace
     * @param string $to      The replacement path segment
     * @param string $varName The Twig variable to append
     *
     * @return string The Twig template string
     */
    private function tplUrl(string $from, string $to, string $varName): string
    {
        // Insert /v1/ between API group and resource (e.g. "zaken/zaken" → "zaken/v1/zaken").
        $fromParts = explode('/', $from);
        $toParts   = explode('/', $to);
        $fromPath  = $fromParts[0].'/v1/'.$fromParts[1];
        $toPath    = $toParts[0].'/v1/'.$toParts[1];

        return sprintf(self::TPL_PREFIX, $fromPath, $toPath, $varName);
    }//end tplUrl()

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
            'catalogus'                    => $this->getCatalogusMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'zaak'                         => $this->getZaakMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'zaaktype'                     => $this->getZaakTypeMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'status'                       => $this->getStatusMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'statustype'                   => $this->getStatusTypeMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'resultaat'                    => $this->getResultaatMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'resultaattype'                => $this->getResultaatTypeMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'rol'                          => $this->getRolMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'roltype'                      => $this->getRolTypeMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'eigenschap'                   => $this->getEigenschapMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'besluit'                      => $this->getBesluitMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'besluittype'                  => $this->getBesluitTypeMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'informatieobjecttype'         => $this->getInformatieObjectTypeMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'zaaktypeinformatieobjecttype' => $this->getZaaktypeInformatieobjecttypeMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'enkelvoudiginformatieobject'  => $this->getEnkelvoudigInformatieObjectMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'objectinformatieobject'       => $this->getObjectInformatieObjectMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'gebruiksrechten'              => $this->getGebruiksrechtenMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'zaakeigenschap'               => $this->getZaakeigenschapMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'zaakinformatieobject'         => $this->getZaakinformatieobjectMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'zaakobject'                   => $this->getZaakobjectMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'klantcontact'                 => $this->getKlantcontactMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'besluitinformatieobject'      => $this->getBesluitinformatieobjectMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'verzending'                   => $this->getVerzendingMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'applicatie'                   => $this->getApplicatieMapping(),
            'kanaal'                       => $this->getKanaalMapping(
                registerId: $registerId,
                settings: $settings
            ),
            'abonnement'                   => $this->getAbonnementMapping(
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
                'zaaktype'                     => $this->tplUrl(
                    from: 'zaken/zaken',
                    to: 'catalogi/zaaktypen',
                    varName: 'caseType'
                ),
                'registratiedatum'             => '{{ _created }}',
                'startdatum'                   => '{{ startDate }}',
                'einddatum'                    => '{{ endDate }}',
                'einddatumGepland'             => '{{ plannedEndDate }}',
                'uiterlijkeEinddatumAfdoening' => '{{ deadline }}',
                'vertrouwelijkheidaanduiding'  => '{{ confidentiality }}',
                'verantwoordelijkeOrganisatie' => '{{ assignee }}',
                'archiefnominatie'            => '{{ archiveNomination }}',
                'archiefactiedatum'           => '{{ archiveActionDate }}',
                'archiefstatus'               => '{{ archiveStatus }}',
                'betalingsindicatie'          => '{{ paymentIndication }}',
                'laatsteBetaaldatum'          => '{{ lastPaymentDate }}',
                'hoofdzaak'                   => '{% if parentCase %}{{ _baseUrl }}/{{ parentCase }}{% endif %}',
            ],
            'reverseMapping'        => [
                'title'              => '{{ omschrijving }}',
                'description'        => '{{ toelichting }}',
                'identifier'         => '{{ identificatie }}',
                'caseType'           => '{{ zaaktype | zgw_extract_uuid }}',
                'startDate'          => '{{ startdatum }}',
                'endDate'            => '{{ einddatum }}',
                'plannedEndDate'     => '{{ einddatumGepland }}',
                'deadline'           => '{{ uiterlijkeEinddatumAfdoening }}',
                'confidentiality'    => '{{ vertrouwelijkheidaanduiding }}',
                'assignee'           => '{{ verantwoordelijkeOrganisatie }}',
                'archiveNomination'  => '{{ archiefnominatie }}',
                'archiveActionDate'  => '{{ archiefactiedatum }}',
                'archiveStatus'      => '{{ archiefstatus }}',
                'paymentIndication'  => '{{ betalingsindicatie }}',
                'lastPaymentDate'    => '{{ laatsteBetaaldatum }}',
                'parentCase'         => '{{ hoofdzaak | zgw_extract_uuid }}',
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
            'nullableFields'        => [
                'einddatum',
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
                'zaaktype'            => [
                    'field'       => 'caseType',
                    'extractUuid' => true,
                ],
                'identificatie'       => [
                    'field' => 'identifier',
                ],
                'bronorganisatie'     => [
                    'field' => 'sourceOrganisation',
                ],
                'startdatum'          => [
                    'field' => 'startDate',
                ],
                'startdatum__gte'     => [
                    'field'    => 'startDate',
                    'operator' => 'gte',
                ],
                'startdatum__lte'     => [
                    'field'    => 'startDate',
                    'operator' => 'lte',
                ],
                'einddatum'           => [
                    'field' => 'endDate',
                ],
                'einddatum__isnull'   => [
                    'field'    => 'endDate',
                    'operator' => 'isnull',
                ],
                'archiefnominatie'    => [
                    'field' => 'archiveNomination',
                ],
                'archiefactiedatum__lt' => [
                    'field'    => 'archiveActionDate',
                    'operator' => 'lt',
                ],
                'archiefstatus'       => [
                    'field' => 'archiveStatus',
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
                'catalogus'                       => $this->tplUrl(
                    from: 'catalogi/zaaktypen',
                    to: 'catalogi/catalogussen',
                    varName: 'catalogus'
                ),
                'doel'                            => '{{ purpose }}',
                'aanleiding'                      => '{{ trigger }}',
                'onderwerp'                       => '{{ subject }}',
                'doorlooptijd'                    => '{{ processingDeadline }}',
                'vertrouwelijkheidaanduiding'     => '{{ confidentiality }}',
                'concept'                         => '{{ isDraft }}',
                'beginGeldigheid'                 => '{{ validFrom }}',
                'eindeGeldigheid'                 => '{{ validUntil }}',
                'handelingInitiator'              => '{{ origin }}',
                'indicatieInternOfExtern'         => '{{ internalOrExternal }}',
                'handelingBehandelaar'            => '{{ handlerAction }}',
                'opschortingEnAanhoudingMogelijk' => '{{ suspensionAllowed }}',
                'verlengingMogelijk'              => '{{ extensionAllowed }}',
                'verlengingstermijn'              => '{{ extensionPeriod }}',
                'publicatieIndicatie'             => '{{ publicationRequired }}',
                'productenOfDiensten'             => '{{ productsOrServices | json_encode }}',
                'selectielijstProcestype'         => '{{ selectionListProcessType }}',
                'referentieproces'                => '{{ referenceProcess | json_encode }}',
                'verantwoordelijke'               => '{{ responsible }}',
                'gerelateerdeZaaktypen'           => '{{ relatedCaseTypes | json_encode }}',
                'besluittypen'                    => 'decisionTypes',
                'informatieobjecttypen'           => '[]',
            ],
            'reverseMapping'        => [
                'title'                    => '{{ omschrijving }}',
                'description'              => '{{ omschrijvingGeneriek }}',
                'identifier'               => '{{ identificatie }}',
                'catalogus'                => '{{ catalogus | zgw_extract_uuid }}',
                'purpose'                  => '{{ doel }}',
                'trigger'                  => '{{ aanleiding }}',
                'subject'                  => '{{ onderwerp }}',
                'processingDeadline'       => '{{ doorlooptijd }}',
                'confidentiality'          => '{{ vertrouwelijkheidaanduiding }}',
                'isDraft'                  => '{{ concept }}',
                'validFrom'                => '{{ beginGeldigheid }}',
                'validUntil'               => '{{ eindeGeldigheid }}',
                'origin'                   => '{{ handelingInitiator }}',
                'internalOrExternal'       => '{{ indicatieInternOfExtern }}',
                'handlerAction'            => '{{ handelingBehandelaar }}',
                'suspensionAllowed'        => '{{ opschortingEnAanhoudingMogelijk }}',
                'extensionAllowed'         => '{{ verlengingMogelijk }}',
                'extensionPeriod'          => '{{ verlengingstermijn }}',
                'publicationRequired'      => '{{ publicatieIndicatie }}',
                'selectionListProcessType' => '{{ selectielijstProcestype }}',
                'responsible'              => '{{ verantwoordelijke }}',
                'productsOrServices'       => '{{ productenOfDiensten | json_encode }}',
                'referenceProcess'         => '{{ referentieproces | json_encode }}',
                'relatedCaseTypes'         => '{{ gerelateerdeZaaktypen | json_encode }}',
                'versionDate'              => '{{ versiedatum }}',
            ],
            'reverseCast'           => [
                'isDraft'             => 'bool',
                'suspensionAllowed'   => 'bool',
                'extensionAllowed'    => 'bool',
                'publicationRequired' => 'bool',
            ],
            'cast'                  => [
                'concept'                         => 'bool',
                'opschortingEnAanhoudingMogelijk' => 'bool',
                'verlengingMogelijk'              => 'bool',
                'publicatieIndicatie'             => 'bool',
                'productenOfDiensten'             => 'jsonToArray',
                'gerelateerdeZaaktypen'           => 'jsonToArray',
                'informatieobjecttypen'           => 'jsonToArray',
                'referentieproces'                => 'jsonToArray',
            ],
            'valueMapping'          => [],
            'queryParameterMapping' => [
                'identificatie' => [
                    'field' => 'identifier',
                ],
                'catalogus'     => [
                    'field'       => 'catalogus',
                    'extractUuid' => true,
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
            'sourceSchema'          => ($settings['status_record_schema'] ?? ''),
            'enabled'               => true,
            'propertyMapping'       => [
                'url'               => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'              => '{{ _uuid }}',
                'zaak'              => $this->tplUrl(
                    from: 'zaken/statussen',
                    to: 'zaken/zaken',
                    varName: 'case'
                ),
                'statustype'        => $this->tplUrl(
                    from: 'zaken/statussen',
                    to: 'catalogi/statustypen',
                    varName: 'statusType'
                ),
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
                'zaaktype'             => $this->tplUrl(
                    from: 'catalogi/statustypen',
                    to: 'catalogi/zaaktypen',
                    varName: 'caseType'
                ),
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
            'reverseCast'           => [
                'order'   => 'int',
                'isFinal' => 'bool',
            ],
            'cast'                  => [
                'volgnummer'   => 'int',
                'isEindstatus' => 'bool',
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
                'zaak'          => $this->tplUrl(
                    from: 'zaken/resultaten',
                    to: 'zaken/zaken',
                    varName: 'case'
                ),
                'resultaattype' => $this->tplUrl(
                    from: 'zaken/resultaten',
                    to: 'catalogi/resultaattypen',
                    varName: 'resultType'
                ),
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
                'url'                  => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'                 => '{{ _uuid }}',
                'omschrijving'         => '{{ name }}',
                'omschrijvingGeneriek' => '{{ genericDescription }}',
                'toelichting'          => '{{ description }}',
                'zaaktype'             => $this->tplUrl(
                    from: 'catalogi/resultaattypen',
                    to: 'catalogi/zaaktypen',
                    varName: 'caseType'
                ),
                'archiefnominatie'             => '{{ archivalAction }}',
                'archiefactietermijn'          => '{{ archivalPeriod }}',
                'brondatumArchiefprocedure'    => '{{ sourceDateArchiveProcedure | json_encode }}',
                'selectielijstklasse'          => '{{ selectionListClass }}',
            ],
            'reverseMapping'        => [
                'name'                        => '{{ omschrijving }}',
                'genericDescription'          => '{{ omschrijvingGeneriek }}',
                'description'                 => '{{ toelichting }}',
                'caseType'                    => '{{ zaaktype | zgw_extract_uuid }}',
                'archivalAction'              => '{{ archiefnominatie }}',
                'archivalPeriod'              => '{{ archiefactietermijn }}',
                'sourceDateArchiveProcedure'  => '{{ brondatumArchiefprocedure | json_encode }}',
                'selectionListClass'          => '{{ selectielijstklasse }}',
            ],
            'valueMapping'          => [
                'archivalAction' => [
                    'bewaren'          => 'bewaren',
                    'vernietigen'      => 'vernietigen',
                    'blijvend_bewaren' => 'blijvend_bewaren',
                ],
            ],
            'cast'                  => [
                'sourceDateArchiveProcedure' => 'jsonToArray',
            ],
            'reverseCast'           => [],
            'nullableFields'        => [
                'archiefactietermijn',
                'omschrijvingGeneriek',
                'brondatumArchiefprocedure',
                'selectielijstklasse',
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
                'zaak'                    => $this->tplUrl(
                    from: 'zaken/rollen',
                    to: 'zaken/zaken',
                    varName: 'case'
                ),
                'roltype'                 => $this->tplUrl(
                    from: 'zaken/rollen',
                    to: 'catalogi/roltypen',
                    varName: 'roleType'
                ),
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
                'zaaktype'             => $this->tplUrl(
                    from: 'catalogi/roltypen',
                    to: 'catalogi/zaaktypen',
                    varName: 'caseType'
                ),
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
                'url'          => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'         => '{{ _uuid }}',
                'naam'         => '{{ name }}',
                'definitie'    => '{{ definition }}',
                'toelichting'  => '{{ description }}',
                'zaaktype'     => $this->tplUrl(
                    from: 'catalogi/eigenschappen',
                    to: 'catalogi/zaaktypen',
                    varName: 'caseType'
                ),
                'specificatie' => '{{ {formaat: propertyType ?: "tekst"} | json_encode }}',
            ],
            'reverseMapping'        => [
                'name'        => '{{ naam }}',
                'definition'  => '{{ definitie }}',
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
                'toelichting'                  => '{{ explanation }}',
                'zaak'                         => $this->tplUrl(
                    from: 'besluiten/besluiten',
                    to: 'zaken/zaken',
                    varName: 'case'
                ),
                'besluittype'                  => $this->tplUrl(
                    from: 'besluiten/besluiten',
                    to: 'catalogi/besluittypen',
                    varName: 'decisionType'
                ),
                'verantwoordelijkeOrganisatie' => '{{ responsibleOrganisation }}',
                'bestuursorgaan'               => '{{ governingBody }}',
                'datum'                        => '{{ decisionDate }}',
                'ingangsdatum'                 => '{{ effectiveDate }}',
                'vervaldatum'                  => '{{ expiryDate }}',
                'publicatiedatum'              => '{{ publicationDate }}',
                'verzenddatum'                 => '{{ deliveryDate }}',
            ],
            'reverseMapping'        => [
                'title'                   => '{{ identificatie }}',
                'explanation'             => '{{ toelichting }}',
                'case'                    => '{% if zaak is defined and zaak %}{{ zaak | zgw_extract_uuid }}{% endif %}',
                'decisionType'            => '{{ besluittype | zgw_extract_uuid }}',
                'responsibleOrganisation' => '{{ verantwoordelijkeOrganisatie }}',
                'governingBody'           => '{{ bestuursorgaan }}',
                'decisionDate'            => '{{ datum }}',
                'effectiveDate'           => '{{ ingangsdatum }}',
                'expiryDate'              => '{{ vervaldatum }}',
                'publicationDate'         => '{{ publicatiedatum }}',
                'deliveryDate'            => '{{ verzenddatum }}',
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
                'url'                   => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'                  => '{{ _uuid }}',
                'omschrijving'          => '{{ name }}',
                'toelichting'           => '{{ description }}',
                'catalogus'             => $this->tplUrl(
                    from: 'catalogi/besluittypen',
                    to: 'catalogi/catalogussen',
                    varName: 'catalogus'
                ),
                'zaaktypen'             => 'caseTypes',
                'concept'               => '{{ isDraft }}',
                'publicatieIndicatie'   => '{{ publicationRequired }}',
                'informatieobjecttypen' => 'documentTypes',
                'beginGeldigheid'       => '{{ validFrom }}',
                'eindeGeldigheid'       => '{{ validUntil }}',
            ],
            'reverseMapping'        => [
                'name'                => '{{ omschrijving }}',
                'description'         => '{{ toelichting }}',
                'catalogus'           => '{{ catalogus | zgw_extract_uuid }}',
                'isDraft'             => '{{ concept }}',
                'publicationRequired' => '{{ publicatieIndicatie }}',
                'caseTypes'           => 'zaaktypen',
                'documentTypes'       => 'informatieobjecttypen',
                'validFrom'           => '{{ beginGeldigheid }}',
                'validUntil'          => '{{ eindeGeldigheid }}',
            ],
            'reverseCast'           => [
                'isDraft'             => 'bool',
                'publicationRequired' => 'bool',
            ],
            'cast'                  => [
                'concept'             => 'bool',
                'publicatieIndicatie' => 'bool',
            ],
            'valueMapping'          => [],
            'queryParameterMapping' => [
                'zaaktypen' => [
                    'field'       => 'caseType',
                    'extractUuid' => true,
                ],
                'catalogus' => [
                    'field'       => 'catalogus',
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
                'url'                         => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'                        => '{{ _uuid }}',
                'omschrijving'                => '{{ name }}',
                'toelichting'                 => '{{ description }}',
                'catalogus'                   => $this->tplUrl(
                    from: 'catalogi/informatieobjecttypen',
                    to: 'catalogi/catalogussen',
                    varName: 'catalogus'
                ),
                'concept'                     => '{{ isDraft }}',
                'vertrouwelijkheidaanduiding' => '{{ confidentiality }}',
                'informatieobjectcategorie'   => '{{ category }}',
                'beginGeldigheid'             => '{{ validFrom }}',
                'eindeGeldigheid'             => '{{ validUntil }}',
                'verplicht'                   => '{{ isRequired }}',
            ],
            'reverseMapping'        => [
                'name'            => '{{ omschrijving }}',
                'description'     => '{{ toelichting }}',
                'catalogus'       => '{{ catalogus | zgw_extract_uuid }}',
                'isDraft'         => '{{ concept }}',
                'confidentiality' => '{{ vertrouwelijkheidaanduiding }}',
                'category'        => '{{ informatieobjectcategorie }}',
                'validFrom'       => '{{ beginGeldigheid }}',
                'validUntil'      => '{{ eindeGeldigheid }}',
                'isRequired'      => '{{ verplicht }}',
            ],
            'reverseCast'           => [
                'isDraft'    => 'bool',
                'isRequired' => 'bool',
            ],
            'cast'                  => [
                'concept'   => 'bool',
                'verplicht' => 'bool',
            ],
            'valueMapping'          => [],
            'queryParameterMapping' => [
                'catalogus' => [
                    'field'       => 'catalogus',
                    'extractUuid' => true,
                ],
            ],
        ];
    }//end getInformatieObjectTypeMapping()

    /**
     * Get default mapping for EnkelvoudigInformatieObject (document).
     *
     * @param string $registerId The register ID
     * @param array  $settings   The Procest settings
     *
     * @return array
     */
    private function getEnkelvoudigInformatieObjectMapping(
        string $registerId,
        array $settings
    ): array {
        return [
            'zgwResource'           => 'enkelvoudiginformatieobject',
            'zgwApiVersion'         => '1',
            'sourceRegister'        => $registerId,
            'sourceSchema'          => ($settings['document_schema'] ?? ''),
            'enabled'               => true,
            'propertyMapping'       => [
                'url'                         => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'                        => '{{ _uuid }}',
                'identificatie'               => '{{ identifier }}',
                'bronorganisatie'             => '{{ sourceOrganisation }}',
                'creatiedatum'                => '{{ creationDate }}',
                'titel'                       => '{{ title }}',
                'vertrouwelijkheidaanduiding' => '{{ confidentiality }}',
                'auteur'                      => '{{ author }}',
                'status'                      => '{{ status }}',
                'formaat'                     => '{{ format }}',
                'taal'                        => '{{ language }}',
                'bestandsnaam'                => '{{ fileName }}',
                'bestandsomvang'              => '{{ fileSize }}',
                'inhoud'                      => '{{ _downloadUrl }}',
                'link'                        => '{{ link }}',
                'beschrijving'                => '{{ description }}',
                'informatieobjecttype'        => $this->tplUrl(
                    from: 'documenten/enkelvoudiginformatieobjecten',
                    to: 'catalogi/informatieobjecttypen',
                    varName: 'documentType'
                ),
                'locked'                      => '{{ locked }}',
                'registratiedatum'            => '{{ _created }}',
                'indicatieGebruiksrecht'      => '{{ usageRightsIndication }}',
            ],
            'reverseMapping'        => [
                'identifier'            => '{{ identificatie }}',
                'sourceOrganisation'    => '{{ bronorganisatie }}',
                'creationDate'          => '{{ creatiedatum }}',
                'title'                 => '{{ titel }}',
                'confidentiality'       => '{{ vertrouwelijkheidaanduiding }}',
                'author'                => '{{ auteur }}',
                'status'                => '{{ status }}',
                'format'                => '{{ formaat }}',
                'language'              => '{{ taal }}',
                'fileName'              => '{{ bestandsnaam }}',
                'fileSize'              => '{{ bestandsomvang }}',
                'link'                  => '{{ link }}',
                'description'           => '{{ beschrijving }}',
                'documentType'          => '{{ informatieobjecttype | zgw_extract_uuid }}',
                'usageRightsIndication' => '{{ indicatieGebruiksrecht }}',
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
                'status'          => [
                    'in_bewerking'     => 'in_bewerking',
                    'ter_vaststelling' => 'ter_vaststelling',
                    'definitief'       => 'definitief',
                    'gearchiveerd'     => 'gearchiveerd',
                ],
            ],
            'reverseCast'           => [
                'fileSize' => 'int',
            ],
            'cast'                  => [
                'bestandsomvang'         => 'int',
                'locked'                 => 'bool',
                'indicatieGebruiksrecht' => '?bool',
            ],
            'queryParameterMapping' => [
                'informatieobjecttype' => [
                    'field'       => 'documentType',
                    'extractUuid' => true,
                ],
                'bronorganisatie'      => [
                    'field' => 'sourceOrganisation',
                ],
            ],
        ];
    }//end getEnkelvoudigInformatieObjectMapping()

    /**
     * Get default mapping for ObjectInformatieObject (documentLink).
     *
     * @param string $registerId The register ID
     * @param array  $settings   The Procest settings
     *
     * @return array
     */
    private function getObjectInformatieObjectMapping(
        string $registerId,
        array $settings
    ): array {
        return [
            'zgwResource'           => 'objectinformatieobject',
            'zgwApiVersion'         => '1',
            'sourceRegister'        => $registerId,
            'sourceSchema'          => ($settings['document_link_schema'] ?? ''),
            'enabled'               => true,
            'propertyMapping'       => [
                'url'              => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'             => '{{ _uuid }}',
                'informatieobject' => '{{ document }}',
                'object'           => '{{ object }}',
                'objectType'       => '{{ objectType }}',
            ],
            'reverseMapping'        => [
                'document'   => '{{ informatieobject }}',
                'object'     => '{{ object }}',
                'objectType' => '{{ objectType }}',
            ],
            'valueMapping'          => [
                'objectType' => [
                    'zaak'    => 'zaak',
                    'besluit' => 'besluit',
                ],
            ],
            'queryParameterMapping' => [
                'informatieobject' => [
                    'field' => 'document',
                ],
                'object'           => [
                    'field' => 'object',
                ],
            ],
        ];
    }//end getObjectInformatieObjectMapping()

    /**
     * Get default mapping for GebruiksRechten (usageRights).
     *
     * @param string $registerId The register ID
     * @param array  $settings   The Procest settings
     *
     * @return array
     */
    private function getGebruiksrechtenMapping(
        string $registerId,
        array $settings
    ): array {
        return [
            'zgwResource'           => 'gebruiksrechten',
            'zgwApiVersion'         => '1',
            'sourceRegister'        => $registerId,
            'sourceSchema'          => ($settings['usage_rights_schema'] ?? ''),
            'enabled'               => true,
            'propertyMapping'       => [
                'url'                     => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'                    => '{{ _uuid }}',
                'informatieobject'        => '{{ document }}',
                'startdatum'              => '{{ startDate }}',
                'einddatum'               => '{{ endDate }}',
                'omschrijvingVoorwaarden' => '{{ conditionsDescription }}',
            ],
            'reverseMapping'        => [
                'document'              => '{{ informatieobject }}',
                'startDate'             => '{{ startdatum }}',
                'endDate'               => '{{ einddatum }}',
                'conditionsDescription' => '{{ omschrijvingVoorwaarden }}',
            ],
            'valueMapping'          => [],
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
     * @param array  $settings   The Procest settings
     *
     * @return array
     */
    private function getKanaalMapping(string $registerId, array $settings): array
    {
        return [
            'zgwResource'           => 'kanaal',
            'zgwApiVersion'         => '1',
            'sourceRegister'        => $registerId,
            'sourceSchema'          => ($settings['kanaal_schema'] ?? ''),
            'enabled'               => true,
            'propertyMapping'       => [
                'url'              => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'             => '{{ _uuid }}',
                'naam'             => '{{ naam }}',
                'documentatieLink' => '{{ documentatieLink }}',
                'filters'          => '{{ filters }}',
            ],
            'cast'                  => [
                'filters' => 'jsonToArray',
            ],
            'reverseMapping'        => [
                'naam'             => '{{ naam }}',
                'documentatieLink' => '{{ documentatieLink }}',
                'filters'          => '{{ filters|json_encode|raw }}',
            ],
            'valueMapping'          => [],
            'queryParameterMapping' => [
                'naam' => [
                    'field' => 'naam',
                ],
            ],
        ];
    }//end getKanaalMapping()

    /**
     * Get default mapping for Abonnement (notification subscription).
     *
     * @param string $registerId The register ID
     * @param array  $settings   The Procest settings
     *
     * @return array
     */
    private function getAbonnementMapping(string $registerId, array $settings): array
    {
        return [
            'zgwResource'           => 'abonnement',
            'zgwApiVersion'         => '1',
            'sourceRegister'        => $registerId,
            'sourceSchema'          => ($settings['abonnement_schema'] ?? ''),
            'enabled'               => true,
            'propertyMapping'       => [
                'url'         => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'        => '{{ _uuid }}',
                'callbackUrl' => '{{ callbackUrl }}',
                'auth'        => '{{ auth }}',
                'kanalen'     => '{{ kanalen }}',
            ],
            'cast'                  => [
                'kanalen' => 'jsonToArray',
            ],
            'reverseMapping'        => [
                'callbackUrl' => '{{ callbackUrl }}',
                'auth'        => '{{ auth }}',
                'kanalen'     => '{{ kanalen|json_encode|raw }}',
            ],
            'valueMapping'          => [],
            'queryParameterMapping' => [],
        ];
    }//end getAbonnementMapping()

    /**
     * Get default mapping for Catalogus.
     *
     * @param string $registerId The register ID
     * @param array  $settings   The Procest settings
     *
     * @return array
     */
    private function getCatalogusMapping(string $registerId, array $settings): array
    {
        return [
            'zgwResource'           => 'catalogus',
            'zgwApiVersion'         => '1',
            'sourceRegister'        => $registerId,
            'sourceSchema'          => ($settings['catalogus_schema'] ?? ''),
            'enabled'               => true,
            'propertyMapping'       => [
                'url'                                => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'                               => '{{ _uuid }}',
                'domein'                             => '{{ domein }}',
                'rsin'                               => '{{ rsin }}',
                'contactpersoonBeheerNaam'           => '{{ contactpersoonBeheerNaam }}',
                'contactpersoonBeheerTelefoonnummer' => '{{ contactpersoonBeheerTelefoonnummer }}',
                'contactpersoonBeheerEmailadres'     => '{{ contactpersoonBeheerEmailadres }}',
                'zaaktypen'                          => '[]',
                'besluittypen'                       => '[]',
                'informatieobjecttypen'              => '[]',
            ],
            'cast'                  => [
                'zaaktypen'             => 'jsonToArray',
                'besluittypen'          => 'jsonToArray',
                'informatieobjecttypen' => 'jsonToArray',
            ],
            'reverseMapping'        => [
                'domein'                             => '{{ domein }}',
                'rsin'                               => '{{ rsin }}',
                'contactpersoonBeheerNaam'           => '{{ contactpersoonBeheerNaam }}',
                'contactpersoonBeheerTelefoonnummer' => '{{ contactpersoonBeheerTelefoonnummer }}',
                'contactpersoonBeheerEmailadres'     => '{{ contactpersoonBeheerEmailadres }}',
            ],
            'valueMapping'          => [],
            'queryParameterMapping' => [
                'domein' => [
                    'field' => 'domein',
                ],
                'rsin'   => [
                    'field' => 'rsin',
                ],
            ],
        ];
    }//end getCatalogusMapping()

    /**
     * Get default mapping for ZaaktypeInformatieobjecttype.
     *
     * @param string $registerId The register ID
     * @param array  $settings   The Procest settings
     *
     * @return array
     */
    private function getZaaktypeInformatieobjecttypeMapping(string $registerId, array $settings): array
    {
        return [
            'zgwResource'           => 'zaaktypeinformatieobjecttype',
            'zgwApiVersion'         => '1',
            'sourceRegister'        => $registerId,
            'sourceSchema'          => ($settings['zaaktype_informatieobjecttype_schema'] ?? ''),
            'enabled'               => true,
            'propertyMapping'       => [
                'url'                  => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'                 => '{{ _uuid }}',
                'zaaktype'             => $this->tplUrl(
                    from: 'catalogi/zaaktype-informatieobjecttypen',
                    to: 'catalogi/zaaktypen',
                    varName: 'zaaktype'
                ),
                'informatieobjecttype' => $this->tplUrl(
                    from: 'catalogi/zaaktype-informatieobjecttypen',
                    to: 'catalogi/informatieobjecttypen',
                    varName: 'informatieobjecttype'
                ),
                'volgnummer'           => '{{ volgnummer }}',
                'richting'             => '{{ richting }}',
                'statustype'           => '{{ statustype }}',
            ],
            'reverseMapping'        => [
                'zaaktype'             => '{{ zaaktype | zgw_extract_uuid }}',
                'informatieobjecttype' => '{{ informatieobjecttype | zgw_extract_uuid }}',
                'volgnummer'           => '{{ volgnummer }}',
                'richting'             => '{{ richting }}',
                'statustype'           => '{{ statustype }}',
            ],
            'reverseCast'           => [
                'volgnummer' => 'int',
            ],
            'cast'                  => [
                'volgnummer' => 'int',
            ],
            'valueMapping'          => [],
            'queryParameterMapping' => [
                'zaaktype'             => [
                    'field'       => 'zaaktype',
                    'extractUuid' => true,
                ],
                'informatieobjecttype' => [
                    'field'       => 'informatieobjecttype',
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
    private function getApplicatieMapping(): array
    {
        return [
            'zgwResource'   => 'applicatie',
            'zgwApiVersion' => '1',
            'enabled'       => true,
            'fieldMapping'  => [
                'clientIds[0]'          => 'name',
                'label'                 => 'description',
                'uuid'                  => 'uuid',
                'heeftAlleAutorisaties' => 'authorizationConfiguration.superuser',
                'autorisaties'          => 'authorizationConfiguration.scopes',
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
     */
    private function createDefaultApplicaties(IOutput $output): void
    {
        try {
            $container      = \OC::$server;
            $consumerMapper = $container->get('OCA\OpenRegister\Db\ConsumerMapper');
        } catch (\Throwable $e) {
            $output->info('OpenRegister ConsumerMapper not available. Skipping default applicaties.');
            return;
        }

        $defaults = $this->getDefaultApplicaties();
        $created  = 0;

        foreach ($defaults as $applicatie) {
            $existing = $consumerMapper->findAll(filters: ['name' => $applicatie['name']]);
            if (count(value: $existing) > 0) {
                continue;
            }

            $applicatie['created'] = new DateTime();
            $applicatie['updated'] = new DateTime();
            $consumerMapper->createFromArray(object: $applicatie);
            $created++;
        }

        $output->info("Created {$created} default test applicaties.");
    }//end createDefaultApplicaties()

    /**
     * Get default test applicatie configurations.
     *
     * @return array[] The default applicatie data
     */
    private function getDefaultApplicaties(): array
    {
        return [
            [
                'name'                       => 'procest-admin',
                'description'                => 'Procest Admin (development)',
                'authorizationType'          => 'jwt-zgw',
                'userId'                     => 'admin',
                'authorizationConfiguration' => [
                    'publicKey' => 'procest-admin-secret-key-for-testing',
                    'algorithm' => 'HS256',
                    'superuser' => true,
                    'scopes'    => [],
                ],
            ],
            [
                'name'                       => 'procest-limited',
                'description'                => 'Procest Limited (testing)',
                'authorizationType'          => 'jwt-zgw',
                'userId'                     => 'admin',
                'authorizationConfiguration' => [
                    'publicKey' => 'procest-limited-secret-key-for-test',
                    'algorithm' => 'HS256',
                    'superuser' => false,
                    'scopes'    => [
                        [
                            'component' => 'ztc',
                            'scopes'    => [
                                'zaaktypen.lezen',
                            ],
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
    private function createDefaultKanalen(IOutput $output): void
    {
        $kanaalMapping = $this->zgwMappingService->getMapping(
            resourceKey: 'kanaal'
        );
        if ($kanaalMapping === null) {
            $output->info('Kanaal mapping not configured. Skipping default channels.');
            return;
        }

        try {
            $container     = \OC::$server;
            $objectService = $container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );
        } catch (\Throwable $e) {
            $output->info('OpenRegister ObjectService not available. Skipping default channels.');
            return;
        }

        $defaults = $this->getDefaultKanalen();
        $created  = 0;

        foreach ($defaults as $kanaal) {
            // Check if kanaal already exists.
            $query    = $objectService->buildSearchQuery(
                requestParams: ['naam' => $kanaal['naam']],
                register: $kanaalMapping['sourceRegister'],
                schema: $kanaalMapping['sourceSchema']
            );
            $existing = $objectService->searchObjectsPaginated(query: $query);
            if (($existing['total'] ?? 0) > 0) {
                continue;
            }

            $objectService->saveObject(
                register: $kanaalMapping['sourceRegister'],
                schema: $kanaalMapping['sourceSchema'],
                object: $kanaal
            );
            $created++;
        }

        $output->info("Created {$created} default notification channels.");
    }//end createDefaultKanalen()

    /**
     * Get default notification channel configurations.
     *
     * @return array[] The default kanaal data
     */
    private function getDefaultKanalen(): array
    {
        return [
            [
                'naam'    => 'zaken',
                'filters' => [
                    'bronorganisatie',
                    'zaaktype',
                    'vertrouwelijkheidaanduiding',
                ],
            ],
            [
                'naam'    => 'documenten',
                'filters' => [
                    'bronorganisatie',
                    'informatieobjecttype',
                    'vertrouwelijkheidaanduiding',
                ],
            ],
            [
                'naam'    => 'besluiten',
                'filters' => [
                    'verantwoordelijkeOrganisatie',
                    'besluittype',
                ],
            ],
            [
                'naam'    => 'catalogi',
                'filters' => [],
            ],
            [
                'naam'    => 'autorisaties',
                'filters' => [],
            ],
        ];
    }//end getDefaultKanalen()

    /**
     * Get default mapping for ZaakEigenschap (case property).
     *
     * @param string $registerId The register ID
     * @param array  $settings   The Procest settings
     *
     * @return array
     */
    private function getZaakeigenschapMapping(string $registerId, array $settings): array
    {
        return [
            'zgwResource'           => 'zaakeigenschap',
            'zgwApiVersion'         => '1',
            'sourceRegister'        => $registerId,
            'sourceSchema'          => ($settings['case_property_schema'] ?? ''),
            'enabled'               => true,
            'propertyMapping'       => [
                'url'        => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'       => '{{ _uuid }}',
                'zaak'       => $this->tplUrl(
                    from: 'zaken/zaakeigenschappen',
                    to: 'zaken/zaken',
                    varName: 'case'
                ),
                'eigenschap' => $this->tplUrl(
                    from: 'zaken/zaakeigenschappen',
                    to: 'catalogi/eigenschappen',
                    varName: 'propertyDefinition'
                ),
                'waarde'     => '{{ value }}',
            ],
            'reverseMapping'        => [
                'case'               => '{{ zaak | zgw_extract_uuid }}',
                'propertyDefinition' => '{{ eigenschap | zgw_extract_uuid }}',
                'value'              => '{{ waarde }}',
            ],
            'valueMapping'          => [],
            'queryParameterMapping' => [
                'zaak' => [
                    'field'       => 'case',
                    'extractUuid' => true,
                ],
            ],
        ];
    }//end getZaakeigenschapMapping()

    /**
     * Get default mapping for ZaakInformatieObject (case document link).
     *
     * @param string $registerId The register ID
     * @param array  $settings   The Procest settings
     *
     * @return array
     */
    private function getZaakinformatieobjectMapping(string $registerId, array $settings): array
    {
        return [
            'zgwResource'           => 'zaakinformatieobject',
            'zgwApiVersion'         => '1',
            'sourceRegister'        => $registerId,
            'sourceSchema'          => ($settings['case_document_schema'] ?? ''),
            'enabled'               => true,
            'propertyMapping'       => [
                'url'              => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'             => '{{ _uuid }}',
                'zaak'             => $this->tplUrl(
                    from: 'zaken/zaakinformatieobjecten',
                    to: 'zaken/zaken',
                    varName: 'case'
                ),
                'informatieobject' => '{{ document }}',
                'titel'            => '{{ title }}',
                'beschrijving'     => '{{ description }}',
                'registratiedatum' => '{{ registrationDate }}',
            ],
            'reverseMapping'        => [
                'case'             => '{{ zaak | zgw_extract_uuid }}',
                'document'         => '{{ informatieobject }}',
                'title'            => '{{ titel }}',
                'description'      => '{{ beschrijving }}',
                'registrationDate' => '{{ registratiedatum }}',
            ],
            'valueMapping'          => [],
            'queryParameterMapping' => [
                'zaak'             => [
                    'field'       => 'case',
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
     * @param array  $settings   The Procest settings
     *
     * @return array
     */
    private function getZaakobjectMapping(string $registerId, array $settings): array
    {
        return [
            'zgwResource'           => 'zaakobject',
            'zgwApiVersion'         => '1',
            'sourceRegister'        => $registerId,
            'sourceSchema'          => ($settings['case_object_schema'] ?? ''),
            'enabled'               => true,
            'propertyMapping'       => [
                'url'                 => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'                => '{{ _uuid }}',
                'zaak'                => $this->tplUrl(
                    from: 'zaken/zaakobjecten',
                    to: 'zaken/zaken',
                    varName: 'case'
                ),
                'object'              => '{{ objectUrl }}',
                'objectType'          => '{{ objectType }}',
                'objectIdentificatie' => '{{ objectIdentification }}',
                'relatieomschrijving' => '{{ description }}',
            ],
            'cast'                  => [
                'objectIdentificatie' => 'jsonToArray',
            ],
            'reverseMapping'        => [
                'case'                 => '{{ zaak | zgw_extract_uuid }}',
                'objectUrl'            => '{{ object }}',
                'objectType'           => '{{ objectType }}',
                'objectIdentification' => '{{ objectIdentificatie | json_encode | raw }}',
                'description'          => '{{ relatieomschrijving }}',
            ],
            'valueMapping'          => [],
            'queryParameterMapping' => [
                'zaak' => [
                    'field'       => 'case',
                    'extractUuid' => true,
                ],
            ],
        ];
    }//end getZaakobjectMapping()

    /**
     * Get default mapping for KlantContact (customer contact).
     *
     * @param string $registerId The register ID
     * @param array  $settings   The Procest settings
     *
     * @return array
     */
    private function getKlantcontactMapping(string $registerId, array $settings): array
    {
        return [
            'zgwResource'           => 'klantcontact',
            'zgwApiVersion'         => '1',
            'sourceRegister'        => $registerId,
            'sourceSchema'          => ($settings['customer_contact_schema'] ?? ''),
            'enabled'               => true,
            'propertyMapping'       => [
                'url'       => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'      => '{{ _uuid }}',
                'zaak'      => $this->tplUrl(
                    from: 'zaken/klantcontacten',
                    to: 'zaken/zaken',
                    varName: 'case'
                ),
                'datumtijd' => '{{ contactDateTime }}',
                'kanaal'    => '{{ channel }}',
                'onderwerp' => '{{ subject }}',
                'initiator' => '{{ initiator }}',
            ],
            'reverseMapping'        => [
                'case'            => '{{ zaak | zgw_extract_uuid }}',
                'contactDateTime' => '{{ datumtijd }}',
                'channel'         => '{{ kanaal }}',
                'subject'         => '{{ onderwerp }}',
                'initiator'       => '{{ initiator }}',
            ],
            'valueMapping'          => [],
            'queryParameterMapping' => [
                'zaak' => [
                    'field'       => 'case',
                    'extractUuid' => true,
                ],
            ],
        ];
    }//end getKlantcontactMapping()

    /**
     * Get default mapping for BesluitInformatieObject (decision document link).
     *
     * @param string $registerId The register ID
     * @param array  $settings   The Procest settings
     *
     * @return array
     */
    private function getBesluitinformatieobjectMapping(
        string $registerId,
        array $settings
    ): array {
        return [
            'zgwResource'           => 'besluitinformatieobject',
            'zgwApiVersion'         => '1',
            'sourceRegister'        => $registerId,
            'sourceSchema'          => ($settings['decision_document_schema'] ?? ''),
            'enabled'               => true,
            'propertyMapping'       => [
                'url'              => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'             => '{{ _uuid }}',
                'besluit'          => $this->tplUrl(
                    from: 'besluiten/besluitinformatieobjecten',
                    to: 'besluiten/besluiten',
                    varName: 'decision'
                ),
                'informatieobject' => '{{ document }}',
            ],
            'reverseMapping'        => [
                'decision' => '{{ besluit | zgw_extract_uuid }}',
                'document' => '{{ informatieobject }}',
            ],
            'valueMapping'          => [],
            'queryParameterMapping' => [
                'besluit'          => [
                    'field'       => 'decision',
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
     * @param array  $settings   The Procest settings
     *
     * @return array
     */
    private function getVerzendingMapping(string $registerId, array $settings): array
    {
        return [
            'zgwResource'           => 'verzending',
            'zgwApiVersion'         => '1',
            'sourceRegister'        => $registerId,
            'sourceSchema'          => ($settings['dispatch_schema'] ?? ''),
            'enabled'               => true,
            'propertyMapping'       => [
                'url'                => '{{ _baseUrl }}/{{ _uuid }}',
                'uuid'               => '{{ _uuid }}',
                'informatieobject'   => '{{ document }}',
                'betrokkene'         => '{{ involvedParty }}',
                'aardRelatie'        => '{{ relationshipType }}',
                'toelichting'        => '{{ description }}',
                'ontvangstdatum'     => '{{ receiveDate }}',
                'verzenddatum'       => '{{ sendDate }}',
                'contactPersoon'     => '{{ contactPerson }}',
                'contactpersoonnaam' => '{{ contactPersonName }}',
            ],
            'reverseMapping'        => [
                'document'          => '{{ informatieobject }}',
                'involvedParty'     => '{{ betrokkene }}',
                'relationshipType'  => '{{ aardRelatie }}',
                'description'       => '{{ toelichting }}',
                'receiveDate'       => '{{ ontvangstdatum }}',
                'sendDate'          => '{{ verzenddatum }}',
                'contactPerson'     => '{{ contactPersoon }}',
                'contactPersonName' => '{{ contactpersoonnaam }}',
            ],
            'valueMapping'          => [],
            'queryParameterMapping' => [
                'informatieobject' => [
                    'field' => 'document',
                ],
            ],
        ];
    }//end getVerzendingMapping()
}//end class
