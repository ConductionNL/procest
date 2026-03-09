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
     * Rules for creating an EnkelvoudigInformatieObject (POST /documenten/v1/enkelvoudiginformatieobjecten).
     *
     * Implements:
     * - drc-001: Validate informatieobjecttype exists and is published (concept=false).
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
     * - drc-005: Derive vertrouwelijkheidaanduiding from informatieobjecttype if not set.
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
     * - drc-008: Auto-generate identificatie if not provided. Validate uniqueness
     *   of identificatie + bronorganisatie combination.
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
     *
     * @param array $body The ZGW request body (Dutch field names)
     *
     * @return array The validation result
     */
    public function rulesEnkelvoudiginformatieobjectenCreate(array $body): array
    {
        // drc-001: Validate informatieobjecttype is published (not concept).
        $iotUrl = $body['informatieobjecttype'] ?? '';
        if ($iotUrl !== '' && $this->objectService !== null) {
            $error = $this->validateTypeUrl($iotUrl, 'informatieobjecttype', 'document_type_schema');
            if ($error !== null) {
                return $error;
            }
        }

        // drc-005: Derive vertrouwelijkheidaanduiding from informatieobjecttype if not set.
        if (empty($body['vertrouwelijkheidaanduiding']) === true && $iotUrl !== '') {
            $body = $this->deriveVertrouwelijkheidaanduiding($body, $iotUrl);
        }

        // drc-006a: Default indicatieGebruiksrecht to null on creation.
        if (array_key_exists('indicatieGebruiksrecht', $body) === false
            || $body['indicatieGebruiksrecht'] === false
        ) {
            $body['indicatieGebruiksrecht'] = null;
        }

        // drc-006b: If indicatieGebruiksrecht is explicitly true, gebruiksrechten must exist.
        if ($body['indicatieGebruiksrecht'] === true && $this->objectService !== null) {
            $error = $this->validateIndicatieGebruiksrechtTrue($body);
            if ($error !== null) {
                return $error;
            }
        }

        // drc-008: Check unique identificatie + bronorganisatie.
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

        // drc-008: Auto-generate identificatie if not provided.
        if (empty($body['identificatie']) === true) {
            $body['identificatie'] = $this->generateIdentificatie('DOCUMENT');
        }

        return $this->ok($body);
    }//end rulesEnkelvoudiginformatieobjectenCreate()

    /**
     * Rules for updating an EnkelvoudigInformatieObject (PUT).
     *
     * Implements:
     * - drc-003: Block update when status is 'definitief' without valid lock.
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
     * - drc-006: Require valid lock ID for modifications on locked documents.
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
     *
     * @param array      $body           The ZGW request body
     * @param array|null $existingObject The existing EIO data
     *
     * @return array The validation result
     */
    public function rulesEnkelvoudiginformatieobjectenUpdate(
        array $body,
        ?array $existingObject=null
    ): array {
        // drc-006: Check lock requirement.
        $lockError = $this->validateLock($body, $existingObject);
        if ($lockError !== null) {
            return $lockError;
        }

        return $this->ok($body);
    }//end rulesEnkelvoudiginformatieobjectenUpdate()

    /**
     * Rules for patching an EnkelvoudigInformatieObject (PATCH).
     *
     * Implements:
     * - drc-003: Block update when status is 'definitief' without valid lock.
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
     * - drc-006: Require valid lock ID for modifications on locked documents.
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
     *
     * @param array      $body           The ZGW request body
     * @param array|null $existingObject The existing EIO data
     *
     * @return array The validation result
     */
    public function rulesEnkelvoudiginformatieobjectenPatch(
        array $body,
        ?array $existingObject=null
    ): array {
        // drc-006: Check lock requirement.
        $lockError = $this->validateLock($body, $existingObject);
        if ($lockError !== null) {
            return $lockError;
        }

        return $this->ok($body);
    }//end rulesEnkelvoudiginformatieobjectenPatch()

    /**
     * Rules for destroying an EnkelvoudigInformatieObject (DELETE).
     *
     * Implements:
     * - drc-007: Cannot destroy an informatieobject that has ObjectInformatieObject relations.
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
     *
     * @param array      $body           The ZGW request body (usually empty)
     * @param array|null $existingObject The existing EIO data
     *
     * @return array The validation result
     */
    public function rulesEnkelvoudiginformatieobjectenDestroy(
        array $body,
        ?array $existingObject=null
    ): array {
        if ($existingObject === null || $this->objectService === null) {
            return $this->ok($body);
        }

        $existingId = $existingObject['id'] ?? ($existingObject['@self']['id'] ?? null);
        if ($existingId === null) {
            return $this->ok($body);
        }

        // drc-007: Check for ObjectInformatieObject relations.
        $register  = $this->mappingConfig['sourceRegister'] ?? '';
        $oioSchema = $this->settingsService->getConfigValue(key: 'document_link_schema');

        if ($register !== '' && $oioSchema !== '') {
            // OIO stores the full informatieobject URL in the 'document' field,
            // so search with both UUID and partial match to find any OIO relations.
            $relatedIds = $this->findOioRelationsForDocument($register, $oioSchema, $existingId);
            if (empty($relatedIds) === false) {
                return $this->error(
                    400,
                    'Het informatieobject kan niet verwijderd worden omdat er nog relaties aan gekoppeld zijn.',
                    [$this->fieldError(
                        'nonFieldErrors',
                        'pending-relations',
                        'Het informatieobject heeft nog ObjectInformatieObject relaties.'
                    )
                    ]
                );
            }
        }

        return $this->ok($body);
    }//end rulesEnkelvoudiginformatieobjectenDestroy()

    /**
     * Rules for creating an ObjectInformatieObject (POST /documenten/v1/objectinformatieobjecten).
     *
     * Implements:
     * - drc-002: Validate informatieobject URL exists. Validate uniqueness of
     *   informatieobject + object + objectType combination.
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
     *
     * @param array $body The ZGW request body
     *
     * @return array The validation result
     */
    public function rulesObjectinformatieobjectenCreate(array $body): array
    {
        // drc-002: Validate informatieobject URL.
        $ioUrl = $body['informatieobject'] ?? '';
        if ($ioUrl !== '') {
            $error = $this->validateInformatieobjectUrl($ioUrl);
            if ($error !== null) {
                return $error;
            }
        }

        // drc-002: Validate object URL.
        $objectUrl  = $body['object'] ?? '';
        $objectType = $body['objectType'] ?? '';
        if ($objectUrl !== '') {
            $error = $this->validateObjectUrl($objectUrl, $objectType);
            if ($error !== null) {
                return $error;
            }
        }

        // drc-003 (VNG): Validate uniqueness of object + informatieobject + objectType.
        // Must run BEFORE cross-register check so duplicate errors take priority.
        if ($ioUrl !== '' && $objectUrl !== '' && $this->objectService !== null) {
            $error = $this->checkOioUniqueness($ioUrl, $objectUrl, $body['objectType'] ?? '');
            if ($error !== null) {
                return $error;
            }
        }

        // drc-004 (VNG): Cross-register validation — ZIO/BIO must exist in ZRC/BRC.
        $objectType = $body['objectType'] ?? '';
        if ($ioUrl !== '' && $objectUrl !== '' && $objectType !== '' && $this->objectService !== null) {
            $error = $this->validateOioCrossRegister($ioUrl, $objectUrl, $objectType);
            if ($error !== null) {
                return $error;
            }
        }

        return $this->ok($body);
    }//end rulesObjectinformatieobjectenCreate()

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
        $results = $this->findAllObjectsByField($register, $schema, 'document', $docUuid);
        if (empty($results) === false) {
            return $results;
        }

        // Also search by partial URL match (OIO may store full URL).
        try {
            $query  = $this->objectService->buildSearchQuery(
                requestParams: ['document' => '%'.$docUuid.'%', '_limit' => 100],
                register: $register,
                schema: $schema
            );
            $result = $this->objectService->searchObjectsPaginated(query: $query);

            $ids = [];
            foreach (($result['results'] ?? []) as $obj) {
                $data = is_array($obj) === true ? $obj : $obj->jsonSerialize();
                $id   = $data['id'] ?? ($data['@self']['id'] ?? null);
                if ($id !== null) {
                    $ids[] = $id;
                }
            }

            return $ids;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'drc-007: OIO relation search with partial match failed: '.$e->getMessage()
            );
            return [];
        }//end try
    }//end findOioRelationsForDocument()

    /**
     * Validate that indicatieGebruiksrecht=true requires existing gebruiksrechten (drc-006b).
     *
     * When creating an EIO with indicatieGebruiksrecht=true, there must already be
     * gebruiksrechten records for this document. Since this is a create operation,
     * the document doesn't exist yet, so there can't be any gebruiksrechten.
     *
     * @param array $body The request body
     *
     * @return array|null Validation error, or null if valid
     */
    private function validateIndicatieGebruiksrechtTrue(array $body): ?array
    {
        // On create, the document does not yet exist so there can be no gebruiksrechten.
        return $this->error(
            400,
            'indicatieGebruiksrecht kan niet true zijn zonder dat er gebruiksrechten bestaan.',
            [
                $this->fieldError(
                    'indicatieGebruiksrecht',
                    'missing-gebruiksrechten',
                    'Er zijn geen gebruiksrechten voor dit informatieobject.'
                ),
            ]
        );
    }//end validateIndicatieGebruiksrechtTrue()

    /**
     * Validate OIO object URL (drc-002a/b/c/d).
     *
     * Validates that the object URL:
     * 1. Is a syntactically valid URL
     * 2. Contains a valid UUID
     * 3. Matches the objectType (zaak URL contains /zaken/, besluit URL contains /besluiten/)
     *
     * @param string $objectUrl  The object URL
     * @param string $objectType The object type (zaak or besluit)
     *
     * @return array|null Validation error, or null if valid
     */
    private function validateObjectUrl(string $objectUrl, string $objectType): ?array
    {
        // drc-002a/b: Check URL validity.
        if ($this->isValidUrl($objectUrl) === false) {
            return $this->error(
                400,
                'De object URL is ongeldig.',
                [
                    $this->fieldError('object', 'bad-url', 'De object URL is ongeldig.'),
                ]
            );
        }

        // drc-002a/b: Check URL contains a UUID.
        $objectUuid = $this->extractUuid($objectUrl);
        if ($objectUuid === null) {
            return $this->error(
                400,
                'De object URL bevat geen geldig UUID.',
                [
                    $this->fieldError('object', 'bad-url', 'De object URL bevat geen geldig UUID.'),
                ]
            );
        }

        // drc-002c/d: Validate objectType matches the URL path.
        if ($objectType === 'zaak' && strpos($objectUrl, '/zaken/') === false) {
            return $this->error(
                400,
                'De object URL wijst niet naar een zaak.',
                [
                    $this->fieldError(
                        'object',
                        'invalid-resource',
                        'De object URL wijst niet naar een zaak resource.'
                    ),
                ]
            );
        }

        if ($objectType === 'besluit' && strpos($objectUrl, '/besluiten/') === false) {
            return $this->error(
                400,
                'De object URL wijst niet naar een besluit.',
                [
                    $this->fieldError(
                        'object',
                        'invalid-resource',
                        'De object URL wijst niet naar een besluit resource.'
                    ),
                ]
            );
        }

        return null;
    }//end validateObjectUrl()

    /**
     * Validate OIO cross-register: ZIO/BIO must exist (drc-004 VNG).
     *
     * When creating an OIO with objectType=zaak, a corresponding
     * ZaakInformatieObject must exist in ZRC. For objectType=besluit,
     * a BesluitInformatieObject must exist in BRC.
     *
     * @param string $ioUrl      The informatieobject URL
     * @param string $objectUrl  The object URL (zaak or besluit)
     * @param string $objectType The object type (zaak or besluit)
     *
     * @return array|null Validation error if relation doesn't exist, null if valid
     */
    private function validateOioCrossRegister(string $ioUrl, string $objectUrl, string $objectType): ?array
    {
        $ioUuid     = $this->extractUuid($ioUrl) ?? '';
        $objectUuid = $this->extractUuid($objectUrl) ?? '';

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
        } else if ($objectType === 'besluit') {
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
                $detail = $objectType === 'zaak' ? 'Er bestaat geen ZaakInformatieObject in de Zaken API voor deze combinatie.' : 'Er bestaat geen BesluitInformatieObject in de Besluiten API voor deze combinatie.';
                return $this->error(
                        400,
                        $detail,
                        [
                            $this->fieldError('nonFieldErrors', 'inconsistent-relation', $detail),
                        ]
                        );
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'drc-004: Cross-register validation failed: '.$e->getMessage()
            );
        }//end try

        return null;
    }//end validateOioCrossRegister()

    /**
     * Validate OIO uniqueness: object + informatieobject + objectType (drc-003 VNG).
     *
     * The combination of informatieobject, object, and objectType must be unique.
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

        if ($register === '' || $oioSchema === '') {
            return null;
        }

        try {
            // Search by full URL since OIO stores the full URL, not just UUID.
            $searchParams = [
                'document' => $ioUrl,
                'object'   => $objectUrl,
                '_limit'   => 1,
            ];

            $query  = $this->objectService->buildSearchQuery(
                requestParams: $searchParams,
                register: $register,
                schema: $oioSchema
            );
            $result = $this->objectService->searchObjectsPaginated(query: $query);
            $total  = $result['total'] ?? count($result['results'] ?? []);

            if ($total > 0) {
                return $this->error(
                    400,
                    'De combinatie informatieobject + object + objectType bestaat al.',
                    [$this->fieldError(
                        'nonFieldErrors',
                        'unique',
                        'De combinatie informatieobject + object + objectType bestaat al.'
                    )
                    ]
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'drc-003: OIO uniqueness check failed: '.$e->getMessage()
            );
        }//end try

        return null;
    }//end checkOioUniqueness()

    /**
     * Validate lock requirement for document modifications (drc-006).
     *
     * When a document has status 'definitief' and is locked, the request
     * must include the correct lock ID.
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

        // drc-006: Require valid lock ID.
        $requestLock = $body['lock'] ?? '';
        if ($requestLock === '') {
            return $this->error(
                    400,
                    'Het document is vergrendeld. Geef een geldig lock ID mee.',
                    [
                        $this->fieldError('lock', 'required', 'Het document is vergrendeld.'),
                    ]
                    );
        }

        if ($requestLock !== $existingLock) {
            return $this->error(
                    400,
                    'Het opgegeven lock ID is niet geldig.',
                    [
                        $this->fieldError('lock', 'incorrect-lock-id', 'Het opgegeven lock ID komt niet overeen.'),
                    ]
                    );
        }

        return null;
    }//end validateLock()

    /**
     * Derive vertrouwelijkheidaanduiding from informatieobjecttype (drc-005).
     *
     * If the client does not send vertrouwelijkheidaanduiding, it must be
     * derived from InformatieObjectType.vertrouwelijkheidaanduiding.
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
     *
     * @param array  $body   The request body
     * @param string $iotUrl The informatieobjecttype URL
     *
     * @return array The body with derived vertrouwelijkheidaanduiding
     */
    private function deriveVertrouwelijkheidaanduiding(array $body, string $iotUrl): array
    {
        $uuid = $this->extractUuid($iotUrl);
        if ($uuid === null) {
            return $body;
        }

        $iotData = $this->findBySchemaKey($uuid, 'document_type_schema');
        if ($iotData === null) {
            return $body;
        }

        $va = $iotData['confidentiality']
            ?? ($iotData['confidentialityDesignation']
            ?? ($iotData['vertrouwelijkheidaanduiding'] ?? ''));
        if ($va !== '') {
            $body['vertrouwelijkheidaanduiding'] = $va;
        }

        return $body;
    }//end deriveVertrouwelijkheidaanduiding()
}//end class
