<?php

/**
 * Procest ZGW ZRC (Zaken) Business Rules Service
 *
 * Implements business rules for the Zaken API as defined by VNG Realisatie.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
 *
 * Business rules implemented:
 *
 * - zrc-001: Valideren zaaktype op de Zaak-resource
 * - zrc-002: Garanderen uniciteit bronorganisatie en identificatie
 * - zrc-003: Valideren informatieobject op ZaakInformatieObject
 * - zrc-004: Zetten relatieinformatie op ZaakInformatieObject
 * - zrc-005: Synchroniseren relaties met informatieobjecten (cross-register, in ZgwService)
 * - zrc-006: Data filteren op basis van zaaktypes (in ZrcController)
 * - zrc-007: Afsluiten zaak (in ZrcController handleEindstatusEffect)
 * - zrc-008: Heropenen zaak (in ZrcController)
 * - zrc-009: Vertrouwelijkheidaanduiding van een zaak
 * - zrc-010: Valideren communicatiekanaal
 * - zrc-011: Valideren relevanteAndereZaken
 * - zrc-012: Gegevensgroepen (opschorting, verlenging)
 * - zrc-013: Valideren hoofdzaak
 * - zrc-014: Betalingsindicatie en laatsteBetaaldatum
 * - zrc-015: Valideren productenOfDiensten bij een Zaak
 * - zrc-016: Valideren statustype bij Zaak.zaaktype
 * - zrc-017: Valideren informatieobjecttype bij Zaak.zaaktype
 * - zrc-018: Valideren eigenschap bij Zaak.zaaktype
 * - zrc-019: Valideren roltype bij Zaak.zaaktype
 * - zrc-020: Valideren resultaattype bij Zaak.zaaktype
 * - zrc-021: Afleiden archiveringsparameters (in ZrcController)
 * - zrc-022: Zetten Zaak.archiefstatus
 * - zrc-023: Vernietigen van zaken (cascade delete, in ZrcController)
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

/**
 * ZRC (Zaken API) business rule validation and enrichment.
 *
 * @psalm-suppress UnusedClass
 */
class ZgwZrcRulesService extends ZgwRulesBase
{
    /**
     * Rules for creating a zaak (POST /zaken/v1/zaken).
     *
     * Implements:
     * - zrc-001: Validate zaaktype URL exists and is published (concept=false).
     * - zrc-002: Guarantee unique combination of identificatie + bronorganisatie.
     *   Auto-generate identificatie if not provided.
     * - zrc-009: Derive vertrouwelijkheidaanduiding from zaaktype if not explicitly set.
     * - zrc-022: Set default archiefstatus to 'nog_te_archiveren'.
     *
     * @param array $body The ZGW request body (Dutch field names)
     *
     * @return array The validation result
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     */
    public function rulesZakenCreate(array $body): array
    {
        // Zrc-001: Validate zaaktype URL.
        $zaaktypeUrl = $body['zaaktype'] ?? '';
        if (empty($zaaktypeUrl) === false && $this->objectService !== null) {
            $error = $this->validateTypeUrl(
                typeUrl: $zaaktypeUrl,
                fieldName: 'zaaktype',
                schemaKey: 'case_type_schema'
            );
            if ($error !== null) {
                return $error;
            }
        }

        // Zrc-002: Check unique identificatie + bronorganisatie.
        if (empty($body['identificatie']) === false) {
            $error = $this->checkFieldUniqueness(
                value: $body['identificatie'] ?? '',
                englishField: 'identifier',
                secondValue: $body['bronorganisatie'] ?? '',
                secondEnglishField: 'sourceOrganisation',
                dutchField: 'identificatie'
            );
            if ($error !== null) {
                return $error;
            }
        }

        // Zrc-002: Auto-generate identificatie if not provided.
        if (empty($body['identificatie']) === true) {
            $body['identificatie'] = $this->generateIdentificatie(prefix: 'ZAAK');
        }

        // Zrc-009: Derive vertrouwelijkheidaanduiding from zaaktype if not set.
        if (empty($body['vertrouwelijkheidaanduiding']) === true && empty($zaaktypeUrl) === false) {
            $body = $this->deriveVertrouwelijkheidaanduiding(body: $body, zaaktypeUrl: $zaaktypeUrl);
        }

        // Zrc-022: Set default archiefstatus.
        if (empty($body['archiefstatus']) === true) {
            $body['archiefstatus'] = 'nog_te_archiveren';
        }

        return $this->validateZaakFields(result: $this->ok(body: $body), existingObject: null, isPatch: false);
    }//end rulesZakenCreate()

