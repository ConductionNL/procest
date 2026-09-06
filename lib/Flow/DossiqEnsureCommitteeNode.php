<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Flow
 * @package   OCA\Dossiq\Flow
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Flow;

use OCA\Dossiq\Service\Bezwaar\CommitteeDelegationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCP\IL10N;
use OCP\WorkflowEngine\IManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * Make sure the objection's advisory committee exists as a governance body.
 *
 * COMMITTEES ARE THE DECISION APP'S. A bezwaaradviescommissie is a governance
 * body, and this node is how a running bezwaar gets one without dossiq keeping
 * a second register of who sits on what. It reads the local committee, asks the
 * decision app to hold it, and records the id it gets back on the local row so
 * the mapping is auditable and the next run is a no-op.
 *
 * 🔴 IT FAILS CLOSED. When the decision app is unavailable the step FAILS and
 * the run stops here. Carrying on would refer an objection to a committee that
 * exists in no shared register — the drift this whole migration exists to end,
 * and worse than stopping because nothing would report it.
 *
 * IT IS SAFE TO RE-RUN, and that is not this node's doing: the seam on the
 * other side resolves on (sourceApp, externalReference) BEFORE it writes, so a
 * second pass updates one body rather than minting another. This node still
 * short-circuits on a recorded id, because not dispatching at all is cheaper
 * than dispatching and being told nothing changed.
 *
 * @spec openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md
 */
class DossiqEnsureCommitteeNode implements IFlowNode {

    /**
     * Item property naming the committee when the config does not say.
     *
     * @var string
     */
    private const DEFAULT_COMMITTEE_FIELD = 'committee';

    /**
     * Item property the resolved body id is written to when the config
     * does not say.
     *
     * @var string
     */
    private const DEFAULT_OUTPUT_KEY = 'governanceBodyId';


    /**
     * Constructor.
     *
     * @param CommitteeDelegationService $delegation Raises the body in the decision app.
     * @param SettingsService            $settings   Resolves OpenRegister and the schema slugs.
     * @param IL10N                      $l10n       The localisation service.
     * @param LoggerInterface            $logger     The logger.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md
     */
    public function __construct(
        private readonly CommitteeDelegationService $delegation,
        private readonly SettingsService $settings,
        private readonly IL10N $l10n,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()


    /**
     * This node's catalogue id.
     *
     * @return string The namespaced node id.
     *
     * @spec openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md
     */
    public function getId(): string {
        return 'dossiq.ensureCommittee';

    }//end getId()


    /**
     * The node's display name.
     *
     * @return string The translated name.
     *
     * @spec openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md
     */
    public function getDisplayName(): string {
        return $this->l10n->t('Register the advisory committee');

    }//end getDisplayName()


    /**
     * What the node does.
     *
     * @return string The translated description.
     *
     * @spec openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md
     */
    public function getDescription(): string {
        return $this->l10n->t('Make sure the objection advisory committee is held as a governance body, and note its id on the objection.');

    }//end getDescription()


    /**
     * The node's icon.
     *
     * @return string The icon name.
     *
     * @spec openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md
     */
    public function getIcon(): string {
        return 'account-group';

    }//end getIcon()


    /**
     * Where this node may be offered.
     *
     * @param integer $scope The Nextcloud workflow scope.
     *
     * @return boolean True when available in this scope.
     *
     * @spec openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md
     */
    public function isAvailableForScope(int $scope): bool {
        return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);

    }//end isAvailableForScope()


    /**
     * Refuse a configuration that names no field to read the committee from.
     *
     * @param array $config The step configuration.
     *
     * @return void
     *
     * @throws UnexpectedValueException When the field name is blank.
     *
     * @spec openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md
     */
    public function validateConfig(array $config): void {
        if (array_key_exists('committeeField', $config) === false) {
            return;
        }

        if (trim((string) $config['committeeField']) === '') {
            throw new UnexpectedValueException(
                $this->l10n->t('Name the property that holds the committee, or leave it out to use the default.')
            );
        }

    }//end validateConfig()


    /**
     * Resolve each item's committee and stamp the governance-body id on it.
     *
     * @param array $items   The input items.
     * @param array $config  The step configuration.
     * @param array $context Run-level metadata.
     *
     * @return array The items, each carrying the resolved id.
     *
     * @throws RuntimeException When the committee cannot be resolved or raised.
     *
     * @spec openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) — $context is IFlowNode's
     * contract; the run's acting identity in it is applied by the engine's
     * dispatcher now (openregister#3332), not read here.
     */
    public function execute(array $items, array $config, array $context): array {
        $this->validateConfig(config: $config);

        $field     = $this->configString(config: $config, key: 'committeeField', fallback: self::DEFAULT_COMMITTEE_FIELD);
        $outputKey = $this->configString(config: $config, key: 'outputKey', fallback: self::DEFAULT_OUTPUT_KEY);

        $out = [];
        foreach ($items as $item) {
            if (is_array($item) === false) {
                $out[] = $item;
                continue;
            }

            $json = (array) ($item['json'] ?? []);

            $committeeId = $this->referenceOf(value: ($json[$field] ?? null));
            if ($committeeId === '') {
                // Not every objection goes to a committee. One that names none
                // passes through untouched rather than failing the run, so a
                // single flow can serve both routes.
                $out[] = $item;
                continue;
            }

            // The committee READ and the mapping WRITE run under the flow
            // run's `runAs` identity: the engine's RegistryStepDispatcher
            // executes every contributed node inside `ObjectService::runAs()`
            // (openregister#3332), so no local wrap is needed.
            $json[$outputKey] = $this->resolveBodyId(committeeId: $committeeId);
            $item['json']     = $json;
            $out[]            = $item;
        }//end foreach

        return $out;

    }//end execute()


