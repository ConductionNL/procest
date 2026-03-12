<?php

/**
 * Procest ZGW DRC (Documenten) Business Rules Service
 *
 * Implements business rules for the Documenten API as defined by VNG Realisatie.
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
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
 *
 * Business rules implemented:
 *
 * - drc-001: Valideren informatieobjecttype op EnkelvoudigInformatieObject
 *   The informatieobjecttype must exist and be published (concept=false).
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
 *
 * - drc-002: Valideren ObjectInformatieObject (OIO) relatie
 *   An ObjectInformatieObject must reference a valid informatieobject and
 *   the combination of informatieobject+object must be unique.
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
 *
 * - drc-003: Valideren status bij enkelvoudiginformatieobject update
 *   When status is 'definitief', only bestandsomvang may be updated (lock check).
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
 *
 * - drc-004: Valideren gebruiksrechten bij status definitief
 *   To set status to 'definitief', gebruiksrechten must exist.
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
 *
 * - drc-005: Vertrouwelijkheidaanduiding afleiden van informatieobjecttype
 *   If not explicitly set, derive vertrouwelijkheidaanduiding from the
 *   informatieobjecttype.
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
 *
 * - drc-006: Lock-mechanisme voor update/patch
 *   Documents with status 'definitief' require a valid lock ID for modification.
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
 *
 * - drc-007: Verwijderen van informatieobjecten met relaties
 *   Cannot destroy an informatieobject that has ObjectInformatieObject relations.
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
 *
 * - drc-008: Identificatie uniciteit
 *   Combination of identificatie + bronorganisatie must be unique.
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

/**
 * DRC (Documenten API) business rule validation and enrichment.
 *
 * @psalm-suppress UnusedClass
 */
class ZgwDrcRulesService extends ZgwRulesBase
{
    /**
     * Rules for creating an EnkelvoudigInformatieObject.
     *
     * Implements drc-001, drc-005, drc-008.
     *
     * @param array $body The ZGW request body (Dutch field names)
     *
     * @return array The validation result
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
     */
    public function rulesEnkelvoudiginformatieobjectenCreate(array $body): array
    {
        // Drc-001: Validate informatieobjecttype is published (not concept).
        $iotUrl = $body['informatieobjecttype'] ?? '';
        if ($iotUrl !== '' && $this->objectService !== null) {
            $error = $this->validateTypeUrl(
                typeUrl: $iotUrl,
                fieldName: 'informatieobjecttype',
                schemaKey: 'document_type_schema'
            );
            if ($error !== null) {
                return $error;
            }
        }

        // Drc-005: Derive vertrouwelijkheidaanduiding from informatieobjecttype if not set.
        if (empty($body['vertrouwelijkheidaanduiding']) === true && $iotUrl !== '') {
            $body = $this->deriveVertrouwelijkheidaanduiding(body: $body, iotUrl: $iotUrl);
        }

        // Drc-006a: Default indicatieGebruiksrecht to null on creation.
        if (
            array_key_exists('indicatieGebruiksrecht', $body) === false
            || $body['indicatieGebruiksrecht'] === false
        ) {
            $body['indicatieGebruiksrecht'] = null;
        }

        // Drc-006b: If indicatieGebruiksrecht is explicitly true, gebruiksrechten must exist.
        if ($body['indicatieGebruiksrecht'] === true && $this->objectService !== null) {
            $error = $this->validateIndicatieGebruiksrechtTrue(body: $body);
            if ($error !== null) {
                return $error;
            }
        }

        // Drc-008: Check unique identificatie + bronorganisatie.
        if (empty($body['identificatie']) === false) {
            $error = $this->checkFieldUniqueness(
                field1Value: $body['identificatie'] ?? '',
                field1Search: 'identifier',
                field2Value: $body['bronorganisatie'] ?? '',
                field2Search: 'sourceOrganisation',
                errorField: 'identificatie'
            );
            if ($error !== null) {
                return $error;
            }
        }

        // Drc-008: Auto-generate identificatie if not provided.
        if (empty($body['identificatie']) === true) {
            $body['identificatie'] = $this->generateIdentificatie(prefix: 'DOCUMENT');
        }

        return $this->ok(body: $body);
    }

    /**
     * Rules for updating an EnkelvoudigInformatieObject (PUT).
     *
     * Implements drc-003, drc-006.
     *
     * @param array      $body           The ZGW request body
     * @param array|null $existingObject The existing EIO data
     *
     * @return array The validation result
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
     */
    public function rulesEnkelvoudiginformatieobjectenUpdate(
        array $body,
        ?array $existingObject = null
    ): array {
        // Drc-006: Check lock requirement.
        $lockError = $this->validateLock(body: $body, existingObject: $existingObject);
        if ($lockError !== null) {
            return $lockError;
        }

        return $this->ok(body: $body);
    }