    /**
     * Rules for updating a zaak (PUT /zaken/v1/zaken/{uuid}).
     *
     * Implements shared zaak field validations.
     *
     * @param array      $body           The ZGW request body
     * @param array|null $existingObject The existing zaak data
     *
     * @return array The validation result
     */
    public function rulesZakenUpdate(array $body, ?array $existingObject=null): array
    {
        // Zrc-002: Preserve immutable identificatie on PUT if not provided.
        // If the PUT body omits identificatie, carry it forward from the existing object
        // to prevent the stored identifier from being erased.
        if (isset($body['identificatie']) === false && $existingObject !== null) {
            $existingId = $existingObject['identifier'] ?? ($existingObject['identificatie'] ?? '');
            if ($existingId !== '') {
                $body['identificatie'] = $existingId;
            }
        }

        // Zrc-002: Preserve immutable bronorganisatie on PUT if not provided.
        if (isset($body['bronorganisatie']) === false && $existingObject !== null) {
            $existingOrg = $existingObject['sourceOrganisation'] ?? ($existingObject['bronorganisatie'] ?? '');
            if ($existingOrg !== '') {
                $body['bronorganisatie'] = $existingOrg;
            }
        }

        // Zrc-009: Derive vertrouwelijkheidaanduiding from zaaktype if not set.
        $zaaktypeUrl = $body['zaaktype'] ?? '';
        if (empty($body['vertrouwelijkheidaanduiding']) === true && empty($zaaktypeUrl) === false) {
            $body = $this->deriveVertrouwelijkheidaanduiding(body: $body, zaaktypeUrl: $zaaktypeUrl);
        }

        return $this->validateZaakFields(
            result: $this->ok(body: $body),
            existingObject: $existingObject,
            isPatch: false
        );
    }//end rulesZakenUpdate()

    /**
     * Rules for patching a zaak (PATCH /zaken/v1/zaken/{uuid}).
     *
     * @param array      $body           The ZGW request body
     * @param array|null $existingObject The existing zaak data
     *
     * @return array The validation result
     */
    public function rulesZakenPatch(array $body, ?array $existingObject=null): array
    {
        // Zrc-009: Derive vertrouwelijkheidaanduiding from zaaktype if not set.
        // For PATCH, the zaaktype might not be in the body — check existing object.
        $zaaktypeUrl = $body['zaaktype'] ?? '';
        if ($zaaktypeUrl === '' && $existingObject !== null) {
            $caseType = $existingObject['caseType'] ?? '';
            if ($caseType !== '') {
                $zaaktypeUrl = $caseType;
            }
        }

        // Ensure zaaktype is available in body for downstream validations
        // (zrc-010, zrc-015) that need the zaaktype URL from the existing object.
        if (($body['zaaktype'] ?? '') === '' && $zaaktypeUrl !== '') {
            $body['zaaktype'] = $zaaktypeUrl;
        }

        if (empty($body['vertrouwelijkheidaanduiding']) === true && empty($zaaktypeUrl) === false) {
            $body = $this->deriveVertrouwelijkheidaanduiding(body: $body, zaaktypeUrl: $zaaktypeUrl);
        }

        return $this->validateZaakFields(
            result: $this->ok(body: $body),
            existingObject: $existingObject,
            isPatch: true
        );
    }//end rulesZakenPatch()

    /**
     * Rules for creating a status (POST /zaken/v1/statussen).
     *
     * Implements:
     * - zrc-016: Validate that statustype belongs to Zaak.zaaktype.statustypen.
     *
     * @param array $body The ZGW request body
     *
     * @return array The validation result
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     */
    public function rulesStatussenCreate(array $body): array
    {
        // Zrc-016: Validate statustype belongs to zaak's zaaktype.
        $statustypeUrl = $body['statustype'] ?? '';
        $zaakUrl       = $body['zaak'] ?? '';
        if ($statustypeUrl !== '' && $zaakUrl !== '') {
            $error = $this->validateSubResourceType(
                zaakUrl: $zaakUrl,
                typeUrl: $statustypeUrl,
                fieldName: 'statustype',
                typeSchemaKey: 'status_type_schema',
                zaaktypeField: 'statusTypes'
            );
            if ($error !== null) {
                return $error;
            }
        }

        return $this->ok(body: $body);
    }//end rulesStatussenCreate()

    /**
     * Rules for creating a resultaat (POST /zaken/v1/resultaten).
     *
     * Implements:
     * - zrc-020: Validate that resultaattype belongs to Zaak.zaaktype.resultaattypen.
     *
     * @param array $body The ZGW request body
     *
     * @return array The validation result
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     */
    public function rulesResultatenCreate(array $body): array
    {
        // Zrc-020: Validate resultaattype belongs to zaak's zaaktype.
        $resultaattypeUrl = $body['resultaattype'] ?? '';
        $zaakUrl          = $body['zaak'] ?? '';
        if ($resultaattypeUrl !== '' && $zaakUrl !== '') {
            $error = $this->validateSubResourceType(
                zaakUrl: $zaakUrl,
                typeUrl: $resultaattypeUrl,
                fieldName: 'resultaattype',
                typeSchemaKey: 'result_type_schema',
                zaaktypeField: 'resultTypes'
            );
            if ($error !== null) {
                return $error;
            }
        }

        return $this->ok(body: $body);
    }//end rulesResultatenCreate()

