<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\PartnerMigrationService;
use OCA\Dossiq\Service\SettingsService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Minimal ObjectService shape for the partner migration tests.
 */
interface PartnerMigrationObjectServiceStub {

	/**
	 * Slug-aware search bridge (real ObjectService::searchObjectsBySlug()).
	 *
	 * @param string              $registerSlug Register slug.
	 * @param string              $schemaSlug   Schema slug.
	 * @param array<string,mixed> $filters      Field filters.
	 *
	 * @return array<int,mixed>|int The rows.
	 */
	public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = []): array|int;

}//end interface

/**
 * Unit tests for PartnerMigrationService.
 *
 * @covers \OCA\Dossiq\Service\PartnerMigrationService
 */
class PartnerMigrationServiceTest extends TestCase {

	/**
	 * The fake mapper, so a test can read what it was asked to insert.
	 *
	 * @var object|null
	 */
	private ?object $mapper = null;

	/**
	 * Uuids the fake mapper should report as already migrated.
	 *
	 * @var array<int, string>
	 */
	private array $existingUuids = [];

	/**
	 * Build the service over fakes.
	 *
	 * The mapper double's method names and shapes are copied from
	 * OCA\OpenRegister\Db\OrganisationMapper: `findByUuid(string): Organisation`
	 * (throwing when absent) and `insert(Entity): Entity`. A double written
	 * from the CALL SITE rather than the callee would encode this caller's
	 * assumptions and pass on its bugs.
	 *
	 * @param array<int, array<string, mixed>> $rows The partner rows.
	 *
	 * @return PartnerMigrationService The service.
	 */
	private function service(array $rows): PartnerMigrationService {
		$objectService = $this->createMock(PartnerMigrationObjectServiceStub::class);
		$objectService->method('searchObjectsBySlug')->willReturn($rows);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);

		$existing = $this->existingUuids;

		$mapper = new class($existing) {

			/**
			 * Organisations this mapper was asked to insert.
			 *
			 * A public property rather than a by-reference constructor
			 * argument. Either works, but reading the sink off the mapper
			 * keeps the failure legible: when this list is empty it means
			 * insert() was never reached, which is the shape a throw inside
			 * migrateOne()'s try produces. That is exactly what happened
			 * while tests/Stubs/Db/Organisation.php was missing setType().
			 *
			 * @var array<int, object>
			 */
			public array $inserted = [];

			/**
			 * @param array<int, string> $existing Uuids already migrated.
			 */
			public function __construct(private array $existing) {
			}

			/**
			 * @param string $uuid The uuid.
			 *
			 * @return object The organisation.
			 *
			 * @throws \RuntimeException When absent, as the real mapper does.
			 */
			public function findByUuid(string $uuid): object {
				if (in_array($uuid, $this->existing, true) === false) {
					throw new \RuntimeException('does not exist');
				}

				return new class($uuid) {

					/**
					 * @param string $uuid The uuid.
					 */
					public function __construct(private string $uuid) {
					}

					/**
					 * @return string The uuid.
					 */
					public function getUuid(): string {
						return $this->uuid;
					}

				};
			}

			/**
			 * @param object $entity The organisation.
			 *
			 * @return object The stored organisation.
			 */
			public function insert(object $entity): object {
				$this->inserted[] = $entity;

				return $entity;
			}

		};

		$this->mapper = $mapper;

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister', 'dossiq']);

