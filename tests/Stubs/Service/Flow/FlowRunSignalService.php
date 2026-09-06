<?php

/**
 * Test stub for OCA\OpenRegister\Service\Flow\FlowRunSignalService.
 *
 * Signature-matched to the real seam (openregister#3332): `signalAs()` and
 * `signalRunAs()` — resolve, assignee guard, audit and delivery in one call,
 * refusing with a typed FlowSignalRefused. The stub delivers unconditionally;
 * dossiq's tests mock this class and drive the refusal paths through the
 * exception, because the guard rule belongs to OpenRegister and is tested
 * there. Self-skips when the real class is present.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;

if (class_exists('\\OCA\\OpenRegister\\Service\\Flow\\FlowRunSignalService', false) === false) {
    /**
     * Minimal FlowRunSignalService stand-in.
     */
    class FlowRunSignalService {

        /**
         * Answer a suspended run by uuid, as a named actor, guarded.
         *
         * @param string      $runUuid  The run to answer.
         * @param array       $payload  What the signaller wants the run to know.
         * @param string|null $actorUid Who is answering; null means anonymous.
         * @param string|null $nodeId   The node the answer addresses, when known.
         *
         * @return FlowRun The parked run.
         */
        public function signalAs(string $runUuid, array $payload, ?string $actorUid, ?string $nodeId = null): FlowRun {
            return new FlowRun();
        }

        /**
         * Answer an already-resolved run, as a named actor, guarded.
         *
         * @param FlowRun     $run      The run to answer.
         * @param array       $payload  What the signaller wants the run to know.
         * @param string|null $actorUid Who is answering; null means anonymous.
         * @param string|null $nodeId   The node the answer addresses, when known.
         *
         * @return FlowRun The parked run.
         */
        public function signalRunAs(FlowRun $run, array $payload, ?string $actorUid, ?string $nodeId = null): FlowRun {
            return $run;
        }
    }
}