    /**
     * Rules for creating a rol (POST /zaken/v1/rollen).
     *
     * Implements:
     * - zrc-019: Validate that roltype belongs to Zaak.zaaktype.roltypen.
     *
     * @param array $body The ZGW request body
     *
     * @return array The validation result
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     */
    public function rulesRollenCreate(array $body): array
    {
        // Zrc-019: Validate roltype belongs to zaak's zaaktype.
        $roltypeUrl = $body['roltype'] ?? '';
        $zaakUrl    = $body['zaak'] ?? '';
        if ($roltypeUrl !== '' && $zaakUrl !== '') {
            $error = $this->validateSubResourceType(
                zaakUrl: $zaakUrl,
                typeUrl: $roltypeUrl,
                fieldName: 'roltype',
                typeSchemaKey: 'role_type_schema',
                zaaktypeField: 'roleTypes'
            );
            if ($error !== null) {
                return $error;
            }
        }

        return $this->ok(body: $body);
    }//end rulesRollenCreate()

    /**
     * Rules for creating a ZaakInformatieObject (POST /zaken/v1/zaakinformatieobjecten).
     *
     * Implements:
     * - zrc-003: Validate informatieobject URL exists.
     * - zrc-004: Set aardRelatieWeergave and registratiedatum.
     * - zrc-017: Validate informatieobjecttype belongs to Zaak.zaaktype.
     *
     * @param array $body The ZGW request body
     *
     * @return array The validation result
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     */
    public function rulesZaakinformatieobjectenCreate(array $body): array
    {
        // Zrc-003: Validate informatieobject URL exists.
        $ioUrl = $body['informatieobject'] ?? '';
        if ($ioUrl !== '') {
            $error = $this->validateInformatieobjectUrl(ioUrl: $ioUrl);
            if ($error !== null) {
                return $error;
            }
        }

        // Zrc-017: Validate informatieobjecttype belongs to zaak's zaaktype.
        $zaakUrl = $body['zaak'] ?? '';
        if ($ioUrl !== '' && $zaakUrl !== '' && $this->objectService !== null) {
            $error = $this->validateZioInformatieobjecttype(zaakUrl: $zaakUrl, ioUrl: $ioUrl);
            if ($error !== null) {
                return $error;
            }
        }

        // Zrc-004: Set aardRelatieWeergave and registratiedatum.
        $body['aardRelatieWeergave'] = 'Hoort bij, omgekeerd: kent';
        $body['registratiedatum']    = date('Y-m-d');

        return $this->ok(body: $body);
    }//end rulesZaakinformatieobjectenCreate()

    /**
     * Rules for updating a ZaakInformatieObject (PUT).
     *
     * Implements:
     * - zrc-004: Zaak and informatieobject fields are immutable; aardRelatieWeergave is fixed.
     *
     * @param array      $body           The ZGW request body
     * @param array|null $existingObject The existing ZIO data
     *
     * @return array The validation result
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     */
    public function rulesZaakinformatieobjectenUpdate(array $body, ?array $existingObject=null): array
    {
        $result = $this->checkZioImmutability(result: $this->ok(body: $body), existingObject: $existingObject);
        if ($result['valid'] === false) {
            return $result;
        }

        $body = $result['enrichedBody'];
        $body['aardRelatieWeergave'] = 'Hoort bij, omgekeerd: kent';

        return $this->ok(body: $body);
    }//end rulesZaakinformatieobjectenUpdate()

    /**
     * Rules for patching a ZaakInformatieObject (PATCH).
     *
     * @param array      $body           The ZGW request body
     * @param array|null $existingObject The existing ZIO data
     *
     * @return array The validation result
     *
     * @see rulesZaakinformatieobjectenUpdate() Same immutability rules apply.
     */
    public function rulesZaakinformatieobjectenPatch(array $body, ?array $existingObject=null): array
    {
        return $this->rulesZaakinformatieobjectenUpdate(body: $body, existingObject: $existingObject);
    }//end rulesZaakinformatieobjectenPatch()

