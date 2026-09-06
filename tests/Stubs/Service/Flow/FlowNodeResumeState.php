<?php

/**
 * Test stub for OCA\OpenRegister\Service\Flow\FlowNodeResumeState.
 *
 * A read/write handle on ONE node's resume slot, scoped out of the run-level
 * {@see FlowResumeState}. dossiq's two waiting nodes read their slot through
 * this handle to tell a first pass from a heartbeat re-entry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

if (class_exists('\\OCA\\OpenRegister\\Service\\Flow\\FlowNodeResumeState', false) === false) {
    /**
     * FlowNodeResumeState stand-in, mirroring the real class's public API.
     *
     * Two things about it used to be wrong in the dangerous direction, and
     * both are pinned by tests/Unit/Support/StubApiDriftTest.php now.
     *
     * The CONSTRUCTOR took `(string $nodeId, array $values)`, both optional,
     * where the real one requires `(FlowResumeState $parent, string $nodeId)`.
     * Every call site built here therefore fatals against OpenRegister.
     *
     * The CONTEXT_KEY said `resumeState`, which is
     * {@see FlowResumeState::CONTEXT_KEY} — the RUN-level bag, not this. The
     * real value is `resume`. The collision is what made the wrong constructor
     * survivable: a test could put a node handle at the run-level key and the
     * node would find it, in a layout the engine never produces.
     */
    class FlowNodeResumeState {

        /**
         * The context key the scoped handle is reachable at.
         *
         * Deliberately DIFFERENT from FlowResumeState::CONTEXT_KEY: that one
         * holds every node's slot and is what gets persisted, this one is the
         * single-node view a node actually uses.
         *
         * @var string
         */
        public const CONTEXT_KEY = 'resume';

        /**
         * Constructor.
         *
         * @param FlowResumeState $parent The state holding every node's slot.
         * @param string          $nodeId The node this view is scoped to.
         */
        public function __construct(
            private readonly FlowResumeState $parent,
            private readonly string $nodeId,
        ) {
        }

        /**
         * This node's id within the graph.
         *
         * @return string The node id.
         */
        public function nodeId(): string {
            return $this->nodeId;
        }

        /**
         * Whether a key is held.
         *
         * @param string $key The key.
         *
         * @return boolean True when held.
         */
        public function has(string $key): bool {
            return array_key_exists($key, $this->parent->read(nodeId: $this->nodeId));
        }

        /**
         * Read a value.
         *
         * @param string $key     The key.
         * @param mixed  $default Returned when absent.
         *
         * @return mixed The value.
         */
        public function get(string $key, mixed $default = null): mixed {
            $values = $this->parent->read(nodeId: $this->nodeId);

            return ($values[$key] ?? $default);
        }

        /**
         * Write one value.
         *
         * @param string $key   The key.
         * @param mixed  $value The value.
         *
         * @return void
         */
        public function set(string $key, mixed $value): void {
            $values = $this->parent->read(nodeId: $this->nodeId);
            $values[$key] = $value;
            $this->parent->write(nodeId: $this->nodeId, values: $values);
        }

        /**
         * Merge values into the slot.
         *
         * @param array<string, mixed> $values The values.
         *
         * @return void
         */
        public function merge(array $values): void {
            $this->parent->write(
                nodeId: $this->nodeId,
                values: array_merge($this->parent->read(nodeId: $this->nodeId), $values)
            );
        }

        /**
         * Everything held.
         *
         * @return array<string, mixed> The slot.
         */
        public function all(): array {
            return $this->parent->read(nodeId: $this->nodeId);
        }

        /**
         * Whether this node is resuming rather than starting.
         *
         * @return boolean True when the slot holds anything.
         */
        public function isResuming(): bool {
            return ($this->parent->read(nodeId: $this->nodeId) !== []);
        }

        /**
         * Drop this node's progress.
         *
         * @return void
         */
        public function clear(): void {
            $this->parent->forget(nodeId: $this->nodeId);
        }
    }
}
