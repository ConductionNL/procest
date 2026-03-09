<?php

/**
 * Procest ZGW BRC (Besluiten) Business Rules Service
 *
 * Implements business rules for the Besluiten API as defined by VNG Realisatie.
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
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
 *
 * Business rules implemented:
 *
 * - brc-001: Valideren besluittype op het Besluit
 *   The besluittype must exist and be published (concept=false).
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
 *
 * - brc-002: Garanderen uniciteit verantwoordelijkeOrganisatie en identificatie
 *   The combination of verantwoordelijkeOrganisatie + identificatie must be unique.
 *   Auto-generate identificatie if not provided. Identificatie and
 *   verantwoordelijkeOrganisatie are immutable after creation.
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
 *
 * - brc-003: Valideren informatieobject op BesluitInformatieObject
 *   The informatieobject URL must resolve to an existing document.
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
 *
 * - brc-004: Valideren aardRelatie op BesluitInformatieObject
 *   The aardRelatie is automatically set to 'legt_vast' on creation.
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
 *
 * - brc-005: Synchroniseren relaties met informatieobjecten (cross-register, in ZgwService)
 *   When a BesluitInformatieObject is created/deleted, sync to DRC.
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
 *
 * - brc-006: Synchroniseren relatie Besluit-Zaak met ZRC (cross-register, in ZgwService)
 *   When a Besluit has a zaak, a ZaakBesluit must be created/deleted in ZRC.
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
 *
 * - brc-007: Valideren zaak-besluittype relatie
 *   The zaak's zaaktype must be listed in the besluittype's zaaktypen.
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
 *
 * - brc-008: Valideren informatieobjecttype bij besluittype
 *   The informatieobjecttype of the linked informatieobject must appear
 *   in besluittype.informatieobjecttypen.
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
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
 * BRC (Besluiten API) business rule validation and enrichment.
 *
 * @psalm-suppress UnusedClass
 */
class ZgwBrcRulesService extends ZgwRulesBase
{
    /**
     * Rules for creating a besluit (POST /besluiten/v1/besluiten).
     *
     * Implements:
     * - brc-001: Validate besluittype URL exists and is published (concept=false).
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
     * - brc-002: Guarantee unique combination of verantwoordelijkeOrganisatie + identificatie.
     *   Auto-generate identificatie if not provided.
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
     * - brc-007: Validate zaak-besluittype relation. The zaak's zaaktype must be listed
     *   in the besluittype's zaaktypen.
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
     *
     * @param array $body The ZGW request body (Dutch field names)
     *
     * @return array The validation result
     */
    public function rulesBesluitenCreate(array $body): array
    {
        // brc-001: Validate besluittype URL.
        $besluitTypeUrl = $body['besluittype'] ?? '';
        if (empty($besluitTypeUrl) === false && $this->objectService !== null) {
            $error = $this->validateTypeUrl($besluitTypeUrl, 'besluittype', 'decision_type_schema');
            if ($error !== null) {
                return $error;
            }
        }

        // brc-002: Check unique combination of verantwoordelijkeOrganisatie + identificatie.
        if (empty($body['identificatie']) === false && $this->objectService !== null) {
            $uniqueError = $this->checkBesluitIdentificatieUnique($body);
            if ($uniqueError !== null) {
                return $uniqueError;
            }
        }

        // brc-007: Validate zaak-besluittype relation.
        $zaakUrl = $body['zaak'] ?? null;
        if ($zaakUrl !== null && $zaakUrl !== '' && empty($besluitTypeUrl) === false
            && $this->objectService !== null
        ) {
            $relError = $this->validateZaakBesluittypeRelation($zaakUrl, $besluitTypeUrl);
            if ($relError !== null) {
                return $relError;
            }
        }

        // brc-002: Auto-generate identificatie if not provided.
        if (empty($body['identificatie']) === true) {
            $body['identificatie'] = $this->generateIdentificatie('BESLUIT');
        }

        return $this->ok($body);
    }//end rulesBesluitenCreate()

    /**
     * Rules for updating a besluit (PUT /besluiten/v1/besluiten/{uuid}).
     *
     * Implements:
     * - brc-001: Besluittype is immutable after creation.
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
     * - brc-002: Identificatie and verantwoordelijkeOrganisatie are immutable.
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
     *
     * @param array      $body           The ZGW request body
     * @param array|null $existingObject The existing besluit data
     *
     * @return array The validation result
     */
    public function rulesBesluitenUpdate(array $body, ?array $existingObject=null): array
    {
        $result = $this->ok($body);

        $result = $this->checkBesluitTypeImmutability($result, $existingObject);
        if ($result['valid'] === false) {
            return $result;
        }

        $result = $this->checkBesluitFieldImmutability($result, $existingObject);
        if ($result['valid'] === false) {
            return $result;
        }

        // Preserve immutable fields from existing object when omitted in PUT body.
        $result = $this->preserveImmutableBesluitFields($result, $existingObject);

        return $result;
    }//end rulesBesluitenUpdate()

