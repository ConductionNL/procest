<?php

/**
 * Procest ZGW ZTC (Catalogi) Business Rules Service
 *
 * Implements business rules for the Catalogi API as defined by VNG Realisatie.
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
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/catalogi/
 *
 * Business rules implemented:
 *
 * - ztc-001: Valideren selectielijstProcestype op zaaktype
 * - ztc-002: Valideren selectielijstklasse + resultaattypeomschrijving (enrichment)
 * - ztc-003: Valideren afleidingswijze vs selectielijstklasse.procestermijn
 * - ztc-004: Valideren datumkenmerk vereist/verboden op basis van afleidingswijze
 * - ztc-005: Valideren einddatumBekend verboden voor afgehandeld/termijn
 * - ztc-006: Valideren objecttype vereist/verboden op basis van afleidingswijze
 * - ztc-007: Valideren registratie vereist voor ander_datumkenmerk
 * - ztc-008: Valideren procestermijn vereist voor termijn afleidingswijze
 * - ztc-009: Concept/gepubliceerd bescherming: types met concept=false mogen niet
 *            gewijzigd of verwijderd worden (behalve eindeGeldigheid via PATCH)
 * - ztc-010: Sub-resources van gepubliceerde zaaktypen mogen niet gewijzigd worden
 *            (behalve CREATE voor eigenschappen/roltypen/statustypen/ZIOTs)
 * - ztc-011: History model — beginGeldigheid + eindeGeldigheid + concept consistency
 * - ztc-012: Publish validation — all relations must be published before publish
 * - ztc-013: Cross-catalogus — zaaktype must belong to the specified catalogus
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
 * ZTC (Catalogi API) business rule validation and enrichment.
 *
 * @psalm-suppress UnusedClass
 */
class ZgwZtcRulesService extends ZgwRulesBase
{

    /**
     * Afleidingswijze values that REQUIRE datumkenmerk (ztc-004).
     *
     * @var array<string>
     */
    private const AFLEIDINGSWIJZE_REQUIRES_DATUMKENMERK = [
        'eigenschap',
        'zaakobject',
        'ander_datumkenmerk',
    ];

    /**
     * Afleidingswijze values that REQUIRE objecttype (ztc-006).
     *
     * @var array<string>
     */
    private const AFLEIDINGSWIJZE_REQUIRES_OBJECTTYPE = [
        'zaakobject',
        'ander_datumkenmerk',
    ];

    /**
     * Afleidingswijze values that FORBID einddatumBekend=true (ztc-005).
     *
     * @var array<string>
     */
    private const AFLEIDINGSWIJZE_FORBIDS_EINDDATUM_BEKEND = [
        'afgehandeld',
        'termijn',
    ];

    /**
     * ZTC resources that are subject to concept/published protection.
     *
     * @var array<string>
     */
    public const CONCEPT_RESOURCES = [
        'zaaktypen',
        'besluittypen',
        'informatieobjecttypen',
    ];

    /**
     * ZTC sub-resources tied to a parent zaaktype (ztc-010).
     *
     * @var array<string>
     */
    public const ZAAKTYPE_SUB_RESOURCES = [
        'statustypen',
        'resultaattypen',
        'roltypen',
        'eigenschappen',
        'zaaktype-informatieobjecttypen',
    ];

