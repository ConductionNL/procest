<?php

/**
 * The besluit migration moves rows onto decidiq and detaches only when clean.
 *
 * @category  Tests
 * @package   OCA\Dossiq\Tests\Unit\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/the-besluit-resolves-to-decidiqs-decision/specs/zgw-brc/spec.md#requirement-besluiten-move-onto-decidiq-only-when-asked-req-brc-021
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\BesluitMigrationService;
use OCA\Dossiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The duck-typed slice of OpenRegister's ObjectService this migration uses.
 *
 * `runAsSystem` is deliberately absent: the trait guards it with
 * `method_exists()`, so leaving it off exercises the direct-call fallback.
 */
interface BesluitMigrationObjectServiceStub {
	/**
	 * Numeric-path search (real ObjectService::searchObjects()).
	 *
	 * @param array<string, mixed> $query The query.
	 *
	 * @return array<int, mixed>|int Rows, or a count.
	 */
	public function searchObjects(array $query): array|int;

	/**
	 * Write one object.
	 *
	 * @param array<string, mixed> $object The object.
	 * @param int|string $register The register.
	 * @param int|string $schema The schema.
	 * @param string|null $uuid The uuid to update, or null to create.
	 *
	 * @return array<string, mixed> The stored object.
	 */
	public function saveObject(array $object, int|string $register, int|string $schema, ?string $uuid = null): array;
}

/**
 * Locks the four things that can go wrong here: reading the wrong schema,
 * duplicating on a re-run, losing the case link, and detaching too early.
 */
final class BesluitMigrationServiceTest extends TestCase {

	/**
	 * Objects written by the ObjectService stub during a run.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * Keys deleted from app config during a run.
	 *
	 * @var array<int, string>
	 */
	private array $deleted = [];

	/**
	 * Build the service over a given instance state.
	 *
	 * @param string $localSchema The stored `decision_schema`, or '' for none.
	 * @param array<int, array<string, mixed>> $sources Rows in this app's schema.
	 * @param array<int, array<string, mixed>> $targets Rows already in decidiq's schema.
	 * @param bool $decidiq Whether decidiq is installed.
	 *
	 * @return BesluitMigrationService The service under test.
	 */
	private function service(
		string $localSchema,
		array $sources = [],
		array $targets = [],
		bool $decidiq = true,
	): BesluitMigrationService {
		$this->written = [];
		$this->deleted = [];

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string
				=> ($key === 'decision_schema' ? $localSchema : $default)
		);
		$appConfig->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				$this->deleted[] = $key;
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($decidiq);

		$objectService = $this->createMock(BesluitMigrationObjectServiceStub::class);
		$objectService->method('searchObjects')->willReturnCallback(
			// The SCHEMA in `@self` is what decides which side answers. A stub
			// that returned one list for both would pass whether or not the
			// service reads the right schema.
			static function (array $query) use ($sources, $targets): array {
				$schema = (int)(($query['@self'] ?? [])['schema'] ?? 0);

				if ($schema === 166) {
					return $sources;
				}

				if ($schema === 339) {
					return $targets;
				}

				return [];
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, int|string $register, int|string $schema, ?string $uuid = null): array {
				$this->written[] = $object;
				return $object;
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);

		return new BesluitMigrationService(
			$appConfig,
			$appManager,
			$this->container(),
			$settings,
			$this->createMock(LoggerInterface::class)
		);

	}//end service()

