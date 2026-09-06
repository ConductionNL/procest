<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The mockable ObjectService surface the substitution and reassignment
 * services talk to.
 *
 * It lives in its own file because TWO test classes now mock it. Declared
 * inside one of them, the other could only run when that file happened to load
 * first — `phpunit <the-other-file>` alone died on "Class or interface does not
 * exist", while the full suite passed. A fixture two tests share is a fixture,
 * not a detail of whichever test was written first.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

/**
 * Mockable ObjectService surface used by the substitution services.
 */
interface SubstitutionObjectServiceStub {

	/** @param int|string $id @param mixed ...$args @return mixed */
	public function find(int|string $id, ...$args): mixed;

	/** @param array<string,mixed> $query @return array<int,mixed>|int */
	public function searchObjects(array $query = []): array|int;

	/** @param string $r @param string $s @param array<string,mixed> $f @return array<int,mixed>|int */
	public function searchObjectsBySlug(string $r, string $s, array $f = []): array|int;

	/** @param mixed ...$args @return mixed */
	public function saveObject(...$args): mixed;

	/** @param mixed ...$args @return mixed */
	public function updateObject(...$args): mixed;

}//end interface
