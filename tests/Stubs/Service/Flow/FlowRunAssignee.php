<?php

/**
 * Test stub for OCA\OpenRegister\Service\Flow\FlowRunAssignee.
 *
 * ⚠️ This stub deliberately does NOT reimplement the rule. It permits
 * everything, so a dossiq test that used it as-is would prove nothing about
 * authorization — which is the point: the rule is OpenRegister's and is tested
 * there (FlowRunAssigneeTest, plus a mutation check). dossiq's own tests inject
 * a double whose answer they control and assert that the LISTENER OBEYS it.
 *
 * Reimplementing the rule here would produce the worst outcome available: a
 * second copy of an access rule, tested against itself, drifting from the real
 * one while both suites stay green.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCP\IGroupManager;

if (class_exists('\\OCA\\OpenRegister\\Service\\Flow\\FlowRunAssignee', false) === false) {
    /**
     * Minimal FlowRunAssignee stand-in.
     */
    class FlowRunAssignee {

        /**
         * Constructor.
         *
         * @param IGroupManager|null $groupManager Group resolution.
         */
        public function __construct(
            private readonly ?IGroupManager $groupManager = null,
        ) {
        }

        /**
         * The recorded assignee.
         *
         * `$nodeId` is not optional decoration. A run accumulates one resume
         * slot PER NODE, so "who is this run assigned to" has no single answer
         * once a flow asks twice; the real method takes the node and this stub
         * must too, or a caller that names one is green here and fatals there.
         *
         * @param FlowRun     $run    The run.
         * @param string|null $nodeId The node whose slot records the assignee.
         *
         * @return string The assignee.
         */
        public function recordedFor(FlowRun $run, ?string $nodeId = null): string {
            return '';
        }

        /**
         * Whether this user may answer.
         *
         * @param FlowRun     $run    The run.
         * @param string|null $uid    The user.
         * @param string|null $nodeId The node being answered.
         *
         * @return boolean Always true — see the file docblock.
         */
        public function mayAnswer(FlowRun $run, ?string $uid, ?string $nodeId = null): bool {
            return true;
        }
    }
}