	/**
	 * A container answering with the two OpenRegister mappers.
	 *
	 * @return ContainerInterface The container double.
	 */
	private function container(): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id): object {
				if ($id === 'OCA\OpenRegister\Db\SchemaMapper') {
					return new class {

						/**
						 * @param string $slug The slug.
						 * @param string $application The owning application.
						 *
						 * @return object|null decidiq's decision schema, or null.
						 */
						public function findByApplicationAndSlug(string $slug, string $application): ?object {
							// The PAIR is the point. Slug alone would match this
							// app's own row as readily as decidiq's — which is
							// the collision this migration exists to end.
							if ($slug !== 'decision' || $application !== 'decidiq') {
								return null;
							}

							return new class {

								/**
								 * @return int The schema id.
								 */
								public function getId(): int {
									return 339;
								}
							};
						}
					};
				}

				if ($id === 'OCA\OpenRegister\Db\RegisterMapper') {
					return new class {

						/**
						 * @param string $slug The register slug.
						 * @param bool $_rbac Whether to apply RBAC.
						 * @param bool $_multitenancy Whether to apply tenancy.
						 *
						 * @return object The register.
						 */
						public function find(string $slug, bool $_rbac = true, bool $_multitenancy = true): object {
							$id = ($slug === 'decidiq' ? 20 : 10);

							return new class($id) {

								/**
								 * @param int $id The register id.
								 */
								public function __construct(private int $id) {
								}

								/**
								 * @return int The register id.
								 */
								public function getId(): int {
									return $this->id;
								}
							};
						}
					};
				}

				throw new \RuntimeException('unexpected service ' . $id);
			}
		);

		return $container;

	}//end container()

	/**
	 * One besluit, as this app stores it.
	 *
	 * @param string $id The source uuid.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function besluit(string $id): array {
		return [
			'id' => $id,
			'title' => 'Vergunning verleend',
			'case' => 'zaak-1',
			'description' => 'De vergunning is verleend.',
			'explanation' => 'Voldoet aan de voorwaarden.',
			'governingBody' => 'college',
			'decisionDate' => '2026-09-01',
			'deliveryDate' => '2026-09-02',
			'publicationDate' => '2026-09-03',
			'expiryDate' => '2027-09-01',
			'responsibleOrganisation' => 'gemeente',
		];
	}//end besluit()

	/**
	 * Without decidiq there is nothing to migrate onto, and the run must say so
	 * rather than report a successful migration of zero rows.
	 *
	 * @return void
	 */
	public function testWithoutDecidiqItIsBlocked(): void {
		$summary = $this->service('166', decidiq: false)->migrate(commit: true);

		$this->assertSame('blocked', $summary['status']);
		$this->assertSame([], $this->written);
		$this->assertSame([], $this->deleted);

	}//end testWithoutDecidiqItIsBlocked()

	/**
	 * An instance with no local schema already resolves to decidiq. Migrating
	 * would be a no-op at best and a duplicate at worst.
	 *
	 * @return void
	 */
	public function testWithNoLocalSchemaItIsBlocked(): void {
		$summary = $this->service('', [$this->besluit('a')])->migrate(commit: true);

		$this->assertSame('blocked', $summary['status']);
		$this->assertSame([], $this->written);

	}//end testWithNoLocalSchemaItIsBlocked()

	/**
	 * A dry run counts and writes nothing. Without this, "reports 3" and
	 * "migrated 3" are indistinguishable.
	 *
	 * @return void
	 */
	public function testADryRunWritesNothing(): void {
		$sources = [$this->besluit('a'), $this->besluit('b'), $this->besluit('c')];

		$summary = $this->service('166', $sources)->migrate(commit: false);

		$this->assertSame('ok', $summary['status']);
		$this->assertSame(3, $summary['total']);
		$this->assertSame(3, $summary['migrated']);
		$this->assertSame([], $this->written, 'a dry run must not write');
		$this->assertSame([], $this->deleted, 'a dry run must not detach');
		$this->assertFalse($summary['detached']);

	}//end testADryRunWritesNothing()

	/**
	 * A commit writes every besluit and then detaches the local key, which is
	 * what makes the fallback in SettingsService start answering.
	 *
	 * @return void
	 */
	public function testACommitWritesAndDetaches(): void {
		$summary = $this->service('166', [$this->besluit('a'), $this->besluit('b')])->migrate(commit: true);

		$this->assertSame(2, $summary['migrated']);
		$this->assertSame(0, $summary['failed']);
		$this->assertCount(2, $this->written);
		$this->assertTrue($summary['detached']);
		$this->assertSame(['decision_schema'], $this->deleted);

	}//end testACommitWritesAndDetaches()

	/**
	 * Every source field lands somewhere, and `case` travels through the subject
	 * block rather than through a `case` field decidiq does not have.
	 *
	 * @return void
	 */
	public function testTheProjectionKeepsEveryFieldAndRoutesTheCase(): void {
		$this->service('166', [$this->besluit('a')])->migrate(commit: true);

		$written = $this->written[0];

		$this->assertSame('Vergunning verleend', $written['title']);
		$this->assertSame('De vergunning is verleend.', $written['text']);
		$this->assertSame('Voldoet aan de voorwaarden.', $written['background']);
		// `governingBody` and NOT `targetBody`. decidiq's `targetBody` is the body
		// an appointment is made FOR and is format `uuid`; the bestuursorgaan that
		// TOOK the decision is a different thing, and 'college' would have been
		// rejected on write. decidiq#1161 gained `governingBody` for exactly this.
		$this->assertSame('college', $written['governingBody']);
		$this->assertArrayNotHasKey('targetBody', $written);

		// decidiq declares `decisionDate` as `date-time` where this app declares
		// `date`. OpenRegister validates on write, so an unwidened value does not
		// move the besluit at all.
		$this->assertSame('2026-09-01T00:00:00+00:00', $written['decisionDate']);
		$this->assertSame('2026-09-02', $written['deliveryDate'], 'a `date` target stays a date');
		$this->assertSame('2026-09-02', $written['deliveryDate']);
		$this->assertSame('2026-09-03', $written['publicationDate']);
		$this->assertSame('2027-09-01', $written['expiryDate']);
		$this->assertSame('gemeente', $written['responsibleOrganisation']);

		$this->assertArrayNotHasKey('case', $written, 'decidiq has no `case` field and is not getting one');
		$this->assertSame('zaak-1', $written['subjectId']);
		$this->assertSame('case', $written['subjectSchema']);
		$this->assertSame('dossiq', $written['subjectRegister']);
		$this->assertSame('dossiq', $written['sourceApp']);

	}//end testTheProjectionKeepsEveryFieldAndRoutesTheCase()

	/**
	 * A second run skips what is already across. The provenance stamp is the
	 * only thing standing between a re-run and a duplicate set of besluiten.
	 *
	 * @return void
	 */
	public function testASecondRunSkipsWhatIsAlreadyThere(): void {
		$targets = [
			['id' => 'x', 'externalReference' => 'dossiq:a'],
			['id' => 'y', 'externalReference' => 'unrelated'],
		];

		$summary = $this->service('166', [$this->besluit('a'), $this->besluit('b')], $targets)
			->migrate(commit: true);

		$this->assertSame(2, $summary['total']);
		$this->assertSame(1, $summary['skipped'], 'the already-migrated besluit is skipped');
		$this->assertSame(1, $summary['migrated']);
		$this->assertCount(1, $this->written);
		$this->assertSame('dossiq:b', $this->written[0]['externalReference']);

	}//end testASecondRunSkipsWhatIsAlreadyThere()

	/**
	 * A row with no identity has no idempotency key, so a re-run would duplicate
	 * it. It counts as failed, and the failure holds the detach back — which is
	 * the guard that keeps a half-migrated instance from 404ing.
	 *
	 * @return void
	 */
	public function testAFailureHoldsBackTheDetach(): void {
		$summary = $this->service('166', [$this->besluit('a'), ['title' => 'no id']])
			->migrate(commit: true);

		$this->assertSame(1, $summary['failed']);
		$this->assertSame(1, $summary['migrated']);
		$this->assertFalse($summary['detached'], 'a besluit left behind must keep the local schema attached');
		$this->assertSame([], $this->deleted);

	}//end testAFailureHoldsBackTheDetach()

}//end class
