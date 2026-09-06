<?php

/**
 * VthCaseTypeChildSeederTest.
 *
 * One question: does a VTH case type end up carrying its statusTypes?
 * For six case types on a clean rig the answer was no, and every VTH workflow
 * template was skipped for want of a status map that could never be built.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Repair\Vth
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair\Vth;

use OCA\Dossiq\Repair\Vth\VthCaseTypeChildSeeder;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the write that was missing.
 *
 * @covers \OCA\Dossiq\Repair\Vth\VthCaseTypeChildSeeder
 */
class VthCaseTypeChildSeederTest extends TestCase {

	/**
	 * The case type uuid every child must point back at.
	 */
	private const CASE_TYPE = 'badc1be9-4ec7-435d-bdac-6720dd77cd06';

	/**
	 * Settings answering with the four configured child schemas.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * Wire the settings service the seeder resolves its schemas through.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'status_type_schema' => '82',
				'role_type_schema' => '83',
				'document_type_schema' => '84',
				'property_definition_schema' => '85',
				default => '',
			}
		);
	}//end setUp()

	/**
	 * One case type as the seed file ships it, trimmed to two of each.
	 *
	 * @return array<string, mixed> The shipped payload.
	 */
	private function caseType(): array {
		return [
			'slug' => 'omgevingsvergunning-bouwactiviteit',
			'title' => 'Omgevingsvergunning Bouwactiviteit',
			'statusTypes' => [
				['name' => 'Ontvangen', 'order' => 1, 'isFinal' => false],
				['name' => 'Afgehandeld', 'order' => 2, 'isFinal' => true],
			],
			'roleTypes' => [['name' => 'Behandelaar']],
			'documentTypes' => [['name' => 'Bouwtekening']],
			'propertyDefinitions' => [['name' => 'bouwkosten', 'propertyType' => 'number']],
		];
	}//end caseType()

	/**
	 * An ObjectService that records what it was asked to write.
	 *
	 * @param array<int, array<string, mixed>> $existing Rows the search answers with.
	 * @param boolean                          $failing  Whether the search throws.
	 *
	 * @return object The fake.
	 */
	private function objectService(array $existing = [], bool $failing = false): object {
		return new class($existing, $failing) {
			/**
			 * Every payload this fake was asked to save, keyed by schema.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $written = [];

			/**
			 * @param array<int, array<string, mixed>> $existing Rows to answer with.
			 * @param boolean                          $failing  Whether to throw.
			 */
			public function __construct(
				private readonly array $existing,
				private readonly bool $failing,
			) {
			}

			/**
			 * @param array<string, mixed> $query         The query.
			 * @param boolean              $_rbac         Unused.
			 * @param boolean              $_multitenancy Unused.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->failing === true) {
					throw new RuntimeException('search unavailable');
				}

				$schema = (string)($query['@self']['schema'] ?? '');

				return array_values(
					array_filter(
						$this->existing,
						static fn (array $row): bool => (string)$row['schema'] === $schema
					)
				);
			}

			/**
			 * @param int|string           $register The register.
			 * @param int|string           $schema   The schema.
			 * @param array<string, mixed> $object   The payload.
			 *
			 * @return array<string, mixed> The stored row.
			 */
			public function saveObject(int|string $register, int|string $schema, array $object): array {
				$this->written[] = ['schema' => (string)$schema, 'object' => $object];

				return $object;
			}
		};
	}//end objectService()

	/**
	 * Build the seeder against one fake ObjectService.
	 *
	 * @return VthCaseTypeChildSeeder The seeder.
	 */
	private function seeder(): VthCaseTypeChildSeeder {
		return new VthCaseTypeChildSeeder(
			$this->settings,
			$this->createMock(LoggerInterface::class)
		);
	}//end seeder()

	/**
	 * Run the seeder once against a fake.
	 *
	 * @param object $objectService The fake.
	 *
	 * @return array{created: int, present: int} The tally.
	 */
	private function seedOnce(object $objectService): array {
		return $this->seeder()->seed(
			objectService: $objectService,
			register: '18',
			caseTypeId: self::CASE_TYPE,
			caseType: $this->caseType(),
			output: $this->createMock(IOutput::class)
		);
	}//end seedOnce()