    /**
     * Rules for creating a zaakeigenschap (POST /zaken/{zaakUuid}/zaakeigenschappen).
     *
     * Implements:
     * - zrc-018: Validate that eigenschap belongs to Zaak.zaaktype.eigenschappen.
     *
     * @param array $body The ZGW request body
     *
     * @return array The validation result
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     */
    public function rulesZaakeigenschappenCreate(array $body): array
    {
        // Zrc-018: Validate eigenschap belongs to zaak's zaaktype.
        $eigenschapUrl = $body['eigenschap'] ?? '';
        $zaakUrl       = $body['zaak'] ?? '';
        if ($eigenschapUrl !== '' && $zaakUrl !== '') {
            $error = $this->validateSubResourceType(
                zaakUrl: $zaakUrl,
                typeUrl: $eigenschapUrl,
                fieldName: 'eigenschap',
                typeSchemaKey: 'property_definition_schema',
                zaaktypeField: 'propertyDefinitions'
            );
            if ($error !== null) {
                return $error;
            }
        }

        return $this->ok(body: $body);
    }//end rulesZaakeigenschappenCreate()

    /**
     * Derive vertrouwelijkheidaanduiding from zaaktype (zrc-009).
     *
     * If the client does not send a vertrouwelijkheidaanduiding,
     * it must be derived from ZaakType.vertrouwelijkheidaanduiding.
     *
     * @param array  $body        The request body
     * @param string $zaaktypeUrl The zaaktype URL
     *
     * @return array The body with derived vertrouwelijkheidaanduiding
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     */
    private function deriveVertrouwelijkheidaanduiding(array $body, string $zaaktypeUrl): array
    {
        $uuid = $this->extractUuid(url: $zaaktypeUrl);
        if ($uuid === null) {
            return $body;
        }

        $ztData = $this->findBySchemaKey(uuid: $uuid, schemaKey: 'case_type_schema');
        if ($ztData === null) {
            return $body;
        }

        $va = $ztData['confidentiality'] ?? ($ztData['confidentialityDesignation'] ?? ($ztData['vertrouwelijkheidaanduiding'] ?? ''));
        if ($va !== '') {
            $body['vertrouwelijkheidaanduiding'] = $va;
        }

        return $body;
    }//end deriveVertrouwelijkheidaanduiding()

    /**
     * Validate a sub-resource type belongs to the zaak's zaaktype (zrc-016..020).
     *
     * Checks that the given type URL's UUID is present in the zaak's
     * zaaktype's corresponding type list.
     *
     * @param string $zaakUrl       The zaak URL
     * @param string $typeUrl       The sub-resource type URL (statustype, roltype, etc.)
     * @param string $fieldName     The field name for error reporting
     * @param string $typeSchemaKey Settings key for the type's schema
     * @param string $zaaktypeField The zaaktype field containing allowed type UUIDs
     *
     * @return array|null Validation error, or null if valid
     */
    private function validateSubResourceType(
        string $zaakUrl,
        string $typeUrl,
        string $fieldName,
        string $typeSchemaKey,
        string $zaaktypeField
    ): ?array {
        if ($this->objectService === null) {
            return null;
        }

        // Look up the zaak to get its zaaktype.
        $zaakUuid = $this->extractUuid(url: $zaakUrl);
        if ($zaakUuid === null) {
            return null;
        }

        $zaakData = $this->findBySchemaKey(uuid: $zaakUuid, schemaKey: 'case_schema');
        if ($zaakData === null) {
            return null;
        }

        $zaaktypeId = $zaakData['caseType'] ?? '';
        if (empty($zaaktypeId) === true) {
            return null;
        }

        $zaaktypeUuid = $this->extractUuid(url: (string) $zaaktypeId);
        if ($zaaktypeUuid === null) {
            return null;
        }

        // Extract UUID from the provided type URL.
        $typeUuid = $this->extractUuid(url: $typeUrl);
        if ($typeUuid === null) {
            return null;
        }

        // Look up the type object and verify its caseType references this zaaktype.
        $typeData = $this->findBySchemaKey(uuid: $typeUuid, schemaKey: $typeSchemaKey);
        if ($typeData === null) {
            $detail = "Het {$fieldName} hoort niet bij het zaaktype van de zaak.";
            return $this->error(
                status: 400,
                detail: $detail,
                invalidParams: [$this->fieldError(fieldName: 'nonFieldErrors', code: 'zaaktype-mismatch', reason: $detail)]
            );
        }

        $typeCaseType     = $typeData['caseType'] ?? '';
        $typeCaseTypeUuid = $this->extractUuid(url: (string) $typeCaseType);

        if ($typeCaseTypeUuid !== $zaaktypeUuid) {
            $detail = "Het {$fieldName} hoort niet bij het zaaktype van de zaak.";
            return $this->error(
                status: 400,
                detail: $detail,
                invalidParams: [$this->fieldError(fieldName: 'nonFieldErrors', code: 'zaaktype-mismatch', reason: $detail)]
            );
        }

        return null;
    }//end validateSubResourceType()

