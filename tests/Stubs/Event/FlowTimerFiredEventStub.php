<?php

/**
 * Test stub for OCA\OpenRegister\Event\FlowTimerFiredEvent.
 *
 * Mirrors the REAL constructor and getter signatures of the engine event
 * (a stub that agrees with the caller cannot fail): timer, kind, named
 * transition, rung key, recipients, priority, message. Self-skips when
 * the real class is present.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\FlowTimer;
use OCP\EventDispatcher\Event;

if (class_exists('\\OCA\\OpenRegister\\Event\\FlowTimerFiredEvent', false) === false) {
    /**
     * FlowTimerFiredEvent stand-in with the engine's own signature.
     */
    class FlowTimerFiredEvent extends Event {

        /**
         * Kinds of fire, as the engine declares them.
         */
        public const KIND_RUNG = 'rung';

        public const KIND_EXPIRY = 'expiry';

        /**
         * Constructor, mirroring the engine's parameter names and order.
         *
         * @param FlowTimer $timer The timer that fired.
         * @param string $kind `rung` or `expiry`.
         * @param string $transition The named transition raised.
         * @param string|null $rungKey The rung's stable key, for a rung fire.
         * @param array $recipients The resolved addressees.
         * @param string|null $priority The rung's priority.
         * @param string|null $message The message identity.
         */
        public function __construct(
            private readonly FlowTimer $timer,
            private readonly string $kind,
            private readonly string $transition,
            private readonly ?string $rungKey,
            private readonly array $recipients,
            private readonly ?string $priority,
            private readonly ?string $message,
        ) {
            parent::__construct();
        }//end __construct()

        /**
         * The timer that fired.
         *
         * @return FlowTimer
         */
        public function getTimer(): FlowTimer {
            return $this->timer;
        }//end getTimer()

        /**
         * Whether this was a rung or the expiry.
         *
         * @return string
         */
        public function getKind(): string {
            return $this->kind;
        }//end getKind()

        /**
         * The named transition raised.
         *
         * @return string
         */
        public function getTransition(): string {
            return $this->transition;
        }//end getTransition()

        /**
         * The rung key, for a rung fire.
         *
         * @return string|null
         */
        public function getRungKey(): ?string {
            return $this->rungKey;
        }//end getRungKey()

        /**
         * The resolved addressees.
         *
         * @return array
         */
        public function getRecipients(): array {
            return $this->recipients;
        }//end getRecipients()

        /**
         * The rung's priority.
         *
         * @return string|null
         */
        public function getPriority(): ?string {
            return $this->priority;
        }//end getPriority()

        /**
         * The message identity.
         *
         * @return string|null
         */
        public function getMessage(): ?string {
            return $this->message;
        }//end getMessage()
    }
}//end if
