<?php

/**
 * Test stub for OCA\OpenRegister\Exception\FlowSignalRefused.
 *
 * Mirrors the real exception (openregister#3332): reason constants, and the
 * accessors the listener reads. It must extend RuntimeException as the real one
 * does — a catch clause in a test is meaningless against a non-Throwable.
 * Self-skips when the real class is present.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use RuntimeException;

if (class_exists('\\OCA\\OpenRegister\\Exception\\FlowSignalRefused', false) === false) {
    /**
     * Thrown by the guarded signal seam when a signal may not be delivered.
     */
    class FlowSignalRefused extends RuntimeException {

        /**
         * No run carries the given uuid.
         *
         * @var string
         */
        public const RUN_NOT_FOUND = 'run-not-found';

        /**
         * The awaiting step is assigned, and the actor is not its assignee.
         *
         * @var string
         */
        public const NOT_ASSIGNEE = 'not-assignee';

        /**
         * The run is not suspended, so there is nothing to answer.
         *
         * @var string
         */
        public const NOT_SUSPENDED = 'not-suspended';

        /**
         * Constructor.
         *
         * @param string      $reason   One of the reason constants.
         * @param string      $message  What went wrong, for a human.
         * @param string      $runUuid  The run the signal addressed.
         * @param string|null $actorUid The refused actor, or null when anonymous.
         */
        public function __construct(
            private readonly string $reason,
            string $message,
            private readonly string $runUuid = '',
            private readonly ?string $actorUid = null,
        ) {
            parent::__construct(message: $message);
        }

        /**
         * Why the signal was refused — one of the reason constants.
         *
         * @return string The reason.
         */
        public function getReason(): string {
            return $this->reason;
        }

        /**
         * The run the refused signal addressed.
         *
         * @return string The run uuid, or '' when unknown.
         */
        public function getRunUuid(): string {
            return $this->runUuid;
        }

        /**
         * The refused actor.
         *
         * @return string|null The actor uid, or null when the caller was anonymous.
         */
        public function getActorUid(): ?string {
            return $this->actorUid;
        }
    }
}
