<?php

/**
 * LinkInFlightRemainingDecisionsRepairTest.
 *
 * The step could not read a single object. It passed the register as a SLUG
 * and the schema as the numeric id out of app config, which sends the search
 * bridge down its slug path with a number where a slug belongs. Every surface
 * came back as "Could not list objects for schema 4711" and the run reported
 * "0 linked, 0 skipped, 0 errors", which reads exactly like an instance with
 * nothing to link.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\LinkInFlightRemainingDecisionsRepair;
use OCA\Dossiq\Service\AdviceDelegationService;
use OCA\Dossiq\Service\BezwaarDecisionDelegationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\JsonEncodedStringProperties;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers which identifier shape reaches OpenRegister.
 */
class LinkInFlightRemainingDecisionsRepairTest extends TestCase {

	/**
	 * An ObjectService that records every slug-path search it is handed.
	 *
	 * @return object The fake.
	 */
	private function objectServiceSpy(): object {
		return new class {
			/** @var array<int, array{register: string, schema: string}> */
			public array $searches = [];

			/**
			 * @param string               $registerSlug The register slug.
			 * @param string               $schemaSlug   The schema slug.
			 * @param array<string, mixed> $filters      The filters.
			 *
			 * @return array<int, array<string, mixed>> No rows.
			 */
			public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = []): array {
				$this->searches[] = ['register' => $registerSlug, 'schema' => $schemaSlug];
				return [];
			}

			/**
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<int, array<string, mixed>> No rows.
			 */
			public function searchObjects(array $query): array {
				$this->searches[] = [
					'register' => (string)($query['@self']['register'] ?? ''),
					'schema' => (string)($query['@self']['schema'] ?? ''),
				];
				return [];
			}
		};
	}

	/**
	 * 🔴 BOTH IDENTIFIERS ARE SLUGS, OR NEITHER IS READ.
	 *
	 * `searchObjectsAsArrays()` picks its path on "is EITHER side
	 * non-numeric". A slug register plus a numeric schema therefore takes the
	 * SLUG path, and OpenRegister is asked for a schema whose slug is a
	 * number. It resolves to nothing, every surface throws, and the step warns
	 * once per surface without examining one object.
	 *
	 * @return void
	 */
	public function testItAsksForEachSurfaceBySlugRatherThanByItsNumericSchemaId(): void {
		$spy = $this->objectServiceSpy();

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($spy);
		// What app config really holds: the numeric schema id.
		$settings->method('getConfigValue')->willReturn('4711');

		$step = new LinkInFlightRemainingDecisionsRepair(
			$this->createMock(BezwaarDecisionDelegationService::class),
			$this->createMock(AdviceDelegationService::class),
			$settings,
			$this->createMock(JsonEncodedStringProperties::class),
			$this->createMock(LoggerInterface::class)
		);

		$step->run($this->createMock(IOutput::class));

		self::assertSame(
			['bezwaarDecision', 'adviesAanvraag', 'consultation'],
			array_column($spy->searches, 'schema'),
			'the schema must reach OpenRegister as a slug, never as the config value'
		);
		self::assertSame(
			['dossiq', 'dossiq', 'dossiq'],
			array_column($spy->searches, 'register'),
			'the register was always a slug; mixing the two is what broke the search'
		);
	}

	/**
	 * An unconfigured surface is skipped without a search, which is how
	 * "not provisioned" stays distinguishable from "could not be read".
	 *
	 * @return void
	 */
	public function testAnUnconfiguredSurfaceIsSkippedWithoutAsking(): void {
		$spy = $this->objectServiceSpy();

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($spy);
		$settings->method('getConfigValue')->willReturn('');

		$step = new LinkInFlightRemainingDecisionsRepair(
			$this->createMock(BezwaarDecisionDelegationService::class),
			$this->createMock(AdviceDelegationService::class),
			$settings,
			$this->createMock(JsonEncodedStringProperties::class),
			$this->createMock(LoggerInterface::class)
		);

		$step->run($this->createMock(IOutput::class));

		self::assertSame([], $spy->searches);
	}
}//end class
