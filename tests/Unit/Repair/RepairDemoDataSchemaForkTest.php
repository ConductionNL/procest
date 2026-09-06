<?php

/**
 * RepairDemoDataSchemaForkTest.
 *
 * The only irreversible thing this step does is delete, so the assertions here
 * are almost entirely about what it REFUSES to delete. A repair that removes
 * one row it could not prove is the fork is worse than the fork.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\RepairDemoDataSchemaFork;
use OCA\Dossiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Covers the deletion predicate and the register restore.
 */
class RepairDemoDataSchemaForkTest extends TestCase {

	private IAppManager&MockObject $appManager;

	private ContainerInterface&MockObject $container;

	private IOutput&MockObject $output;

	/**
	 * Set up the mocks every case needs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('getInstalledApps')->willReturn(['dossiq', 'openregister']);

		$this->container = $this->createMock(ContainerInterface::class);
		$this->output = $this->createMock(IOutput::class);
	}

	/**
	 * A schema row, as far as this step is concerned.
	 *
	 * @param integer $id   The row id.
	 * @param string  $slug The row slug.
	 *
	 * @return object The fake schema.
	 */
	private function schema(int $id, string $slug): object {
		return new class($id, $slug) {
			/**
			 * @param integer $id   The row id.
			 * @param string  $slug The row slug.
			 */
			public function __construct(private readonly int $id, private readonly string $slug) {
			}

			/**
			 * @return integer The row id.
			 */
			public function getId(): int {
				return $this->id;
			}

			/**
			 * @return string The row slug.
			 */
			public function getSlug(): string {
				return $this->slug;
			}
		};
	}

	/**
	 * Wire a container that hands back the four OpenRegister collaborators.
	 *
	 * @param object $schemaMapper   The schema mapper fake.
	 * @param object $registerMapper The register mapper fake.
	 * @param object $deletion       The deletion-service fake.
	 *
	 * @return void
	 */
	private function wire(object $schemaMapper, object $registerMapper, object $deletion): void {
		$configurationMapper = new class {
			/**
			 * @param string  $app          The app id.
			 * @param boolean $systemLookup Whether to bypass the organisation filter.
			 *
			 * @return array<int, object> No configuration rows.
			 */
			public function findByApp(string $app, bool $systemLookup = false): array {
				return [];
			}
		};

		$this->container->method('get')->willReturnCallback(
			static function (string $id) use ($schemaMapper, $registerMapper, $deletion, $configurationMapper): object {
				return match ($id) {
					'OCA\OpenRegister\Db\SchemaMapper' => $schemaMapper,
					'OCA\OpenRegister\Db\RegisterMapper' => $registerMapper,
					'OCA\OpenRegister\Service\SchemaDeletionService' => $deletion,
					'OCA\OpenRegister\Db\ConfigurationMapper' => $configurationMapper,
					default => throw new \RuntimeException('unexpected lookup ' . $id),
				};
			}
		);
	}

	/**
	 * A schema mapper that answers from a fixed table of rows.
	 *
	 * @param array<int, object> $forked Rows under `dossiq.demo`.
	 * @param array<string, int> $twins  Slug => how many rows exist under `dossiq`.
	 *
	 * @return object The fake mapper.
	 */
	private function schemaMapper(array $forked, array $twins): object {
		$rowFor = fn (string $slug): object => $this->schema(id: 900, slug: $slug);

		return new class($forked, $twins, $rowFor) {
			/**
			 * @param array<int, object>       $forked Rows under `dossiq.demo`.
			 * @param array<string, int>       $twins  Slug => twin count under `dossiq`.
			 * @param callable(string): object $rowFor Builds a twin row.
			 */
			public function __construct(
				private readonly array $forked,
				private readonly array $twins,
				private $rowFor,
			) {
			}

			/**
			 * @param integer|null              $limit         Unused.
			 * @param integer|null              $offset        Unused.
			 * @param array<string, mixed>|null $filters       The application/slug filter.
			 * @param array<int, string>|null   $searchC       Unused.
			 * @param array<string, mixed>|null $searchP       Unused.
			 * @param array<int, string>|null   $_extend       Unused.
			 * @param boolean                   $_rbac         Unused.
			 * @param boolean                   $_multitenancy Unused.
			 *
			 * @return array<int, object> The matching rows.
			 */
			public function findAll(
				?int $limit = null,
				?int $offset = null,
				?array $filters = [],
				?array $searchC = [],
				?array $searchP = [],
				?array $_extend = [],
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				if (($filters['application'] ?? '') === 'dossiq.demo') {
					return $this->forked;
				}

				$slug = (string)($filters['slug'] ?? '');
				$count = (int)($this->twins[$slug] ?? 0);

				return array_fill(0, $count, ($this->rowFor)($slug));
			}
		};
	}