    /**
     * Check concept/published protection for ZTC resources (ztc-009, ztc-010).
     *
     * Published types (concept=false) cannot be modified or deleted.
     * Sub-resources of published zaaktypen cannot be modified or deleted
     * (except CREATE for most sub-resources per VNG test exceptions).
     *
     * Implements:
     * - ztc-009: Protect published (concept=false) types from modification/deletion.
     *   Exception: PATCH with only eindeGeldigheid is allowed on published types.
     *
     * - ztc-010: Protect sub-resources of published zaaktypen.
     *   Per VNG tests ztc-010i/k/l/m, CREATE is allowed for eigenschappen, roltypen,
     *   statustypen, and ZIOTs on published zaaktypen. Only resultaattypen creation
     *   is blocked (ztc-010j). Update/patch/destroy remain blocked for ALL.
     *
     * @param string     $resource            The ZGW resource name
     * @param string     $action              The action (create/update/patch/destroy)
     * @param array      $body                The request body
     * @param array|null $existingObject      The existing object data
     * @param bool|null  $parentZaaktypeDraft Whether the parent zaaktype isDraft
     *
     * @return array|null Validation error result, or null if check passes
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/catalogi/
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/catalogi/
     */
    public function checkConceptProtection(
        string $resource,
        string $action,
        array $body,
        ?array $existingObject,
        ?bool $parentZaaktypeDraft
    ): ?array {
        // Ztc-009: Direct concept resources (zaaktypen, besluittypen, informatieobjecttypen).
        if (in_array($resource, self::CONCEPT_RESOURCES, true) === true) {
            return $this->checkDirectConceptProtection(resource: $resource, action: $action, body: $body, existingObject: $existingObject);
        }

        // Ztc-010: Sub-resources of zaaktypen.
        if (in_array($resource, self::ZAAKTYPE_SUB_RESOURCES, true) === true
            && $parentZaaktypeDraft === false
        ) {
            // Allow creation of all sub-resources except resultaattypen.
            if ($action === 'create' && $resource !== 'resultaattypen') {
                return null;
            }

            // Block resultaattypen creation + update/patch/destroy for all sub-resources.
            if (in_array($action, ['create', 'update', 'patch', 'destroy'], true) === true) {
                $detail = 'Het is niet toegestaan om typen van een gepubliceerd zaaktype aan te passen.';
                return $this->error(
                        status: 400,
                        detail: $detail,
                        invalidParams: [
                            $this->fieldError(name: 'nonFieldErrors', code: 'non-concept-zaaktype', reason: $detail),
                        ]
                        );
            }
        }

        return null;
    }//end checkConceptProtection()

    /**
     * Default concept=true for new ZTC concept resources.
     *
     * When creating a new zaaktype, besluittype, or informatieobjecttype,
     * concept defaults to true if not explicitly set.
     *
     * @param array  $body     The request body
     * @param string $resource The resource name
     *
     * @return array The body with concept defaulted
     */
    public function defaultConcept(array $body, string $resource): array
    {
        if (in_array($resource, self::CONCEPT_RESOURCES, true) === true
            && array_key_exists('concept', $body) === false
        ) {
            $body['concept'] = true;
        }

        return $body;
    }//end defaultConcept()

    /**
     * Preserve existing concept value on update/patch.
     *
     * Concept can only be changed via the /publish endpoint, not via PUT/PATCH.
     *
     * @param array      $body           The request body
     * @param string     $resource       The resource name
     * @param array|null $existingObject The existing object data
     *
     * @return array The body with concept preserved
     */
    public function preserveConcept(array $body, string $resource, ?array $existingObject): array
    {
        if ($existingObject === null
            || in_array($resource, self::CONCEPT_RESOURCES, true) === false
        ) {
            return $body;
        }

        $existingDraft = $existingObject['isDraft'] ?? ($existingObject['concept'] ?? true);
        if ($existingDraft === 'true' || $existingDraft === '1' || $existingDraft === 1) {
            $existingDraft = true;
        } else if ($existingDraft === 'false' || $existingDraft === '0' || $existingDraft === 0) {
            $existingDraft = false;
        }

        $body['concept'] = $existingDraft;

        return $body;
    }//end preserveConcept()