    /**
     * Rules for patching an EnkelvoudigInformatieObject (PATCH).
     *
     * Implements drc-003, drc-006.
     *
     * @param array      $body           The ZGW request body
     * @param array|null $existingObject The existing EIO data
     *
     * @return array The validation result
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
     */
    public function rulesEnkelvoudiginformatieobjectenPatch(
        array $body,
        ?array $existingObject = null
    ): array {
        // Drc-006: Check lock requirement.
        $lockError = $this->validateLock(body: $body, existingObject: $existingObject);
        if ($lockError !== null) {
            return $lockError;
        }

        return $this->ok(body: $body);
    }

    /**
     * Rules for destroying an EnkelvoudigInformatieObject (DELETE).
     *
     * Implements drc-007.
     *
     * @param array      $body           The ZGW request body (usually empty)
     * @param array|null $existingObject The existing EIO data
     *
     * @return array The validation result
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
     */
    public function rulesEnkelvoudiginformatieobjectenDestroy(
        array $body,
        ?array $existingObject = null
    ): array {
        if ($existingObject === null || $this->objectService === null) {
            return $this->ok(body: $body);
        }

        $existingId = $existingObject['id'] ?? ($existingObject['@self']['id'] ?? null);
        if ($existingId === null) {
            return $this->ok(body: $body);
        }

        // Drc-007: Check for ObjectInformatieObject relations.
        $register  = $this->mappingConfig['sourceRegister'] ?? '';
        $oioSchema = $this->settingsService->getConfigValue(key: 'document_link_schema');

        if ($register !== '' && $oioSchema !== '') {
            // OIO stores the full informatieobject URL in the 'document' field,
            // so search with both UUID and partial match to find any OIO relations.
            $relatedIds = $this->findOioRelationsForDocument(
                register: $register,
                schema: $oioSchema,
                docUuid: $existingId
            );
            if (empty($relatedIds) === false) {
                return $this->error(
                    status: 400,
                    detail: 'Het informatieobject kan niet verwijderd worden omdat er nog relaties aan gekoppeld zijn.',
                    invalidParams: [
                        $this->fieldError(
                            fieldName: 'nonFieldErrors',
                            code: 'pending-relations',
                            reason: 'Het informatieobject heeft nog ObjectInformatieObject relaties.'
                        ),
                    ]
                );
            }
        }

        return $this->ok(body: $body);
    }

    /**
     * Rules for creating an ObjectInformatieObject.
     *
     * Implements drc-002.
     *
     * @param array $body The ZGW request body
     *
     * @return array The validation result
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
     */
    public function rulesObjectinformatieobjectenCreate(array $body): array
    {
        // Drc-002: Validate informatieobject URL.
        $ioUrl = $body['informatieobject'] ?? '';
        if ($ioUrl !== '') {
            $error = $this->validateInformatieobjectUrl(ioUrl: $ioUrl);
            if ($error !== null) {
                return $error;
            }
        }

        // Drc-002: Validate object URL.
        $objectUrl  = $body['object'] ?? '';
        $objectType = $body['objectType'] ?? '';
        if ($objectUrl !== '') {
            $error = $this->validateObjectUrl(objectUrl: $objectUrl, objectType: $objectType);
            if ($error !== null) {
                return $error;
            }
        }

        // Drc-003 (VNG): Validate uniqueness of object + informatieobject + objectType.
        // Must run BEFORE cross-register check so duplicate errors take priority.
        if ($ioUrl !== '' && $objectUrl !== '' && $this->objectService !== null) {
            $error = $this->checkOioUniqueness(
                ioUrl: $ioUrl,
                objectUrl: $objectUrl,
                objectType: $body['objectType'] ?? ''
            );
            if ($error !== null) {
                return $error;
            }
        }

        // Drc-004 (VNG): Cross-register validation — ZIO/BIO must exist in ZRC/BRC.
        $objectType = $body['objectType'] ?? '';
        if ($ioUrl !== '' && $objectUrl !== '' && $objectType !== '' && $this->objectService !== null) {
            $error = $this->validateOioCrossRegister(
                ioUrl: $ioUrl,
                objectUrl: $objectUrl,
                objectType: $objectType
            );
            if ($error !== null) {
                return $error;
            }
        }

        return $this->ok(body: $body);
    }

