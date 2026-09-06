<?php

/**
 * Dossiq ZGW ZTC (Catalogi) Business Rules Service
 *
 * Implements business rules for the Catalogi API as defined by VNG Realisatie.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/catalogi/
 *
 * Business rules implemented:
 *
 * - ztc-001: Valideren selectielijstProcestype op zaaktype
 * - ztc-002: Valideren selectielijstklasse + resultaattypeomschrijving (enrichment)
 *            — in ZgwZtcResultaattypeRules
 * - ztc-003: Valideren afleidingswijze vs selectielijstklasse.procestermijn
 *            — in BrondatumArchiefValidator
 * - ztc-004: Valideren datumkenmerk vereist/verboden op basis van afleidingswijze
 *            — in BrondatumArchiefValidator
 * - ztc-005: Valideren einddatumBekend verboden voor afgehandeld/termijn
 *            — in BrondatumArchiefValidator
 * - ztc-006: Valideren objecttype vereist/verboden op basis van afleidingswijze
 *            — in BrondatumArchiefValidator
 * - ztc-007: Valideren registratie vereist voor ander_datumkenmerk
 *            — in BrondatumArchiefValidator
 * - ztc-008: Valideren procestermijn vereist voor termijn afleidingswijze
 *            — in BrondatumArchiefValidator
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
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * ZTC (Catalogi API) business rule validation and enrichment.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class ZgwZtcRulesService extends ZgwRulesBase {
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
	 * @param string $resource The ZGW resource name
	 * @param string $action The action (create/update/patch/destroy)
	 * @param array $body The request body
	 * @param array|null $existingObject The existing object data
	 * @param bool|null $parentCaseTypeDraft Whether the parent zaaktype isDraft
	 *
	 * @return array|null Validation error result, or null if check passes
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/catalogi/
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/catalogi/
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function checkConceptProtection(
		string $resource,
		string $action,
		array $body,
		?array $existingObject,
		?bool $parentCaseTypeDraft,
	): ?array {
		// Ztc-009: Direct concept resources (zaaktypen, besluittypen, informatieobjecttypen).
		if (in_array($resource, self::CONCEPT_RESOURCES, true) === true) {
			return $this->checkDirectDraftProtection(
				resource: $resource,
				action: $action,
				body: $body,
				existingObject: $existingObject
			);
		}

		// Ztc-010: Sub-resources of zaaktypen.
		if (in_array($resource, self::ZAAKTYPE_SUB_RESOURCES, true) === true
			&& $parentCaseTypeDraft === false
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
						$this->fieldError(fieldName: 'nonFieldErrors', code: 'non-concept-zaaktype', reason: $detail),
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
	 * @param array $body The request body
	 * @param string $resource The resource name
	 *
	 * @return array The body with concept defaulted
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function defaultConcept(array $body, string $resource): array {
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
	 * @param array $body The request body
	 * @param string $resource The resource name
	 * @param array|null $existingObject The existing object data
	 *
	 * @return array The body with concept preserved
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function preserveConcept(array $body, string $resource, ?array $existingObject): array {
		if ($existingObject === null
			|| in_array($resource, self::CONCEPT_RESOURCES, true) === false
		) {
			return $body;
		}

		$existingDraft = $existingObject['isDraft'] ?? ($existingObject['concept'] ?? true);
		if ($existingDraft === 'true' || $existingDraft === '1' || $existingDraft === 1) {
			$existingDraft = true;
		} elseif ($existingDraft === 'false' || $existingDraft === '0' || $existingDraft === 0) {
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
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) — ZGW business rules validation
	 * @SuppressWarnings(PHPMD.NPathComplexity)      — ZGW business rules validation
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function rulesZaaktypenCreate(array $body): array {
		// Ztc-001: Validate selectielijstProcestype URL.
		$procesTypeUrl = $body['selectielijstProcestype'] ?? '';
		if (empty($procesTypeUrl) === false) {
			$procesTypeData = $this->fetchExternalUrl(url: $procesTypeUrl);
			if ($procesTypeData === null || isset($procesTypeData['nummer']) === false) {
				return $this->error(
					status: 400,
					detail: 'De selectielijstProcestype URL is ongeldig of wijst niet naar een procestype resource.',
					invalidParams: [$this->fieldError(
						fieldName: 'selectielijstProcestype',
						code: 'invalid-resource',
						reason: 'De selectielijstProcestype URL is ongeldig of wijst niet naar een procestype resource.'
					)
					]
				);
			}
		}

		// Resolve reference arrays by omschrijving/identificatie to UUIDs.
		$body = $this->resolveTypeReferences(
			body: $body,
			field: 'informatieobjecttypen',
			schemaKey: 'document_type_schema',
			lookupField: 'name'
		);
		$body = $this->resolveTypeReferences(
			body: $body,
			field: 'besluittypen',
			schemaKey: 'decision_type_schema',
			lookupField: 'name'
		);
		$body = $this->resolveTypeReferences(
			body: $body,
			field: 'deelzaaktypen',
			schemaKey: 'case_type_schema',
			lookupField: 'identifier'
		);
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

		return $this->isValid(body: $body);
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
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function rulesBesluittypenCreate(array $body): array {
		// Resolve reference arrays by omschrijving/identificatie to UUIDs.
		$body = $this->resolveTypeReferences(
			body: $body,
			field: 'informatieobjecttypen',
			schemaKey: 'document_type_schema',
			lookupField: 'name'
		);
		$body = $this->resolveTypeReferences(
			body: $body,
			field: 'zaaktypen',
			schemaKey: 'case_type_schema',
			lookupField: 'identifier'
		);

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

		return $this->isValid(body: $body);
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
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) — ZGW resolution of omschrijving/UUID/URL
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function rulesZaaktypeinformatieobjecttypenCreate(array $body): array {
		// Resolve informatieobjecttype: omschrijving → UUID, or bare UUID → verify.
		$iotRef = $body['informatieobjecttype'] ?? '';
		if ($iotRef !== '' && $this->objectService !== null) {
			$register = $this->mappingConfig['sourceRegister'] ?? '';
			$schema = $this->settingsService->getConfigValue(key: 'document_type_schema');

			if (empty($register) === false && empty($schema) === false) {
				$uuid = $this->extractUuid(url: $iotRef);

				// If the value is a URL containing a UUID, keep as-is (reverse mapping extracts it).
				$isUrl = (str_starts_with($iotRef, 'http://') === true
					|| str_starts_with($iotRef, 'https://') === true);

				$needsNameLookup = false;
				if ($isUrl === true) {
					// URL — let reverse mapping handle UUID extraction.
				} elseif ($uuid !== null) {
					// Bare UUID — verify it exists; if not, treat as omschrijving.
					$existing = $this->findBySchemaKey(uuid: $uuid, schemaKey: 'document_type_schema');
					$needsNameLookup = ($existing === null);
				}

				if ($isUrl === false && ($uuid === null || $needsNameLookup === true)) {
					// Not a URL, or bare UUID that didn't resolve — resolve by name.
					$found = $this->findObjectByField(
						register: $register,
						schema: $schema,
						field: 'name',
						value: $iotRef
					);
					if ($found !== null) {
						$body['informatieobjecttype'] = $found;
					}
				}//end if
			}//end if
		}//end if

		return $this->isValid(body: $body);
	}//end rulesZaaktypeinformatieobjecttypenCreate()

	/**
	 * Check if a direct concept resource is published (ztc-009).
	 *
	 * Published types (concept=false) cannot be modified or deleted,
	 * except PATCH with only eindeGeldigheid.
	 *
	 * @param string $resource The resource name
	 * @param string $action The action
	 * @param array $body The request body
	 * @param array|null $existingObject The existing object data
	 *
	 * @return array|null Validation error, or null if OK
	 */
	private function checkDirectDraftProtection(
		string $resource,
		string $action,
		array $body,
		?array $existingObject,
	): ?array {
		if ($existingObject === null) {
			return null;
		}

		$isDraft = $existingObject['isDraft'] ?? ($existingObject['concept'] ?? true);
		if ($isDraft === 'false' || $isDraft === false || $isDraft === '0' || $isDraft === 0) {
			// Ztc-009c/g/k: PATCH with only geldigheid fields is allowed on published types.
			if ($action === 'patch') {
				$metadataKeys = ['_route', 'zgwApi', 'resource', 'uuid', 'concept'];
				$allowedKeys = ['endValidity', 'startValidity', 'beginObject'];
				$contentKeys = array_values(array_diff(array_keys($body), $metadataKeys, $allowedKeys));
				if (count($contentKeys) === 0 && array_key_exists('endValidity', $body) === true) {
					return null;
				}
			}

			$resourceLabel = rtrim($resource, 'n');
			$detail = "Het is niet toegestaan om een {$resourceLabel} met concept=false " . $this->actionLabel(action: $action) . '.';
			return $this->error(
				status: 400,
				detail: $detail,
				invalidParams: [
					$this->fieldError(fieldName: 'nonFieldErrors', code: 'non-concept-object', reason: $detail),
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
	private function actionLabel(string $action): string {
		return match ($action) {
			'update' => 'bij te werken',
			'patch' => 'deels bij te werken',
			'destroy' => 'te verwijderen',
			default => 'aan te passen',
		};
	}//end actionLabel()

	/**
	 * Resolve non-URL references in a type array field to actual object UUIDs.
	 *
	 * When a ZGW type resource has array fields like informatieobjecttypen
	 * or besluittypen, VNG tests may send references by omschrijving instead
	 * of URLs. This resolves those to the corresponding object UUIDs.
	 *
	 * @param array $body The request body
	 * @param string $field The field name containing the references
	 * @param string $schemaKey The settings config key for the target schema
	 * @param string $lookupField The OpenRegister field to search by
	 *
	 * @return array The body with resolved references
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	private function resolveTypeReferences(
		array $body,
		string $field,
		string $schemaKey,
		string $lookupField,
	): array {
		if (isset($body[$field]) === false || is_array($body[$field]) === false
			|| $this->objectService === null
		) {
			return $body;
		}

		$register = $this->mappingConfig['sourceRegister'] ?? '';
		$schema = $this->settingsService->getConfigValue(key: $schemaKey);

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
			$foundIds = $this->findAllObjectsByField(
				register: $register,
				schema: $schema,
				field: $lookupField,
				value: $ref
			);
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
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) — nested object resolution
	 * @SuppressWarnings(PHPMD.NPathComplexity)      — nested object resolution
	 */
	private function resolveGerelateerdeZaaktypen(array $body): array {
		if (isset($body['gerelateerdeZaaktypen']) === false
			|| is_array($body['gerelateerdeZaaktypen']) === false
			|| $this->objectService === null
		) {
			return $body;
		}

		$register = $this->mappingConfig['sourceRegister'] ?? '';
		$schema = $this->settingsService->getConfigValue(key: 'case_type_schema');

		if (empty($register) === true || empty($schema) === true) {
			return $body;
		}

		$resolved = [];
		foreach ($body['gerelateerdeZaaktypen'] as $rel) {
			$caseTypeRef = $rel['caseType'] ?? '';
			if ($caseTypeRef === '' || is_string($caseTypeRef) === false) {
				continue;
			}

			if (str_starts_with($caseTypeRef, 'http://') === true
				|| str_starts_with($caseTypeRef, 'https://') === true
			) {
				$resolved[] = $rel;
				continue;
			}

			$foundIds = $this->findAllObjectsByField(
				register: $register,
				schema: $schema,
				field: 'identifier',
				value: $caseTypeRef
			);
			foreach ($foundIds as $id) {
				$entry = $rel;
				$entry['caseType'] = $id;
				$resolved[] = $entry;
			}
		}//end foreach

		$body['gerelateerdeZaaktypen'] = $resolved;

		return $body;
	}//end resolveGerelateerdeZaaktypen()

	/**
	 * Validate a caseType is publishable (isDraft true → false).
	 *
	 * REQ-CT-02b. Loads the case type's statusType objects and verifies
	 * preconditions: at least one statusType exists, at least one is final,
	 * and the case type itself has a validFrom date.
	 *
	 * @param string $register Register slug.
	 * @param string $caseTypeId Case type id.
	 *
	 * @return array<int, string> Error strings (empty = valid).
	 *
	 * @spec openspec/changes/case-types-02-backend-validation/tasks.md#task-ct-08
	 */
	public function validatePublish(string $register, string $caseTypeId): array {
		$errors = [];
		if ($this->objectService === null) {
			$errors[] = 'Cannot validate publish: OpenRegister object service unavailable';
			return $errors;
		}

		if ($caseTypeId === '') {
			$errors[] = 'Cannot validate publish: case type id is empty';
			return $errors;
		}

		$statusSchema = (string)$this->settingsService->getConfigValue(key: 'status_type_schema');
		$caseSchema = (string)$this->settingsService->getConfigValue(key: 'case_type_schema');

		try {
			$statusTypes = $this->searchObjectsAsArrays(
				objectService: $this->objectService,
				register: $register,
				schema: $statusSchema,
				filters: ['caseType' => $caseTypeId],
			);
		} catch (\Throwable $e) {
			$errors[] = 'Could not load status types for case type';
			return $errors;
		}

		if (count($statusTypes) === 0) {
			$errors[] = 'At least one status type must be defined before publishing';
		}

		if (count($statusTypes) > 0 && $this->hasFinalStatusType(statusTypes: $statusTypes) === false) {
			$errors[] = 'At least one status type must be marked as final';
		}

		$caseType = $this->loadCaseTypeRow(
			objectService: $this->objectService,
			register: $register,
			schema: $caseSchema,
			caseTypeId: $caseTypeId,
		);

		$validFrom = (string)($caseType['validFrom'] ?? '');
		if ($validFrom === '') {
			$errors[] = "'Valid from' date must be set before publishing";
		}

		return $errors;
	}//end validatePublish()

	/**
	 * Test whether any of the supplied statusType rows is marked final.
	 *
	 * @param array<int, mixed> $statusTypes The statusType rows for a case type.
	 *
	 * @return bool True when at least one row carries isFinal.
	 */
	private function hasFinalStatusType(array $statusTypes): bool {
		foreach ($statusTypes as $row) {
			if (is_array($row) === true && (bool)($row['isFinal'] ?? false) === true) {
				return true;
			}
		}

		return false;
	}//end hasFinalStatusType()

	/**
	 * Load the caseType row itself, returning an empty array when it cannot be read.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register Register slug.
	 * @param string $schema Case type schema slug.
	 * @param string $caseTypeId Case type id.
	 *
	 * @return array<string, mixed> The case type row, or an empty array.
	 */
	private function loadCaseTypeRow(object $objectService, string $register, string $schema, string $caseTypeId): array {
		// A top-level `['id' => $caseTypeId]` filter does not resolve in
		// OpenRegister (ids are metadata, not schema properties) and silently
		// matches nothing. The get-by-uuid path resolves the id directly.
		try {
			$caseType = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseTypeId
			);
		} catch (\Throwable $e) {
			return [];
		}

		return ($caseType ?? []);
	}//end loadCaseTypeRow()

	/**
	 * Validate a caseType can be safely deleted (no active cases).
	 *
	 * REQ-CT-01d. Returns a triple-shape result:
	 *
	 *   ['blocked' => bool, 'requiresConfirmation' => bool, 'message' => string]
	 *
	 * - blocked=true   → 409 Conflict; active cases exist.
	 * - requiresConfirmation=true → 200 OK with confirmation prompt (closed-only cases).
	 * - otherwise → safe to delete.
	 *
	 * @param string $register Register slug.
	 * @param string $caseTypeId Case type id.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/case-types-02-backend-validation/tasks.md#task-ct-09
	 */
	public function validateDeletion(string $register, string $caseTypeId): array {
		$default = ['blocked' => false, 'requiresConfirmation' => false, 'message' => ''];
		if ($this->objectService === null || $caseTypeId === '') {
			return $default;
		}

		$caseSchema = (string)$this->settingsService->getConfigValue(key: 'case_schema');

		try {
			$cases = $this->searchObjectsAsArrays(
				objectService: $this->objectService,
				register: $register,
				schema: $caseSchema,
				filters: ['caseType' => $caseTypeId],
			);
		} catch (\Throwable $e) {
			return $default;
		}

		if (count($cases) === 0) {
			return $default;
		}

		$finalSlugs = $this->loadFinalStatusSlugs(
			objectService: $this->objectService,
			register: $register,
			caseTypeId: $caseTypeId,
		);

		$tally = $this->tallyCaseClosure(cases: $cases, finalSlugs: $finalSlugs);
		$activeCount = $tally['active'];
		$closedCount = $tally['closed'];

		if ($activeCount > 0) {
			return [
				'blocked' => true,
				'requiresConfirmation' => false,
				'message' => "Cannot delete case type: $activeCount active case(s) still use this type. "
					. 'Close or reassign all cases first.',
			];
		}

		return [
			'blocked' => false,
			'requiresConfirmation' => true,
			'message' => "Deleting will affect $closedCount closed case(s). Confirm to proceed.",
		];
	}//end validateDeletion()

	/**
	 * Load the ids of the final statusTypes of a case type, or an empty list when unreadable.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register Register slug.
	 * @param string $caseTypeId Case type id.
	 *
	 * @return array<int, string> The final statusType ids.
	 */
	private function loadFinalStatusSlugs(object $objectService, string $register, string $caseTypeId): array {
		$statusSchema = (string)$this->settingsService->getConfigValue(key: 'status_type_schema');
		try {
			$finalStatusTypes = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $statusSchema,
				filters: ['caseType' => $caseTypeId, 'isFinal' => true],
			);
		} catch (\Throwable $e) {
			return [];
		}

		$finalSlugs = [];
		foreach ($finalStatusTypes as $row) {
			if (is_array($row) === true && isset($row['id']) === true) {
				$finalSlugs[] = (string)$row['id'];
			}
		}

		return $finalSlugs;
	}//end loadFinalStatusSlugs()

	/**
	 * Split a case type's cases into closed (their status is one of the final statusTypes) and
	 * active (everything else, including cases with no status at all).
	 *
	 * @param array<int, mixed> $cases The cases that use the case type.
	 * @param array<int, string> $finalSlugs The final statusType ids.
	 *
	 * @return array{active: int, closed: int} The tallies.
	 */
	private function tallyCaseClosure(array $cases, array $finalSlugs): array {
		$activeCount = 0;
		$closedCount = 0;
		foreach ($cases as $case) {
			$caseStatus = (string)($case['status'] ?? '');
			if ($caseStatus !== '' && in_array($caseStatus, $finalSlugs, true) === true) {
				$closedCount++;
				continue;
			}

			$activeCount++;
		}

		return ['active' => $activeCount, 'closed' => $closedCount];
	}//end tallyCaseClosure()
}//end class
