<?php

/**
 * ConfiguredRegistryService Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/admin-settings/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\ConfiguredRegistryService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for ConfiguredRegistryService.
 *
 * @covers \OCA\Dossiq\Service\Support\ConfiguredRegistryService
 */
class ConfiguredRegistryServiceTest extends TestCase {

	/**
	 * Settings service, mocked.
	 *
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * Logger, mocked.
	 *
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Configure the settings stub.
	 *
	 * @param string $register The register id, '' to simulate unconfigured.
	 * @param string $schema The schema id, '' to simulate unconfigured.
	 *
	 * @return void
	 */
	private function withConfig(string $register, string $schema): void {
		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = '') use ($register, $schema): string {
				if ($key === 'register') {
					return $register;
				}

				return $schema;
			}
		);
	}//end withConfig()

	/**
	 * Build a recording object-service stub.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows the search returns.
	 *
	 * @return object The stub.
	 */
	private function objectService(array $rows = []): object {
		return new class($rows) {
			/**
			 * Calls recorded, for assertion.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $calls = [];

			/**
			 * Construct with the rows to return.
			 *
			 * @param array<int, array<string, mixed>> $rows Rows.
			 */
			public function __construct(
				private array $rows,
			) {
			}//end __construct()

			/**
			 * Mimic the slug-aware search bridge.
			 *
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 * @param array<string, mixed> $filters Filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				$this->calls[] = ['search', $register, $schema, $filters];
				return $this->rows;
			}//end searchObjectsBySlug()

			/**
			 * Mimic ObjectService::searchObjects — the NUMERIC path.
			 *
			 * A live instance stores numeric register/schema ids (register 14,
			 * organisatie_rol_schema 153), so this, not the slug bridge, is what
			 * production actually calls. The register/schema arrive nested under
			 * `@self`; a top-level filter would silently return nothing.
			 *
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjects(array $query = []): array {
				$this->calls[] = [
					'search',
					(string)($query['@self']['register'] ?? ''),
					(string)($query['@self']['schema'] ?? ''),
					$query,
				];
				return $this->rows;
			}//end searchObjects()

			/**
			 * Mimic ObjectService::saveObject.
			 *
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 * @param array<string, mixed> $object Payload.
			 *
			 * @return array<string, mixed> The saved object.
			 */
			public function saveObject(string $register, string $schema, array $object): array {
				$this->calls[] = ['save', $register, $schema, $object];
				return ($object + ['id' => 'generated']);
			}//end saveObject()

			/**
			 * Mimic ObjectService::deleteObject — note the parameter is $uuid.
			 *
			 * @param string $uuid Object id.
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 *
			 * @return bool Always true.
			 */
			public function deleteObject(string $uuid, string $register = '', string $schema = ''): bool {
				$this->calls[] = ['delete', $uuid, $register, $schema];
				return true;
			}//end deleteObject()
		};
	}//end objectService()

	/**
	 * Build the subject.
	 *
	 * @param object|null $objectService The object service, or null.
	 *
	 * @return ConfiguredRegistryService The subject.
	 */
	private function subject(?object $objectService): ConfiguredRegistryService {
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		return new ConfiguredRegistryService(
			settingsService: $this->settingsService,
			logger: $this->logger,
		);
	}//end subject()

	/**
	 * Listing returns the rows and applies the documented cap.
	 *
	 * Uses NUMERIC register/schema ids because that is what a live instance
	 * stores (register 14, organisatie_rol_schema 153), which routes through
	 * `searchObjects()` rather than the slug bridge.
	 *
	 * @return void
	 */
	public function testListReturnsRowsAndAppliesTheLimit(): void {
		$this->withConfig('14', '153');
		$stub = $this->objectService([['id' => 'a'], ['id' => 'b']]);
		$service = $this->subject($stub);

		$rows = $service->list('organisatie_rol_schema');

		$this->assertCount(2, $rows);
		$this->assertSame(
			ConfiguredRegistryService::LIST_LIMIT,
			$stub->calls[0][3]['_limit']
		);
	}//end testListReturnsRowsAndAppliesTheLimit()

	/**
	 * 🔴 On the numeric path, register/schema must be NESTED under `@self`.
	 *
	 * A top-level `register`/`schema` filter is treated as an object-field
	 * filter and silently returns an empty set — the OpenRegister trap that
	 * reads as "there is no data" rather than as a malformed query.
	 *
	 * @return void
	 */
	public function testNumericIdsAreNestedUnderSelf(): void {
		$this->withConfig('14', '153');
		$stub = $this->objectService([['id' => 'a']]);
		$service = $this->subject($stub);

		$service->list('organisatie_rol_schema');

		$query = $stub->calls[0][3];
		$this->assertSame(14, $query['@self']['register']);
		$this->assertSame(153, $query['@self']['schema']);
		$this->assertArrayNotHasKey('register', $query, 'a top-level register filter returns nothing');
		$this->assertArrayNotHasKey('schema', $query);
	}//end testNumericIdsAreNestedUnderSelf()

	/**
	 * A slug-configured registry routes through the slug bridge instead.
	 *
	 * @return void
	 */
	public function testSlugIdsUseTheSlugBridge(): void {
		$this->withConfig('dossiq', 'organisatieRol');
		$stub = $this->objectService([['id' => 'a']]);
		$service = $this->subject($stub);

		$rows = $service->list('organisatie_rol_schema');

		$this->assertCount(1, $rows);
		$this->assertSame(['search', 'dossiq', 'organisatieRol'], array_slice($stub->calls[0], 0, 3));
	}//end testSlugIdsUseTheSlugBridge()

	/**
	 * An unconfigured schema degrades to an empty list rather than throwing.
	 *
	 * @return void
	 */
	public function testListDegradesWhenTheSchemaIsUnconfigured(): void {
		$this->withConfig('14', '');
		$service = $this->subject($this->objectService([['id' => 'a']]));

		$this->assertSame([], $service->list('organisatie_rol_schema'));
	}//end testListDegradesWhenTheSchemaIsUnconfigured()

	/**
	 * An absent OpenRegister degrades to an empty list.
	 *
	 * @return void
	 */
	public function testListDegradesWhenOpenRegisterIsAbsent(): void {
		$this->withConfig('14', '153');
		$service = $this->subject(null);

		$this->assertSame([], $service->list('organisatie_rol_schema'));
	}//end testListDegradesWhenOpenRegisterIsAbsent()

	/**
	 * Saving without an id creates; the id is not forced into the payload.
	 *
	 * @return void
	 */
	public function testSaveWithoutAnIdCreates(): void {
		$this->withConfig('14', '153');
		$stub = $this->objectService();
		$service = $this->subject($stub);

		$service->save('organisatie_rol_schema', ['roleName' => 'X']);

		$this->assertSame('save', $stub->calls[0][0]);
		$this->assertArrayNotHasKey('id', $stub->calls[0][3]);
	}//end testSaveWithoutAnIdCreates()

	/**
	 * Saving with an id sets it on the payload so OpenRegister updates in place.
	 *
	 * @return void
	 */
	public function testSaveWithAnIdUpdatesInPlace(): void {
		$this->withConfig('14', '153');
		$stub = $this->objectService();
		$service = $this->subject($stub);

		$service->save('organisatie_rol_schema', ['roleName' => 'X'], 'role-9');

		$this->assertSame('role-9', $stub->calls[0][3]['id']);
	}//end testSaveWithAnIdUpdatesInPlace()

	/**
	 * 🔴 A CLIENT-SUPPLIED IDENTITY MUST NOT ADDRESS AN OBJECT.
	 *
	 * `saveObject()` resolves its target from the payload — `@self.id` first,
	 * then `id` — and the write is PUT-semantic, so keys the payload omits are
	 * NULLED. Every caller here builds the payload from
	 * `$this->request->getParams()`.
	 *
	 * Several callers stripped `id` for exactly this reason and did not know
	 * about `@self`. Measured on a running instance BEFORE this fix: a POST to
	 * the create endpoint carrying `@self: {id: <another role>}` returned 201
	 * with the victim's own uuid, and the victim's row came back holding the
	 * attacker's values.
	 *
	 * @return void
	 */
	public function testAClientSuppliedIdentityCannotAddressAnObject(): void {
		$this->withConfig('14', '153');
		$stub = $this->objectService();
		$service = $this->subject($stub);

		$service->save(
			'organisatie_rol_schema',
			[
				'roleName' => 'X',
				'id' => 'someone-elses-object',
				'uuid' => 'someone-elses-object',
				'@self' => ['id' => 'someone-elses-object'],
			]
		);

		$written = $stub->calls[0][3];
		$this->assertArrayNotHasKey('id', $written);
		$this->assertArrayNotHasKey('uuid', $written);
		$this->assertArrayNotHasKey('@self', $written, '@self is the key saveObject reads FIRST');
		$this->assertSame('X', $written['roleName'], 'the rest of the payload still saves');
	}//end testAClientSuppliedIdentityCannotAddressAnObject()

	/**
	 * The explicit $id parameter still wins, so updates keep working.
	 *
	 * The guard above must not turn every update into a create — that would
	 * trade a security hole for silent data duplication.
	 *
	 * @return void
	 */
	public function testTheExplicitIdParameterStillAddressesTheObject(): void {
		$this->withConfig('14', '153');
		$stub = $this->objectService();
		$service = $this->subject($stub);

		$service->save(
			'organisatie_rol_schema',
			['roleName' => 'X', '@self' => ['id' => 'someone-elses-object']],
			'role-9'
		);

		$this->assertSame('role-9', $stub->calls[0][3]['id']);
		$this->assertArrayNotHasKey('@self', $stub->calls[0][3]);
	}//end testTheExplicitIdParameterStillAddressesTheObject()

	/**
	 * Saving into an unconfigured registry throws rather than silently no-oping.
	 *
	 * A silent no-op here is exactly the failure mode procest#794 was about:
	 * a write that appears to succeed and changes nothing.
	 *
	 * @return void
	 */
	public function testSaveThrowsWhenTheRegistryIsUnconfigured(): void {
		$this->withConfig('', '');
		$service = $this->subject($this->objectService());

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/Not configured/');

		$service->save('organisatie_rol_schema', ['roleName' => 'X']);
	}//end testSaveThrowsWhenTheRegistryIsUnconfigured()

	/**
	 * 🔴 Delete passes the id as `uuid`, not `id`.
	 *
	 * `ObjectService::deleteObject()`'s first parameter is `$uuid`. Passing
	 * `id:` raises "Unknown named parameter $id" at runtime — the bug that made
	 * InspectionChecklistService::deleteChecklist() 500 on every call. This
	 * test fails if that regression returns.
	 *
	 * @return void
	 */
	public function testDeletePassesTheIdentifierAsUuid(): void {
		$this->withConfig('14', '153');
		$stub = $this->objectService();
		$service = $this->subject($stub);

		$service->delete('organisatie_rol_schema', 'role-9');

		$this->assertSame(['delete', 'role-9', '14', '153'], $stub->calls[0]);
	}//end testDeletePassesTheIdentifierAsUuid()

	/**
	 * Deleting from an unconfigured registry throws.
	 *
	 * @return void
	 */
	public function testDeleteThrowsWhenTheRegistryIsUnconfigured(): void {
		$this->withConfig('', '');
		$service = $this->subject($this->objectService());

		$this->expectException(RuntimeException::class);

		$service->delete('organisatie_rol_schema', 'role-9');
	}//end testDeleteThrowsWhenTheRegistryIsUnconfigured()
}//end class