    /**
     * Validate ZIO informatieobjecttype belongs to zaak's zaaktype (zrc-017).
     *
     * The informatieobjecttype of the linked informatieobject must appear
     * in Zaak.zaaktype.informatieobjecttypen.
     *
     * @param string $zaakUrl The zaak URL
     * @param string $ioUrl   The informatieobject URL
     *
     * @return array|null Validation error, or null if valid
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     */
    private function validateZioInformatieobjecttype(string $zaakUrl, string $ioUrl): ?array
    {
        // Get the informatieobject to find its informatieobjecttype.
        $ioUuid = $this->extractUuid(url: $ioUrl);
        if ($ioUuid === null) {
            return null;
        }

        $ioData = $this->findBySchemaKey(uuid: $ioUuid, schemaKey: 'document_schema');
        if ($ioData === null) {
            return null;
        }

        $docTypeId = $ioData['documentType'] ?? '';
        if (empty($docTypeId) === true) {
            return null;
        }

        // Get the zaak's zaaktype.
        $zaakUuid = $this->extractUuid(url: $zaakUrl);
        if ($zaakUuid === null) {
            return null;
        }

        $zaakData = $this->findBySchemaKey(uuid: $zaakUuid, schemaKey: 'case_schema');
        if ($zaakData === null) {
            return null;
        }

        $zaaktypeId   = $zaakData['caseType'] ?? '';
        $zaaktypeUuid = $this->extractUuid(url: (string) $zaaktypeId);
        if ($zaaktypeUuid === null) {
            return null;
        }

        // Check if a ZaakType-InformatieObjectType record links this zaaktype
        // to the document's informatieobjecttype.
        $docTypeUuid = $this->extractUuid(url: (string) $docTypeId);
        if ($docTypeUuid === null) {
            return null;
        }

        $ziotSchemaId = $this->settingsService->getConfigValue(key: 'zaaktype_informatieobjecttype_schema');
        $register     = $this->settingsService->getConfigValue(key: 'register');
        if ($ziotSchemaId === '' || $register === '') {
            return null;
        }

        try {
            $query  = $this->objectService->buildSearchQuery(
                requestParams: ['zaaktype' => $zaaktypeUuid, 'informatieobjecttype' => $docTypeUuid, '_limit' => 1],
                register: $register,
                schema: $ziotSchemaId
            );
            $result = $this->objectService->searchObjectsPaginated(query: $query);
            $found  = empty($result['results'] ?? []) === false;
        } catch (\Throwable $e) {
            return null;
        }

        if ($found === false) {
            $detail = 'Het informatieobjecttype van het informatieobject hoort niet bij het zaaktype van de zaak.';
            return $this->error(
                status: 400,
                detail: $detail,
                invalidParams: [$this->fieldError(
                    field: 'nonFieldErrors',
                    code: 'missing-zaaktype-informatieobjecttype-relation',
                    reason: $detail
                )
                ]
            );
        }

        return null;
    }//end validateZioInformatieobjecttype()