    /**
     * Rules for patching a besluit (PATCH /besluiten/v1/besluiten/{uuid}).
     *
     * @param array      $body           The ZGW request body
     * @param array|null $existingObject The existing besluit data
     *
     * @return array The validation result
     *
     * @see rulesBesluitenUpdate() Same immutability rules apply.
     */
    public function rulesBesluitenPatch(array $body, ?array $existingObject=null): array
    {
        $result = $this->ok($body);

        $result = $this->checkBesluitTypeImmutability($result, $existingObject);
        if ($result['valid'] === false) {
            return $result;
        }

        return $this->checkBesluitFieldImmutability($result, $existingObject);
    }//end rulesBesluitenPatch()

    /**
     * Rules for creating a BesluitInformatieObject (POST /besluiten/v1/besluitinformatieobjecten).
     *
     * Implements:
     * - brc-003: Validate informatieobject URL exists.
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
     * - brc-004: Set aardRelatieWeergave automatically.
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
     * - brc-008: Validate informatieobjecttype in besluittype.informatieobjecttypen.
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
     *
     * @param array $body The ZGW request body
     *
     * @return array The validation result
     */
    public function rulesBesluitinformatieobjectenCreate(array $body): array
    {
        // brc-003: Validate informatieobject URL.
        $ioUrl = $body['informatieobject'] ?? '';
        if ($ioUrl !== '' && $this->objectService !== null) {
            $error = $this->validateInformatieobjectUrl($ioUrl);
            if ($error !== null) {
                return $error;
            }
        }

        // brc-008: Validate informatieobjecttype in besluittype.informatieobjecttypen.
        $besluitUrl = $body['besluit'] ?? '';
        if ($besluitUrl !== '' && $ioUrl !== '' && $this->objectService !== null) {
            $iotError = $this->validateBioInformatieobjecttype($besluitUrl, $ioUrl);
            if ($iotError !== null) {
                return $iotError;
            }
        }

        // brc-004: Set aardRelatieWeergave automatically.
        $body['aardRelatieWeergave'] = 'Legt vast, omgekeerd: wordt vastgelegd door';

        return $this->ok($body);
    }//end rulesBesluitinformatieobjectenCreate()

    /**
     * Check that besluittype is not changed on update/patch (brc-001).
     *
     * @param array      $result         The current validation result
     * @param array|null $existingObject The existing object data
     *
     * @return array The updated validation result
     */
    private function checkBesluitTypeImmutability(array $result, ?array $existingObject): array
    {
        if ($existingObject === null) {
            return $result;
        }

        $body           = $result['enrichedBody'];
        $newBesluittype = $body['besluittype'] ?? null;
        $existBesluittype = $existingObject['decisionType'] ?? '';

        if ($newBesluittype !== null && $existBesluittype !== '') {
            $newUuid = $this->extractUuid($newBesluittype);
            if ($newUuid !== null && $newUuid !== $existBesluittype
                && $this->extractUuid($existBesluittype) !== $newUuid
            ) {
                return $this->fieldImmutableError('besluittype');
            }
        }

        return $result;
    }//end checkBesluitTypeImmutability()

    /**
     * Check besluit field immutability (brc-002).
     *
     * Identificatie and verantwoordelijkeOrganisatie are immutable after creation.
     *
     * @param array      $result         The current validation result
     * @param array|null $existingObject The existing object data
     *
     * @return array The updated validation result
     */
    private function checkBesluitFieldImmutability(array $result, ?array $existingObject): array
    {
        if ($existingObject === null) {
            return $result;
        }

        $body = $result['enrichedBody'];

        // brc-002: identificatie is immutable.
        if (isset($body['identificatie']) === true) {
            $existingId = $existingObject['title'] ?? ($existingObject['identifier'] ?? ($existingObject['identificatie'] ?? ''));
            if ($existingId !== '' && $body['identificatie'] !== $existingId) {
                return $this->fieldImmutableError('identificatie');
            }
        }

        // brc-002: verantwoordelijkeOrganisatie is immutable.
        if (isset($body['verantwoordelijkeOrganisatie']) === true) {
            $existingOrg = $existingObject['responsibleOrganisation'] ?? ($existingObject['verantwoordelijkeOrganisatie'] ?? '');
            if ($existingOrg !== '' && $body['verantwoordelijkeOrganisatie'] !== $existingOrg) {
                return $this->fieldImmutableError('verantwoordelijkeOrganisatie');
            }
        }

        return $result;
    }//end checkBesluitFieldImmutability()

