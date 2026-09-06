<?php

/**
 * Dossiq ZGW BRC (Besluiten) Business Rules Service
 *
 * Implements business rules for the Besluiten API as defined by VNG Realisatie.
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
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * BRC (Besluiten API) business rule validation and enrichment.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class ZgwBrcRulesService extends ZgwRulesBase {
	/**
	 * Rules for creating a besluit (POST /besluiten/v1/besluiten).
	 *
	 * Implements brc-001, brc-002, brc-007.
	 *
	 * @param array $body The ZGW request body (Dutch field names)
	 *
	 * @return array The validation result
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) — ZGW business rules validation
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function rulesBesluitenCreate(array $body): array {
		// Brc-001: Validate besluittype URL.
		$decisionTypeUrl = $body['besluittype'] ?? '';
		if (empty($decisionTypeUrl) === false && $this->objectService !== null) {
			$error = $this->validateTypeUrl(
				typeUrl: $decisionTypeUrl,
				fieldName: 'besluittype',
				schemaKey: 'decision_type_schema'
			);
			if ($error !== null) {
				return $error;
			}
		}

		// Brc-002: Check unique combination of verantwoordelijkeOrganisatie + identificatie.
		if (empty($body['identificatie']) === false && $this->objectService !== null) {
			$uniqueError = $this->checkDecisionIdentificationUnique(body: $body);
			if ($uniqueError !== null) {
				return $uniqueError;
			}
		}

		// Brc-007: Validate zaak-besluittype relation.
		$caseUrl = $body['case'] ?? null;
		if ($caseUrl !== null && $caseUrl !== '' && empty($decisionTypeUrl) === false
			&& $this->objectService !== null
		) {
			$relError = $this->validateCaseBesluittypeRelation(
				caseUrl: $caseUrl,
				decisionTypeUrl: $decisionTypeUrl
			);
			if ($relError !== null) {
				return $relError;
			}
		}

		// Brc-002: Auto-generate identificatie if not provided.
		if (empty($body['identificatie']) === true) {
			$body['identificatie'] = $this->generateIdentificatie(prefix: 'BESLUIT');
		}

		return $this->isValid(body: $body);
	}//end rulesBesluitenCreate()

	/**
	 * Rules for updating a besluit (PUT /besluiten/v1/besluiten/{uuid}).
	 *
	 * Implements brc-001 and brc-002 immutability.
	 *
	 * @param array $body The ZGW request body
	 * @param array|null $existingObject The existing besluit data
	 *
	 * @return array The validation result
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function rulesBesluitenUpdate(array $body, ?array $existingObject = null): array {
		$result = $this->isValid(body: $body);

		$result = $this->checkDecisionTypeImmutability(
			result: $result,
			existingObject: $existingObject
		);
		if ($result['valid'] === false) {
			return $result;
		}

		$result = $this->checkDecisionFieldImmutability(
			result: $result,
			existingObject: $existingObject
		);
		if ($result['valid'] === false) {
			return $result;
		}

		// Preserve immutable fields from existing object when omitted in PUT body.
		$result = $this->preserveImmutableDecisionFields(
			result: $result,
			existingObject: $existingObject
		);

		return $result;
	}//end rulesBesluitenUpdate()

	/**
	 * Rules for patching a besluit (PATCH /besluiten/v1/besluiten/{uuid}).
	 *
	 * @param array $body The ZGW request body
	 * @param array|null $existingObject The existing besluit data
	 *
	 * @return array The validation result
	 *
	 * @see rulesBesluitenUpdate() Same immutability rules apply.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function rulesBesluitenPatch(array $body, ?array $existingObject = null): array {
		$result = $this->isValid(body: $body);

		$result = $this->checkDecisionTypeImmutability(
			result: $result,
			existingObject: $existingObject
		);
		if ($result['valid'] === false) {
			return $result;
		}

		return $this->checkDecisionFieldImmutability(
			result: $result,
			existingObject: $existingObject
		);
	}//end rulesBesluitenPatch()

	/**
	 * Rules for creating a BesluitInformatieObject.
	 *
	 * Implements brc-003, brc-004, brc-008.
	 *
	 * @param array $body The ZGW request body
	 *
	 * @return array The validation result
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function rulesBesluitinformatieobjectenCreate(array $body): array {
		// Brc-003: Validate informatieobject URL.
		$ioUrl = $body['informatieobject'] ?? '';
		if ($ioUrl !== '' && $this->objectService !== null) {
			$error = $this->validateInformatieobjectUrl(ioUrl: $ioUrl);
			if ($error !== null) {
				return $error;
			}
		}

		// Brc-008: Validate informatieobjecttype in besluittype.informatieobjecttypen.
		$decisionUrl = $body['decision'] ?? '';
		if ($decisionUrl !== '' && $ioUrl !== '' && $this->objectService !== null) {
			$iotError = $this->validateBioInformatieobjecttype(
				decisionUrl: $decisionUrl,
				ioUrl: $ioUrl
			);
			if ($iotError !== null) {
				return $iotError;
			}
		}

		// Brc-004: Set aardRelatieWeergave automatically.
		$body['natureRelationshipDisplay'] = 'Legt vast, omgekeerd: wordt vastgelegd door';

		return $this->isValid(body: $body);
	}//end rulesBesluitinformatieobjectenCreate()

	/**
	 * Check that besluittype is not changed on update/patch (brc-001).
	 *
	 * @param array $result The current validation result
	 * @param array|null $existingObject The existing object data
	 *
	 * @return array The updated validation result
	 */
	private function checkDecisionTypeImmutability(array $result, ?array $existingObject): array {
		if ($existingObject === null) {
			return $result;
		}

		$body = $result['enrichedBody'];
		$newBesluittype = $body['besluittype'] ?? null;
		$existBesluittype = $existingObject['decisionType'] ?? '';

		if ($newBesluittype !== null && $existBesluittype !== '') {
			$newUuid = $this->extractUuid(url: $newBesluittype);
			if ($newUuid !== null && $newUuid !== $existBesluittype
				&& $this->extractUuid(url: $existBesluittype) !== $newUuid
			) {
				return $this->fieldImmutableError(fieldName: 'besluittype');
			}
		}

		return $result;
	}//end checkBesluitTypeImmutability()

	/**
	 * Check besluit field immutability (brc-002).
	 *
	 * Identificatie and verantwoordelijkeOrganisatie are immutable after creation.
	 *
	 * @param array $result The current validation result
	 * @param array|null $existingObject The existing object data
	 *
	 * @return array The updated validation result
	 */
	private function checkDecisionFieldImmutability(array $result, ?array $existingObject): array {
		if ($existingObject === null) {
			return $result;
		}

		$body = $result['enrichedBody'];

		// Brc-002: identificatie is immutable.
		if (isset($body['identificatie']) === true) {
			$existingId = $existingObject['title'] ?? $existingObject['identifier'] ?? '';
			if ($existingId === '') {
				$existingId = $existingObject['identificatie'] ?? '';
			}

			if ($existingId !== '' && $body['identificatie'] !== $existingId) {
				return $this->fieldImmutableError(fieldName: 'identificatie');
			}
		}

		// Brc-002: verantwoordelijkeOrganisatie is immutable.
		if (isset($body['verantwoordelijkeOrganisatie']) === true) {
			$orgKey = 'responsibleOrganisation';
			$orgFallback = 'verantwoordelijkeOrganisatie';
			$existingOrg = $existingObject[$orgKey] ?? $existingObject[$orgFallback] ?? '';
			if ($existingOrg !== '' && $body['verantwoordelijkeOrganisatie'] !== $existingOrg) {
				return $this->fieldImmutableError(fieldName: 'verantwoordelijkeOrganisatie');
			}
		}

		return $result;
	}//end checkBesluitFieldImmutability()

	/**
	 * Preserve immutable besluit fields from existing object when omitted in PUT body.
	 *
	 * @param array $result The current validation result
	 * @param array|null $existingObject The existing object data
	 *
	 * @return array The updated validation result with preserved fields
	 */
	private function preserveImmutableDecisionFields(array $result, ?array $existingObject): array {
		if ($existingObject === null) {
			return $result;
		}

		$body = $result['enrichedBody'];

		if (isset($body['identificatie']) === false || $body['identificatie'] === '') {
			$existingId = $existingObject['title'] ?? $existingObject['identifier'] ?? '';
			if ($existingId === '') {
				$existingId = $existingObject['identificatie'] ?? '';
			}

			if ($existingId !== '') {
				$body['identificatie'] = $existingId;
			}
		}

		if (isset($body['verantwoordelijkeOrganisatie']) === false
			|| $body['verantwoordelijkeOrganisatie'] === ''
		) {
			$orgKey = 'responsibleOrganisation';
			$orgFallback = 'verantwoordelijkeOrganisatie';
			$existingOrg = $existingObject[$orgKey] ?? $existingObject[$orgFallback] ?? '';
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
	private function checkDecisionIdentificationUnique(array $body): ?array {
		$identification = $body['identificatie'] ?? '';
		$organisation = $body['verantwoordelijkeOrganisatie'] ?? '';

		if ($identification === '' || $this->objectService === null) {
			return null;
		}

		$register = $this->mappingConfig['sourceRegister'] ?? '';
		$schema = $this->mappingConfig['sourceSchema'] ?? '';

		if (empty($register) === true || empty($schema) === true) {
			return null;
		}

		try {
			$searchParams = ['title' => $identification];
			if ($organisation !== '') {
				$searchParams['responsibleOrganisation'] = $organisation;
			}

			$query = $this->objectService->buildSearchQuery(
				requestParams: $searchParams,
				register: $register,
				schema: $schema
			);
			$result = $this->objectService->searchObjectsPaginated(query: $query);
			$total = $result['total'] ?? count($result['results'] ?? []);

			if ($total > 0) {
				return $this->error(
					status: 400,
					detail: 'De combinatie van verantwoordelijke_organisatie en identificatie is niet uniek.',
					invalidParams: [
						$this->fieldError(
							fieldName: 'identificatie',
							code: 'identificatie-niet-uniek',
							reason: 'De combinatie van verantwoordelijke_organisatie en identificatie bestaat al.'
						),
					]
				);
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'brc-002: Could not check besluit identificatie uniqueness: ' . $e->getMessage()
			);
		}//end try

		return null;
	}//end checkBesluitIdentificatieUnique()

	/**
	 * Validate zaak-besluittype relation (brc-007).
	 *
	 * The zaak's zaaktype must be listed in the besluittype's zaaktypen.
	 *
	 * @param string $caseUrl The zaak URL from the request
	 * @param string $decisionTypeUrl The besluittype URL from the request
	 *
	 * @return array|null Validation error, or null if valid
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	private function validateCaseBesluittypeRelation(string $caseUrl, string $decisionTypeUrl): ?array {
		$register = $this->mappingConfig['sourceRegister'] ?? '';
		if (empty($register) === true || $this->objectService === null) {
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

		$caseCaseType = $caseData['caseType'] ?? '';
		if (empty($caseCaseType) === true) {
			return null;
		}

		// Look up the besluittype to check its zaaktypen/caseTypes.
		$btUuid = $this->extractUuid(url: $decisionTypeUrl);
		if ($btUuid === null) {
			return null;
		}

		$btData = $this->findBySchemaKey(uuid: $btUuid, schemaKey: 'decision_type_schema');
		if ($btData === null) {
			return null;
		}

		$zaakCaseTypeUuid = $this->extractUuid(url: $caseCaseType);

		// Check direction 1: BT.caseTypes contains the zaaktype UUID.
		$caseTypes = $btData['caseTypes'] ?? [];
		if (is_string($caseTypes) === true) {
			$caseTypes = json_decode($caseTypes, true) ?? [];
		}

		if (is_array($caseTypes) === false) {
			$caseTypes = [];
		}

		foreach ($caseTypes as $ct) {
			$ctUuid = $this->extractUuid(url: (string)$ct);
			if ($ctUuid !== null && $ctUuid === $zaakCaseTypeUuid) {
				return null;
			}
		}

		// Check direction 2: ZT.decisionTypes contains the BT omschrijving or UUID.
		$ztData = $this->findBySchemaKey(uuid: $zaakCaseTypeUuid, schemaKey: 'case_type_schema');
		if ($ztData !== null) {
			$decisionTypes = $ztData['decisionTypes'] ?? [];
			if (is_string($decisionTypes) === true) {
				$decisionTypes = json_decode($decisionTypes, true) ?? [];
			}

			if (is_array($decisionTypes) === true) {
				$btOmschrijving = $btData['title'] ?? ($btData['name'] ?? '');
				foreach ($decisionTypes as $dt) {
					$dtStr = (string)$dt;
					// Match by omschrijving or UUID.
					$dtUuid = $this->extractUuid(url: $dtStr);
					if (($dtUuid !== null && $dtUuid === $btUuid)
						|| ($btOmschrijving !== '' && $dtStr === $btOmschrijving)
					) {
						return null;
					}
				}
			}
		}//end if

		$detail = 'Het zaaktype van de zaak is niet gerelateerd aan het besluittype.';

		return $this->error(
			status: 400,
			detail: $detail,
			invalidParams: [
				$this->fieldError(
					fieldName: 'nonFieldErrors',
					code: 'zaaktype-mismatch',
					reason: $detail
				),
			]
		);
	}//end validateZaakBesluittypeRelation()

	/**
	 * Validate informatieobjecttype is in besluittype.informatieobjecttypen (brc-008).
	 *
	 * @param string $decisionUrl The besluit URL
	 * @param string $ioUrl The informatieobject URL
	 *
	 * @return array|null Validation error, or null if valid
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) — ZGW cross-register validation
	 */
	private function validateBioInformatieobjecttype(string $decisionUrl, string $ioUrl): ?array {
		if ($this->objectService === null) {
			return null;
		}

		// Get the besluit to find its besluittype.
		$besluitUuid = $this->extractUuid(url: $decisionUrl);
		if ($besluitUuid === null) {
			return null;
		}

		$decisionData = $this->findBySchemaKey(uuid: $besluitUuid, schemaKey: 'decision_schema');
		if ($decisionData === null) {
			return null;
		}

		$decisionTypeId = $decisionData['decisionType'] ?? '';
		if (empty($decisionTypeId) === true) {
			return null;
		}

		// Get the besluittype.
		$btUuid = $this->extractUuid(url: $decisionTypeId);
		if ($btUuid === null) {
			return null;
		}

		$btData = $this->findBySchemaKey(uuid: $btUuid, schemaKey: 'decision_type_schema');
		if ($btData === null) {
			return null;
		}

		// Get the allowed documentTypes from besluittype.
		$allowedDocTypes = $btData['documentTypes'] ?? '[]';
		if (is_string($allowedDocTypes) === true) {
			$allowedDocTypes = json_decode($allowedDocTypes, true) ?? [];
		}

		if (is_array($allowedDocTypes) === false || empty($allowedDocTypes) === true) {
			// phpcs:ignore Generic.Files.LineLength.TooLong
			$detail = 'Het informatieobjecttype van het informatieobject is niet gespecificeerd in het besluittype.informatieobjecttypen.';
			return $this->error(
				status: 400,
				detail: $detail,
				invalidParams: [
					$this->fieldError(
						fieldName: 'nonFieldErrors',
						code: 'missing-informatieobjecttype',
						reason: $detail
					),
				]
			);
		}

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

		// Look up the documentType to get its name.
		$docTypeUuid = $this->extractUuid(url: $docTypeId);
		if ($docTypeUuid === null) {
			return null;
		}

		$dtData = $this->findBySchemaKey(uuid: $docTypeUuid, schemaKey: 'document_type_schema');
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
			// phpcs:ignore Generic.Files.LineLength.TooLong
			$detail = 'Het informatieobjecttype van het informatieobject is niet gespecificeerd in het besluittype.informatieobjecttypen.';
			return $this->error(
				status: 400,
				detail: $detail,
				invalidParams: [
					$this->fieldError(
						fieldName: 'nonFieldErrors',
						code: 'missing-informatieobjecttype',
						reason: $detail
					),
				]
			);
		}

		return null;
	}//end validateBioInformatieobjecttype()
}//end class