    /**
     * Rules for creating a zaaktype (POST /catalogi/v1/zaaktypen).
     *
     * Implements:
     * - ztc-001: Validate selectielijstProcestype URL points to a valid procestype resource.
     *
     * Also resolves reference arrays (informatieobjecttypen, besluittypen,
     * deelzaaktypen, gerelateerdeZaaktypen) from omschrijving/identificatie to UUIDs.
     *
     * @param array $body The ZGW request body (Dutch field names)
     *
     * @return array The validation result
     *
     * @link https://vng-realisatie.github.io/gemma-zaken/standaard/catalogi/
     */
    public function rulesZaaktypenCreate(array $body): array
    {
        // Ztc-001: Validate selectielijstProcestype URL.
        $procesTypeUrl = $body['selectielijstProcestype'] ?? '';
        if (empty($procesTypeUrl) === false) {
            $procesTypeData = $this->fetchExternalUrl(url: $procesTypeUrl);
            if ($procesTypeData === null || isset($procesTypeData['nummer']) === false) {
                return $this->error(
                    status: 400,
                    detail: 'De selectielijstProcestype URL is ongeldig of wijst niet naar een procestype resource.',
                    invalidParams: [$this->fieldError(
                        name: 'selectielijstProcestype',
                        code: 'invalid-resource',
                        reason: 'De selectielijstProcestype URL is ongeldig of wijst niet naar een procestype resource.'
                    )
                    ]
                );
            }
        }

        // Resolve reference arrays by omschrijving/identificatie to UUIDs.
        $body = $this->resolveTypeReferences(body: $body, field: 'informatieobjecttypen', schemaKey: 'document_type_schema', lookupField: 'name');
        $body = $this->resolveTypeReferences(body: $body, field: 'besluittypen', schemaKey: 'decision_type_schema', lookupField: 'name');
        $body = $this->resolveTypeReferences(body: $body, field: 'deelzaaktypen', schemaKey: 'case_type_schema', lookupField: 'identifier');
        $body = $this->resolveGerelateerdeZaaktypen(body: $body);

        // Store resolved array fields via _directFields (bypasses Twig mapping).
        $directFields = [];
        if (isset($body['deelzaaktypen']) === true && is_array($body['deelzaaktypen']) === true) {
            $directFields['subCaseTypes'] = $body['deelzaaktypen'];
        }

        if (isset($body['besluittypen']) === true && is_array($body['besluittypen']) === true) {
            $directFields['decisionTypes'] = $body['besluittypen'];
        }

        if (isset($body['gerelateerdeZaaktypen']) === true
            && is_array($body['gerelateerdeZaaktypen']) === true
        ) {
            // JSON-encode since relatedCaseTypes is a string field in the schema.
            $directFields['relatedCaseTypes'] = json_encode($body['gerelateerdeZaaktypen']);
        }

        if (empty($directFields) === false) {
            $body['_directFields'] = $directFields;
        }

        return $this->ok(body: $body);
    }//end rulesZaaktypenCreate()

    /**
     * Rules for creating a besluittype (POST /catalogi/v1/besluittypen).
     *
     * Resolves reference arrays (informatieobjecttypen, zaaktypen) from
     * omschrijving/identificatie to UUIDs.
     *
     * @param array $body The ZGW request body
     *
     * @return array The validation result
     */
    public function rulesBesluittypenCreate(array $body): array
    {
        // Resolve reference arrays by omschrijving/identificatie to UUIDs.
        $body = $this->resolveTypeReferences(body: $body, field: 'informatieobjecttypen', schemaKey: 'document_type_schema', lookupField: 'name');
        $body = $this->resolveTypeReferences(body: $body, field: 'zaaktypen', schemaKey: 'case_type_schema', lookupField: 'identifier');

        // Store resolved arrays as _directFields (bypass Twig mapping for array fields).
        $directFields = [];
        if (isset($body['informatieobjecttypen']) === true && is_array($body['informatieobjecttypen']) === true) {
            $directFields['documentTypes'] = $body['informatieobjecttypen'];
        }

        if (isset($body['zaaktypen']) === true && is_array($body['zaaktypen']) === true) {
            $directFields['caseTypes'] = $body['zaaktypen'];
        }

        if (empty($directFields) === false) {
            $body['_directFields'] = $directFields;
        }

        return $this->ok(body: $body);
    }//end rulesBesluittypenCreate()