    /**
     * Common zaak field validation for create/update/patch.
     *
     * Implements:
     * - zrc-002: Identificatie immutability on update/patch.
     * - zrc-010: Validate communicatiekanaal URL.
     * - zrc-011: Validate relevanteAndereZaken URLs.
     * - zrc-012: Validate gegevensgroepen (opschorting, verlenging).
     * - zrc-013: Validate hoofdzaak URL.
     * - zrc-014: Validate betalingsindicatie + laatsteBetaaldatum consistency.
     * - zrc-015: Validate productenOfDiensten subset of zaaktype.
     * - zrc-022: Validate archiefstatus transition requires archiefnominatie + archiefactiedatum.
     *
     * @param array      $result         The current validation result
     * @param array|null $existingObject The existing object data
     * @param bool       $isPatch        Whether this is a PATCH operation
     *
     * @return array The updated validation result
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    private function validateZaakFields(array $result, ?array $existingObject, bool $isPatch): array
    {
        $body = $result['enrichedBody'];

        // Zrc-002: Identificatie immutability on update/patch.
        if ($existingObject !== null && isset($body['identificatie']) === true) {
            $existingId = $existingObject['identifier'] ?? ($existingObject['identificatie'] ?? '');
            if ($existingId !== '' && $body['identificatie'] !== $existingId) {
                return $this->fieldImmutableError(field: 'identificatie');
            }
        }

        // Zrc-010: Validate communicatiekanaal URL.
        $commKanaal = $body['communicatiekanaal'] ?? null;
        if ($commKanaal !== null && $commKanaal !== '') {
            if (filter_var($commKanaal, FILTER_VALIDATE_URL) === false) {
                return $this->error(
                    status: 400,
                    detail: 'De communicatiekanaal URL is ongeldig.',
                    invalidParams: [
                        $this->fieldError(
                            field: 'communicatiekanaal',
                            code: 'bad-url',
                            reason: 'De communicatiekanaal URL is ongeldig.'
                        ),
                    ]
                );
            }

            if ($this->isValidUrl(url: $commKanaal) === false) {
                // Determine error code: if the last path segment looks like a garbled
                // UUID (contains hex chars + dashes) → bad-url.
                // If it's a collection endpoint (word-only) → invalid-resource.
                $path          = (string) parse_url($commKanaal, PHP_URL_PATH);
                $segments      = array_filter(explode('/', trim($path, '/')));
                $last          = end($segments);
                $looksLikeUuid = preg_match('/[0-9a-f]{4,}-/i', $last) === 1;
                if ($looksLikeUuid === true) {
                    $code = 'bad-url';
                } else {
                    $code = 'invalid-resource';
                }

                return $this->error(
                    status: 400,
                    detail: 'De communicatiekanaal URL is ongeldig.',
                    invalidParams: [
                        $this->fieldError(
                            field: 'communicatiekanaal',
                            code: $code,
                            reason: 'De communicatiekanaal URL is ongeldig.'
                        ),
                    ]
                );
            }//end if
        }//end if

        // Zrc-011: Validate relevanteAndereZaken URLs.
        $relevanteZaken = $body['relevanteAndereZaken'] ?? null;
        if (is_array($relevanteZaken) === true) {
            foreach ($relevanteZaken as $idx => $relZaak) {
                $relUrl = $relZaak['url'] ?? '';
                if ($relUrl !== '' && $this->isValidUrl(url: $relUrl) === false) {
                    return $this->error(
                        status: 400,
                        detail: 'relevanteAndereZaken bevat een ongeldige URL.',
                        invalidParams: [$this->fieldError(
                            field: "relevanteAndereZaken.{$idx}.url",
                            code: 'bad-url',
                            reason: 'De URL is ongeldig.'
                        )
                        ]
                    );
                }
            }
        }

        // Zrc-012: Validate opschorting.
        $opschorting = $body['opschorting'] ?? null;
        if (is_array($opschorting) === true) {
            $errors = [];
            if (($opschorting['indicatie'] ?? null) === null) {
                $errors[] = $this->fieldError(
                    field: 'opschorting.indicatie',
                    code: 'required',
                    reason: 'Indicatie is vereist bij opschorting.'
                );
            }

            if (($opschorting['reden'] ?? '') === '') {
                $errors[] = $this->fieldError(
                    field: 'opschorting.reden',
                    code: 'required',
                    reason: 'Reden is vereist bij opschorting.'
                );
            }

            if (empty($errors) === false) {
                return $this->error(
                    status: 400,
                    detail: 'Opschorting vereist indicatie en reden.',
                    invalidParams: $errors
                );
            }
        }//end if

        // Zrc-012: Validate verlenging.
        $verlenging = $body['verlenging'] ?? null;
        if (is_array($verlenging) === true) {
            $errors = [];
            if (($verlenging['reden'] ?? '') === '') {
                $errors[] = $this->fieldError(
                    field: 'verlenging.reden',
                    code: 'required',
                    reason: 'Reden is vereist bij verlenging.'
                );
            }

            if (($verlenging['duur'] ?? '') === '') {
                $errors[] = $this->fieldError(
                    field: 'verlenging.duur',
                    code: 'required',
                    reason: 'Duur is vereist bij verlenging.'
                );
            }

            if (empty($errors) === false) {
                return $this->error(
                    status: 400,
                    detail: 'Verlenging vereist reden en duur.',
                    invalidParams: $errors
                );
            }
        }//end if

        // Zrc-013: Validate hoofdzaak URL.
        $hoofdzaak = $body['hoofdzaak'] ?? null;
        if ($hoofdzaak !== null && $hoofdzaak !== '') {
            if ($this->isValidUrl(url: $hoofdzaak) === false) {
                return $this->error(
                    status: 400,
                    detail: 'De hoofdzaak URL is ongeldig.',
                    invalidParams: [
                        $this->fieldError(fieldName: 'hoofdzaak', code: 'bad-url', reason: 'De URL is ongeldig.'),
                    ]
                );
            }

            // Zrc-013d: A zaak cannot be a deelzaak of itself.
            if ($existingObject !== null) {
                $selfUuid      = $existingObject['id'] ?? ($existingObject['@self']['id'] ?? null);
                $hoofdzaakUuid = $this->extractUuid(url: $hoofdzaak);
                if ($selfUuid !== null && $hoofdzaakUuid !== null && $selfUuid === $hoofdzaakUuid) {
                    return $this->error(
                        status: 400,
                        detail: 'Een zaak kan niet zijn eigen hoofdzaak zijn.',
                        invalidParams: [$this->fieldError(
                            field: 'hoofdzaak',
                            code: 'self-forbidden',
                            reason: 'Een zaak kan niet zijn eigen hoofdzaak zijn.'
                        )
                        ]
                    );
                }
            }

            // Zrc-013c: Deelzaak of deelzaak is not allowed.
            $error = $this->validateHoofdzaakNesting(hoofdzaakUrl: $hoofdzaak);
            if ($error !== null) {
                return $error;
            }
        }//end if

        // Zrc-014: Validate betalingsindicatie + laatsteBetaaldatum.
        $betalingsindicatie = $body['betalingsindicatie'] ?? null;
        $laatsteBetaald     = $body['laatsteBetaaldatum'] ?? null;

        // On update/patch, also consider existing values when not explicitly sent.
        if ($betalingsindicatie === null && $existingObject !== null) {
            $betalingsindicatie = $existingObject['paymentIndication'] ?? ($existingObject['betalingsindicatie'] ?? null);
        }

        if ($laatsteBetaald === null && $existingObject !== null) {
            $laatsteBetaald = $existingObject['lastPaymentDate'] ?? ($existingObject['laatsteBetaaldatum'] ?? null);
        }

        if ($betalingsindicatie === 'nvt' && $laatsteBetaald !== null && $laatsteBetaald !== '') {
            // On create: reject (cannot set date with nvt).
            if ($existingObject === null) {
                return $this->error(
                    status: 400,
                    detail: 'Als betalingsindicatie "nvt" is, mag laatsteBetaaldatum niet gezet worden.',
                    invalidParams: [$this->fieldError(
                        field: 'laatsteBetaaldatum',
                        code: 'betaling-nvt',
                        reason: 'Als betalingsindicatie "nvt" is, mag laatsteBetaaldatum niet gezet worden.'
                    )
                    ]
                );
            }

            // On update/patch: clear laatsteBetaaldatum when switching to nvt.
            $body['laatsteBetaaldatum'] = null;
        }

        // Zrc-015: Validate productenOfDiensten.
        $producten = $body['productenOfDiensten'] ?? null;
        if (is_array($producten) === true && empty($producten) === false) {
            $error = $this->validateProductenOfDiensten(body: $body);
            if ($error !== null) {
                return $error;
            }
        }

        // Zrc-022: Validate archiefstatus transition.
        $archiefstatus = $body['archiefstatus'] ?? null;
        if ($archiefstatus !== null && $archiefstatus !== 'nog_te_archiveren') {
            if (empty($body['archiefnominatie'] ?? null) === true) {
                return $this->error(
                    status: 400,
                    detail: 'archiefnominatie is vereist als archiefstatus niet "nog_te_archiveren" is.',
                    invalidParams: [$this->fieldError(
                        field: 'archiefnominatie',
                        code: 'archiefnominatie-not-set',
                        reason: 'Vereist.'
                    )
                    ]
                );
            }

            if (empty($body['archiefactiedatum'] ?? null) === true) {
                return $this->error(
                    status: 400,
                    detail: 'archiefactiedatum is vereist als archiefstatus niet "nog_te_archiveren" is.',
                    invalidParams: [$this->fieldError(
                        field: 'archiefactiedatum',
                        code: 'archiefactiedatum-not-set',
                        reason: 'Vereist.'
                    )
                    ]
                );
            }
        }//end if

        $result['enrichedBody'] = $body;

        return $result;
    }//end validateZaakFields()

    /**
     * Validate hoofdzaak is not a deelzaak itself (zrc-013).
     *
     * A deelzaak of a deelzaak is not allowed.
     *
     * @param string $hoofdzaakUrl The hoofdzaak URL
     *
     * @return array|null Validation error if hoofdzaak is itself a deelzaak
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     */
    private function validateHoofdzaakNesting(string $hoofdzaakUrl): ?array
    {
        if ($this->objectService === null) {
            return null;
        }

        $hoofdzaakUuid = $this->extractUuid(url: $hoofdzaakUrl);
        if ($hoofdzaakUuid === null) {
            return null;
        }

        $hoofdzaakData = $this->findBySchemaKey(uuid: $hoofdzaakUuid, schemaKey: 'case_schema');
        if ($hoofdzaakData === null) {
            return $this->error(
                status: 400,
                detail: 'De hoofdzaak is ongeldig.',
                invalidParams: [$this->fieldError(
                    field: 'hoofdzaak',
                    code: 'no_match',
                    reason: 'De hoofdzaak URL verwijst niet naar een bekende zaak.'
                )
                ]
            );
        }

        // If the hoofdzaak itself has a hoofdzaak, it's a deelzaak of a deelzaak.
        $parentHoofdzaak = $hoofdzaakData['parentCase'] ?? ($hoofdzaakData['mainCase'] ?? ($hoofdzaakData['hoofdzaak'] ?? null));
        if ($parentHoofdzaak !== null && $parentHoofdzaak !== '') {
            return $this->error(
                status: 400,
                detail: 'Een deelzaak van een deelzaak is niet toegestaan.',
                invalidParams: [$this->fieldError(
                    field: 'hoofdzaak',
                    code: 'deelzaak-als-hoofdzaak',
                    reason: 'De opgegeven hoofdzaak is zelf een deelzaak.'
                )
                ]
            );
        }

        return null;
    }//end validateHoofdzaakNesting()

