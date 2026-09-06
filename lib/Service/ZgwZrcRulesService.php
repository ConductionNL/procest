<?php

/**
 * Dossiq ZGW ZRC (Zaken) Business Rules Service
 *
 * Implements business rules for the Zaken API as defined by VNG Realisatie.
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
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
 *
 * Business rules implemented:
 *
 * - zrc-001: Valideren zaaktype op de Zaak-resource
 * - zrc-002: Garanderen uniciteit bronorganisatie en identificatie
 * - zrc-003: Valideren informatieobject op ZaakInformatieObject (in ZgwZrcZaakinformatieobjectRules)
 * - zrc-004: Zetten relatieinformatie op ZaakInformatieObject (in ZgwZrcZaakinformatieobjectRules)
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
 * - zrc-017: Valideren informatieobjecttype bij Zaak.zaaktype (in ZgwZrcZaakinformatieobjectRules)
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

namespace OCA\Dossiq\Service;

/**
 * ZRC (Zaken API) business rule validation and enrichment.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class ZgwZrcRulesService extends ZgwRulesBase {
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
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function rulesZakenCreate(array $body): array {
		// Zrc-001: Validate zaaktype URL.
		$caseTypeUrl = $body['caseType'] ?? '';
		$error = $this->validateCaseTypeReference(caseTypeUrl: $caseTypeUrl);
		if ($error !== null) {
			return $error;
		}

		// Zrc-002: Check unique identificatie + bronorganisatie.
		if (empty($body['identificatie']) === false) {
			$error = $this->checkFieldUniqueness(
				field1Value: $body['identificatie'],
				field1Search: 'identifier',
				field2Value: $body['bronorganisatie'] ?? '',
				field2Search: 'sourceOrganisation',
				errorField: 'identificatie'
			);
			if ($error !== null) {
				return $error;
			}
		}

		// Zrc-002: Auto-generate identificatie if not provided.
		if (empty($body['identificatie']) === true) {
			$body['identificatie'] = $this->generateIdentificatie(prefix: 'ZAAK');
		}

		// Zrc-009: Derive vertrouwelijkheidaanduiding from zaaktype — always override
		// template defaults to prevent leakage (incoming value used only as fallback).
		if (empty($caseTypeUrl) === false) {
			$body = $this->deriveVertrouwelijkheidaanduiding(body: $body, caseTypeUrl: $caseTypeUrl);
		}

		// Zrc-022: Set default archiefstatus.
		if (empty($body['archiefstatus']) === true) {
			$body['archiefstatus'] = 'nog_te_archiveren';
		}

		// Intake channel: mark ZGW API as source channel.
		if (empty($body['intakeChannel']) === true) {
			$body['intakeChannel'] = 'zgw-api';
		}

		// Auto-assign handler from zaaktype defaultAssignee if no handler set.
		$body = $this->applyDefaultAssignee(body: $body, caseTypeUrl: $caseTypeUrl);

		return $this->validateCaseFields(result: $this->isValid(body: $body), existingObject: null, isPatch: false);
	}//end rulesZakenCreate()

	/**
	 * Validate the zaaktype reference on a create body (zrc-001).
	 *
	 * Returns null when there is nothing to validate — no zaaktype was supplied, or OpenRegister
	 * is unavailable — which is exactly what the inline guard did.
	 *
	 * @param mixed $caseTypeUrl The `zaaktype` value from the request body
	 *
	 * @return array|null The validation error, or null when the reference is acceptable
	 */
	private function validateCaseTypeReference(mixed $caseTypeUrl): ?array {
		if (empty($caseTypeUrl) === true || $this->objectService === null) {
			return null;
		}

		return $this->validateTypeUrl(
			typeUrl: $caseTypeUrl,
			fieldName: 'caseType',
			schemaKey: 'case_type_schema'
		);
	}//end validateZaaktypeReference()

	/**
	 * Stamp the zaaktype's `defaultAssignee` on a zaak that carries no handler yet.
	 *
	 * @param array $body The ZGW request body
	 * @param mixed $caseTypeUrl The `zaaktype` value from the request body
	 *
	 * @return array The body, with `assignee` filled in when one could be resolved
	 */
	private function applyDefaultAssignee(array $body, mixed $caseTypeUrl): array {
		if (empty($body['assignee']) === false || empty($caseTypeUrl) === true || $this->objectService === null) {
			return $body;
		}

		$assignee = $this->caseTypeDefaultAssignee(objectService: $this->objectService, caseTypeUrl: $caseTypeUrl);
		if ($assignee !== null) {
			$body['assignee'] = $assignee;
		}

		return $body;
	}//end applyDefaultAssignee()

	/**
	 * Read the `defaultAssignee` off the zaaktype a zaak points at.
	 *
	 * Returns null whenever the zaaktype cannot be resolved or declares no default — a lookup
	 * failure is swallowed, exactly as the inline block did.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param mixed $caseTypeUrl The `zaaktype` value from the request body
	 *
	 * @return mixed The default assignee, or null when there is none
	 */
	private function caseTypeDefaultAssignee(object $objectService, mixed $caseTypeUrl): mixed {
		$extractedUuid = $this->extractUuid(url: $caseTypeUrl);
		if ($extractedUuid === null) {
			return null;
		}

		$register = $this->mappingConfig['sourceRegister'] ?? '';
		$schema = $this->settingsService->getConfigValue(key: 'case_type_schema');
		if (empty($register) === true || empty($schema) === true) {
			return null;
		}

		try {
			$caseType = $objectService->find(
				id: $extractedUuid,
				register: $register,
				schema: $schema
			);

			$ztData = $caseType;
			if (is_array($caseType) === false) {
				$ztData = $caseType->jsonSerialize();
			}

			if (empty($ztData['defaultAssignee']) === false) {
				return $ztData['defaultAssignee'];
			}
		} catch (\Throwable $e) {
			// Zaaktype not found; skip auto-assignment.
			return null;
		}//end try

		return null;
	}//end zaaktypeDefaultAssignee()

	/**
	 * Rules for updating a zaak (PUT /zaken/v1/zaken/{uuid}).
	 *
	 * Implements shared zaak field validations.
	 *
	 * @param array $body The ZGW request body
	 * @param array|null $existingObject The existing zaak data
	 *
	 * @return array The validation result
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function rulesZakenUpdate(array $body, ?array $existingObject = null): array {
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

		// Zrc-009: Derive vertrouwelijkheidaanduiding from zaaktype — always override.
		$caseTypeUrl = $body['caseType'] ?? '';
		if (empty($caseTypeUrl) === false) {
			$body = $this->deriveVertrouwelijkheidaanduiding(body: $body, caseTypeUrl: $caseTypeUrl);
		}

		return $this->validateCaseFields(
			result: $this->isValid(body: $body),
			existingObject: $existingObject,
			isPatch: false
		);
	}//end rulesZakenUpdate()

	/**
	 * Rules for patching a zaak (PATCH /zaken/v1/zaken/{uuid}).
	 *
	 * @param array $body The ZGW request body
	 * @param array|null $existingObject The existing zaak data
	 *
	 * @return array The validation result
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function rulesZakenPatch(array $body, ?array $existingObject = null): array {
		// Zrc-009: Derive vertrouwelijkheidaanduiding from zaaktype if not set.
		// For PATCH, the zaaktype might not be in the body — check existing object.
		$caseTypeUrl = $body['caseType'] ?? '';
		if ($caseTypeUrl === '' && $existingObject !== null) {
			$caseType = $existingObject['caseType'] ?? '';
			if ($caseType !== '') {
				$caseTypeUrl = $caseType;
			}
		}

		// Ensure zaaktype is available in body for downstream validations
		// (zrc-010, zrc-015) that need the zaaktype URL from the existing object.
		if (($body['caseType'] ?? '') === '' && $caseTypeUrl !== '') {
			$body['caseType'] = $caseTypeUrl;
		}

		// Zrc-009: Always override from zaaktype to prevent template leakage.
		if (empty($caseTypeUrl) === false) {
			$body = $this->deriveVertrouwelijkheidaanduiding(body: $body, caseTypeUrl: $caseTypeUrl);
		}

		return $this->validateCaseFields(
			result: $this->isValid(body: $body),
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
	 * NOTE: From `status-transition-engine` onwards, the actual `case.status`
	 * mutation is owned by `StatusTransitionService`. This method retains
	 * the zrc-016 ZGW request-shape validation only; callers that want a
	 * `statusRecord` written for every status mutation should funnel through
	 * `StatusTransitionService::execute()` or `executeFreeForm()`. The legacy
	 * write path remains for ZGW API contract compatibility.
	 *
	 * @param array $body The ZGW request body
	 *
	 * @return array The validation result
	 *
	 * @spec openspec/changes/status-transition-engine/tasks.md#T13
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
	 */
	public function rulesStatussenCreate(array $body): array {
		// Zrc-016: Validate statustype belongs to zaak's zaaktype.
		$statustypeUrl = $body['statustype'] ?? '';
		$caseUrl = $body['case'] ?? '';
		if ($statustypeUrl !== '' && $caseUrl !== '') {
			$error = $this->validateSubResourceType(
				caseUrl: $caseUrl,
				typeUrl: $statustypeUrl,
				fieldName: 'statustype',
				typeSchemaKey: 'status_type_schema',
				caseTypeField: 'statusTypes'
			);
			if ($error !== null) {
				return $error;
			}
		}

		return $this->isValid(body: $body);
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
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function rulesResultatenCreate(array $body): array {
		// Zrc-020: Validate resultaattype belongs to zaak's zaaktype.
		$resultaattypeUrl = $body['resultaattype'] ?? '';
		$caseUrl = $body['case'] ?? '';
		if ($resultaattypeUrl !== '' && $caseUrl !== '') {
			$error = $this->validateSubResourceType(
				caseUrl: $caseUrl,
				typeUrl: $resultaattypeUrl,
				fieldName: 'resultaattype',
				typeSchemaKey: 'result_type_schema',
				caseTypeField: 'resultTypes'
			);
			if ($error !== null) {
				return $error;
			}
		}

		return $this->isValid(body: $body);
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
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function rulesRollenCreate(array $body): array {
		// Zrc-019: Validate roltype belongs to zaak's zaaktype.
		$roltypeUrl = $body['roltype'] ?? '';
		$caseUrl = $body['case'] ?? '';
		if ($roltypeUrl !== '' && $caseUrl !== '') {
			$error = $this->validateSubResourceType(
				caseUrl: $caseUrl,
				typeUrl: $roltypeUrl,
				fieldName: 'roltype',
				typeSchemaKey: 'role_type_schema',
				caseTypeField: 'roleTypes'
			);
			if ($error !== null) {
				return $error;
			}
		}

		return $this->isValid(body: $body);
	}//end rulesRollenCreate()

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
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function rulesZaakeigenschappenCreate(array $body): array {
		// Zrc-018: Validate eigenschap belongs to zaak's zaaktype.
		$attributeUrl = $body['eigenschap'] ?? '';
		$caseUrl = $body['case'] ?? '';
		if ($attributeUrl !== '' && $caseUrl !== '') {
			$error = $this->validateSubResourceType(
				caseUrl: $caseUrl,
				typeUrl: $attributeUrl,
				fieldName: 'eigenschap',
				typeSchemaKey: 'property_definition_schema',
				caseTypeField: 'propertyDefinitions'
			);
			if ($error !== null) {
				return $error;
			}
		}

		return $this->isValid(body: $body);
	}//end rulesZaakeigenschappenCreate()

	/**
	 * Derive vertrouwelijkheidaanduiding from zaaktype (zrc-009).
	 *
	 * The zaaktype's vertrouwelijkheidaanduiding ALWAYS overrides any value from
	 * the request or mapping template to prevent template leakage. The incoming
	 * value is only used as a fallback when the zaaktype field is absent.
	 *
	 * @param array $body The request body
	 * @param string $caseTypeUrl The zaaktype URL
	 *
	 * @return array The body with derived vertrouwelijkheidaanduiding
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
	 */
	private function deriveVertrouwelijkheidaanduiding(array $body, string $caseTypeUrl): array {
		$uuid = $this->extractUuid(url: $caseTypeUrl);
		if ($uuid === null) {
			return $body;
		}

		$ztData = $this->findBySchemaKey(uuid: $uuid, schemaKey: 'case_type_schema');
		if ($ztData === null) {
			return $body;
		}

		// Zrc-009: zaaktype value always overrides template default to prevent leakage.
		$val = $ztData['confidentiality'] ?? ($ztData['confidentialityDesignation'] ?? ($ztData['vertrouwelijkheidaanduiding'] ?? ''));
		if ($val !== '') {
			$body['vertrouwelijkheidaanduiding'] = $val;
		}

		// When zaaktype has no value, preserve any incoming value (fallback).
		return $body;
	}//end deriveVertrouwelijkheidaanduiding()

	/**
	 * Validate a sub-resource type belongs to the zaak's zaaktype (zrc-016..020).
	 *
	 * Checks that the given type URL's UUID is present in the zaak's
	 * zaaktype's corresponding type list.
	 *
	 * @param string $caseUrl The zaak URL
	 * @param string $typeUrl The sub-resource type URL (statustype, roltype, etc.)
	 * @param string $fieldName The field name for error reporting
	 * @param string $typeSchemaKey Settings key for the type's schema
	 * @param string $caseTypeField The zaaktype field containing allowed type UUIDs
	 *
	 * @return array|null Validation error, or null if valid
	 *
	 * @psalm-suppress UnusedParam — $caseTypeField reserved for future filtering
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) — $caseTypeField reserved for future filtering
	 * @SuppressWarnings(PHPMD.NPathComplexity)       — cross-register validation with multiple lookups
	 */
	private function validateSubResourceType(
		string $caseUrl,
		string $typeUrl,
		string $fieldName,
		string $typeSchemaKey,
		string $caseTypeField,
	): ?array {
		if ($this->objectService === null) {
			return null;
		}

		// Look up the zaak to get its zaaktype.
		$zaakUuid = $this->extractUuid(url: $caseUrl);
		if ($zaakUuid === null) {
			return null;
		}

		$caseData = $this->findBySchemaKey(uuid: $zaakUuid, schemaKey: 'case_schema');
		if ($caseData === null) {
			return null;
		}

		$caseTypeId = $caseData['caseType'] ?? '';
		if (empty($caseTypeId) === true) {
			return null;
		}

		$zaaktypeUuid = $this->extractUuid(url: (string)$caseTypeId);
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

		$typeCaseType = $typeData['caseType'] ?? '';
		$typeCaseTypeUuid = $this->extractUuid(url: (string)$typeCaseType);

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
	 * @param array $result The current validation result
	 * @param array|null $existingObject The existing object data
	 * @param bool $isPatch Whether this is a PATCH operation
	 *
	 * @return array The updated validation result
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 *
	 * @psalm-suppress UnusedParam — $isPatch reserved for partial-update field validation
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) — $isPatch reserved for partial-update validation
	 */
	private function validateCaseFields(array $result, ?array $existingObject, bool $isPatch): array {
		$body = $result['enrichedBody'];

		// Zrc-002: Identificatie immutability on update/patch.
		if ($existingObject !== null && isset($body['identificatie']) === true) {
			$existingId = $existingObject['identifier'] ?? ($existingObject['identificatie'] ?? '');
			if ($existingId !== '' && $body['identificatie'] !== $existingId) {
				return $this->fieldImmutableError(fieldName: 'identificatie');
			}
		}

		// Zrc-010: Validate communicatiekanaal URL.
		$commChannel = $body['communicatiekanaal'] ?? null;
		if ($commChannel !== null && $commChannel !== '') {
			if (filter_var($commChannel, FILTER_VALIDATE_URL) === false) {
				return $this->error(
					status: 400,
					detail: 'De communicatiekanaal URL is ongeldig.',
					invalidParams: [
						$this->fieldError(
							fieldName: 'communicatiekanaal',
							code: 'bad-url',
							reason: 'De communicatiekanaal URL is ongeldig.'
						),
					]
				);
			}

			if ($this->isValidUrl(url: $commChannel) === false) {
				// Zrc-010: URL is syntactically valid but does not point to a specific
				// resource (no UUID path segment) → VNG requires 'invalid-resource'.
				return $this->error(
					status: 400,
					detail: 'De communicatiekanaal URL is ongeldig.',
					invalidParams: [
						$this->fieldError(
							fieldName: 'communicatiekanaal',
							code: 'invalid-resource',
							reason: 'De communicatiekanaal URL wijst niet naar een geldig object.'
						),
					]
				);
			}//end if
		}//end if

		// Zrc-011: Validate relevanteAndereZaken URLs.
		$relevanteCases = $body['relevanteAndereZaken'] ?? null;
		if (is_array($relevanteCases) === true) {
			foreach ($relevanteCases as $idx => $relCase) {
				$relUrl = $relCase['url'] ?? '';
				if ($relUrl !== '' && $this->isValidUrl(url: $relUrl) === false) {
					return $this->error(
						status: 400,
						detail: 'relevanteAndereZaken bevat een ongeldige URL.',
						invalidParams: [$this->fieldError(
							fieldName: "relevanteAndereZaken.{$idx}.url",
							code: 'bad-url',
							reason: 'De URL is ongeldig.'
						)
						]
					);
				}
			}
		}

		// Zrc-012: Validate opschorting.
		$suspension = $body['suspension'] ?? null;
		if (is_array($suspension) === true) {
			$errors = [];
			if (($suspension['indicatie'] ?? null) === null) {
				$errors[] = $this->fieldError(
					fieldName: 'opschorting.indicatie',
					code: 'required',
					reason: 'Indicatie is vereist bij opschorting.'
				);
			}

			if (($suspension['reason'] ?? '') === '') {
				$errors[] = $this->fieldError(
					fieldName: 'opschorting.reden',
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
		$extension = $body['verlenging'] ?? null;
		if (is_array($extension) === true) {
			$errors = [];
			if (($extension['reason'] ?? '') === '') {
				$errors[] = $this->fieldError(
					fieldName: 'verlenging.reden',
					code: 'required',
					reason: 'Reden is vereist bij verlenging.'
				);
			}

			if (($extension['duur'] ?? '') === '') {
				$errors[] = $this->fieldError(
					fieldName: 'verlenging.duur',
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
				$selfUuid = $existingObject['id'] ?? ($existingObject['@self']['id'] ?? null);
				$hoofdzaakUuid = $this->extractUuid(url: $hoofdzaak);
				if ($selfUuid !== null && $hoofdzaakUuid !== null && $selfUuid === $hoofdzaakUuid) {
					return $this->error(
						status: 400,
						detail: 'Een zaak kan niet zijn eigen hoofdzaak zijn.',
						invalidParams: [$this->fieldError(
							fieldName: 'hoofdzaak',
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
		$lastPaid = $body['laatsteBetaaldatum'] ?? null;

		// On update/patch, also consider existing values when not explicitly sent.
		if ($betalingsindicatie === null && $existingObject !== null) {
			$betalingsindicatie = $existingObject['paymentIndication'] ?? ($existingObject['betalingsindicatie'] ?? null);
		}

		if ($lastPaid === null && $existingObject !== null) {
			$lastPaid = $existingObject['lastPaymentDate'] ?? ($existingObject['laatsteBetaaldatum'] ?? null);
		}

		if ($betalingsindicatie === 'nvt' && $lastPaid !== null && $lastPaid !== '') {
			// On create: reject (cannot set date with nvt).
			if ($existingObject === null) {
				return $this->error(
					status: 400,
					detail: 'Als betalingsindicatie "nvt" is, mag laatsteBetaaldatum niet gezet worden.',
					invalidParams: [$this->fieldError(
						fieldName: 'laatsteBetaaldatum',
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
						fieldName: 'archiefnominatie',
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
						fieldName: 'archiefactiedatum',
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
	private function validateHoofdzaakNesting(string $hoofdzaakUrl): ?array {
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
					fieldName: 'hoofdzaak',
					code: 'does-not-exist',
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
					fieldName: 'hoofdzaak',
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
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) — ZGW business rules validation
	 * @SuppressWarnings(PHPMD.NPathComplexity)      — ZGW business rules validation
	 */
	private function validateProductenOfDiensten(array $body): ?array {
		if ($this->objectService === null) {
			return null;
		}

		$caseTypeUrl = $body['caseType'] ?? '';
		if (empty($caseTypeUrl) === true) {
			return null;
		}

		$zaaktypeUuid = $this->extractUuid(url: $caseTypeUrl);
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
						fieldName: 'productenOfDiensten',
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
						fieldName: 'productenOfDiensten',
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
	 * Detect whether a statustype is the eindstatus by volgnummer fallback (zrc-007a).
	 *
	 * When `isEindstatus` is not explicitly set on the statustype, the statustype
	 * with the highest `volgnummer` for the zaaktype is treated as the eindstatus.
	 *
	 * @param string $statustypeUuid The statustype UUID to check
	 * @param string $zaaktypeUuid The zaaktype UUID to fetch all statustypes for
	 *
	 * @return bool True if this statustype is the eindstatus
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function detectEindstatus(string $statustypeUuid, string $zaaktypeUuid): bool {
		if ($this->objectService === null) {
			return false;
		}

		$statusTypeData = $this->findBySchemaKey(uuid: $statustypeUuid, schemaKey: 'status_type_schema');
		if ($statusTypeData === null) {
			return false;
		}

		// If isEindstatus is explicitly set, use it directly.
		$isEindstatus = $statusTypeData['isEindstatus'] ?? null;
		if ($isEindstatus !== null) {
			return $isEindstatus === true || $isEindstatus === 1 || $isEindstatus === 'true';
		}

		// Fallback: find the statustype with the highest volgnummer for this zaaktype.
		return $this->isHighestSequenceNumberStatustype(
			objectService: $this->objectService,
			statustypeUuid: $statustypeUuid,
			zaaktypeUuid: $zaaktypeUuid
		);
	}//end detectEindstatus()

	/**
	 * Test whether a statustype carries the highest `volgnummer` of its zaaktype (zrc-007a).
	 *
	 * Returns false when the register/schema are unconfigured or the lookup fails — a failure is
	 * logged and never raised, exactly as the inline block did.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param string $statustypeUuid The statustype UUID to check
	 * @param string $zaaktypeUuid The zaaktype UUID to fetch all statustypes for
	 *
	 * @return bool True if this statustype has the highest volgnummer
	 */
	private function isHighestSequenceNumberStatustype(object $objectService, string $statustypeUuid, string $zaaktypeUuid): bool {
		$register = $this->mappingConfig['sourceRegister'] ?? '';
		$statusTypeSchema = $this->settingsService->getConfigValue(key: 'status_type_schema');
		if (empty($register) === true || empty($statusTypeSchema) === true) {
			return false;
		}

		try {
			$query = $objectService->buildSearchQuery(
				requestParams: ['caseType' => $zaaktypeUuid, '_limit' => 1000],
				register: $register,
				schema: $statusTypeSchema
			);
			$result = $objectService->searchObjectsPaginated(query: $query);

			$maxSequenceNumber = -1;
			$maxStatustypeUuid = null;
			foreach (($result['results'] ?? []) as $obj) {
				$data = $obj;
				if (is_array($obj) === false) {
					$data = $obj->jsonSerialize();
				}

				$sequenceNumber = (int)($data['sequenceNumber'] ?? 0);
				$objId = $data['id'] ?? ($data['@self']['id'] ?? null);
				if ($sequenceNumber > $maxSequenceNumber) {
					$maxSequenceNumber = $sequenceNumber;
					$maxStatustypeUuid = $objId;
				}
			}

			return $maxStatustypeUuid === $statustypeUuid;
		} catch (\Throwable $e) {
			$this->logger->warning('detectEindstatus failed: ' . $e->getMessage());
			return false;
		}//end try
	}//end isHighestVolgnummerStatustype()

	/**
	 * Filter a list of zaken by consumer's authorization scope (zrc-006).
	 *
	 * Reads consumer authorizations from context and removes zaken whose
	 * zaaktype is not authorized or whose vertrouwelijkheidaanduiding exceeds
	 * the consumer's maxVertrouwelijkheidaanduiding for the zaaktype.
	 * Falls back to unfiltered when no authorizations context is present.
	 *
	 * @param array $cases Array of zaak objects (already serialized to arrays)
	 * @param array $authorizations The consumer's authorizations array from ZgwAuthMiddleware
	 *
	 * @return array The filtered zaken array
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function filterZakenForConsumer(array $cases, array $authorizations): array {
		// No authorizations context → return all zaken unfiltered.
		if (empty($authorizations) === true) {
			return $cases;
		}

		// Build lookup: zaaktype UUID → max vertrouwelijkheidaanduiding level.
		$allowedZaaktypen = [];
		foreach ($authorizations as $auth) {
			$caseTypeUrl = $auth['caseType'] ?? ($auth['zaaktypeUrl'] ?? '');
			if (empty($caseTypeUrl) === true) {
				continue;
			}

			$zaaktypeUuid = $this->extractUuid(url: (string)$caseTypeUrl);
			if ($zaaktypeUuid === null) {
				continue;
			}

			$maxVa = $auth['maxVertrouwelijkheidaanduiding'] ?? ($auth['max_vertrouwelijkheidaanduiding'] ?? 'zeer_geheim');
			$maxLevel = self::VERTROUWELIJKHEID_LEVELS[$maxVa] ?? 8;

			// Keep the most permissive level if zaaktype appears in multiple auths.
			if (isset($allowedZaaktypen[$zaaktypeUuid]) === false
				|| $allowedZaaktypen[$zaaktypeUuid] < $maxLevel
			) {
				$allowedZaaktypen[$zaaktypeUuid] = $maxLevel;
			}
		}//end foreach

		return array_values(
			array_filter(
				$cases,
				function (array $case) use ($allowedZaaktypen): bool {
					$caseTypeId = $case['caseType'] ?? '';
					$zaaktypeUuid = $this->extractUuid(url: (string)$caseTypeId);

					if ($zaaktypeUuid === null || isset($allowedZaaktypen[$zaaktypeUuid]) === false) {
						return false;
					}

					$caseVa = $case['vertrouwelijkheidaanduiding'] ?? ($case['confidentiality'] ?? 'openbaar');
					$caseLevel = self::VERTROUWELIJKHEID_LEVELS[(string)$caseVa] ?? 1;

					return $caseLevel <= $allowedZaaktypen[$zaaktypeUuid];
				}
			)
		);
	}//end filterZakenForConsumer()
}//end class