    /**
     * Rules for creating a zaaktype-informatieobjecttype (ZIOT).
     *
     * Resolves the informatieobjecttype field from omschrijving to UUID when
     * the value is not a URL or UUID.
     *
     * @param array $body The ZGW request body
     *
     * @return array The validation result
     */
    public function rulesZaaktypeinformatieobjecttypenCreate(array $body): array
    {
        // Resolve informatieobjecttype: omschrijving → UUID, or bare UUID → verify.
        $iotRef = $body['informatieobjecttype'] ?? '';
        if ($iotRef !== '' && $this->objectService !== null) {
            $register = $this->mappingConfig['sourceRegister'] ?? '';
            $schema   = $this->settingsService->getConfigValue(key: 'document_type_schema');

            if (empty($register) === false && empty($schema) === false) {
                $uuid = $this->extractUuid(url: $iotRef);

                // If the value is a URL containing a UUID, keep as-is (reverse mapping extracts it).
                $isUrl = (str_starts_with($iotRef, 'http://') === true
                    || str_starts_with($iotRef, 'https://') === true);

                if ($isUrl === true) {
                    // URL — let reverse mapping handle UUID extraction.
                } else if ($uuid !== null) {
                    // Bare UUID — verify it exists; if not, treat as omschrijving.
                    $existing = $this->findBySchemaKey(uuid: $uuid, schemaKey: 'document_type_schema');
                    if ($existing === null) {
                        $found = $this->findObjectByField(register: $register, schema: $schema, field: 'name', value: $iotRef);
                        if ($found !== null) {
                            $body['informatieobjecttype'] = $found;
                        }
                    }
                } else {
                    // Not a URL or UUID — resolve by name (omschrijving).
                    $found = $this->findObjectByField(register: $register, schema: $schema, field: 'name', value: $iotRef);
                    if ($found !== null) {
                        $body['informatieobjecttype'] = $found;
                    }
                }
            }//end if
        }//end if

        return $this->ok(body: $body);
    }//end rulesZaaktypeinformatieobjecttypenCreate()