	/**
	 * A register mapper answering with one register and a fixed schema list.
	 *
	 * @param array<int, int> $linkedSchemaIds Schema ids the register lists.
	 * @param string          $application     The register's application id.
	 * @param string          $title           The register's title.
	 *
	 * @return object The fake mapper, carrying the register it hands out.
	 */
	private function registerMapper(array $linkedSchemaIds, string $application = 'dossiq', string $title = 'Dossiq Case Management Register'): object {
		$register = new class($linkedSchemaIds, $application, $title) {
			public bool $updated = false;

			/**
			 * @param array<int, int> $schemas     Linked schema ids.
			 * @param string          $application The application id.
			 * @param string          $title       The title.
			 */
			public function __construct(
				private array $schemas,
				private string $application,
				private string $title,
			) {
			}

			/**
			 * @return array<int, int> Linked schema ids.
			 */
			public function getSchemas(): array {
				return $this->schemas;
			}

			/**
			 * @return string The application id.
			 */
			public function getApplication(): string {
				return $this->application;
			}

			/**
			 * @param string $application The new application id.
			 *
			 * @return void
			 */
			public function setApplication(string $application): void {
				$this->application = $application;
			}

			/**
			 * @return string The title.
			 */
			public function getTitle(): string {
				return $this->title;
			}

			/**
			 * @param string $title The new title.
			 *
			 * @return void
			 */
			public function setTitle(string $title): void {
				$this->title = $title;
			}

			/**
			 * @param string $description The new description.
			 *
			 * @return void
			 */
			public function setDescription(string $description): void {
			}

			/**
			 * @param string $version The new version.
			 *
			 * @return void
			 */
			public function setVersion(string $version): void {
			}
		};

		return new class($register) {
			/**
			 * @param object $register The register it hands out.
			 */
			public function __construct(public readonly object $register) {
			}

			/**
			 * @param string|integer $id            The slug or id.
			 * @param boolean        $_rbac         Unused.
			 * @param boolean        $_multitenancy Unused.
			 *
			 * @return object The register.
			 */
			public function find(string|int $id, bool $_rbac = true, bool $_multitenancy = true): object {
				return $this->register;
			}

			/**
			 * @param integer|null              $limit         Unused.
			 * @param integer|null              $offset        Unused.
			 * @param array<string, mixed>|null $filters       Unused.
			 * @param array<int, string>|null   $searchC       Unused.
			 * @param array<string, mixed>|null $searchP       Unused.
			 * @param boolean                   $_rbac         Unused.
			 * @param boolean                   $_multitenancy Unused.
			 *
			 * @return array<int, object> Every register.
			 */
			public function findAll(
				?int $limit = null,
				?int $offset = null,
				?array $filters = [],
				?array $searchC = [],
				?array $searchP = [],
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				return [$this->register];
			}

			/**
			 * @param object $entity The register to store.
			 *
			 * @return object The register.
			 */
			public function update(object $entity): object {
				$entity->updated = true;
				return $entity;
			}
		};
	}

	/**
	 * A deletion service that records which schemas it was asked to remove.
	 *
	 * @return object The fake.
	 */
	private function deletionSpy(): object {
		return new class {
			/** @var array<int, string> */
			public array $deleted = [];

			/**
			 * @param object $schema The schema to tear down.
			 *
			 * @return array<string, mixed> The cascade result.
			 */
			public function cascadeDeleteSchema(object $schema): array {
				$this->deleted[] = $schema->getSlug();
				return ['deletedCount' => 3, 'deletedUuids' => [], 'tableDropped' => true];
			}
		};
	}

	/**
	 * Build the step under test.
	 *
	 * @return RepairDemoDataSchemaFork The step.
	 */
	private function step(): RepairDemoDataSchemaFork {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);

