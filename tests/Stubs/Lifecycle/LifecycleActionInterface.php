<?php

/**
 * Test stub mirroring OpenRegister's LifecycleActionInterface.
 *
 * Dossiq's lib/Lifecycle actions implement OR's
 * OCA\OpenRegister\Lifecycle\LifecycleActionInterface, which is only present at
 * runtime when OpenRegister is installed. This stub lets the dossiq unit suite
 * and the static analysers resolve the type without the OR app on the
 * classpath. It is autoloaded via the OCA\OpenRegister\ → tests/Stubs/ map in
 * composer.json (autoload-dev).
 *
 * 🔴 THE SIGNATURE IS COPIED, NOT PARAPHRASED — parameter names included. These
 * are called by name from OpenRegister's LifecycleActionExecutor, so a stub
 * that renamed one would let a call compile here and fail on a live instance,
 * which is the class of defect a stub is most likely to introduce.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Stub
 * @package  OCA\OpenRegister\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Lifecycle;

/**
 * Runs a declared lifecycle action on a transition.
 */
interface LifecycleActionInterface {
	/**
	 * Run the action on a transitioning object.
	 *
	 * @param array<string, mixed> $objectData The object payload after the lifecycle field was moved to its target value.
	 * @param array<string, mixed> $previousData The object payload before the transition.
	 * @param array<string, mixed> $parameters The declared `actionParameters` block (empty array when absent).
	 * @param string $actionName The declared `action` name that resolved to this handler.
	 *
	 * @return array<string, mixed> The object payload, with any self-mutations applied.
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array;
}//end interface
