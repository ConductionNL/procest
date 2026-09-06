<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Transitions
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCP\EventDispatcher\IEventDispatcher;
use OCA\Dossiq\Service\Transitions\ActionHandlerInterface;
use OCA\Dossiq\Service\Transitions\ActionHandlerRegistry;
use OCA\Dossiq\Service\Transitions\ActionResult;
use OCA\Dossiq\Service\Transitions\SideEffectDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the transition side-effect dispatcher after the flow-node port.
 *
 * It had NO direct tests before this — both suites that mention it mock it away
 * — while it is the thing that decides whether a status change fires its
 * actions at all.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
 */
class SideEffectDispatcherTest extends TestCase {


    /**
     * A node that records nothing and either succeeds or throws.
     *
     * @param string          $id     The node id.
     * @param \Throwable|null $throws Optional failure to raise.
     *
     * @return IFlowNode The node.
     */
    private function node(string $id, ?\Throwable $throws=null): IFlowNode {
        $node = $this->createMock(IFlowNode::class);
        $node->method('getId')->willReturn($id);
        if ($throws !== null) {
            $node->method('execute')->willThrowException($throws);
        } else {
            $node->method('execute')->willReturnArgument(0);
        }

        return $node;

    }//end node()


    /**
     * Build the dispatcher over an optional node catalogue.
     *
     * @param FlowNodeRegistry|null      $nodes  The catalogue, or null for the fallback path.
     * @param ActionHandlerRegistry|null $legacy The local registry.
     *
     * @return SideEffectDispatcher The dispatcher.
     */
    private function dispatcher(?FlowNodeRegistry $nodes, ?ActionHandlerRegistry $legacy=null): SideEffectDispatcher {
        $container = $this->createMock(ContainerInterface::class);
        if ($nodes !== null) {
            $container->method('get')->willReturn($nodes);
        } else {
            $container->method('get')->willThrowException(new RuntimeException('absent'));
        }

        return new SideEffectDispatcher(
            $legacy ?? $this->createMock(ActionHandlerRegistry::class),
            $container,
            $this->createMock(LoggerInterface::class)
        );

    }//end dispatcher()


    /**
     * An empty node catalogue, built the way the real one must be.
     *
     * The registry takes a dispatcher and a logger — it collects contributed
     * nodes through the first — and these tests register into it directly, so
     * neither dependency does anything here. They are passed because leaving
     * them out is a fatal against the real class, which is what a no-argument
     * stub hid for six call sites.
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
     * With OpenRegister present, a transition action runs the SHARED node.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testActionsRunThroughTheSharedNode(): void {
        $registry = $this->registry();
        $registry->register($this->node('dossiq.sendEmail'));

        $results = $this->dispatcher($registry)->dispatch(
            [['type' => 'sendEmail']],
            ['id' => 'case-1'],
            ['transition' => 'submitted']
        );

        $this->assertSame([['type' => 'sendEmail', 'ok' => true]], $results);

    }//end testActionsRunThroughTheSharedNode()


    /**
     * The dispatcher resolves the LIVE id space, not the catalogue's.
     *
     * Both action systems ship a sendEmail. Resolving `dossiq.action.sendEmail`
     * here would run the configured-action handler for a transition — a
     * different class with different config keys.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testItResolvesTheLiveIdSpace(): void {
        $registry = $this->registry();
        $registry->register($this->node('dossiq.action.sendEmail'));

        $results = $this->dispatcher($registry)->dispatch([['type' => 'sendEmail']], [], []);

        $this->assertFalse($results[0]['ok']);
        $this->assertSame('unknown_action_type', $results[0]['error']);

    }//end testItResolvesTheLiveIdSpace()


    /**
     * A node that throws becomes a failed row — it does NOT abort the loop.
     *
     * A node signals failure by throwing, because the flow engine's onError
     * policy only sees what propagates. This dispatcher's contract is the
     * opposite and predates it: a failed action must not stop the remaining
     * ones or roll back the status change.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testAFailedActionDoesNotAbortTheRest(): void {
        $registry = $this->registry();
        $registry->register($this->node('dossiq.sendEmail', new RuntimeException('smtp down')));
        $registry->register($this->node('dossiq.createTask'));

        $results = $this->dispatcher($registry)->dispatch(
            [['type' => 'sendEmail'], ['type' => 'createTask']],
            [],
            []
        );

        $this->assertCount(2, $results);
        $this->assertFalse($results[0]['ok']);
        $this->assertSame('smtp down', $results[0]['error']);
        $this->assertTrue($results[1]['ok']);

    }//end testAFailedActionDoesNotAbortTheRest()


    /**
     * An action type nothing provides is reported, not silently dropped.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testUnknownActionTypeIsReported(): void {
        $results = $this->dispatcher($this->registry())->dispatch(
            [['type' => 'doesNotExist']],
            [],
            []
        );

        $this->assertSame(
            [['type' => 'doesNotExist', 'ok' => false, 'error' => 'unknown_action_type']],
            $results
        );

    }//end testUnknownActionTypeIsReported()


    /**
     * Without OpenRegister the local handlers still fire.
     *
     * A transition must never silently skip its side effects because a
     * neighbouring app is not installed.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testFallsBackToTheLocalHandlersWithoutOpenRegister(): void {
        $handler = $this->createMock(ActionHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturn(new ActionResult(true));

        $legacy = $this->createMock(ActionHandlerRegistry::class);
        $legacy->method('getHandler')->willReturn($handler);

        $results = $this->dispatcher(null, $legacy)->dispatch([['type' => 'sendEmail']], [], []);

        $this->assertSame([['type' => 'sendEmail', 'ok' => true]], $results);

    }//end testFallsBackToTheLocalHandlersWithoutOpenRegister()


    /**
     * An action with no type is skipped rather than dispatched blind.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testTypelessActionIsSkipped(): void {
        $this->assertSame(
            [],
            $this->dispatcher($this->registry())->dispatch([['config' => 1]], [], [])
        );

    }//end testTypelessActionIsSkipped()


}//end class
