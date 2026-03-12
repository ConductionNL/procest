<?php

/**
 * Procest ZGW Business Rules Service (Dispatcher)
 *
 * Thin dispatcher that delegates validation and enrichment to per-register
 * rule services:
 * - ZgwZrcRulesService  (Zaken API rules)
 * - ZgwZtcRulesService  (Catalogi API rules)
 * - ZgwDrcRulesService  (Documenten API rules)
 * - ZgwBrcRulesService  (Besluiten API rules)
 *
 * Cross-register rules (zrc-005, brc-005, brc-006) live in ZgwService.
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
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use Psr\Log\LoggerInterface;

/**
 * Dispatcher for ZGW business rule validation and enrichment.
 *
 * Delegates to per-register rule services (ZRC, ZTC, DRC, BRC).
 * Handles cross-register concerns like concept protection (ztc-009/010)
 * and closed-zaak protection (zrc-007) before delegating.
 */
class ZgwBusinessRulesService
{
    /**
     * Constructor.
     *
     * @param LoggerInterface    $logger          The logger
     * @param SettingsService    $settingsService The settings service
     * @param ZgwZrcRulesService $zrcRules        ZRC (Zaken) rules
     * @param ZgwZtcRulesService $ztcRules        ZTC (Catalogi) rules
     * @param ZgwDrcRulesService $drcRules        DRC (Documenten) rules
     * @param ZgwBrcRulesService $brcRules        BRC (Besluiten) rules
     *
     * @return void
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly SettingsService $settingsService,
        private readonly ZgwZrcRulesService $zrcRules,
        private readonly ZgwZtcRulesService $ztcRules,
        private readonly ZgwDrcRulesService $drcRules,
        private readonly ZgwBrcRulesService $brcRules,
    ) {
    }

    /**
     * Validate and enrich a request body before saving.
     *
     * @param string      $zgwApi              The ZGW API group (e.g. 'zaken', 'besluiten')
     * @param string      $resource            The ZGW resource name (e.g. 'zaken', 'besluiten')
     * @param string      $action              The action ('create', 'update', 'patch', 'destroy')
     * @param array       $body                The ZGW request body (Dutch field names)
     * @param array|null  $existingObject      The existing object data (for update/patch/destroy)
     * @param object|null $objectService       The OpenRegister ObjectService
     * @param array|null  $mappingConfig       The mapping config
     * @param bool|null   $parentZaaktypeDraft Whether the parent zaaktype isDraft (for ztc-010)
     * @param bool|null   $zaakClosed          Whether the (parent) zaak is closed (for zrc-007)
     * @param bool        $hasGeforceerd       Whether consumer has geforceerd-bijwerken scope
     *
     * @return array{valid: bool, status: int, detail: string, enrichedBody: array}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function validate(
        string $zgwApi,
        string $resource,
        string $action,
        array $body,
        ?array $existingObject = null,
        ?object $objectService = null,
        ?array $mappingConfig = null,
        ?bool $parentZaaktypeDraft = null,
        ?bool $zaakClosed = null,
        bool $hasGeforceerd = true
    ): array {
        // Set context on all per-register rule services.
        $this->zrcRules->setContext($objectService, $mappingConfig);
        $this->ztcRules->setContext($objectService, $mappingConfig);
        $this->drcRules->setContext($objectService, $mappingConfig);
        $this->brcRules->setContext($objectService, $mappingConfig);

        // ---- ZTC cross-cutting concerns (concept protection) ----
        if ($zgwApi === 'catalogi') {
            // Default concept=true for new concept resources.
            if ($action === 'create') {
                $body = $this->ztcRules->defaultConcept($body, $resource);
            }

            // Preserve concept on update/patch (only changeable via /publish).
            if ($action === 'update' || $action === 'patch') {
                $body = $this->ztcRules->preserveConcept($body, $resource, $existingObject);
            }

            // Ztc-009/ztc-010: Protect published types from modification.
            $conceptCheck = $this->ztcRules->checkConceptProtection(
                $resource,
                $action,
                $body,
                $existingObject,
                $parentZaaktypeDraft
            );
            if ($conceptCheck !== null) {
                return $conceptCheck;
            }
        }

        // ---- ZRC cross-cutting concern: closed zaak protection (zrc-007) ----
        if ($zaakClosed === true && $hasGeforceerd === false) {
            return [
                'valid'         => false,
                'status'        => 403,
                'detail'        => 'Zaak is afgesloten. Wijzigingen zijn niet toegestaan'
                    . ' zonder scope zaken.geforceerd-bijwerken.',
                'code'          => 'permission_denied',
                'invalidParams' => [
                    [
                        'name'   => 'nonFieldErrors',
                        'code'   => 'zaak-closed',
                        'reason' => 'De zaak is afgesloten.',
                    ],
                ],
                'enrichedBody'  => [],
            ];
        }

        // ---- Delegate to per-register rule services ----
        return $this->dispatchToRegister(
            zgwApi: $zgwApi,
            resource: $resource,
            action: $action,
            body: $body,
            existingObject: $existingObject
        );
    }

    /**
     * Dispatch to the appropriate per-register rule service.
     *
     * @param string     $zgwApi         The ZGW API group
     * @param string     $resource       The ZGW resource name
     * @param string     $action         The action
     * @param array      $body           The request body
     * @param array|null $existingObject The existing object data
     *
     * @return array The validation result
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function dispatchToRegister(
        string $zgwApi,
        string $resource,
        string $action,
        array $body,
        ?array $existingObject
    ): array {
        $ok = [
            'valid'        => true,
            'status'       => 200,
            'detail'       => '',
            'enrichedBody' => $body,
        ];

        // --- Zaken API (ZRC) ---
        if ($zgwApi === 'zaken') {
            return $this->dispatchZrc(
                resource: $resource,
                action: $action,
                body: $body,
                existingObject: $existingObject
            );
        }

        // --- Catalogi API (ZTC) ---
        if ($zgwApi === 'catalogi') {
            return $this->dispatchZtc(
                resource: $resource,
                action: $action,
                body: $body,
                existingObject: $existingObject
            );
        }

        // --- Documenten API (DRC) ---
        if ($zgwApi === 'documenten') {
            return $this->dispatchDrc(
                resource: $resource,
                action: $action,
                body: $body,
                existingObject: $existingObject
            );
        }

        // --- Besluiten API (BRC) ---
        if ($zgwApi === 'besluiten') {
            return $this->dispatchBrc(
                resource: $resource,
                action: $action,
                body: $body,
                existingObject: $existingObject
            );
        }

        return $ok;
    }

    /**
     * Dispatch ZRC (Zaken API) rules.
     *
     * @param string     $resource       The resource name
     * @param string     $action         The action
     * @param array      $body           The request body
     * @param array|null $existingObject The existing object data
     *
     * @return array The validation result
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function dispatchZrc(string $resource, string $action, array $body, ?array $existingObject): array
    {
        return match (true) {
            $resource === 'zaken' && $action === 'create'
                => $this->zrcRules->rulesZakenCreate($body),
            $resource === 'zaken' && $action === 'update'
                => $this->zrcRules->rulesZakenUpdate($body, $existingObject),
            $resource === 'zaken' && $action === 'patch'
                => $this->zrcRules->rulesZakenPatch($body, $existingObject),
            $resource === 'statussen' && $action === 'create'
                => $this->zrcRules->rulesStatussenCreate($body),
            $resource === 'resultaten' && $action === 'create'
                => $this->zrcRules->rulesResultatenCreate($body),
            $resource === 'rollen' && $action === 'create'
                => $this->zrcRules->rulesRollenCreate($body),
            $resource === 'zaakinformatieobjecten' && $action === 'create'
                => $this->zrcRules->rulesZaakinformatieobjectenCreate($body),
            $resource === 'zaakinformatieobjecten' && $action === 'update'
                => $this->zrcRules->rulesZaakinformatieobjectenUpdate($body, $existingObject),
            $resource === 'zaakinformatieobjecten' && $action === 'patch'
                => $this->zrcRules->rulesZaakinformatieobjectenPatch($body, $existingObject),
            $resource === 'zaakeigenschappen' && $action === 'create'
                => $this->zrcRules->rulesZaakeigenschappenCreate($body),
            default => $this->ok(body: $body),
        };//end match
    }

    /**
     * Dispatch ZTC (Catalogi API) rules.
     *
     * @param string     $resource       The resource name
     * @param string     $action         The action
     * @param array      $body           The request body
     * @param array|null $existingObject The existing object data
     *
     * @return array The validation result
     */
    private function dispatchZtc(string $resource, string $action, array $body, ?array $existingObject): array
    {
        return match (true) {
            $resource === 'zaaktypen' && $action === 'create'
                => $this->ztcRules->rulesZaaktypenCreate($body),
            $resource === 'besluittypen' && $action === 'create'
                => $this->ztcRules->rulesBesluittypenCreate($body),
            $resource === 'zaaktype-informatieobjecttypen' && $action === 'create'
                => $this->ztcRules->rulesZaaktypeinformatieobjecttypenCreate($body),
            $resource === 'resultaattypen' && $action === 'create'
                => $this->ztcRules->rulesResultaattypenCreate($body),
            default => $this->ok(body: $body),
        };
    }

