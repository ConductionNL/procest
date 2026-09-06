<?php

/**
 * ZGW JWT Validation Exception.
 *
 * Thrown by ZgwJwtValidator when a bearer token is missing, malformed, or
 * its signature/payload cannot be validated.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * Exception for ZGW JWT validation failures.
 *
 * @spec openspec/specs/zgw-autorisaties-api/spec.md
 */
class ZgwAuthValidationException extends \Exception {
}//end class
