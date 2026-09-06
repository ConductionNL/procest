<?php

/**
 * Dossiq StUF TimeoutException.
 *
 * Raised when a synchronous Lv01 vraag does not receive a La01 antwoord
 * within the configured timeout (default 30 seconds).
 *
 * @category Exception
 * @package  OCA\Dossiq\Service\Stuf
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/stuf-zkn-outbound/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Stuf;

/**
 * Synchronous vraag/antwoord exceeded the configured timeout.
 *
 * @spec openspec/specs/stuf-zkn-outbound/spec.md
 */
class TimeoutException extends StufException {
}//end class