    /**
     * The governance-body id for one local committee, raising it if needed.
     *
     * @param string $committeeId The local committee id.
     *
     * @return string The governance-body id.
     *
     * @throws RuntimeException When the committee is missing or cannot be raised.
     */
    private function resolveBodyId(string $committeeId): string {
        $committee = $this->loadCommittee(committeeId: $committeeId);

        $recorded = trim((string) ($committee['governanceBodyId'] ?? ''));
        if ($recorded !== '') {
            return $recorded;
        }

        $bodyId = $this->delegation->ensureGovernanceBody(committee: $committee);

        $this->recordMapping(committee: $committee, bodyId: $bodyId);

        return $bodyId;

    }//end resolveBodyId()


    /**
     * Read the local committee row.
     *
     * @param string $committeeId The committee id.
     *
     * @return array The committee row.
     *
     * @throws RuntimeException When it cannot be read.
     */
    private function loadCommittee(string $committeeId): array {
        $objectService = $this->settings->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register = $this->settings->getConfigValue(key: 'register');
        $schema   = $this->settings->getConfigValue(key: 'bezwaaradviescommissie_schema');
        if ($register === '' || $schema === '') {
            throw new RuntimeException('The committee register/schema is not configured');
        }

        try {
            $committee = $objectService->find($committeeId, register: $register, schema: $schema);
        } catch (Throwable $e) {
            throw new RuntimeException('Could not read committee ' . $committeeId . ': ' . $e->getMessage(), 0, $e);
        }

        if (is_object($committee) === true && method_exists($committee, 'jsonSerialize') === true) {
            $committee = $committee->jsonSerialize();
        }

        if (is_array($committee) === false) {
            throw new RuntimeException('Committee not found: ' . $committeeId);
        }

        return (array) $committee;

    }//end loadCommittee()


    /**
     * Note the governance-body id on the local committee row.
     *
     * A best-effort write. The id has already been resolved by the time this
     * runs, so a failure here costs one redundant dispatch on the next run —
     * which the other side answers idempotently — and must not fail a run whose
     * real work succeeded.
     *
     * @param array  $committee The committee row.
     * @param string $bodyId    The resolved governance-body id.
     *
     * @return void
     */
    private function recordMapping(array $committee, string $bodyId): void {
        $objectService = $this->settings->getObjectService();
        if ($objectService === null) {
            return;
        }

        $register = $this->settings->getConfigValue(key: 'register');
        $schema   = $this->settings->getConfigValue(key: 'bezwaaradviescommissie_schema');
        $id       = $this->referenceOf(value: ($committee['id'] ?? ($committee['@self']['id'] ?? null)));
        if ($register === '' || $schema === '' || $id === '') {
            return;
        }

        try {
            $objectService->saveObject(
                object: array_merge($committee, ['governanceBodyId' => $bodyId]),
                register: $register,
                schema: $schema,
                uuid: $id,
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Dossiq ensureCommittee: could not record the governance-body id; the next run will re-resolve it',
                ['committee' => $id, 'governanceBodyId' => $bodyId, 'error' => $e->getMessage()]
            );
        }

    }//end recordMapping()


    /**
     * Read a config value, falling back when absent or blank.
     *
     * @param array  $config   The step configuration.
     * @param string $key      The key.
     * @param string $fallback The fallback.
     *
     * @return string The value.
     */
    private function configString(array $config, string $key, string $fallback): string {
        $value = trim((string) ($config[$key] ?? ''));
        if ($value === '') {
            return $fallback;
        }

        return $value;

    }//end configString()


    /**
     * Reduce a relation value to the id it points at.
     *
     * OpenRegister hands a relation back as a bare uuid, or as the expanded
     * object when the read inlined it. Reading the raw value would turn an
     * expanded committee into an empty id and silently skip the item.
     *
     * @param mixed $value The relation value.
     *
     * @return string The id, or an empty string.
     */
    private function referenceOf(mixed $value): string {
        if (is_array($value) === true) {
            return trim((string) ($value['id'] ?? ($value['@self']['id'] ?? '')));
        }

        if (is_scalar($value) === true) {
            return trim((string) $value);
        }

        return '';

    }//end referenceOf()


}//end class