    /**
     * Find OIO relations for a document UUID (drc-007/drc-008a).
     *
     * OIO objects store the full informatieobject URL in the 'document' field.
     * This method searches by UUID first, then by partial URL match if needed.
     *
     * @param string $register The register ID
     * @param string $schema   The OIO schema ID
     * @param string $docUuid  The document UUID
     *
     * @return array<string> Array of matching OIO UUIDs
     */
    private function findOioRelationsForDocument(
        string $register,
        string $schema,
        string $docUuid
    ): array {
        // First try by exact UUID (in case stored as UUID).
        $results = $this->findAllObjectsByField(
            register: $register,
            schema: $schema,
            field: 'document',
            value: $docUuid
        );
        if (empty($results) === false) {
            return $results;
        }

        // Also search by partial URL match (OIO may store full URL).
        try {
            $query  = $this->objectService->buildSearchQuery(
                requestParams: ['document' => '%' . $docUuid . '%', '_limit' => 100],
                register: $register,
                schema: $schema
            );
            $result = $this->objectService->searchObjectsPaginated(query: $query);

            $ids = [];
            foreach (($result['results'] ?? []) as $obj) {
                if (is_array($obj) === true) {
                    $data = $obj;
                } else {
                    $data = $obj->jsonSerialize();
                }

                $id = $data['id'] ?? ($data['@self']['id'] ?? null);
                if ($id !== null) {
                    $ids[] = $id;
                }
            }

            return $ids;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Drc-007: OIO relation search with partial match failed: ' . $e->getMessage()
            );
            return [];
        }
    }

    /**
     * Validate that indicatieGebruiksrecht=true requires existing gebruiksrechten (drc-006b).
     *
     * @param array $body The request body
     *
     * @return array|null Validation error, or null if valid
     */
    private function validateIndicatieGebruiksrechtTrue(array $body): ?array
    {
        // On create, the document does not yet exist so there can be no gebruiksrechten.
        return $this->error(
            status: 400,
            detail: 'indicatieGebruiksrecht kan niet true zijn zonder dat er gebruiksrechten bestaan.',
            invalidParams: [
                $this->fieldError(
                    fieldName: 'indicatieGebruiksrecht',
                    code: 'missing-gebruiksrechten',
                    reason: 'Er zijn geen gebruiksrechten voor dit informatieobject.'
                ),
            ]
        );
    }

    /**
     * Validate OIO object URL (drc-002a/b/c/d).
     *
     * @param string $objectUrl  The object URL
     * @param string $objectType The object type (zaak or besluit)
     *
     * @return array|null Validation error, or null if valid
     */
    private function validateObjectUrl(string $objectUrl, string $objectType): ?array
    {
        // Drc-002a/b: Check URL validity.
        if ($this->isValidUrl(url: $objectUrl) === false) {
            return $this->error(
                status: 400,
                detail: 'De object URL is ongeldig.',
                invalidParams: [
                    $this->fieldError(fieldName: 'object', code: 'bad-url', reason: 'De object URL is ongeldig.'),
                ]
            );
        }

        // Drc-002a/b: Check URL contains a UUID.
        $objectUuid = $this->extractUuid(value: $objectUrl);
        if ($objectUuid === null) {
            return $this->error(
                status: 400,
                detail: 'De object URL bevat geen geldig UUID.',
                invalidParams: [
                    $this->fieldError(
                        fieldName: 'object',
                        code: 'bad-url',
                        reason: 'De object URL bevat geen geldig UUID.'
                    ),
                ]
            );
        }

        // Drc-002c/d: Validate objectType matches the URL path.
        if ($objectType === 'zaak' && strpos($objectUrl, '/zaken/') === false) {
            return $this->error(
                status: 400,
                detail: 'De object URL wijst niet naar een zaak.',
                invalidParams: [
                    $this->fieldError(
                        fieldName: 'object',
                        code: 'invalid-resource',
                        reason: 'De object URL wijst niet naar een zaak resource.'
                    ),
                ]
            );
        }

        if ($objectType === 'besluit' && strpos($objectUrl, '/besluiten/') === false) {
            return $this->error(
                status: 400,
                detail: 'De object URL wijst niet naar een besluit.',
                invalidParams: [
                    $this->fieldError(
                        fieldName: 'object',
                        code: 'invalid-resource',
                        reason: 'De object URL wijst niet naar een besluit resource.'
                    ),
                ]
            );
        }

        return null;
    }

    /**
     * Validate OIO cross-register: ZIO/BIO must exist (drc-004 VNG).
     *
     * @param string $ioUrl      The informatieobject URL
     * @param string $objectUrl  The object URL (zaak or besluit)
     * @param string $objectType The object type (zaak or besluit)
     *
     * @return array|null Validation error if relation doesn't exist, null if valid
     */
    private function validateOioCrossRegister(string $ioUrl, string $objectUrl, string $objectType): ?array
    {
        $ioUuid     = $this->extractUuid(value: $ioUrl) ?? '';
        $objectUuid = $this->extractUuid(value: $objectUrl) ?? '';

        if ($ioUuid === '' || $objectUuid === '') {
            return null;
        }

        $register = $this->mappingConfig['sourceRegister'] ?? '';
        if ($register === '') {
            return null;
        }

        $schemaKey   = '';
        $searchField = '';
        if ($objectType === 'zaak') {
            $schemaKey   = 'case_document_schema';
            $searchField = 'case';
        } elseif ($objectType === 'besluit') {
            $schemaKey   = 'decision_document_schema';
            $searchField = 'decision';
        } else {
            return null;
        }

        $schema = $this->settingsService->getConfigValue(key: $schemaKey);
        if ($schema === '') {
            return null;
        }

        try {
            $query  = $this->objectService->buildSearchQuery(
                requestParams: [
                    $searchField => $objectUuid,
                    'document'   => $ioUuid,
                    '_limit'     => 1,
                ],
                register: $register,
                schema: $schema
            );
            $result = $this->objectService->searchObjectsPaginated(query: $query);
            $total  = $result['total'] ?? count($result['results'] ?? []);

            if ($total === 0) {
                if ($objectType === 'zaak') {
                    $detail = 'Er bestaat geen ZaakInformatieObject in de Zaken API voor deze combinatie.';
                } else {
                    $detail = 'Er bestaat geen BesluitInformatieObject in de Besluiten API voor deze combinatie.';
                }

                return $this->error(
                    status: 400,
                    detail: $detail,
                    invalidParams: [
                        $this->fieldError(
                            fieldName: 'nonFieldErrors',
                            code: 'inconsistent-relation',
                            reason: $detail
                        ),
                    ]
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Drc-004: Cross-register validation failed: ' . $e->getMessage()
            );
        }

        return null;
    }

    /**
     * Validate OIO uniqueness: object + informatieobject + objectType (drc-003 VNG).
     *
     * @param string $ioUrl      The informatieobject URL
     * @param string $objectUrl  The object URL (zaak or besluit)
     * @param string $objectType The object type (zaak or besluit)
     *
     * @return array|null Validation error if duplicate found, null if unique
     */
    private function checkOioUniqueness(string $ioUrl, string $objectUrl, string $objectType): ?array
    {
        $register  = $this->mappingConfig['sourceRegister'] ?? '';
        $oioSchema = $this->settingsService->getConfigValue(key: 'document_link_schema');

        if ($register === '') {
            return null;
        }

        $duplicateError = $this->error(
            status: 400,
            detail: 'De combinatie informatieobject + object + objectType bestaat al.',
            invalidParams: [
                $this->fieldError(
                    fieldName: 'nonFieldErrors',
                    code: 'unique',
                    reason: 'De combinatie informatieobject + object + objectType bestaat al.'
                ),
            ]
        );

        $ioUuid     = $this->extractUuid(value: $ioUrl);
        $objectUuid = $this->extractUuid(value: $objectUrl);

        // Check OIO schema for existing duplicates.
        if ($oioSchema !== '') {
            $found = $this->searchDuplicateRelation(
                register: $register,
                schema: $oioSchema,
                ioUrl: $ioUrl,
                objectUrl: $objectUrl,
                ioUuid: $ioUuid,
                objectUuid: $objectUuid,
                objectField: 'object'
            );
            if ($found === true) {
                return $duplicateError;
            }
        }

        // Drc-003a/b: Also check ZIO/BIO schemas — a relationship created via
        // ZRC (zaakinformatieobjecten) or BRC (besluitinformatieobjecten) counts
        // as a duplicate for OIO creation.
        $crossSchemaKey = '';
        $crossField     = '';
        if ($objectType === 'zaak') {
            $crossSchemaKey = 'case_document_schema';
            $crossField     = 'case';
        } elseif ($objectType === 'besluit') {
            $crossSchemaKey = 'decision_document_schema';
            $crossField     = 'decision';
        }

        if ($crossSchemaKey !== '') {
            $crossSchema = $this->settingsService->getConfigValue(key: $crossSchemaKey);
            if ($crossSchema !== '') {
                $found = $this->searchDuplicateRelation(
                    register: $register,
                    schema: $crossSchema,
                    ioUrl: $ioUrl,
                    objectUrl: $objectUrl,
                    ioUuid: $ioUuid,
                    objectUuid: $objectUuid,
                    objectField: $crossField
                );
                if ($found === true) {
                    return $duplicateError;
                }
            }
        }

        return null;
    }

    /**
     * Search for an existing relation in a schema by document+object combination.
     *
     * @param string      $register    The register ID
     * @param string      $schema      The schema ID to search
     * @param string      $ioUrl       The informatieobject URL
     * @param string      $objectUrl   The object URL
     * @param string|null $ioUuid      The extracted informatieobject UUID
     * @param string|null $objectUuid  The extracted object UUID
     * @param string      $objectField The field name for the object reference
     *
     * @return bool True if a duplicate was found
     */
    private function searchDuplicateRelation(
        string $register,
        string $schema,
        string $ioUrl,
        string $objectUrl,
        ?string $ioUuid,
        ?string $objectUuid,
        string $objectField
    ): bool {
        try {
            // Search by full URL.
            $query  = $this->objectService->buildSearchQuery(
                requestParams: ['document' => $ioUrl, $objectField => $objectUrl, '_limit' => 1],
                register: $register,
                schema: $schema
            );
            $result = $this->objectService->searchObjectsPaginated(query: $query);
            if (($result['total'] ?? count($result['results'] ?? [])) > 0) {
                return true;
            }

            // Fallback: search by UUID (stored data may use UUID instead of URL).
            if ($ioUuid !== null && $objectUuid !== null) {
                $query  = $this->objectService->buildSearchQuery(
                    requestParams: ['document' => $ioUuid, $objectField => $objectUuid, '_limit' => 1],
                    register: $register,
                    schema: $schema
                );
                $result = $this->objectService->searchObjectsPaginated(query: $query);
                if (($result['total'] ?? count($result['results'] ?? [])) > 0) {
                    return true;
                }

                // Full-text search using both UUIDs (field-specific LIKE
                // with % is not supported by MagicMapper).
                $query  = $this->objectService->buildSearchQuery(
                    requestParams: ['_search' => $ioUuid . ' ' . $objectUuid, '_limit' => 1],
                    register: $register,
                    schema: $schema
                );
                $result = $this->objectService->searchObjectsPaginated(query: $query);
                if (($result['total'] ?? count($result['results'] ?? [])) > 0) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Drc-003: Uniqueness check failed for schema ' . $schema . ': ' . $e->getMessage()
            );
        }

        return false;
    }

    /**
     * Validate lock requirement for document modifications (drc-006).
     *
     * @param array      $body           The request body
     * @param array|null $existingObject The existing EIO data
     *
     * @return array|null Validation error, or null if valid
     */
    private function validateLock(array $body, ?array $existingObject): ?array
    {
        if ($existingObject === null) {
            return null;
        }

        // Check if the document is locked.
        $existingLock = $existingObject['locked'] ?? ($existingObject['lock'] ?? '');
        if ($existingLock === '' || $existingLock === false || $existingLock === null) {
            return null;
        }

        // Drc-006: Require valid lock ID.
        $requestLock = $body['lock'] ?? '';
        if ($requestLock === '') {
            return $this->error(
                status: 400,
                detail: 'Het document is vergrendeld. Geef een geldig lock ID mee.',
                invalidParams: [
                    $this->fieldError(
                        fieldName: 'lock',
                        code: 'required',
                        reason: 'Het document is vergrendeld.'
                    ),
                ]
            );
        }

        if ($requestLock !== $existingLock) {
            return $this->error(
                status: 400,
                detail: 'Het opgegeven lock ID is niet geldig.',
                invalidParams: [
                    $this->fieldError(
                        fieldName: 'lock',
                        code: 'incorrect-lock-id',
                        reason: 'Het opgegeven lock ID komt niet overeen.'
                    ),
                ]
            );
        }

        return null;
    }

    /**
     * Derive vertrouwelijkheidaanduiding from informatieobjecttype (drc-005).
     *
     * @param array  $body   The request body
     * @param string $iotUrl The informatieobjecttype URL
     *
     * @return array The body with derived vertrouwelijkheidaanduiding
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
     */
    private function deriveVertrouwelijkheidaanduiding(array $body, string $iotUrl): array
    {
        $uuid = $this->extractUuid(value: $iotUrl);
        if ($uuid === null) {
            return $body;
        }

        $iotData = $this->findBySchemaKey(uuid: $uuid, schemaKey: 'document_type_schema');
        if ($iotData === null) {
            return $body;
        }

        $va = $iotData['confidentiality']
            ?? $iotData['confidentialityDesignation']
            ?? $iotData['vertrouwelijkheidaanduiding'] ?? '';
        if ($va !== '') {
            $body['vertrouwelijkheidaanduiding'] = $va;
        }

        return $body;
    }
}