    /**
     * Rules for creating a resultaattype (POST /catalogi/v1/resultaattypen).
     *
     * Implements:
     * - ztc-002: Validate and fetch selectielijstklasse + resultaattypeomschrijving.
     *   Enrich with omschrijvingGeneriek, archiefnominatie, archiefactietermijn.
     *
     * - ztc-003: Validate afleidingswijze vs selectielijstklasse.procestermijn.
     *   procestermijn=nihil only afgehandeld; procestermijn=bestaansduur_procesobject only termijn.
     * - ztc-004: datumkenmerk required for eigenschap/zaakobject/ander_datumkenmerk, forbidden otherwise.
     * - ztc-005: einddatumBekend must be false for afgehandeld/termijn.
     * - ztc-006: objecttype required for zaakobject/ander_datumkenmerk, forbidden otherwise.
     * - ztc-007: registratie required only for ander_datumkenmerk.
     * - ztc-008: procestermijn required only for termijn afleidingswijze.
     *
     * @param array $body The ZGW request body
     *
     * @return array The validation result
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function rulesResultaattypenCreate(array $body): array
    {
        $errors = [];

        // Ztc-002: Validate and fetch external URLs for enrichment.
        $selectielijstklasseUrl = $body['selectielijstklasse'] ?? '';
        $selectielijstData      = null;
        if (empty($selectielijstklasseUrl) === false) {
            $selectielijstData = $this->fetchExternalUrl(url: $selectielijstklasseUrl);
            if ($selectielijstData === null) {
                $errors[] = $this->fieldError(
                    name: 'selectielijstklasse',
                    code: 'invalid',
                    reason: 'De selectielijstklasse URL is ongeldig of niet bereikbaar.'
                );
            }
        }

        $rtoUrl = $body['resultaattypeomschrijving'] ?? '';
        if (is_array($rtoUrl) === true) {
            $rtoUrl = $rtoUrl[0] ?? '';
        }

        $rtoData = null;
        if (empty($rtoUrl) === false) {
            $rtoData = $this->fetchExternalUrl(url: $rtoUrl);
            if ($rtoData === null) {
                $errors[] = $this->fieldError(
                    name: 'resultaattypeomschrijving',
                    code: 'invalid',
                    reason: 'De resultaattypeomschrijving URL is ongeldig of niet bereikbaar.'
                );
            }
        }

        if (empty($errors) === false) {
            return $this->error(status: 400, detail: $errors[0]['reason'], invalidParams: $errors);
        }

        // Ztc-002b/f/g: Enrich body with derived fields from external data.
        $body = $this->enrichResultaattype(body: $body, selectielijstData: $selectielijstData, rtoData: $rtoData);

        // Ztc-002e: Validate selectielijstklasse procesType matches zaaktype selectielijstProcestype.
        if ($selectielijstData !== null) {
            $procestypeError = $this->validateProcestypeMatch(body: $body, selectielijstData: $selectielijstData);
            if ($procestypeError !== null) {
                return $procestypeError;
            }
        }

        // Validate brondatumArchiefprocedure cross-field constraints (ztc-003 to ztc-008).
        $archief = $body['brondatumArchiefprocedure'] ?? null;
        if ($archief !== null) {
            $errors = $this->validateBrondatumArchief(archief: $archief, selectielijstData: $selectielijstData);
        }

        if (empty($errors) === false) {
            return $this->error(status: 400, detail: $errors[0]['reason'], invalidParams: $errors);
        }

        return $this->ok(body: $body);
    }//end rulesResultaattypenCreate()

    /**
     * Check if a direct concept resource is published (ztc-009).
     *
     * Published types (concept=false) cannot be modified or deleted,
     * except PATCH with only eindeGeldigheid.
     *
     * @param string     $resource       The resource name
     * @param string     $action         The action
     * @param array      $body           The request body
     * @param array|null $existingObject The existing object data
     *
     * @return array|null Validation error, or null if OK
     */
    private function checkDirectConceptProtection(
        string $resource,
        string $action,
        array $body,
        ?array $existingObject
    ): ?array {
        if ($existingObject === null) {
            return null;
        }

        $isDraft = $existingObject['isDraft'] ?? ($existingObject['concept'] ?? true);
        if ($isDraft === 'false' || $isDraft === false || $isDraft === '0' || $isDraft === 0) {
            // Ztc-009c/g/k: PATCH with only geldigheid fields is allowed on published types.
            if ($action === 'patch') {
                $metadataKeys = ['_route', 'zgwApi', 'resource', 'uuid', 'concept'];
                $allowedKeys  = ['eindeGeldigheid', 'beginGeldigheid', 'beginObject'];
                $contentKeys  = array_values(array_diff(array_keys($body), $metadataKeys, $allowedKeys));
                if (count($contentKeys) === 0 && array_key_exists('eindeGeldigheid', $body) === true) {
                    return null;
                }
            }

            $resourceLabel = rtrim($resource, 'n');
            $detail        = "Het is niet toegestaan om een {$resourceLabel} met concept=false ".$this->actionLabel(action: $action).'.';
            return $this->error(
                    status: 400,
                    detail: $detail,
                    invalidParams: [
                        $this->fieldError(name: 'nonFieldErrors', code: 'non-concept-object', reason: $detail),
                    ]
                    );
        }//end if

        return null;
    }//end checkDirectConceptProtection()

    /**
     * Get a Dutch action label for error messages.
     *
     * @param string $action The action
     *
     * @return string The Dutch label
     */
    private function actionLabel(string $action): string
    {
        return match ($action) {
            'update'  => 'bij te werken',
            'patch'   => 'deels bij te werken',
            'destroy' => 'te verwijderen',
            default   => 'aan te passen',
        };
    }//end actionLabel()

