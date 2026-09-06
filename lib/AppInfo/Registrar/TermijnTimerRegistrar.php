<?php

/**
 * Dossiq termijn timer listener registrar.
 *
 * One clock (termijnbewaking-op-engine-timers): the OpenRegister
 * FlowTimerWorker sweep fires the armed termijn timers, and
 * {@see \OCA\Dossiq\Listener\TermijnTimerFiredListener} does the domain
 * side — threshold bookkeeping, the breach flip, dwangsom accrual sync
 * and pause expiry. Subsystem-scoped per the registrar convention.
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
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Listener\TermijnTimerFiredListener;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the engine timer fired-listener.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */
class TermijnTimerRegistrar {
	/**
	 * Register the timer fired-listener.
	 *
	 * The ::class reference does not autoload, so registration is safe
	 * when OpenRegister is absent; the listener simply never fires.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(
			event: FlowTimerFiredEvent::class,
			listener: TermijnTimerFiredListener::class
		);
	}//end register()
}//end class