    /**
     * Preserve immutable besluit fields from existing object when omitted in PUT body.
     *
     * @param array      $result         The current validation result
     * @param array|null $existingObject The existing object data
     *
     * @return array The updated validation result with preserved fields
     */
    private function preserveImmutableBesluitFields(array $result, ?array $existingObject): array
    {
        if ($existingObject === null) {
            return $result;
        }

        $body = $result['enrichedBody'];

        if (isset($body['identificatie']) === false || $body['identificatie'] === '') {
            $existingId = $existingObject['title'] ?? ($existingObject['identifier'] ?? ($existingObject['identificatie'] ?? ''));
            if ($existingId !== '') {
                $body['identificatie'] = $existingId;
            }
        }

        if (isset($body['verantwoordelijkeOrganisatie']) === false
            || $body['verantwoordelijkeOrganisatie'] === ''
        ) {
            $existingOrg = $existingObject['responsibleOrganisation'] ?? ($existingObject['verantwoordelijkeOrganisatie'] ?? '');
            if ($existingOrg !== '') {
                $body['verantwoordelijkeOrganisatie'] = $existingOrg;
            }
        }

        $result['enrichedBody'] = $body;

        return $result;
    }//end preserveImmutableBesluitFields()

    /**
     * Check that besluit identificatie + verantwoordelijkeOrganisatie is unique (brc-002).
     *
     * @param array $body The request body (ZGW Dutch field names)
     *
     * @return array|null Validation error, or null if unique
     */
    private function checkBesluitIdentificatieUnique(array $body): ?array
    {
        $identificatie = $body['identificatie'] ?? '';
        $organisation  = $body['verantwoordelijkeOrganisatie'] ?? '';

        if ($identificatie === '' || $this->objectService === null) {
            return null;
        }

        $register = $this->mappingConfig['sourceRegister'] ?? '';
        $schema   = $this->mappingConfig['sourceSchema'] ?? '';

        if (empty($register) === true || empty($schema) === true) {
            return null;
        }

        try {
            $searchParams = ['title' => $identificatie];
            if ($organisation !== '') {
                $searchParams['responsibleOrganisation'] = $organisation;
            }

            $query  = $this->objectService->buildSearchQuery(
                requestParams: $searchParams,
                register: $register,
                schema: $schema
            );
            $result = $this->objectService->searchObjectsPaginated(query: $query);
            $total  = $result['total'] ?? count($result['results'] ?? []);

            if ($total > 0) {
                return $this->error(
                    400,
                    'De combinatie van verantwoordelijke_organisatie en identificatie is niet uniek.',
                    [$this->fieldError(
                        'identificatie',
                        'identificatie-niet-uniek',
                        'De combinatie van verantwoordelijke_organisatie en identificatie bestaat al.'
                    )
                    ]
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'brc-002: Could not check besluit identificatie uniqueness: '.$e->getMessage()
            );
        }//end try

        return null;
    }//end checkBesluitIdentificatieUnique()

    /**
     * Validate zaak-besluittype relation (brc-007).
     *
     * The zaak's zaaktype must be listed in the besluittype's zaaktypen.
     *
     * @param string $zaakUrl        The zaak URL from the request
     * @param string $besluitTypeUrl The besluittype URL from the request
     *
     * @return array|null Validation error, or null if valid
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function validateZaakBesluittypeRelation(string $zaakUrl, string $besluitTypeUrl): ?array
    {
        $register = $this->mappingConfig['sourceRegister'] ?? '';
        if (empty($register) === true || $this->objectService === null) {
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

        $zaakCaseType = $zaakData['caseType'] ?? '';
        if (empty($zaakCaseType) === true) {
            return null;
        }

        // Look up the besluittype to check its zaaktypen/caseTypes.
        $btUuid = $this->extractUuid($besluitTypeUrl);
        if ($btUuid === null) {
            return null;
        }

        $btData = $this->findBySchemaKey($btUuid, 'decision_type_schema');
        if ($btData === null) {
            return null;
        }

        $zaakCaseTypeUuid = $this->extractUuid($zaakCaseType);

        // Check direction 1: BT.caseTypes contains the zaaktype UUID.
        $caseTypes = $btData['caseTypes'] ?? [];
        if (is_string($caseTypes) === true) {
            $caseTypes = json_decode($caseTypes, true) ?? [];
        }

        if (is_array($caseTypes) === false) {
            $caseTypes = [];
        }

        foreach ($caseTypes as $ct) {
            $ctUuid = $this->extractUuid((string) $ct);
            if ($ctUuid !== null && $ctUuid === $zaakCaseTypeUuid) {
                return null;
            }
        }

        // Check direction 2: ZT.decisionTypes contains the BT omschrijving or UUID.
        $ztData = $this->findBySchemaKey($zaakCaseTypeUuid, 'case_type_schema');
        if ($ztData !== null) {
            $decisionTypes = $ztData['decisionTypes'] ?? [];
            if (is_string($decisionTypes) === true) {
                $decisionTypes = json_decode($decisionTypes, true) ?? [];
            }

            if (is_array($decisionTypes) === true) {
                $btOmschrijving = $btData['title'] ?? ($btData['name'] ?? '');
                foreach ($decisionTypes as $dt) {
                    $dtStr = (string) $dt;
                    // Match by omschrijving or UUID.
                    $dtUuid = $this->extractUuid($dtStr);
                    if (($dtUuid !== null && $dtUuid === $btUuid)
                        || ($btOmschrijving !== '' && $dtStr === $btOmschrijving)
                    ) {
                        return null;
                    }
                }
            }
        }

        $detail = 'Het zaaktype van de zaak is niet gerelateerd aan het besluittype.';

        return $this->error(
            400,
            $detail,
            [
                $this->fieldError('nonFieldErrors', 'zaaktype-mismatch', $detail),
            ]
        );
    }//end validateZaakBesluittypeRelation()

    /**
     * Validate informatieobjecttype is in besluittype.informatieobjecttypen (brc-008).
     *
     * @param string $besluitUrl The besluit URL
     * @param string $ioUrl      The informatieobject URL
     *
     * @return array|null Validation error, or null if valid
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function validateBioInformatieobjecttype(string $besluitUrl, string $ioUrl): ?array
    {
        if ($this->objectService === null) {
            return null;
        }

        // Get the besluit to find its besluittype.
        $besluitUuid = $this->extractUuid($besluitUrl);
        if ($besluitUuid === null) {
            return null;
        }

        $besluitData = $this->findBySchemaKey($besluitUuid, 'decision_schema');
        if ($besluitData === null) {
            return null;
        }

        $decisionTypeId = $besluitData['decisionType'] ?? '';
        if (empty($decisionTypeId) === true) {
            return null;
        }

        // Get the besluittype.
        $btUuid = $this->extractUuid($decisionTypeId);
        if ($btUuid === null) {
            return null;
        }

        $btData = $this->findBySchemaKey($btUuid, 'decision_type_schema');
        if ($btData === null) {
            return null;
        }

        // Get the allowed documentTypes from besluittype.
        $allowedDocTypes = $btData['documentTypes'] ?? '[]';
        if (is_string($allowedDocTypes) === true) {
            $allowedDocTypes = json_decode($allowedDocTypes, true) ?? [];
        }

        if (is_array($allowedDocTypes) === false || empty($allowedDocTypes) === true) {
            $detail = 'Het informatieobjecttype van het informatieobject is niet '.'gespecificeerd in het besluittype.informatieobjecttypen.';
            return $this->error(
                    400,
                    $detail,
                    [
                        $this->fieldError('nonFieldErrors', 'missing-informatieobjecttype', $detail),
                    ]
                    );
        }

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

        // Look up the documentType to get its name.
        $docTypeUuid = $this->extractUuid($docTypeId);
        if ($docTypeUuid === null) {
            return null;
        }

        $dtData = $this->findBySchemaKey($docTypeUuid, 'document_type_schema');
        if ($dtData === null) {
            return null;
        }

        $docTypeName = $dtData['name'] ?? '';

        // Check if the documentType is in the allowed list.
        $found = false;
        foreach ($allowedDocTypes as $allowed) {
            if ($allowed === $docTypeName || $allowed === $docTypeUuid) {
                $found = true;
                break;
            }
        }

        if ($found === false) {
            $detail = 'Het informatieobjecttype van het informatieobject is niet '.'gespecificeerd in het besluittype.informatieobjecttypen.';
            return $this->error(
                    400,
                    $detail,
                    [
                        $this->fieldError('nonFieldErrors', 'missing-informatieobjecttype', $detail),
                    ]
                    );
        }

        return null;
    }//end validateBioInformatieobjecttype()
}//end class