    /**
     * Validate brondatumArchiefprocedure cross-field constraints (ztc-003 to ztc-008).
     *
     * @param array      $archief           The brondatumArchiefprocedure data
     * @param array|null $selectielijstData The fetched selectielijstklasse data
     *
     * @return array<array{name: string, code: string, reason: string}> Validation errors
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function validateBrondatumArchief(array $archief, ?array $selectielijstData): array
    {
        $afleidingswijze = $archief['afleidingswijze'] ?? '';
        $errors          = [];

        // Ztc-004: datumkenmerk required/forbidden.
        $errors = array_merge(
            $errors,
            $this->validateFieldPresence(
                afleidingswijze: $afleidingswijze,
                fieldName: 'brondatumArchiefprocedure.datumkenmerk',
                fieldValue: ($archief['datumkenmerk'] ?? ''),
                requiredFor: self::AFLEIDINGSWIJZE_REQUIRES_DATUMKENMERK
            )
        );

        // Ztc-005: einddatumBekend must be false for afgehandeld/termijn.
        $einddatumBekend = $archief['einddatumBekend'] ?? false;
        if (($einddatumBekend === true || $einddatumBekend === 'true')
            && in_array($afleidingswijze, self::AFLEIDINGSWIJZE_FORBIDS_EINDDATUM_BEKEND, true) === true
        ) {
            $errors[] = $this->fieldError(
                name: 'brondatumArchiefprocedure.einddatumBekend',
                code: 'must-be-empty',
                reason: "einddatumBekend moet false zijn voor afleidingswijze \"{$afleidingswijze}\"."
            );
        }

        // Ztc-006: objecttype required/forbidden.
        $errors = array_merge(
            $errors,
            $this->validateFieldPresence(
                afleidingswijze: $afleidingswijze,
                fieldName: 'brondatumArchiefprocedure.objecttype',
                fieldValue: ($archief['objecttype'] ?? ''),
                requiredFor: self::AFLEIDINGSWIJZE_REQUIRES_OBJECTTYPE
            )
        );

        // Ztc-007: registratie required only for ander_datumkenmerk.
        $errors = array_merge(
            $errors,
            $this->validateFieldPresence(
                afleidingswijze: $afleidingswijze,
                fieldName: 'brondatumArchiefprocedure.registratie',
                fieldValue: ($archief['registratie'] ?? ''),
                requiredFor: ['ander_datumkenmerk']
            )
        );

        // Ztc-008: procestermijn required only for termijn.
        $procestermijn = $archief['procestermijn'] ?? null;
        if (is_string($procestermijn) === true) {
            $ptValue = $procestermijn;
        } else {
            $ptValue = '';
        }

        $errors = array_merge(
            $errors,
            $this->validateFieldPresence(
                afleidingswijze: $afleidingswijze,
                fieldName: 'brondatumArchiefprocedure.procestermijn',
                fieldValue: $ptValue,
                requiredFor: ['termijn']
            )
        );

        // Ztc-003: Validate afleidingswijze against selectielijstklasse.procestermijn.
        if ($selectielijstData !== null) {
            $slProcestermijn = $selectielijstData['procestermijn'] ?? null;
            $ptCheck         = $this->checkProcestermijnCompatibility(afleidingswijze: $afleidingswijze, procestermijn: $slProcestermijn);
            if ($ptCheck !== null) {
                $errors[] = $ptCheck;
            }
        }

        return $errors;
    }//end validateBrondatumArchief()

    /**
     * Enrich a resultaattype body with derived fields from external APIs (ztc-002b/f/g).
     *
     * - ztc-002b: Derive omschrijvingGeneriek from resultaattypeomschrijving.omschrijving
     * - ztc-002f: Derive archiefnominatie from selectielijstklasse.waardering
     * - ztc-002g: Derive archiefactietermijn from selectielijstklasse.bewaartermijn
     *
     * @param array      $body              The request body
     * @param array|null $selectielijstData The fetched selectielijstklasse data
     * @param array|null $rtoData           The fetched resultaattypeomschrijving data
     *
     * @return array The enriched body
     */
    private function enrichResultaattype(array $body, ?array $selectielijstData, ?array $rtoData): array
    {
        if ($rtoData !== null && empty($body['omschrijvingGeneriek']) === true) {
            $body['omschrijvingGeneriek'] = $rtoData['omschrijving'] ?? '';
        }

        if ($selectielijstData !== null && empty($body['archiefnominatie']) === true) {
            $waardering = $selectielijstData['waardering'] ?? null;
            if ($waardering !== null) {
                $body['archiefnominatie'] = $waardering;
            }
        }

        if ($selectielijstData !== null && empty($body['archiefactietermijn']) === true) {
            $bewaartermijn = $selectielijstData['bewaartermijn'] ?? null;
            if ($bewaartermijn !== null) {
                $body['archiefactietermijn'] = $bewaartermijn;
            }
        }

        return $body;
    }//end enrichResultaattype()