	/**
	 * 🔴 THE WRITE THAT WAS MISSING. `VthSeedDataRepairStep` stripped these
	 * four collections off the case-type payload and nothing wrote them
	 * anywhere, so a VTH case type installed with no statuses at all.
	 *
	 * @return void
	 */
	public function testItWritesEveryChildIntoItsOwnSchema(): void {
		$objectService = $this->objectService();
		$tally = $this->seedOnce($objectService);

		$this->assertSame(5, $tally['created']);
		$this->assertSame(
			['82', '82', '83', '84', '85'],
			array_column($objectService->written, 'schema')
		);
	}//end testItWritesEveryChildIntoItsOwnSchema()

	/**
	 * 🔑 THE LINK LIVES ON THE CHILD. A status written without the `caseType`
	 * back-reference belongs to nothing: `StatusTypeLookup::statusesOf()`
	 * filters on exactly this field, so the case type would still resolve zero
	 * statuses with all seven rows sitting in the table.
	 *
	 * @return void
	 */
	public function testEveryChildCarriesTheCaseTypeBackReference(): void {
		$objectService = $this->objectService();
		$this->seedOnce($objectService);

		foreach ($objectService->written as $write) {
			$this->assertSame(self::CASE_TYPE, $write['object']['caseType']);
		}
	}//end testEveryChildCarriesTheCaseTypeBackReference()

	/**
	 * A repair step runs on every upgrade, so a second pass over a case type
	 * that already has its children must write nothing.
	 *
	 * The names come back in the casing a person may have edited them into,
	 * because that is the comparison `StatusTypeLookup::idForName()` resolves
	 * by: matching more strictly here would seed a duplicate of a row the
	 * reader already finds.
	 *
	 * @return void
	 */
	public function testASecondRunWritesNothing(): void {
		$objectService = $this->objectService(
			[
				['schema' => '82', 'name' => 'ontvangen'],
				['schema' => '82', 'name' => 'Afgehandeld '],
				['schema' => '83', 'name' => 'Behandelaar'],
				['schema' => '84', 'name' => 'Bouwtekening'],
				['schema' => '85', 'name' => 'bouwkosten'],
			]
		);

		$tally = $this->seedOnce($objectService);

		$this->assertSame(0, $tally['created']);
		$this->assertSame(5, $tally['present']);
		$this->assertSame([], $objectService->written);
	}//end testASecondRunWritesNothing()

	/**
	 * An unreadable list is NOT an empty one. Seeding on the strength of a
	 * failed read is how a step that runs on every upgrade turns one throw
	 * into a duplicate set — measured on this very seed, where a broken
	 * idempotency read left nine copies of all six case types.
	 *
	 * @return void
	 */
	public function testAFailedIdempotencyReadSeedsNothing(): void {
		$objectService = $this->objectService(failing: true);

		$tally = $this->seedOnce($objectService);

		$this->assertSame(0, $tally['created']);
		$this->assertSame([], $objectService->written);
	}//end testAFailedIdempotencyReadSeedsNothing()

	/**
	 * A collection whose schema is not configured is reported, not written
	 * into the empty string.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredSchemaSkipsOnlyItsOwnCollection(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'status_type_schema' => '82',
				default => '',
			}
		);

		$objectService = $this->objectService();
		$tally = (new VthCaseTypeChildSeeder($settings, $this->createMock(LoggerInterface::class)))->seed(
			objectService: $objectService,
			register: '18',
			caseTypeId: self::CASE_TYPE,
			caseType: $this->caseType(),
			output: $this->createMock(IOutput::class)
		);

		$this->assertSame(2, $tally['created']);
		$this->assertSame(['82', '82'], array_column($objectService->written, 'schema'));
	}//end testAnUnconfiguredSchemaSkipsOnlyItsOwnCollection()

	/**
	 * A case type with no id yet writes nothing: a child pointing at the empty
	 * string is a reference no reader can follow and no later run can repair.
	 *
	 * @return void
	 */
	public function testNothingIsWrittenWithoutACaseTypeId(): void {
		$objectService = $this->objectService();

		$tally = $this->seeder()->seed(
			objectService: $objectService,
			register: '18',
			caseTypeId: '',
			caseType: $this->caseType(),
			output: $this->createMock(IOutput::class)
		);

		$this->assertSame(0, $tally['created']);
		$this->assertSame([], $objectService->written);
	}//end testNothingIsWrittenWithoutACaseTypeId()
}//end class