    /**
     * Validate productenOfDiensten subset of zaaktype (zrc-015).
     *
     * ProductenOfDiensten of the zaak must be a subset of
     * Zaaktype.productenOfDiensten.
     *
     * @param array $body The request body
     *
     * @return array|null Validation error, or null if valid
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     */
    private function validateProductenOfDiensten(array $body): ?array
    {
        if ($this->objectService === null) {
            return null;
        }

        $zaaktypeUrl = $body['zaaktype'] ?? '';
        if (empty($zaaktypeUrl) === true) {
            return null;
        }

        $zaaktypeUuid = $this->extractUuid(url: $zaaktypeUrl);
        if ($zaaktypeUuid === null) {
            return null;
        }

        $ztData = $this->findBySchemaKey(uuid: $zaaktypeUuid, schemaKey: 'case_type_schema');
        if ($ztData === null) {
            return null;
        }

        $allowedProducts = $ztData['productsOrServices'] ?? ($ztData['productsAndServices'] ?? ($ztData['productenOfDiensten'] ?? []));
        if (is_string($allowedProducts) === true) {
            $allowedProducts = json_decode($allowedProducts, true) ?? [];
        }

        if (is_array($allowedProducts) === false) {
            return null;
        }

        // If zaaktype has no products configured, any product is allowed.
        if (empty($allowedProducts) === true) {
            return null;
        }

        $requestProducts = $body['productenOfDiensten'] ?? [];

        // Validate each product URL format first (basic URL check, no UUID required).
        foreach ($requestProducts as $product) {
            if (filter_var($product, FILTER_VALIDATE_URL) === false) {
                return $this->error(
                    status: 400,
                    detail: 'productenOfDiensten bevat een ongeldige URL.',
                    invalidParams: [$this->fieldError(
                        field: 'productenOfDiensten',
                        code: 'invalid-products-services',
                        reason: "'{$product}' is geen geldige URL."
                    )
                    ]
                );
            }
        }

        foreach ($requestProducts as $product) {
            if (in_array($product, $allowedProducts, true) === false) {
                return $this->error(
                    status: 400,
                    detail: 'productenOfDiensten bevat een waarde die niet in het zaaktype voorkomt.',
                    invalidParams: [$this->fieldError(
                        field: 'productenOfDiensten',
                        code: 'invalid-products-services',
                        reason: "Product '{$product}' is niet toegestaan voor dit zaaktype."
                    )
                    ]
                );
            }
        }

        return null;
    }//end validateProductenOfDiensten()

