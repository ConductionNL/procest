<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Flow
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Flow;

use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCA\Dossiq\Flow\DossiqCallWebhookNode;
use OCA\Dossiq\Flow\DossiqCreateDocumentNode;
use OCA\Dossiq\Flow\DossiqFlowNodeListener;
use OCA\Dossiq\Flow\DossiqMergeTemplateNode;
use OCA\Dossiq\Flow\DossiqNotifyRoleNode;
use OCA\Dossiq\Flow\DossiqScheduleReminderNode;
use OCA\Dossiq\Flow\DossiqSendEmailNode;
use OCA\Dossiq\Flow\DossiqTxSendEmailNode;
use OCA\Dossiq\Flow\DossiqTxCreateTaskNode;
use OCA\Dossiq\Flow\DossiqTxCreateSubCaseNode;
use OCA\Dossiq\Flow\DossiqTxWebhookNode;
use OCA\Dossiq\Flow\DossiqAskPersonNode;
use OCA\Dossiq\Flow\DossiqEnsureCommitteeNode;
use OCA\Dossiq\Flow\DossiqRequestDecisionNode;
use OCA\Dossiq\Flow\DossiqTxSetFieldNode;
use OCA\Dossiq\Flow\DossiqTxSetStatusNode;
use OCA\Dossiq\Flow\DossiqTxNotifyNode;
use OCA\Dossiq\Flow\DossiqTxBesluitvormingPublishNode;
use OCA\Dossiq\Flow\DossiqTxEvaluateDecisionNode;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Proves dossiq actually contributes all six case actions.
 *
 * A node class that exists but is never registered is invisible to the flow
 * editor — and looks identical to one that works, right up until somebody tries
 * to build a flow with it.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
 */
class DossiqFlowNodeListenerTest extends TestCase {

    /**
     * The id each node class reports, in the order the listener registers them.
     *
     * @var array<class-string, string>
     */
    private const EXPECTED_IDS = [
        DossiqTxSendEmailNode::class => 'dossiq.sendEmail',
        DossiqTxCreateTaskNode::class => 'dossiq.createTask',
        DossiqTxCreateSubCaseNode::class => 'dossiq.createSubCase',
        DossiqTxWebhookNode::class => 'dossiq.webhook',
        DossiqTxSetFieldNode::class => 'dossiq.setField',
        DossiqTxSetStatusNode::class => 'dossiq.setStatus',
        DossiqAskPersonNode::class => 'dossiq.askPerson',
        DossiqRequestDecisionNode::class => 'dossiq.requestDecision',
        DossiqEnsureCommitteeNode::class => 'dossiq.ensureCommittee',
        DossiqTxNotifyNode::class => 'dossiq.notify',
        DossiqTxBesluitvormingPublishNode::class => 'dossiq.besluitvormingPublish',
        DossiqTxEvaluateDecisionNode::class => 'dossiq.evaluateDecision',
        DossiqSendEmailNode::class => 'dossiq.action.sendEmail',
        DossiqNotifyRoleNode::class => 'dossiq.action.notifyRole',
        DossiqCallWebhookNode::class => 'dossiq.action.callWebhook',
        DossiqCreateDocumentNode::class => 'dossiq.action.createDocument',
        DossiqMergeTemplateNode::class => 'dossiq.action.mergeTemplate',
        DossiqScheduleReminderNode::class => 'dossiq.action.scheduleReminder',
    ];



    /**
     * Build the listener over a container that yields id-reporting nodes.
     *
     * The listener resolves its nodes from a class-string list, so the test
     * asserts what reaches the CATALOGUE rather than what was injected — which
     * is the thing that actually matters: a node class that exists but never
     * registers is invisible to the flow editor and looks identical to one that
     * works.
     *
     * @param string[] $failing Class names the container should refuse to build.
     *
     * @return DossiqFlowNodeListener The listener under test.
     */
    private function listener(array $failing=[]): DossiqFlowNodeListener {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            function (string $class) use ($failing): IFlowNode {
                if (in_array($class, $failing, true) === true) {
                    throw new RuntimeException('cannot construct ' . $class);
                }

                $node = $this->createMock(IFlowNode::class);
                $node->method('getId')->willReturn(self::EXPECTED_IDS[$class]);
                return $node;
            }
        );

        return new DossiqFlowNodeListener($container, $this->createMock(LoggerInterface::class));

    }//end listener()


    /**
     * An empty node catalogue for the listener to contribute to.
     *
     * The registry, not the event, is where a contributed node lands — the
     * event only carries it. Both take constructor arguments the stubs used
     * to omit, which is how six sibling call sites came to build them in a
     * way that fatals against the real OpenRegister while green here.
     *
     * @return FlowNodeRegistry The catalogue.
     */
    private function registry(): FlowNodeRegistry {
        return new FlowNodeRegistry(
            $this->createMock(IEventDispatcher::class),
            $this->createMock(LoggerInterface::class)
        );

    }//end registry()


    /**
     * Every action lands on the catalogue — both vocabularies.
     *
     * Asserted against the fixture rather than a literal count, so adding a node
     * means adding it in ONE place; a count in the test name goes stale the
     * first time somebody adds a node and does not notice.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testEveryActionIsRegistered(): void {
        $registry = $this->registry();
        $this->listener()->handle(new RegisterFlowNodesEvent($registry));

        $ids = array_keys($registry->all());

        $this->assertSame(
            array_values(self::EXPECTED_IDS),
            $ids
        );

    }//end testEveryActionIsRegistered()


    /**
     * An unrelated event is ignored rather than half-handled.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testUnrelatedEventIsIgnored(): void {
        $other = new class extends Event {
        };

        $this->listener()->handle($other);

        $this->addToAssertionCount(1);

    }//end testUnrelatedEventIsIgnored()

    /**
     * One unbuildable node does not cost the others their place.
     *
     * The list-based resolution introduced this branch: if a single node's
     * dependencies cannot be constructed, aborting would empty the whole
     * catalogue. A skipped node is visible — the editor simply does not offer
     * it — where a failed registration takes everything down with it.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testOneUnbuildableNodeDoesNotCostTheRest(): void {
        $registry = $this->registry();
        $this->listener(failing: [DossiqTxSetFieldNode::class])->handle(new RegisterFlowNodesEvent($registry));

        $ids = array_keys($registry->all());

        // Derived from the fixture, not written as a literal. The sibling test
        // above already says why: a count in a test goes stale the first time
        // somebody adds or retires a node, and this one did — it was 17
        // against a catalogue that had grown to 18.
        $this->assertCount((count(self::EXPECTED_IDS) - 1), $ids);
        $this->assertNotContains('dossiq.setField', $ids);
        $this->assertContains('dossiq.sendEmail', $ids);
        $this->assertContains('dossiq.action.sendEmail', $ids);

    }//end testOneUnbuildableNodeDoesNotCostTheRest()


}//end class
