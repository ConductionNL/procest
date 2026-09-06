<?php

/**
 * Dossiq listener registrar.
 *
 * The composite over every event listener Application::register() wires up:
 * the cross-subsystem object-lifecycle listeners, the bezwaar / parafering
 * listeners, and the termijn + decision workflow listeners. It owns no
 * registration itself — only which specialised registrars run.
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
 * @spec openspec/specs/bezwaar-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Runs every event-listener registrar.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/bezwaar-lifecycle/spec.md
 */
class ListenerRegistrar {
	/**
	 * Register every dossiq event listener.
	 *
	 * The bezwaar listeners that declare a register/schema interest are NOT
	 * registered here — they are subscribed from boot() by
	 * {@see BezwaarSubscriptionRegistrar}, because the OpenRegister
	 * `ObjectEventSubscription` guard only resolves once every app's register()
	 * has run.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bezwaar-lifecycle/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		(new ObjectListenerRegistrar())->register(context: $context);
		(new ImmutabilityListenerRegistrar())->register(context: $context);
		(new BezwaarListenerRegistrar())->register(context: $context);
		(new WorkflowListenerRegistrar())->register(context: $context);
		(new TermijnTimerRegistrar())->register(context: $context);

		// ADR-065: OpenRegister owns the flow engine; dossiq contributes the six
		// things a case can DO, because every one of OpenRegister's own nineteen
		// nodes is control-flow or data and none of them acts outward.
		//
		// Guarded on the event class, the same way hermiq guards its node
		// listener: `::class` is a compile-time string and does not autoload, so
		// an instance without OpenRegister still boots — it simply offers no
		// nodes rather than failing at registration.
		if (class_exists(\OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent::class) === true) {
			$context->registerEventListener(
				\OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent::class,
				\OCA\Dossiq\Flow\DossiqFlowNodeListener::class
			);
		}

		// ADR-041 delivery seam: integriq concludes a besluit-publication
		// delivery this app requested (PublicationService) with a terminal
		// DeliveryConcludedEvent; the listener projects the outcome onto the
		// case's publication record. FQN string, not ::class — integriq is an
		// optional runtime dependency and a cross-app event class name is a
		// runtime lookup this app can only follow (see the decidesk→decidiq
		// rename incident in WorkflowListenerRegistrar).
		if (class_exists('\\OCA\\Integriq\\Event\\DeliveryConcludedEvent') === true) {
			$context->registerEventListener(
				'OCA\Integriq\Event\DeliveryConcludedEvent',
				\OCA\Dossiq\Listener\DeliveryConcludedListener::class
			);
		}
	}//end register()
}//end class