    /**
     * Check ZaakInformatieObject field immutability (zrc-004).
     *
     * Zaak and informatieobject fields are immutable after creation.
     *
     * @param array      $result         The current validation result
     * @param array|null $existingObject The existing object data
     *
     * @return array The updated validation result
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     */
    private function checkZioImmutability(array $result, ?array $existingObject): array
    {
        if ($existingObject === null) {
            return $result;
        }

        $body = $result['enrichedBody'];

        // Zrc-004: zaak is immutable.
        if (isset($body['zaak']) === true) {
            $existingZaak = $existingObject['case'] ?? ($existingObject['zaak'] ?? '');
            $newZaakUuid  = $this->extractUuid(url: $body['zaak']);
            if (is_string($existingZaak) === true) {
                $existZaakId = $this->extractUuid(url: $existingZaak);
            } else {
                $existZaakId = $existingZaak;
            }

            if ($existZaakId !== null && $newZaakUuid !== null && $newZaakUuid !== $existZaakId) {
                return $this->fieldImmutableError(field: 'zaak');
            }
        }

        // Zrc-004: informatieobject is immutable.
        if (isset($body['informatieobject']) === true) {
            $existingIo = $existingObject['document'] ?? ($existingObject['informatieobject'] ?? '');
            $newIoUuid  = $this->extractUuid(url: $body['informatieobject']);
            if (is_string($existingIo) === true) {
                $existIoId = $this->extractUuid(url: $existingIo);
            } else {
                $existIoId = $existingIo;
            }

            if ($existIoId !== null && $newIoUuid !== null && $newIoUuid !== $existIoId) {
                return $this->fieldImmutableError(field: 'informatieobject');
            }
        }

        return $result;
    }//end checkZioImmutability()
}//end class
