<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Test stub for OpenRegister's node-registration collect event.
 *
 * dossiq's DossiqFlowNodeListener is typed against it, so without this stub
 * the listener cannot be loaded — and phpstan cannot resolve the
 * `IEventListener<RegisterFlowNodesEvent>` generic bound either.
 *
 * DELIBERATELY NOT A FAITHFUL COPY OF THE CONSTRUCTOR. The real event takes
 * OpenRegister's FlowNodeRegistry and hands each node straight to it; dossiq
 * has no such class and does not need one to prove it registers the right six.
 * This stub collects them instead, which is exactly what a consumer-side test
 * needs to assert.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Flow
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCP\EventDispatcher\Event;

/**
 * Stub of OpenRegister's RegisterFlowNodesEvent.
 *
 * ⚠️ IT CARRIES A REGISTRY, because the real one does. This stub used to
 * take no constructor argument and expose a `getRegisteredNodes()` accessor of
 * its own invention, so the listener's tests read the contribution from a
 * place that does not exist in production and built the event in a way that
 * fatals against the real class. The catalogue is where a contributed node
 * lands; asking the event instead was asking the wrong object.
 */
class RegisterFlowNodesEvent extends Event {

	/**
	 * Constructor, mirroring the real class's.
	 *
	 * @param FlowNodeRegistry $registry The registry to contribute to.
	 */
	public function __construct(
		private readonly FlowNodeRegistry $registry,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Register one node on the catalogue.
	 *
	 * @param IFlowNode $node The node to register.
	 *
	 * @return void
	 */
	public function registerNode(IFlowNode $node): void {
		$this->registry->register(node: $node);

	}//end registerNode()

}//end class
