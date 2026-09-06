<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Test stub for OpenRegister's flow-node catalogue.
 *
 * SideEffectDispatcher resolves nodes through it, so the dispatcher's own tests
 * need the type to exist. Declaration-only: the real class wins whenever
 * OpenRegister is installed.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Flow
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCP\EventDispatcher\IEventDispatcher;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * Stub of OpenRegister's FlowNodeRegistry.
 *
 * ⚠️ THE CONSTRUCTOR IS PART OF THE CONTRACT. This stub used to take no
 * arguments while the real class has always required two, so six call sites
 * across two suites constructed it in a way that fatals against the real
 * OpenRegister — and every one of them was green here. A stub that is easier
 * to build than the thing it stands for teaches the suite the wrong shape.
 */
class FlowNodeRegistry {

	/**
	 * Registered nodes, keyed by id.
	 *
	 * @var array<string, IFlowNode>
	 */
	private array $nodes = [];

	/**
	 * Constructor, mirroring the real class's.
	 *
	 * The dispatcher is where the real registry collects contributed nodes;
	 * this stub is registered into directly, so it holds them and uses
	 * neither dependency.
	 *
	 * @param IEventDispatcher    $dispatcher Dispatches the contribution event.
	 * @param LoggerInterface     $logger     The logger.
	 * @param IURLGenerator|null  $urls       URL generator. Optional on the real
	 *                                        class and unused here, but present
	 *                                        so the signatures match: a stub that
	 *                                        is one argument short is exactly the
	 *                                        drift StubApiDriftTest exists to
	 *                                        catch.
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
		private readonly ?IURLGenerator $urls = null,
	) {
	}//end __construct()

	/**
	 * Register a node.
	 *
	 * @param IFlowNode $node The node.
	 *
	 * @return void
	 */
	public function register(IFlowNode $node): void {
		$this->nodes[$node->getId()] = $node;

	}//end register()

	/**
	 * Every registered node type, keyed by id, in registration order.
	 *
	 * Present because the real class has it, and because it is where a test
	 * reads back what an app contributed.
	 *
	 * @param integer|null $scope Ignored here; the real class narrows by it.
	 *
	 * @return array<string, IFlowNode> The catalogue.
	 */
	public function all(?int $scope = null): array {
		return $this->nodes;
	}//end all()

	/**
	 * Resolve a node by its type id.
	 *
	 * @param string $type The node id.
	 *
	 * @return IFlowNode The node.
	 *
	 * @throws UnexpectedValueException When no app provides that type.
	 */
	public function get(string $type): IFlowNode {
		if (isset($this->nodes[$type]) === false) {
			throw new UnexpectedValueException(sprintf('No node provides "%s".', $type));
		}

		return $this->nodes[$type];
	}//end get()

}//end class
