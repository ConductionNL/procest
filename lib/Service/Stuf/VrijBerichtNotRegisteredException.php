<?php

/**
 * Dossiq VrijBerichtNotRegisteredException.
 *
 * Raised when adapter.vrijBericht is invoked with a name that has no
 * registered template on the target StufEndpoint.
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
 * Pre-send domain error: vrijBericht template not registered.
 *
 * @spec openspec/specs/stuf-zkn-outbound/spec.md
 */
class VrijBerichtNotRegisteredException extends StufException {
}//end class