		return new PartnerMigrationService(
			$settings,
			$container,
			$appManager,
			$this->createMock(LoggerInterface::class),
		);

	}//end service()

	/**
	 * A workable partner row.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function partner(array $overrides=[]): array {
		return ($overrides + [
			'id' => 'partner-uuid-1',
			'slug' => 'gemeente-zwolle',
			'name' => 'Gemeente Zwolle',
			'oin' => '00000001234567890000',
			'contactEmail' => 'ketenpartner@zwolle.nl',
			'isActive' => true,
			'groupId' => 'partner_gemeente-zwolle',
			'defaultPermissionLevel' => 'read',
			'qualityScore' => 87,
			'qualityStatus' => 'good',
		]);

	}//end partner()

	/**
	 * 🔴 The three fields dossiq kept its own schema for survive the move.
	 *
	 * `defaultPermissionLevel`, `qualityScore` and `qualityStatus` are the
	 * reason a partner was not already an Organisation. A migration that
	 * dropped them would leave case sharing without the permission default it
	 * reads, and would do it silently.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/partners-are-organisations/specs/partner-organisations/spec.md
	 */
	public function testTheCaseSharingFieldsSurvive(): void {
		$summary = $this->service([$this->partner()])->migrate();

		$this->assertSame(1, $summary['migrated']);
		$this->assertCount(1, $this->mapper->inserted);

		$org = $this->mapper->inserted[0];
		$this->assertSame('read', $org->getDefaultPermissionLevel());
		$this->assertSame(87, $org->getQualityScore());
		$this->assertSame('good', $org->getQualityStatus());

	}//end testTheCaseSharingFieldsSurvive()

	/**
	 * Every other declared property has a destination too.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/partners-are-organisations/specs/partner-organisations/spec.md
	 */
	public function testTheRemainingPropertiesArriveAsWell(): void {
		$this->service([$this->partner()])->migrate();

		$org = $this->mapper->inserted[0];
		$this->assertSame('gemeente-zwolle', $org->getSlug());
		$this->assertSame('Gemeente Zwolle', $org->getName());
		$this->assertSame('00000001234567890000', $org->getOin());
		$this->assertSame('ketenpartner@zwolle.nl', $org->getContactEmail());
		$this->assertSame(['partner_gemeente-zwolle'], $org->getGroups());
		$this->assertTrue($org->getActive());

	}//end testTheRemainingPropertiesArriveAsWell()

	/**
	 * 🔴 The partner's own uuid carries over.
	 *
	 * Case shares reference partners by id. Minting a new one would strand
	 * every existing share while reporting success.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/partners-are-organisations/specs/partner-organisations/spec.md
	 */
	public function testThePartnersUuidIsPreserved(): void {
		$this->service([$this->partner()])->migrate();

		$this->assertSame('partner-uuid-1', $this->mapper->inserted[0]->getUuid());

	}//end testThePartnersUuidIsPreserved()

	/**
	 * A migrated partner is external, and the row says so.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/partners-are-organisations/specs/partner-organisations/spec.md
	 */
	public function testAMigratedPartnerIsNotALocalTenant(): void {
		$this->service([$this->partner()])->migrate();

		$org = $this->mapper->inserted[0];
		$this->assertFalse($org->getIsLocalTenant());
		$this->assertSame('partner', $org->getType());

	}//end testAMigratedPartnerIsNotALocalTenant()

	/**
	 * Running it twice creates nothing the second time.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/partners-are-organisations/specs/partner-organisations/spec.md
	 */
	public function testASecondRunCreatesNothing(): void {
		$this->existingUuids = ['partner-uuid-1'];

		$summary = $this->service([$this->partner()])->migrate();

		$this->assertSame(0, $summary['migrated']);
		$this->assertSame(1, $summary['skipped']);
		$this->assertSame([], $this->mapper->inserted);

	}//end testASecondRunCreatesNothing()

	/**
	 * 🔴 A partner carrying no slug still migrates.
	 *
	 * `partnerOrganization` requires only `name` and `contactEmail`, so a
	 * slug-less partner is ordinary data, not a broken row. Keying on the slug
	 * would have failed the migration on exactly the rows it exists to move.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/partners-are-organisations/specs/partner-organisations/spec.md
	 */
	public function testAPartnerWithNoSlugStillMigrates(): void {
		$summary = $this->service([$this->partner(['slug' => '  '])])->migrate();

		$this->assertSame(1, $summary['migrated']);
		// Derived from the uuid, not the name: two partners sharing a name
		// would derive one slug, and a slug collision here is a silent merge.
		$this->assertSame('partner-partner-uuid-1', $this->mapper->inserted[0]->getSlug());

	}//end testAPartnerWithNoSlugStillMigrates()

	/**
	 * A row with no id is refused, because the id is the idempotency key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/partners-are-organisations/specs/partner-organisations/spec.md
	 */
	public function testARowWithNoIdIsRefused(): void {
		$summary = $this->service([$this->partner(['id' => '  '])])->migrate();

		$this->assertSame(0, $summary['migrated']);
		$this->assertSame(1, $summary['failed']);
		$this->assertSame([], $this->mapper->inserted);

	}//end testARowWithNoIdIsRefused()

	/**
	 * An inactive partner arrives suspended rather than active.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/partners-are-organisations/specs/partner-organisations/spec.md
	 */
	public function testAnInactivePartnerArrivesSuspended(): void {
		$this->service([$this->partner(['isActive' => false])])->migrate();

		$org = $this->mapper->inserted[0];
		$this->assertFalse($org->getActive());
		$this->assertSame('suspended', $org->getStatus());

	}//end testAnInactivePartnerArrivesSuspended()

	/**
	 * 🔴 Every property the partner schema declares has a destination.
	 *
	 * This is the structural version of the tests above: it reads the schema
	 * rather than a fixture, so a property added to `partnerOrganization`
	 * later cannot be silently left behind by this migration.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/partners-are-organisations/specs/partner-organisations/spec.md
	 */
	public function testEveryPartnerPropertyHasADestination(): void {
		$register = json_decode(
			file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'),
			true
		);
		$properties = array_keys(
			$register['components']['schemas']['partnerOrganization']['properties']
		);

		// The map the migration implements, field for field.
		$destinations = [
			'name' => 'name',
			'slug' => 'slug',
			'oin' => 'oin',
			'contactEmail' => 'contactEmail',
			'isActive' => 'active',
			'groupId' => 'groups',
			'defaultPermissionLevel' => 'defaultPermissionLevel',
			'qualityScore' => 'qualityScore',
			'qualityStatus' => 'qualityStatus',
		];

		foreach ($properties as $property) {
			$this->assertArrayHasKey(
				$property,
				$destinations,
				sprintf(
					'partnerOrganization declares %s and PartnerMigrationService has nowhere to put it',
					$property
				)
			);
		}

	}//end testEveryPartnerPropertyHasADestination()

}//end class