    /**
     * Dispatch DRC (Documenten API) rules.
     *
     * @param string     $resource       The resource name
     * @param string     $action         The action
     * @param array      $body           The request body
     * @param array|null $existingObject The existing object data
     *
     * @return array The validation result
     */
    private function dispatchDrc(string $resource, string $action, array $body, ?array $existingObject): array
    {
        return match (true) {
            $resource === 'enkelvoudiginformatieobjecten' && $action === 'create'
                => $this->drcRules->rulesEnkelvoudiginformatieobjectenCreate($body),
            $resource === 'enkelvoudiginformatieobjecten' && $action === 'update'
                => $this->drcRules->rulesEnkelvoudiginformatieobjectenUpdate($body, $existingObject),
            $resource === 'enkelvoudiginformatieobjecten' && $action === 'patch'
                => $this->drcRules->rulesEnkelvoudiginformatieobjectenPatch($body, $existingObject),
            $resource === 'enkelvoudiginformatieobjecten' && $action === 'destroy'
                => $this->drcRules->rulesEnkelvoudiginformatieobjectenDestroy($body, $existingObject),
            $resource === 'objectinformatieobjecten' && $action === 'create'
                => $this->drcRules->rulesObjectinformatieobjectenCreate($body),
            default => $this->ok(body: $body),
        };
    }

