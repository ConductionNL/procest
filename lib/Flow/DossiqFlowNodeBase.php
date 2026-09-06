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

use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\Dossiq\Service\Actions\ActionHandlerInterface as CatalogueActionHandler;
use OCA\Dossiq\Service\Transitions\ActionHandlerInterface as TransitionActionHandler;
use OCP\IL10N;
use OCP\WorkflowEngine\IManager;
use OCP\IURLGenerator;
use UnexpectedValueException;

/**
 * Presents one dossiq action handler to OpenRegister's flow engine.
 *
 * Two families extend this: DossiqActionNode for the configured-action
 * catalogue (`dossiq.action.*`) and DossiqTransitionNode for the live
 * transition vocabulary (`dossiq.*`). They are siblings rather than one base
 * with fifteen children because they ARE two systems — and phpmd flagged the
 * single hierarchy at exactly the point where that stopped being expressible.
 *
 * WHY A NODE AND NOT A MAPPING. OpenRegister's engine registers nineteen nodes
 * and every one of them is control-flow or data — await-signal, batch, filter,
 * iterate, map, merge, object-read, object-write, route, set-fields, sub-flow,
 * switch, the three triggers, wait. Not one does anything outward-facing. All
 * six dossiq actions DO: they send mail, call a webhook, render a document,
 * notify a role. Mapping them onto existing nodes would mean inventing
 * behaviour OpenRegister deliberately does not own, so dossiq contributes them
 * instead — which is what FlowNodeRegistry is built for ("apps present nodes
 * through OpenRegister"), and what hermiq already does with its agent nodes.
 *
 * THE HANDLERS KEEP THEIR LOGIC. This is a wrapper, not a port: each subclass
 * hands its existing ActionHandlerInterface the same `(actionConfig, case,
 * transitionContext)` it always got. What changes is who calls it — the flow
 * engine rather than dossiq's private registry.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
 */
abstract class DossiqFlowNodeBase implements IFlowNode {


    /**
     * Constructor.
     *
     * @param IL10N         $l10n The localisation service.
     * @param IURLGenerator $urls The URL generator, for the node icon.
     *
     * @return void
     */
    public function __construct(
        protected readonly IL10N $l10n,
        protected readonly IURLGenerator $urls,
    ) {

    }//end __construct()


    /**
     * The handler this node runs.
     *
     * A UNION, because dossiq carries two action systems with two interfaces
     * of the same name in different namespaces. They declare an identical
     * `handle(array, array, array): ActionResult` and their ActionResults have
     * an identical shape (succeeded / error / data), so one node body serves
     * both — and naming both here says so out loud rather than duck-typing it.
     *
     * @return CatalogueActionHandler|TransitionActionHandler The action handler.
     */
    abstract protected function handler(): CatalogueActionHandler|TransitionActionHandler;


    /**
     * Config keys without which this action cannot run.
     *
     * @return string[] The required key names.
     */
    abstract protected function requiredConfigKeys(): array;


    /**
     * This node's id.
     *
     * Stated by the subclass rather than derived, because the two action
     * systems both ship a `sendEmail` and their ids would collide. The LIVE
     * transition vocabulary takes the plain `dossiq.<type>` names; the
     * configured-action catalogue takes `dossiq.action.<type>`.
     *
     * @return string The namespaced node id.
     */
    abstract protected function nodeId(): string;


    /**
     * The node id.
     *
     * @return string The namespaced node id.
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function getId(): string {
        return $this->nodeId();

    }//end getId()


    /**
     * The node icon.
     *
     * @return string The icon path.
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function getIcon(): string {
        return $this->urls->imagePath('dossiq', 'app-dark.svg');

    }//end getIcon()


    /**
     * Reject a config this node cannot act on.
     *
     * @param array<string, mixed> $config The step config.
     *
     * @return void
     *
     * @throws UnexpectedValueException When a required key is missing.
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function validateConfig(array $config): void {
        foreach ($this->requiredConfigKeys() as $key) {
            if (trim((string) ($config[$key] ?? '')) === '') {
                throw new UnexpectedValueException(
                    $this->l10n->t('%1$s needs a value for "%2$s".', [$this->getDisplayName(), $key])
                );
            }
        }

    }//end validateConfig()


    /**
     * Run the handler once per item.
     *
     * The item's `json` payload is the case; the flow context carries the
     * transition. A FAILED action THROWS rather than returning the item
     * untouched: the engine's per-step `onError` policy is what decides, and it
     * only ever sees failures that propagate out of execute(). Swallowing one
     * here would make the step a silent pass-through whose output key is simply
     * absent — and a downstream router would then take its default branch as
     * though the action had succeeded.
     *
     * @param array<int, array<string, mixed>> $items   The items to act on.
     * @param array<string, mixed>             $config  The step config.
     * @param array<string, mixed>             $context The run context.
     *
     * @return array<int, array<string, mixed>> The items, each carrying the result.
     *
     * @throws UnexpectedValueException When the config is unusable.
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function execute(array $items, array $config, array $context): array {
        // ValidateConfig() only runs when a flow is SAVED; a flow imported or
        // seeded through another path reaches execute() unvalidated. Same shape
        // of defect hermiq's agent node documents, so the same guard.
        $this->validateConfig(config: $config);

        $outKey = (string) ($config['output'] ?? 'actionResult');
        $out    = [];

        foreach ($items as $item) {
            $case   = (array) ($item['json'] ?? []);
            $result = $this->handler()->handle(
                actionConfig: $config,
                case: $case,
                transitionContext: $context
            );

            if ($result->succeeded === false) {
                throw new UnexpectedValueException(
                    (string) ($result->error ?? $this->l10n->t('The action did not complete.'))
                );
            }

            // The handler's own case writes travel with the item, so the NEXT
            // step's snapshot already carries what this step just stored.
            // Without this, the document step wrote `besluitDocument` to
            // storage while the outgoing item still lacked it — and one hop
            // later a stale snapshot was all the status step had.
            $item['json'] = array_merge($case, $result->caseChanges, [$outKey => $result->data]);
            $out[]        = $item;
        }//end foreach

        return $out;

    }//end execute()

    /**
     * Where this node may be offered.
     *
     * Admin and user scope, matching every other action-bearing node in the
     * fleet: a case action is not a system-internal step.
     *
     * @param integer $scope The Nextcloud workflow scope.
     *
     * @return boolean True when available in this scope.
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function isAvailableForScope(int $scope): bool {
        return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);

    }//end isAvailableForScope()


}//end class
