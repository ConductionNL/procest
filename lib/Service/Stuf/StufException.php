<?php

/**
 * Dossiq StufException — base exception for the StUF-ZKN/BG outbound gateway.
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

use RuntimeException;

/**
 * Base StUF adapter exception.
 *
 * @spec openspec/specs/stuf-zkn-outbound/spec.md
 */
class StufException extends RuntimeException {
}//end class
