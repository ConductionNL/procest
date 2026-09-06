<?php

/**
 * Test stub for OCA\OpenRegister\Db\FlowTimer.
 *
 * Only the surface dossiq reads: the timer's uuid, its owning app id and
 * its metadata (where TermijnTimerService parks the instance binding the
 * fired-listener resolves). Self-skips when the real class is present.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

if (class_exists('\\OCA\\OpenRegister\\Db\\FlowTimer', false) === false) {
    /**
     * Minimal FlowTimer stand-in.
     */
    class FlowTimer {

        /**
         * The timer's uuid.
         *
         * @var string|null
         */
        private ?string $uuid = null;

        /**
         * The arming app.
         *
         * @var string|null
         */
        private ?string $appId = null;

        /**
         * Caller metadata.
         *
         * @var array|null
         */
        private ?array $metadata = null;

        /**
         * Set the uuid.
         *
         * @param string|null $uuid The uuid.
         *
         * @return void
         */
        public function setUuid(?string $uuid): void {
            $this->uuid = $uuid;
        }//end setUuid()

        /**
         * Get the uuid.
         *
         * @return string|null
         */
        public function getUuid(): ?string {
            return $this->uuid;
        }//end getUuid()

        /**
         * Set the app id.
         *
         * @param string|null $appId The app id.
         *
         * @return void
         */
        public function setAppId(?string $appId): void {
            $this->appId = $appId;
        }//end setAppId()

        /**
         * Get the app id.
         *
         * @return string|null
         */
        public function getAppId(): ?string {
            return $this->appId;
        }//end getAppId()

        /**
         * Set the metadata.
         *
         * @param array|null $metadata The metadata.
         *
         * @return void
         */
        public function setMetadata(?array $metadata): void {
            $this->metadata = $metadata;
        }//end setMetadata()

        /**
         * Get the metadata.
         *
         * @return array|null
         */
        public function getMetadata(): ?array {
            return $this->metadata;
        }//end getMetadata()
    }
}//end if
