<?php

/**
 * Dossiq workflow listener registrar.
 *
 * The two delegation-boundary listeners: the AWB termijn binding that runs on
 * case creation, and the decidesk decision-outcome listener that materialises a
 * ZGW Besluit from a concluded decision. Both are pure observers (ADR-022).
 *
 * @category AppInfo
 * @package  OCA\Dossiq\AppInfo\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Listener\DeadlineCaseCreatedListener;
use OCA\Dossiq\Listener\DecisionConcludedListener;
use OCA\Dossiq\Listener\TaskCompletionResumeListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the termijnbewaking and decision-outcome listeners.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
 */
class WorkflowListenerRegistrar {
	/**
	 * Every spelling of the decision-concluded event, newest first.
	 *
	 * A cross-app event class name is a RUNTIME lookup: this app cannot move it,
	 * only follow it. When the other app renamed its namespace without an alias,
	 * naming one spelling meant the listener silently stopped registering — so
	 * both are listed, and the listener attaches to whichever exists. The old
	 * entry can be dropped once no supported install still ships it.
	 *
	 * @var array<int, string>
	 */
	private const DECISION_CONCLUDED_EVENTS = [
		'OCA\Decidiq\Event\DecisionConcludedEvent',
		'OCA\Decidesk\Event\DecisionConcludedEvent',
	];

	/**
	 * Register the termijn and decision listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
	 */
	public function register(IRegistrationContext $context): void {
		$this->registerTermListeners(context: $context);
		$this->registerDecisionListeners(context: $context);
		$this->registerHumanStepListeners(context: $context);
	}//end register()

	/**
	 * Register termijnbewaking (AWB deadline engine) listeners.
	 *
	 * On case creation, an AWB TermijnInstance is automatically bound to
	 * the case using the active TermijnDefinitie for the zaaktype. The
	 * listener is a pure observer (ADR-022); all logic lives in
	 * {@see \OCA\Dossiq\Service\TermijnService}.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
	 */
	private function registerTermListeners(IRegistrationContext $context): void {
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: DeadlineCaseCreatedListener::class
		);
	}//end registerTermijnListeners()

	/**
	 * Register the decidesk decision-outcome listener.
	 *
	 * Dossiq delegates contract / besluit / bezwaar / advice DECISIONS to
	 * decidesk by dispatching `DecisionRequestedEvent`; the terminal outcome
	 * arrives back as decidesk's `DecisionConcludedEvent`. This listener
	 * materialises the ZGW `Besluit` from that outcome (filtered to this app via
	 * `getSourceApp()`). The event class is registered by FQN string and only
	 * when decidesk is installed, so dossiq carries no hard compile-time
	 * dependency on the optional decidesk app.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-003-the-zgw-besluit-is-materialised-from-the-decisionconcludedevent
	 */
	private function registerDecisionListeners(IRegistrationContext $context): void {
		// BOTH spellings, and the old one is not optional politeness — it is the
		// only thing that keeps this integration working during an upgrade where
		// the two apps move at different times.
		//
		// The app renamed its namespace from OCA\Decidesk to OCA\Decidiq with no
		// compatibility alias. This guard named only the OLD class, so from the
		// moment that landed it returned false, the listener was never
		// registered, and every concluded decision stopped materialising a ZGW
		// Besluit here. Nothing errored: a class_exists() guard that goes false
		// looks exactly like the optional app not being installed.
		//
		// FQN strings (not ::class) so there is no hard compile-time dependency
		// on the optional app — mirrors the OpenRegister approval-event
		// registration in BezwaarListenerRegistrar.
		foreach (self::DECISION_CONCLUDED_EVENTS as $event) {
			if (class_exists('\\' . $event) === false) {
				continue;
			}

			$context->registerEventListener(event: $event, listener: DecisionConcludedListener::class);
		}
	}//end registerDecisionListeners()

	/**
	 * Register the listener that resumes a run when its task is completed.
	 *
	 * A task is an ordinary OpenRegister object, so completing one is an object
	 * UPDATE — there is no dossiq task endpoint this could hang on instead.
	 *
	 * Registered unconditionally: unlike the decision events, `ObjectUpdatedEvent`
	 * is OpenRegister's own and OpenRegister is a hard dependency of this app.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/task-management/spec.md
	 */
	private function registerHumanStepListeners(IRegistrationContext $context): void {
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: TaskCompletionResumeListener::class
		);

	}//end registerHumanStepListeners()
}//end class
