<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Flow
 * @package   OCA\Dossiq\Flow
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Flow;

use JsonSerializable;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use RuntimeException;

/**
 * The task rows an ask writes and reads back.
 *
 * WHY THIS IS ITS OWN CLASS. `dossiq.askPerson` became a two-way conversation
 * with storage when it learned to recover: it writes a task once and then reads
 * that row on every re-entry, and both halves have to agree about where the row
 * lives, what identifies it, and which shapes the duck-typed object service can
 * hand back. Keeping the pair together is what stops the write and the read
 * drifting apart; leaving them inline pushed the node past its complexity
 * budget, which was the measurement saying the same thing.
 *
 * DUCK-TYPED ON PURPOSE. `SettingsService::getObjectService()` resolves
 * OpenRegister's service as `?object`, because dossiq stays installable without
 * it. Everything here therefore accepts what that service really returns rather
 * than what a caller assumes — the assumption that a save returned an array is
 * exactly what once left every created task orphaned.
 *
 * @spec openspec/changes/askperson-recovers-a-missed-answer/specs/case-flow-human-steps/spec.md
 */
class AskPersonTaskStore {


    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Resolves the object service and the configured schemas.
     *
     * @return void
     *
     * @spec openspec/changes/askperson-recovers-a-missed-answer/specs/case-flow-human-steps/spec.md
     */
    public function __construct(
        private readonly SettingsService $settingsService,
    ) {

    }//end __construct()


    /**
     * Write the task and return the id the run must remember.
     *
     * The write runs under the flow run's `runAs` identity because the
     * engine's RegistryStepDispatcher executes every contributed node inside
     * `ObjectService::runAs()` (openregister#3332) — the seam that fixed the
     * 'Anonymous' refusal which stopped the seeded case flow live.
     *
     * @param array $task The task to persist.
     *
     * @return string The created task's id.
     *
     * @throws RuntimeException When storage is unavailable, unconfigured, or the
     *                          written task cannot be identified.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function create(array $task): string {
        $objectService = $this->objectService();
        [$register, $taskSchema] = $this->location();

        $created = $objectService->saveObject(object: $task, register: $register, schema: $taskSchema);

        $taskId = $this->createdTaskId(created: $created);
        if ($taskId === '') {
            // A task that was written but cannot be identified is worse than
            // none: the slot would stay empty, so the next heartbeat writes
            // another, and the run accumulates duplicates nobody asked for.
            throw new RuntimeException('dossiq.askPerson could not identify the task it created');
        }

        return $taskId;

    }//end create()


    /**
     * The task carrying this id, or null when that row is gone.
     *
     * A MISSING row and an UNREADABLE store are different answers and the
     * caller treats them differently, so only the miss is turned into null;
     * everything else propagates and buys the run another heartbeat.
     *
     * @param string $taskId The task id held in the resume slot.
     *
     * @return array|null The task, or null when no row carries that id.
     *
     * @throws RuntimeException When storage is unavailable, unconfigured, or
     *                          answers with something that is not an object.
     *
     * @spec openspec/changes/askperson-recovers-a-missed-answer/specs/case-flow-human-steps/spec.md
     */
    public function find(string $taskId): ?array {
        $objectService = $this->objectService();
        [$register, $taskSchema] = $this->location();

        try {
            $found = $objectService->find($taskId, register: $register, schema: $taskSchema);
        } catch (DoesNotExistException) {
            return null;
        }

        if ($found === null) {
            return null;
        }

        return $this->asTask(found: $found, taskId: $taskId);

    }//end find()


    /**
     * The object service, or a loud refusal.
     *
     * @return object The object service.
     *
     * @throws RuntimeException When storage is unavailable.
     */
    private function objectService(): object {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('storage_unavailable');
        }

        return $objectService;

    }//end objectService()


    /**
     * Where the task rows live: the register and the task schema.
     *
     * @return array{0: string, 1: string} The register and schema.
     *
     * @throws RuntimeException When either is unconfigured.
     */
    private function location(): array {
        $register   = $this->settingsService->getConfigValue(key: 'register');
        $taskSchema = $this->settingsService->getConfigValue(key: 'task_schema');
        if ($register === '' || $taskSchema === '') {
            throw new RuntimeException('task_schema_not_configured');
        }

        return [$register, $taskSchema];

    }//end location()


    /**
     * The task the object service answered with, as an array.
     *
     * @param mixed  $found  Whatever the read returned.
     * @param string $taskId The id that was asked for, for the message.
     *
     * @return array The task.
     *
     * @throws RuntimeException When the answer is not something a task can be read from.
     */
    private function asTask(mixed $found, string $taskId): array {
        if (is_object($found) === true && method_exists($found, 'getObject') === true) {
            $found = $found->getObject();
        } else if (is_object($found) === true && ($found instanceof JsonSerializable) === true) {
            $found = $found->jsonSerialize();
        }

        if (is_array($found) === false) {
            throw new RuntimeException(
                sprintf('dossiq.askPerson could not read task %s as an object', $taskId)
            );
        }

        return $found;

    }//end asTask()


    /**
     * The id of the task the object service reports having written.
     *
     * `ObjectService::saveObject()` returns an ObjectEntity — it always has.
     * This used to accept only an array, so every successful save was followed
     * by "could not identify the task it created": the task existed, the run
     * STOPPED instead of suspending, no resume slot was written, and the task
     * sat orphaned in somebody's list with no way to wake anything. The
     * entity's uuid is the id every read surface serves back, so it is the one
     * the resume slot must remember.
     *
     * The array shape is still accepted because the service is duck-typed, and
     * refusing a shape that carries a perfectly good id would recreate this bug
     * for the other shape.
     *
     * @param mixed $created Whatever the object service returned.
     *
     * @return string The task id, or '' when the result names none.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    private function createdTaskId(mixed $created): string {
        if (is_object($created) === true && method_exists($created, 'getUuid') === true) {
            $uuid = (string) ($created->getUuid() ?? '');
            if ($uuid !== '') {
                return $uuid;
            }
        }

        if (is_object($created) === true && ($created instanceof JsonSerializable) === true) {
            $created = (array) $created->jsonSerialize();
        }

        if (is_array($created) === true) {
            return (string) ($created['id'] ?? ($created['uuid'] ?? ''));
        }

        return '';

    }//end createdTaskId()


}//end class