    /**
     * Validate selectielijstklasse procesType matches zaaktype selectielijstProcestype (ztc-002e).
     *
     * @param array $body              The request body (with zaaktype URL)
     * @param array $selectielijstData The fetched selectielijstklasse data
     *
     * @return array|null Validation error result, or null if valid
     */
    private function validateProcestypeMatch(array $body, array $selectielijstData): ?array
    {
        $zaaktypeUrl = $body['zaaktype'] ?? '';
        if (empty($zaaktypeUrl) === true || $this->objectService === null) {
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

        $zaaktypeProcestype = $ztData['selectionListProcessType'] ?? '';
        $selectieProcestype = $selectielijstData['procesType'] ?? '';

        if (empty($zaaktypeProcestype) === true || empty($selectieProcestype) === true) {
            return null;
        }

        if ($zaaktypeProcestype !== $selectieProcestype) {
            $detail = 'Het procestype van de selectielijstklasse komt niet overeen met het procestype van het zaaktype.';
            return $this->error(
                    status: 400,
                    detail: $detail,
                    invalidParams: [
                        $this->fieldError(name: 'nonFieldErrors', code: 'procestype-mismatch', reason: $detail),
                    ]
                    );
        }

        return null;
    }//end validateProcestypeMatch()

    /**
     * Validate field presence based on afleidingswijze (required vs forbidden).
     *
     * @param string        $afleidingswijze The afleidingswijze value
     * @param string        $fieldName       The full field path for error reporting
     * @param string        $fieldValue      The field value
     * @param array<string> $requiredFor     Afleidingswijze values that require this field
     *
     * @return array<array{name: string, code: string, reason: string}> Validation errors
     */
    private function validateFieldPresence(
        string $afleidingswijze,
        string $fieldName,
        string $fieldValue,
        array $requiredFor
    ): array {
        $hasValue = ($fieldValue !== '' && $fieldValue !== null);

        if (in_array($afleidingswijze, $requiredFor, true) === true) {
            if ($hasValue === false) {
                return [
                    $this->fieldError(
                        name: $fieldName,
                        code: 'required',
                        reason: "{$fieldName} is vereist voor afleidingswijze \"{$afleidingswijze}\"."
                    ),
                ];
            }
        } else {
            if ($hasValue === true) {
                return [
                    $this->fieldError(
                        name: $fieldName,
                        code: 'must-be-empty',
                        reason: "{$fieldName} mag niet ingevuld zijn voor afleidingswijze \"{$afleidingswijze}\"."
                    ),
                ];
            }
        }//end if

        return [];
    }//end validateFieldPresence()

    /**
     * Check afleidingswijze compatibility with selectielijstklasse.procestermijn (ztc-003).
     *
     * @param string      $afleidingswijze The afleidingswijze value
     * @param string|null $procestermijn   The selectielijstklasse procestermijn value
     *
     * @return array|null Field error array, or null if compatible
     */
    private function checkProcestermijnCompatibility(
        string $afleidingswijze,
        ?string $procestermijn
    ): ?array {
        if ($procestermijn === 'nihil' && $afleidingswijze !== 'afgehandeld') {
            return $this->fieldError(
                name: 'nonFieldErrors',
                code: 'invalid-afleidingswijze-for-procestermijn',
                reason: "Afleidingswijze \"{$afleidingswijze}\" is niet geldig bij selectielijstklasse met procestermijn \"nihil\"."
            );
        }

        if ($procestermijn === 'bestaansduur_procesobject' && $afleidingswijze !== 'termijn') {
            // phpcs:ignore Generic.Files.LineLength.MaxExceeded
            $reason = "Afleidingswijze \"{$afleidingswijze}\" is niet geldig bij selectielijstklasse met procestermijn \"bestaansduur_procesobject\".";
            return $this->fieldError(
                name: 'nonFieldErrors',
                code: 'invalid-afleidingswijze-for-procestermijn',
                reason: $reason
            );
        }

        if (($procestermijn === '' || $procestermijn === null) && $afleidingswijze === 'termijn') {
            // phpcs:ignore Generic.Files.LineLength.MaxExceeded
            $reason = 'brondatumArchiefprocedure.procestermijn is vereist voor afleidingswijze "termijn" maar selectielijstklasse heeft geen procestermijn.';
            return $this->fieldError(
                name: 'brondatumArchiefprocedure.procestermijn',
                code: 'required',
                reason: $reason
            );
        }

        return null;
    }//end checkProcestermijnCompatibility()

    /**
     * Resolve non-URL references in a type array field to actual object UUIDs.
     *
     * When a ZGW type resource has array fields like informatieobjecttypen
     * or besluittypen, VNG tests may send references by omschrijving instead
     * of URLs. This resolves those to the corresponding object UUIDs.
     *
     * @param array  $body        The request body
     * @param string $field       The field name containing the references
     * @param string $schemaKey   The settings config key for the target schema
     * @param string $lookupField The OpenRegister field to search by
     *
     * @return array The body with resolved references
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function resolveTypeReferences(
        array $body,
        string $field,
        string $schemaKey,
        string $lookupField
    ): array {
        if (isset($body[$field]) === false || is_array($body[$field]) === false
            || $this->objectService === null
        ) {
            return $body;
        }

        $register = $this->mappingConfig['sourceRegister'] ?? '';
        $schema   = $this->settingsService->getConfigValue(key: $schemaKey);

        if (empty($register) === true || empty($schema) === true) {
            return $body;
        }

        $resolved = [];
        foreach ($body[$field] as $ref) {
            if (is_string($ref) === false || $ref === '') {
                continue;
            }

            // If it's a URL containing a UUID, extract and store just the UUID.
            if (str_starts_with($ref, 'http://') === true
                || str_starts_with($ref, 'https://') === true
            ) {
                $urlUuid = $this->extractUuid(url: $ref);
                if ($urlUuid !== null) {
                    $resolved[] = $urlUuid;
                    continue;
                }
            }

            // Search by omschrijving/identificatie in OpenRegister.
            $foundIds = $this->findAllObjectsByField(register: $register, schema: $schema, field: $lookupField, value: $ref);
            if (empty($foundIds) === false) {
                foreach ($foundIds as $id) {
                    $resolved[] = $id;
                }

                continue;
            }

            // Fallback: if name lookup found nothing and it looks like a UUID, use as-is.
            $bareUuid = $this->extractUuid(url: $ref);
            if ($bareUuid !== null) {
                $resolved[] = $bareUuid;
            }
        }//end foreach

        $body[$field] = $resolved;

        return $body;
    }//end resolveTypeReferences()

    /**
     * Resolve gerelateerdeZaaktypen references (nested objects with zaaktype field).
     *
     * @param array $body The request body
     *
     * @return array The body with resolved zaaktype references
     */
    private function resolveGerelateerdeZaaktypen(array $body): array
    {
        if (isset($body['gerelateerdeZaaktypen']) === false
            || is_array($body['gerelateerdeZaaktypen']) === false
            || $this->objectService === null
        ) {
            return $body;
        }

        $register = $this->mappingConfig['sourceRegister'] ?? '';
        $schema   = $this->settingsService->getConfigValue(key: 'case_type_schema');

        if (empty($register) === true || empty($schema) === true) {
            return $body;
        }

        $resolved = [];
        foreach ($body['gerelateerdeZaaktypen'] as $rel) {
            $zaaktypeRef = $rel['zaaktype'] ?? '';
            if ($zaaktypeRef === '' || is_string($zaaktypeRef) === false) {
                continue;
            }

            if (str_starts_with($zaaktypeRef, 'http://') === true
                || str_starts_with($zaaktypeRef, 'https://') === true
            ) {
                $resolved[] = $rel;
                continue;
            }

            $foundIds = $this->findAllObjectsByField(register: $register, schema: $schema, field: 'identifier', value: $zaaktypeRef);
            foreach ($foundIds as $id) {
                $entry = $rel;
                $entry['zaaktype'] = $id;
                $resolved[]        = $entry;
            }
        }

        $body['gerelateerdeZaaktypen'] = $resolved;

        return $body;
    }//end resolveGerelateerdeZaaktypen()
}//end class
