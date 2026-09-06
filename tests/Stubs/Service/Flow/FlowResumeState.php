<?php

/**
 * Test stub for OCA\OpenRegister\Service\Flow\FlowResumeState.
 *
 * The run-level bag of per-node resume slots. dossiq reads `CONTEXT_KEY` to
 * find the slot that records which decision a suspended run is waiting on, and
 * the two waiting nodes reach their own slot through the scoped handle
 * {@see FlowNodeResumeState} that `forNode()` hands out.
 *
 * Its absence was not a test failure — it was a PSALM failure
 * (`UndefinedClass`), because dossiq maps the whole OpenRegister namespace to
 * this directory. A class dossiq references but never stubs simply does not
 * exist as far as static analysis is concerned.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

if (class_exists('\\OCA\\OpenRegister\\Service\\Flow\\FlowResumeState', false) === false) {
    /**
     * FlowResumeState stand-in, mirroring the real class's public API.
     *
     * The slot storage lives HERE and not in the per-node handle, exactly as
     * it does in OpenRegister. That is not a detail: a handle holding its own
     * copy would let a node write progress the run never sees, which is the
     * one behaviour every heartbeat-recovery test depends on being real.
     */
    class FlowResumeState implements \JsonSerializable {

        /**
         * The run-context key the per-node slots travel under.
         *
         * @var string
         */
        public const CONTEXT_KEY = 'resumeState';

        /**
         * Constructor.
         *
         * The parameter is named `byNode` because the real one is: a caller
         * writing `new FlowResumeState(byNode: [...])` must not be green here
         * and fatal against OpenRegister.
         *
         * @param array<string, array<string, mixed>> $byNode The stored slots.
         */
        public function __construct(
            private array $byNode = [],
        ) {
        }

        /**
         * A view of one node's slot.
         *
         * @param string $nodeId The node's id in the flow document.
         *
         * @return FlowNodeResumeState The scoped handle handed to that node.
         */
        public function forNode(string $nodeId): FlowNodeResumeState {
            return new FlowNodeResumeState(parent: $this, nodeId: $nodeId);
        }

        /**
         * Read a node's slot.
         *
         * @param string $nodeId The node's id.
         *
         * @return array<string, mixed> The stored values, empty when it has none.
         */
        public function read(string $nodeId): array {
            return (array) ($this->byNode[$nodeId] ?? []);
        }

        /**
         * Replace a node's slot.
         *
         * Writing an empty array FORGETS the slot rather than storing one, as
         * the real class does; a stub that stored it would report a node as
         * resuming when the engine would call it fresh.
         *
         * @param string               $nodeId The node's id.
         * @param array<string, mixed> $values The values to hold.
         *
         * @return void
         */
        public function write(string $nodeId, array $values): void {
            if ($values === []) {
                $this->forget(nodeId: $nodeId);
                return;
            }

            $this->byNode[$nodeId] = $values;
        }

        /**
         * Drop a node's slot.
         *
         * @param string $nodeId The node's id.
         *
         * @return void
         */
        public function forget(string $nodeId): void {
            unset($this->byNode[$nodeId]);
        }

        /**
         * Whether any node holds progress.
         *
         * @return boolean True when no slot is occupied.
         */
        public function isEmpty(): bool {
            return ($this->byNode === []);
        }

        /**
         * Every slot.
         *
         * @return array<string, array<string, mixed>> The slots, keyed by node id.
         */
        public function all(): array {
            return $this->byNode;
        }

        /**
         * What the run should persist, given whether it can still advance.
         *
         * The parameter is `$live`, not `$suspended`, and the difference is
         * semantic rather than cosmetic: a run that is queued or running is
         * ALSO not terminal, and must keep its slots. Renamed in
         * openregister#3358; a named-argument call site cannot work against
         * both spellings, which is what StubApiDriftTest caught.
         *
         * @param boolean $live Whether the run can still advance (any
         *                      non-terminal status — suspended, queued,
         *                      running).
         *
         * @return array<string, array<string, mixed>>|null The slots, or null.
         */
        public function storableWhen(bool $live): ?array {
            if ($live === false || $this->byNode === []) {
                return null;
            }

            return $this->byNode;
        }

        /**
         * The stored shape.
         *
         * @return array<string, array<string, mixed>> The slots.
         */
        public function jsonSerialize(): array {
            return $this->byNode;
        }
    }
}
