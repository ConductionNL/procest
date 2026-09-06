<?php

/**
 * Dossiq missing-TermijnDefinitie exception.
 *
 * Separates the one refusal a caller can reason about — "this case type has no
 * statutory term configured" — from every other failure of the termijn write
 * path. Before it existed, `DeadlineCaseCreatedListener` caught a bare
 * `Throwable` and logged the lot at DEBUG, so an install where NO case type
 * could ever match a definition was indistinguishable, at the default
 * loglevel, from one where every clock was running.
 *
 * @category Exception
 * @package  OCA\Dossiq\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * @spec openspec/specs/termijnbewaking-schemas/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Exception;

use RuntimeException;

/**
 * No active TermijnDefinitie matches a case type (REQ-TERM-001-A).
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/termijnbewaking-schemas/spec.md
 */
class NoTermijnDefinitieException extends RuntimeException {

}//end class
