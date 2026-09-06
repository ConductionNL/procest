<?php

/**
 * VthSeedLookupTest.
 *
 * One question: can this lookup see a case type that IS on the instance?
 * For every VTH case type the answer was no, and the step reported it as
 * "caseType not found ... run base-register-seed-data first" — a message that
 * names a cause an operator can act on, for a defect they cannot.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Repair\Vth
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair\Vth;

use OCA\Dossiq\Repair\Vth\VthSeedLookup;
use OCA\Dossiq\Repair\Vth\VthSeedRowReader;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers how a case type is found.
 */
class VthSeedLookupTest extends TestCase {

	private SettingsService&MockObject $settings;

	/**
	 * Wire the settings service every probe goes through.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => '18',
				'case_type_schema' => '81',
				default => '',
			}
		);
	}

	/**
	 * An ObjectService that only answers the query shape it is told to.
	 *
	 * @param string $answersTo Which filter shape returns a row: identifier, slug or metaSlug.
	 *
	 * @return object The fake.
	 */
	private function objectService(string $answersTo): object {
		return new class($answersTo) {
			/**
			 * @param string $answersTo The filter shape that matches.
			 */
			public function __construct(private readonly string $answersTo) {
			}

			/**
			 * @param array<string, mixed> $query         The query.
			 * @param boolean              $_rbac         Unused.
			 * @param boolean              $_multitenancy Unused.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				$hit = match ($this->answersTo) {
					'identifier' => isset($query['identifier']),
					'slug' => isset($query['slug']),
					'metaSlug' => isset($query['@self']['slug']),
					default => false,
				};

				if ($hit === false) {
					return [];
				}

				return [['id' => 'badc1be9-4ec7-435d-bdac-6720dd77cd06']];
			}
		};
	}

	/**
	 * Build the lookup against one fake ObjectService.
	 *
	 * @param object $objectService The fake.
	 *
	 * @return VthSeedLookup The lookup.
	 */
	private function lookup(object $objectService): VthSeedLookup {
		$this->settings->method('getObjectService')->willReturn($objectService);

		return new VthSeedLookup(
			$this->settings,
			new VthSeedRowReader(),
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * 🔴 THE PROBE THAT WAS MISSING. `VthSeedDataRepairStep` writes a case
	 * type's slug as METADATA, so the row carries no `identifier` and no
	 * `slug` property at all. Both existing probes filter on object
	 * properties, so both missed every VTH case type that was sitting right
	 * there, and five of six templates were skipped on every install.
	 *
	 * @return void
	 */
	public function testItFindsACaseTypeWhoseSlugIsMetadataRatherThanAProperty(): void {
		$id = $this->lookup($this->objectService('metaSlug'))
			->resolveCaseTypeId(slug: 'omgevingsvergunning-bouwactiviteit');

		self::assertSame('badc1be9-4ec7-435d-bdac-6720dd77cd06', $id);
	}

	/**
	 * The bezwaar/beroep seeds write an `identifier` property, and that path
	 * has to keep working.
	 *
	 * @return void
	 */
	public function testItStillFindsACaseTypeByItsIdentifierProperty(): void {
		$id = $this->lookup($this->objectService('identifier'))->resolveCaseTypeId(slug: 'objectionProceeding');

		self::assertSame('badc1be9-4ec7-435d-bdac-6720dd77cd06', $id);
	}

	/**
	 * A seed that declares a `slug` property keeps working too.
	 *
	 * @return void
	 */
	public function testItStillFindsACaseTypeByASlugProperty(): void {
		$id = $this->lookup($this->objectService('slug'))->resolveCaseTypeId(slug: 'handhavingszaak');

		self::assertSame('badc1be9-4ec7-435d-bdac-6720dd77cd06', $id);
	}

	/**
	 * A case type that is genuinely absent still resolves to nothing, so the
	 * caller can go on reporting an honest skip.
	 *
	 * @return void
	 */
	public function testACaseTypeThatIsAbsentResolvesToNothing(): void {
		self::assertSame('', $this->lookup($this->objectService('none'))->resolveCaseTypeId(slug: 'klacht-toezicht'));
	}
}//end class