		return new RepairDemoDataSchemaFork(
			$this->appManager,
			$this->container,
			$settings,
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * The whole point: a proven fork goes, and its objects go with it.
	 *
	 * @return void
	 */
	public function testItRetiresAForkedSchemaThatHasExactlyOneRealTwinAndNoRegisterLink(): void {
		$deletion = $this->deletionSpy();
		$this->wire(
			schemaMapper: $this->schemaMapper(
				forked: [$this->schema(id: 229, slug: 'case')],
				twins: ['case' => 1]
			),
			registerMapper: $this->registerMapper(linkedSchemaIds: [88]),
			deletion: $deletion
		);

		$this->step()->run($this->output);

		self::assertSame(['case'], $deletion->deleted);
	}

	/**
	 * 🔴 NO TWIN, NO DELETION. A slug that exists nowhere under `dossiq` may
	 * be the only copy of that data on the instance. There is nothing to fall
	 * back to, so there is nothing to prove, so the row stays.
	 *
	 * @return void
	 */
	public function testItSparesAForkedSchemaWithNoTwinUnderTheAppsOwnApplicationId(): void {
		$deletion = $this->deletionSpy();
		$this->wire(
			schemaMapper: $this->schemaMapper(
				forked: [$this->schema(id: 229, slug: 'orphanOnly')],
				twins: []
			),
			registerMapper: $this->registerMapper(linkedSchemaIds: []),
			deletion: $deletion
		);

		$this->step()->run($this->output);

		self::assertSame([], $deletion->deleted);
	}

	/**
	 * 🔴 AMBIGUOUS IS NOT PROVEN. Two rows sharing the slug under `dossiq`
	 * means the "real home" is a choice about data, and a repair step does not
	 * get to make it.
	 *
	 * @return void
	 */
	public function testItSparesAForkedSchemaWhoseSlugHasMoreThanOneTwin(): void {
		$deletion = $this->deletionSpy();
		$this->wire(
			schemaMapper: $this->schemaMapper(
				forked: [$this->schema(id: 229, slug: 'case')],
				twins: ['case' => 2]
			),
			registerMapper: $this->registerMapper(linkedSchemaIds: []),
			deletion: $deletion
		);

		$this->step()->run($this->output);

		self::assertSame([], $deletion->deleted);
	}

	/**
	 * 🔴 REACHABLE IS NOT THE FORK. The fork's defining property is that no
	 * register lists it, so nothing on the instance can read it. A register
	 * link means somebody, or something, can.
	 *
	 * @return void
	 */
	public function testItSparesAForkedSchemaThatARegisterStillLinks(): void {
		$deletion = $this->deletionSpy();
		$this->wire(
			schemaMapper: $this->schemaMapper(
				forked: [$this->schema(id: 229, slug: 'case')],
				twins: ['case' => 1]
			),
			registerMapper: $this->registerMapper(linkedSchemaIds: [229]),
			deletion: $deletion
		);

		$this->step()->run($this->output);

		self::assertSame([], $deletion->deleted);
	}

	/**
	 * The register goes back to its own application id and its shipped title.
	 *
	 * @return void
	 */
	public function testItRestoresTheRegisterIdentityTheDemoImportOverwrote(): void {
		$registerMapper = $this->registerMapper(
			linkedSchemaIds: [],
			application: 'dossiq.demo',
			title: 'Dossiq (demo)'
		);
		$this->wire(
			schemaMapper: $this->schemaMapper(forked: [], twins: []),
			registerMapper: $registerMapper,
			deletion: $this->deletionSpy()
		);

		$this->step()->run($this->output);

		self::assertSame('dossiq', $registerMapper->register->getApplication());
		self::assertSame('Dossiq Case Management Register', $registerMapper->register->getTitle());
	}

	/**
	 * 🔴 A NAME SOMEBODY CHOSE IS NOT DAMAGE. The title is restored only when
	 * it still reads exactly what the demo import made it read. An operator who
	 * renamed the register keeps their name, and only the application id, which
	 * nothing but the defect ever wrote, is corrected.
	 *
	 * @return void
	 */
	public function testItKeepsATitleAnOperatorChoseThemselves(): void {
		$registerMapper = $this->registerMapper(
			linkedSchemaIds: [],
			application: 'dossiq.demo',
			title: 'Zaken gemeente Almere'
		);
		$this->wire(
			schemaMapper: $this->schemaMapper(forked: [], twins: []),
			registerMapper: $registerMapper,
			deletion: $this->deletionSpy()
		);

		$this->step()->run($this->output);

		self::assertSame('dossiq', $registerMapper->register->getApplication());
		self::assertSame('Zaken gemeente Almere', $registerMapper->register->getTitle());
	}

	/**
	 * An undamaged register is not touched at all, which is what makes a second
	 * run of the step free.
	 *
	 * @return void
	 */
	public function testItLeavesARegisterThatWasNeverForkedAlone(): void {
		$registerMapper = $this->registerMapper(linkedSchemaIds: []);
		$this->wire(
			schemaMapper: $this->schemaMapper(forked: [], twins: []),
			registerMapper: $registerMapper,
			deletion: $this->deletionSpy()
		);

		$this->step()->run($this->output);

		self::assertFalse($registerMapper->register->updated, 'an undamaged register must not be written');
	}

	/**
	 * Without OpenRegister there is nothing to repair, and asking its container
	 * for a class it does not ship is how a repair step turns into a fatal.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterItDoesNothingAtAll(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['dossiq']);
		$this->container->expects($this->never())->method('get');

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);

		$step = new RepairDemoDataSchemaFork(
			$appManager,
			$this->container,
			$settings,
			$this->createMock(LoggerInterface::class)
		);

		$step->run($this->output);
	}
}//end class
