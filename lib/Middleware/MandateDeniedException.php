<?php

/**
 * Dossiq Mandate Denied Exception
 *
 * @category Middleware
 * @package  OCA\Dossiq\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-06-mandate-validation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Middleware;

use Exception;

/**
 * Mandate matrix denied this request.
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-06-mandate-validation/tasks.md
 */
class MandateDeniedException extends Exception {
}//end class