    /**
     * Dispatch BRC (Besluiten API) rules.
     *
     * @param string     $resource       The resource name
     * @param string     $action         The action
     * @param array      $body           The request body
     * @param array|null $existingObject The existing object data
     *
     * @return array The validation result
     */
    private function dispatchBrc(string $resource, string $action, array $body, ?array $existingObject): array
    {
        return match (true) {
            $resource === 'besluiten' && $action === 'create'
                => $this->brcRules->rulesBesluitenCreate($body),
            $resource === 'besluiten' && $action === 'update'
                => $this->brcRules->rulesBesluitenUpdate($body, $existingObject),
            $resource === 'besluiten' && $action === 'patch'
                => $this->brcRules->rulesBesluitenPatch($body, $existingObject),
            $resource === 'besluitinformatieobjecten' && $action === 'create'
                => $this->brcRules->rulesBesluitinformatieobjectenCreate($body),
            default => $this->ok(body: $body),
        };
    }

    /**
     * Build a successful validation result (pass-through).
     *
     * @param array $body The (possibly enriched) request body
     *
     * @return array{valid: bool, status: int, detail: string, enrichedBody: array}
     */
    private function ok(array $body): array
    {
        return [
            'valid'        => true,
            'status'       => 200,
            'detail'       => '',
            'enrichedBody' => $body,
        ];
    }
}
