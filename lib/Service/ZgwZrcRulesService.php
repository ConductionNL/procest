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
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     * - zrc-002: Guarantee unique combination of identificatie + bronorganisatie.
     *   Auto-generate identificatie if not provided.
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     * - zrc-009: Derive vertrouwelijkheidaanduiding from zaaktype if not explicitly set.
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     * - zrc-022: Set default archiefstatus to 'nog_te_archiveren'.
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     *
     * @param array $body The ZGW request body (Dutch field names)
     *
     * @return array The validation result
     */
    public function rulesZakenCreate(array $body): array
    {
        // zrc-001: Validate zaaktype URL.
        $zaaktypeUrl = $body['zaaktype'] ?? '';
        if (empty($zaaktypeUrl) === false && $this->objectService !== null) {
            $error = $this->validateTypeUrl($zaaktypeUrl, 'zaaktype', 'case_type_schema');
            if ($error !== null) {
                return $error;
            }
        }

        // zrc-002: Check unique identificatie + bronorganisatie.
        if (empty($body['identificatie']) === false) {
            $error = $this->checkFieldUniqueness(
                $body['identificatie'] ?? '',
                'identifier',
                $body['bronorganisatie'] ?? '',
                'sourceOrganisation',
                'identificatie'
            );
            if ($error !== null) {
                return $error;
            }
        }

        // zrc-002: Auto-generate identificatie if not provided.
        if (empty($body['identificatie']) === true) {
            $body['identificatie'] = $this->generateIdentificatie('ZAAK');
        }

        // zrc-009: Derive vertrouwelijkheidaanduiding from zaaktype if not set.
        if (empty($body['vertrouwelijkheidaanduiding']) === true && empty($zaaktypeUrl) === false) {
            $body = $this->deriveVertrouwelijkheidaanduiding($body, $zaaktypeUrl);
        }

        // zrc-022: Set default archiefstatus.
        if (empty($body['archiefstatus']) === true) {
            $body['archiefstatus'] = 'nog_te_archiveren';
        }

        return $this->validateZaakFields($this->ok($body), null, false);
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
        // zrc-002: Preserve immutable identificatie on PUT if not provided.
        // If the PUT body omits identificatie, carry it forward from the existing object
        // to prevent the stored identifier from being erased.
        if (isset($body['identificatie']) === false && $existingObject !== null) {
            $existingId = $existingObject['identifier'] ?? ($existingObject['identificatie'] ?? '');
            if ($existingId !== '') {
                $body['identificatie'] = $existingId;
            }
        }

        // zrc-002: Preserve immutable bronorganisatie on PUT if not provided.
        if (isset($body['bronorganisatie']) === false && $existingObject !== null) {
            $existingOrg = $existingObject['sourceOrganisation'] ?? ($existingObject['bronorganisatie'] ?? '');
            if ($existingOrg !== '') {
                $body['bronorganisatie'] = $existingOrg;
            }
        }

        // zrc-009: Derive vertrouwelijkheidaanduiding from zaaktype if not set.
        $zaaktypeUrl = $body['zaaktype'] ?? '';
        if (empty($body['vertrouwelijkheidaanduiding']) === true && empty($zaaktypeUrl) === false) {
            $body = $this->deriveVertrouwelijkheidaanduiding($body, $zaaktypeUrl);
        }

        return $this->validateZaakFields($this->ok($body), $existingObject, false);
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
        // zrc-009: Derive vertrouwelijkheidaanduiding from zaaktype if not set.
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
            $body = $this->deriveVertrouwelijkheidaanduiding($body, $zaaktypeUrl);
        }

        return $this->validateZaakFields($this->ok($body), $existingObject, true);
    }//end rulesZakenPatch()

    /**
     * Rules for creating a status (POST /zaken/v1/statussen).
     *
     * Implements:
     * - zrc-016: Validate that statustype belongs to Zaak.zaaktype.statustypen.
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     *
     * @param array $body The ZGW request body
     *
     * @return array The validation result
     */
    public function rulesStatussenCreate(array $body): array
    {
        // zrc-016: Validate statustype belongs to zaak's zaaktype.
        $statustypeUrl = $body['statustype'] ?? '';
        $zaakUrl       = $body['zaak'] ?? '';
        if ($statustypeUrl !== '' && $zaakUrl !== '') {
            $error = $this->validateSubResourceType(
                $zaakUrl,
                $statustypeUrl,
                'statustype',
                'status_type_schema',
                'statusTypes'
            );
            if ($error !== null) {
                return $error;
            }
        }

        return $this->ok($body);
    }//end rulesStatussenCreate()

    /**
     * Rules for creating a resultaat (POST /zaken/v1/resultaten).
     *
     * Implements:
     * - zrc-020: Validate that resultaattype belongs to Zaak.zaaktype.resultaattypen.
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     *
     * @param array $body The ZGW request body
     *
     * @return array The validation result
     */
    public function rulesResultatenCreate(array $body): array
    {
        // zrc-020: Validate resultaattype belongs to zaak's zaaktype.
        $resultaattypeUrl = $body['resultaattype'] ?? '';
        $zaakUrl          = $body['zaak'] ?? '';
        if ($resultaattypeUrl !== '' && $zaakUrl !== '') {
            $error = $this->validateSubResourceType(
                $zaakUrl,
                $resultaattypeUrl,
                'resultaattype',
                'result_type_schema',
                'resultTypes'
            );
            if ($error !== null) {
                return $error;
            }
        }

        return $this->ok($body);
    }//end rulesResultatenCreate()

    /**
     * Rules for creating a rol (POST /zaken/v1/rollen).
     *
     * Implements:
     * - zrc-019: Validate that roltype belongs to Zaak.zaaktype.roltypen.
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     *
     * @param array $body The ZGW request body
     *
     * @return array The validation result
     */
    public function rulesRollenCreate(array $body): array
    {
        // zrc-019: Validate roltype belongs to zaak's zaaktype.
        $roltypeUrl = $body['roltype'] ?? '';
        $zaakUrl    = $body['zaak'] ?? '';
        if ($roltypeUrl !== '' && $zaakUrl !== '') {
            $error = $this->validateSubResourceType(
                $zaakUrl,
                $roltypeUrl,
                'roltype',
                'role_type_schema',
                'roleTypes'
            );
            if ($error !== null) {
                return $error;
            }
        }

        return $this->ok($body);
    }//end rulesRollenCreate()

    /**
     * Rules for creating a ZaakInformatieObject (POST /zaken/v1/zaakinformatieobjecten).
     *
     * Implements:
     * - zrc-003: Validate informatieobject URL exists.
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     * - zrc-004: Set aardRelatieWeergave and registratiedatum.
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     * - zrc-017: Validate informatieobjecttype belongs to Zaak.zaaktype.
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     *
     * @param array $body The ZGW request body
     *
     * @return array The validation result
     */
    public function rulesZaakinformatieobjectenCreate(array $body): array
    {
        // zrc-003: Validate informatieobject URL exists.
        $ioUrl = $body['informatieobject'] ?? '';
        if ($ioUrl !== '') {
            $error = $this->validateInformatieobjectUrl($ioUrl);
            if ($error !== null) {
                return $error;
            }
        }

        // zrc-017: Validate informatieobjecttype belongs to zaak's zaaktype.
        $zaakUrl = $body['zaak'] ?? '';
        if ($ioUrl !== '' && $zaakUrl !== '' && $this->objectService !== null) {
            $error = $this->validateZioInformatieobjecttype($zaakUrl, $ioUrl);
            if ($error !== null) {
                return $error;
            }
        }

        // zrc-004: Set aardRelatieWeergave and registratiedatum.
        $body['aardRelatieWeergave'] = 'Hoort bij, omgekeerd: kent';
        $body['registratiedatum']    = date('Y-m-d');

        return $this->ok($body);
    }//end rulesZaakinformatieobjectenCreate()

    /**
     * Rules for updating a ZaakInformatieObject (PUT).
     *
     * Implements:
     * - zrc-004: Zaak and informatieobject fields are immutable; aardRelatieWeergave is fixed.
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     *
     * @param array      $body           The ZGW request body
     * @param array|null $existingObject The existing ZIO data
     *
     * @return array The validation result
     */
    public function rulesZaakinformatieobjectenUpdate(array $body, ?array $existingObject=null): array
    {
        $result = $this->checkZioImmutability($this->ok($body), $existingObject);
        if ($result['valid'] === false) {
            return $result;
        }

        $body = $result['enrichedBody'];
        $body['aardRelatieWeergave'] = 'Hoort bij, omgekeerd: kent';

        return $this->ok($body);
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
        return $this->rulesZaakinformatieobjectenUpdate($body, $existingObject);
    }//end rulesZaakinformatieobjectenPatch()

    /**
     * Rules for creating a zaakeigenschap (POST /zaken/{zaakUuid}/zaakeigenschappen).
     *
     * Implements:
     * - zrc-018: Validate that eigenschap belongs to Zaak.zaaktype.eigenschappen.
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     *
     * @param array $body The ZGW request body
     *
     * @return array The validation result
     */
    public function rulesZaakeigenschappenCreate(array $body): array
    {
        // zrc-018: Validate eigenschap belongs to zaak's zaaktype.
        $eigenschapUrl = $body['eigenschap'] ?? '';
        $zaakUrl       = $body['zaak'] ?? '';
        if ($eigenschapUrl !== '' && $zaakUrl !== '') {
            $error = $this->validateSubResourceType(
                $zaakUrl,
                $eigenschapUrl,
                'eigenschap',
                'property_definition_schema',
                'propertyDefinitions'
            );
            if ($error !== null) {
                return $error;
            }
        }

        return $this->ok($body);
    }//end rulesZaakeigenschappenCreate()

    /**
     * Derive vertrouwelijkheidaanduiding from zaaktype (zrc-009).
     *
     * If the client does not send a vertrouwelijkheidaanduiding,
     * it must be derived from ZaakType.vertrouwelijkheidaanduiding.
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     *
     * @param array  $body        The request body
     * @param string $zaaktypeUrl The zaaktype URL
     *
     * @return array The body with derived vertrouwelijkheidaanduiding
     */
    private function deriveVertrouwelijkheidaanduiding(array $body, string $zaaktypeUrl): array
    {
        $uuid = $this->extractUuid($zaaktypeUrl);
        if ($uuid === null) {
            return $body;
        }

        $ztData = $this->findBySchemaKey($uuid, 'case_type_schema');
        if ($ztData === null) {
            return $body;
        }

        $va = $ztData['confidentiality']
            ?? ($ztData['confidentialityDesignation']
            ?? ($ztData['vertrouwelijkheidaanduiding'] ?? ''));
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
        $zaakUuid = $this->extractUuid($zaakUrl);
        if ($zaakUuid === null) {
            return null;
        }

        $zaakData = $this->findBySchemaKey($zaakUuid, 'case_schema');
        if ($zaakData === null) {
            return null;
        }

        $zaaktypeId = $zaakData['caseType'] ?? '';
        if (empty($zaaktypeId) === true) {
            return null;
        }

        $zaaktypeUuid = $this->extractUuid((string) $zaaktypeId);
        if ($zaaktypeUuid === null) {
            return null;
        }

        // Extract UUID from the provided type URL.
        $typeUuid = $this->extractUuid($typeUrl);
        if ($typeUuid === null) {
            return null;
        }

        // Look up the type object and verify its caseType references this zaaktype.
        $typeData = $this->findBySchemaKey($typeUuid, $typeSchemaKey);
        if ($typeData === null) {
            $detail = "Het {$fieldName} hoort niet bij het zaaktype van de zaak.";
            return $this->error(
                400,
                $detail,
                [$this->fieldError('nonFieldErrors', 'zaaktype-mismatch', $detail)]
            );
        }

        $typeCaseType     = $typeData['caseType'] ?? '';
        $typeCaseTypeUuid = $this->extractUuid((string) $typeCaseType);

        if ($typeCaseTypeUuid !== $zaaktypeUuid) {
            $detail = "Het {$fieldName} hoort niet bij het zaaktype van de zaak.";
            return $this->error(
                400,
                $detail,
                [$this->fieldError('nonFieldErrors', 'zaaktype-mismatch', $detail)]
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
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     *
     * @param string $zaakUrl The zaak URL
     * @param string $ioUrl   The informatieobject URL
     *
     * @return array|null Validation error, or null if valid
     */
    private function validateZioInformatieobjecttype(string $zaakUrl, string $ioUrl): ?array
    {
        // Get the informatieobject to find its informatieobjecttype.
        $ioUuid = $this->extractUuid($ioUrl);
        if ($ioUuid === null) {
            return null;
        }

        $ioData = $this->findBySchemaKey($ioUuid, 'document_schema');
        if ($ioData === null) {
            return null;
        }

        $docTypeId = $ioData['documentType'] ?? '';
        if (empty($docTypeId) === true) {
            return null;
        }

        // Get the zaak's zaaktype.
        $zaakUuid = $this->extractUuid($zaakUrl);
        if ($zaakUuid === null) {
            return null;
        }

        $zaakData = $this->findBySchemaKey($zaakUuid, 'case_schema');
        if ($zaakData === null) {
            return null;
        }

        $zaaktypeId   = $zaakData['caseType'] ?? '';
        $zaaktypeUuid = $this->extractUuid((string) $zaaktypeId);
        if ($zaaktypeUuid === null) {
            return null;
        }

        // Check if a ZaakType-InformatieObjectType record links this zaaktype
        // to the document's informatieobjecttype.
        $docTypeUuid = $this->extractUuid((string) $docTypeId);
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
                400,
                $detail,
                [$this->fieldError('nonFieldErrors', 'missing-zaaktype-informatieobjecttype-relation', $detail)]
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
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     * - zrc-011: Validate relevanteAndereZaken URLs.
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     * - zrc-012: Validate gegevensgroepen (opschorting, verlenging).
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     * - zrc-013: Validate hoofdzaak URL.
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     * - zrc-014: Validate betalingsindicatie + laatsteBetaaldatum consistency.
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     * - zrc-015: Validate productenOfDiensten subset of zaaktype.
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     * - zrc-022: Validate archiefstatus transition requires archiefnominatie + archiefactiedatum.
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     *
     * @param array      $result         The current validation result
     * @param array|null $existingObject The existing object data
     * @param bool       $isPatch        Whether this is a PATCH operation
     *
     * @return array The updated validation result
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    private function validateZaakFields(array $result, ?array $existingObject, bool $isPatch): array
    {
        $body = $result['enrichedBody'];

        // zrc-002: Identificatie immutability on update/patch.
        if ($existingObject !== null && isset($body['identificatie']) === true) {
            $existingId = $existingObject['identifier'] ?? ($existingObject['identificatie'] ?? '');
            if ($existingId !== '' && $body['identificatie'] !== $existingId) {
                return $this->fieldImmutableError('identificatie');
            }
        }

        // zrc-010: Validate communicatiekanaal URL.
        $commKanaal = $body['communicatiekanaal'] ?? null;
        if ($commKanaal !== null && $commKanaal !== '') {
            if (filter_var($commKanaal, FILTER_VALIDATE_URL) === false) {
                return $this->error(
                    400,
                    'De communicatiekanaal URL is ongeldig.',
                    [
                        $this->fieldError('communicatiekanaal', 'bad-url', 'De communicatiekanaal URL is ongeldig.'),
                    ]
                );
            }

            if ($this->isValidUrl($commKanaal) === false) {
                // Determine error code: if the last path segment looks like a garbled
                // UUID (contains hex chars + dashes) → bad-url.
                // If it's a collection endpoint (word-only) → invalid-resource.
                $path     = (string) parse_url($commKanaal, PHP_URL_PATH);
                $segments = array_filter(explode('/', trim($path, '/')));
                $last     = end($segments);
                $looksLikeUuid = preg_match('/[0-9a-f]{4,}-/i', $last) === 1;
                $code     = $looksLikeUuid === true ? 'bad-url' : 'invalid-resource';
                return $this->error(
                    400,
                    'De communicatiekanaal URL is ongeldig.',
                    [
                        $this->fieldError('communicatiekanaal', $code, 'De communicatiekanaal URL is ongeldig.'),
                    ]
                );
            }
        }

        // zrc-011: Validate relevanteAndereZaken URLs.
        $relevanteZaken = $body['relevanteAndereZaken'] ?? null;
        if (is_array($relevanteZaken) === true) {
            foreach ($relevanteZaken as $idx => $relZaak) {
                $relUrl = $relZaak['url'] ?? '';
                if ($relUrl !== '' && $this->isValidUrl($relUrl) === false) {
                    return $this->error(
                        400,
                        'relevanteAndereZaken bevat een ongeldige URL.',
                        [$this->fieldError(
                            "relevanteAndereZaken.{$idx}.url",
                            'bad-url',
                            'De URL is ongeldig.'
                        )
                        ]
                    );
                }
            }
        }

        // zrc-012: Validate opschorting.
        $opschorting = $body['opschorting'] ?? null;
        if (is_array($opschorting) === true) {
            $errors = [];
            if (($opschorting['indicatie'] ?? null) === null) {
                $errors[] = $this->fieldError(
                    'opschorting.indicatie',
                    'required',
                    'Indicatie is vereist bij opschorting.'
                );
            }

            if (($opschorting['reden'] ?? '') === '') {
                $errors[] = $this->fieldError(
                    'opschorting.reden',
                    'required',
                    'Reden is vereist bij opschorting.'
                );
            }

            if (empty($errors) === false) {
                return $this->error(400, 'Opschorting vereist indicatie en reden.', $errors);
            }
        }//end if

        // zrc-012: Validate verlenging.
        $verlenging = $body['verlenging'] ?? null;
        if (is_array($verlenging) === true) {
            $errors = [];
            if (($verlenging['reden'] ?? '') === '') {
                $errors[] = $this->fieldError('verlenging.reden', 'required', 'Reden is vereist bij verlenging.');
            }

            if (($verlenging['duur'] ?? '') === '') {
                $errors[] = $this->fieldError('verlenging.duur', 'required', 'Duur is vereist bij verlenging.');
            }

            if (empty($errors) === false) {
                return $this->error(400, 'Verlenging vereist reden en duur.', $errors);
            }
        }

        // zrc-013: Validate hoofdzaak URL.
        $hoofdzaak = $body['hoofdzaak'] ?? null;
        if ($hoofdzaak !== null && $hoofdzaak !== '') {
            if ($this->isValidUrl($hoofdzaak) === false) {
                return $this->error(
                        400,
                        'De hoofdzaak URL is ongeldig.',
                        [
                            $this->fieldError('hoofdzaak', 'bad-url', 'De URL is ongeldig.'),
                        ]
                        );
            }

            // zrc-013d: A zaak cannot be a deelzaak of itself.
            if ($existingObject !== null) {
                $selfUuid      = $existingObject['id'] ?? ($existingObject['@self']['id'] ?? null);
                $hoofdzaakUuid = $this->extractUuid($hoofdzaak);
                if ($selfUuid !== null && $hoofdzaakUuid !== null && $selfUuid === $hoofdzaakUuid) {
                    return $this->error(
                        400,
                        'Een zaak kan niet zijn eigen hoofdzaak zijn.',
                        [$this->fieldError(
                            'hoofdzaak',
                            'self-forbidden',
                            'Een zaak kan niet zijn eigen hoofdzaak zijn.'
                        )
                        ]
                    );
                }
            }

            // zrc-013c: Deelzaak of deelzaak is not allowed.
            $error = $this->validateHoofdzaakNesting($hoofdzaak);
            if ($error !== null) {
                return $error;
            }
        }

        // zrc-014: Validate betalingsindicatie + laatsteBetaaldatum.
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
                    400,
                    'Als betalingsindicatie "nvt" is, mag laatsteBetaaldatum niet gezet worden.',
                    [$this->fieldError(
                        'laatsteBetaaldatum',
                        'betaling-nvt',
                        'Als betalingsindicatie "nvt" is, mag laatsteBetaaldatum niet gezet worden.'
                    )
                    ]
                );
            }

            // On update/patch: clear laatsteBetaaldatum when switching to nvt.
            $body['laatsteBetaaldatum'] = null;
        }

        // zrc-015: Validate productenOfDiensten.
        $producten = $body['productenOfDiensten'] ?? null;
        if (is_array($producten) === true && empty($producten) === false) {
            $error = $this->validateProductenOfDiensten($body);
            if ($error !== null) {
                return $error;
            }
        }

        // zrc-022: Validate archiefstatus transition.
        $archiefstatus = $body['archiefstatus'] ?? null;
        if ($archiefstatus !== null && $archiefstatus !== 'nog_te_archiveren') {
            if (empty($body['archiefnominatie'] ?? null) === true) {
                return $this->error(
                    400,
                    'archiefnominatie is vereist als archiefstatus niet "nog_te_archiveren" is.',
                    [$this->fieldError('archiefnominatie', 'archiefnominatie-not-set', 'Vereist.')]
                );
            }

            if (empty($body['archiefactiedatum'] ?? null) === true) {
                return $this->error(
                    400,
                    'archiefactiedatum is vereist als archiefstatus niet "nog_te_archiveren" is.',
                    [$this->fieldError('archiefactiedatum', 'archiefactiedatum-not-set', 'Vereist.')]
                );
            }
        }

        $result['enrichedBody'] = $body;

        return $result;
    }//end validateZaakFields()

    /**
     * Validate hoofdzaak is not a deelzaak itself (zrc-013).
     *
     * A deelzaak of a deelzaak is not allowed.
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     *
     * @param string $hoofdzaakUrl The hoofdzaak URL
     *
     * @return array|null Validation error if hoofdzaak is itself a deelzaak
     */
    private function validateHoofdzaakNesting(string $hoofdzaakUrl): ?array
    {
        if ($this->objectService === null) {
            return null;
        }

        $hoofdzaakUuid = $this->extractUuid($hoofdzaakUrl);
        if ($hoofdzaakUuid === null) {
            return null;
        }

        $hoofdzaakData = $this->findBySchemaKey($hoofdzaakUuid, 'case_schema');
        if ($hoofdzaakData === null) {
            return $this->error(
                400,
                'De hoofdzaak is ongeldig.',
                [$this->fieldError(
                    'hoofdzaak',
                    'no_match',
                    'De hoofdzaak URL verwijst niet naar een bekende zaak.'
                )
                ]
            );
        }

        // If the hoofdzaak itself has a hoofdzaak, it's a deelzaak of a deelzaak.
        $parentHoofdzaak = $hoofdzaakData['parentCase'] ?? ($hoofdzaakData['mainCase'] ?? ($hoofdzaakData['hoofdzaak'] ?? null));
        if ($parentHoofdzaak !== null && $parentHoofdzaak !== '') {
            return $this->error(
                400,
                'Een deelzaak van een deelzaak is niet toegestaan.',
                [$this->fieldError(
                    'hoofdzaak',
                    'deelzaak-als-hoofdzaak',
                    'De opgegeven hoofdzaak is zelf een deelzaak.'
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
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     *
     * @param array $body The request body
     *
     * @return array|null Validation error, or null if valid
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

        $zaaktypeUuid = $this->extractUuid($zaaktypeUrl);
        if ($zaaktypeUuid === null) {
            return null;
        }

        $ztData = $this->findBySchemaKey($zaaktypeUuid, 'case_type_schema');
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
                    400,
                    'productenOfDiensten bevat een ongeldige URL.',
                    [$this->fieldError(
                        'productenOfDiensten',
                        'invalid-products-services',
                        "'{$product}' is geen geldige URL."
                    )
                    ]
                );
            }
        }

        foreach ($requestProducts as $product) {
            if (in_array($product, $allowedProducts, true) === false) {
                return $this->error(
                    400,
                    'productenOfDiensten bevat een waarde die niet in het zaaktype voorkomt.',
                    [$this->fieldError(
                        'productenOfDiensten',
                        'invalid-products-services',
                        "Product '{$product}' is niet toegestaan voor dit zaaktype."
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
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
     *
     * @param array      $result         The current validation result
     * @param array|null $existingObject The existing object data
     *
     * @return array The updated validation result
     */
    private function checkZioImmutability(array $result, ?array $existingObject): array
    {
        if ($existingObject === null) {
            return $result;
        }

        $body = $result['enrichedBody'];

        // zrc-004: zaak is immutable.
        if (isset($body['zaak']) === true) {
            $existingZaak = $existingObject['case'] ?? ($existingObject['zaak'] ?? '');
            $newZaakUuid  = $this->extractUuid($body['zaak']);
            $existZaakId  = is_string($existingZaak) === true ? $this->extractUuid($existingZaak) : $existingZaak;
            if ($existZaakId !== null && $newZaakUuid !== null && $newZaakUuid !== $existZaakId) {
                return $this->fieldImmutableError('zaak');
            }
        }

        // zrc-004: informatieobject is immutable.
        if (isset($body['informatieobject']) === true) {
            $existingIo = $existingObject['document'] ?? ($existingObject['informatieobject'] ?? '');
            $newIoUuid  = $this->extractUuid($body['informatieobject']);
            $existIoId  = is_string($existingIo) === true ? $this->extractUuid($existingIo) : $existingIo;
            if ($existIoId !== null && $newIoUuid !== null && $newIoUuid !== $existIoId) {
                return $this->fieldImmutableError('informatieobject');
            }
        }

        return $result;
    }//end checkZioImmutability()
}//end class
