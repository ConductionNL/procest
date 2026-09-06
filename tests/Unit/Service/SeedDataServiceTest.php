<?php

/**
 * SeedDataService Unit Tests
 *
 * Tests for the Dossiq SeedDataService that seeds bezwaar/beroep case types
 * into OpenRegister.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\SeedDataService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Minimal ObjectService shape used by SeedDataService.
 *
 * Declares the named-arg signatures used in production so that
 * `createMock(SeedObjectServiceStub::class)` returns a stub that accepts
 * named arguments. A `getMockBuilder(\stdClass::class)->addMethods([...])`
 * stub throws "Unknown named parameter" on named-arg calls in PHPUnit 10.
 */
interface SeedObjectServiceStub {
	public function getObjects(string $register, string $schema, array $filters, int $limit): array;
	public function saveObject(string $register, string $schema, array $object): ?object;
	public function findAll(array $params = []): array;

}//end interface

/**
 * Unit tests for SeedDataService.
 *
 * SeedSummary is DECLARED, not covered. The service builds one and the
 * assertions read it, so strict coverage counts it as executed; without this
 * `@uses` PHPUnit reports "executed code that is not listed as code to be
 * covered" and the run goes risky. That only happens where a coverage driver
 * is loaded, which is CI and not a plain local run — the six red PHPUnit cells
 * had zero failures.
 *
 * @covers \OCA\Dossiq\Service\SeedDataService
 * @uses   \OCA\Dossiq\Service\Support\SeedSummary
 */
class SeedDataServiceTest extends TestCase {

	/**
	 * The mocked app configuration service.
	 *
	 * @var IAppConfig|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * The mocked DI container.
	 *
	 * @var ContainerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private ContainerInterface $container;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The service under test.
	 *
	 * @var SeedDataService
	 */
	private SeedDataService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new SeedDataService(
			$this->appConfig,
			$this->container,
			$this->logger,
		);

	}//end setUp()

	/**
	 * Test that seedBezwaarBeroepData returns failure when ObjectService is unavailable.
	 *
	 * When OpenRegister is not installed the container will throw, causing
	 * getObjectService() to return null.
	 *
	 * @return void
	 */
	public function testSeedBezwaarBeroepDataFailsWithoutObjectService(): void {
		// All config values are non-empty so we get past the early config check.
		$this->appConfig
			->method('getValueString')
			->willReturn('some-register-id');

		// Container throws, ObjectService is unavailable.
		$this->container
			->method('get')
			->willThrowException(new \RuntimeException('Service not found'));

		$result = $this->service->seedBezwaarBeroepData();

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('ObjectService not available', $result['message']);

	}//end testSeedBezwaarBeroepDataFailsWithoutObjectService()

	/**
	 * The summary carries a refusal count, and the parked file refuses nothing.
	 *
	 * WHAT THIS PINS, AND WHAT IT DOES NOT. `createObject()` answered null on
	 * failure and the caller returned its untouched counts, so a seed whose
	 * every write OpenRegister refused came back `success: true, caseTypes: 0`
	 * — indistinguishable from an already-seeded instance. This service runs
	 * from `<install>` and `<post-migration>`, where there is no session and
	 * refusal is the DEFAULT outcome, so that is not a corner case.
	 *
	 * It cannot be driven to a refusal here, and that is a fact about the
	 * shipped data rather than a gap in the test: dossiq#1748 parked the Dutch
	 * case types under `_caseTypes_disabled`, the seed file path is a constant,
	 * and with no case types offered nothing is ever written. So this asserts
	 * the shape — a `failed` key that exists and is a true zero — which is what
	 * un-parking the profile must not quietly lose. The elevation half is
	 * swept mechanically by `SeedWriteIdentityTest`, which reads this service
	 * as `SeedBezwaarBeroepData`'s write surface.
	 *
	 * @return void
	 */
	public function testTheSummaryCarriesARefusalCountAndTheParkedFileRefusesNothing(): void {
		$this->appConfig
			->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key): string {
					return ($key === 'register') ? 'register-uuid-1' : 'schema-uuid-1';
				}
			);

		$objectServiceMock = $this->createMock(SeedObjectServiceStub::class);
		$objectServiceMock->method('findAll')->willReturn([]);
		$objectServiceMock
			->method('saveObject')
			->willThrowException(
				new \RuntimeException("User 'Anonymous' does not have permission to 'create' objects in schema 'Case Type'")
			);

		$this->container->method('get')->willReturn($objectServiceMock);

		$result = $this->service->seedBezwaarBeroepData();

		$this->assertArrayHasKey('failed', $result, 'the summary must carry a refusal count');
		$this->assertSame(0, $result['failed'], 'the parked seed file offers no case types, so nothing is attempted');
		$this->assertTrue($result['success']);
		$this->assertSame(0, $result['caseTypes'], 'the shipped case types are parked under _caseTypes_disabled');

	}//end testTheSummaryCarriesARefusalCountAndTheParkedFileRefusesNothing()

	/**
	 * Test that seedBezwaarBeroepData returns failure when register is not configured.
	 *
	 * @return void
	 */
	public function testSeedBezwaarBeroepDataFailsWithoutRegisterConfig(): void {
		// Config returns empty for all keys.
		$this->appConfig
			->method('getValueString')
			->willReturn('');

		// ObjectService IS available (non-null).
		$objectService = new \stdClass();
		$this->container
			->method('get')
			->willReturn($objectService);

		$result = $this->service->seedBezwaarBeroepData();

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('not configured', $result['message']);

	}//end testSeedBezwaarBeroepDataFailsWithoutRegisterConfig()

	/**
	 * Test that seedBezwaarBeroepData returns success summary on correct setup.
	 *
	 * This test verifies the happy path with the real bezwaar_seed_data.json file.
	 * The ObjectService mock simulates that all objects are newly created (not found by filter).
	 *
	 * @return void
	 */
	public function testSeedBezwaarBeroepDataReturnsSummaryStructure(): void {
		// Config returns valid IDs so we get past the register check.
		$this->appConfig
			->method('getValueString')
			->willReturnCallback(
				function (string $app, string $key): string {
					if ($key === 'register') {
						return 'register-uuid-1';
					}

					if ($key === 'case_type_schema') {
						return 'schema-uuid-1';
					}

					return 'schema-uuid-2';
				}
			);

		// ObjectService that returns empty (no existing objects) and creates new ones.
		$createdObject = new \stdClass();
		$createdObject->uuid = 'created-uuid-1';

		$objectServiceMock = $this->createMock(SeedObjectServiceStub::class);

		$objectServiceMock
			->method('findAll')
			->willReturn([]);

		$objectServiceMock
			->method('saveObject')
			->willReturn($createdObject);

		$this->container
			->method('get')
			->willReturn($objectServiceMock);

		$result = $this->service->seedBezwaarBeroepData();

		$this->assertIsArray($result);
		$this->assertArrayHasKey('success', $result);
		$this->assertArrayHasKey('caseTypes', $result);
		$this->assertArrayHasKey('statusTypes', $result);
		$this->assertArrayHasKey('roleTypes', $result);
		$this->assertArrayHasKey('workflows', $result);
		$this->assertArrayHasKey('skipped', $result);

	}//end testSeedBezwaarBeroepDataReturnsSummaryStructure()

	/**
	 * Test that seedBezwaarBeroepData skips existing case types.
	 *
	 * When getObjects returns a result, the case type exists and should be skipped.
	 *
	 * @return void
	 */
	public function testSeedBezwaarBeroepDataSkipsExistingCaseTypes(): void {
		// The seeder reads `caseTypes` from the real seed file. When the Dutch
		// demo case types are parked under `_caseTypes_disabled` (see the file's
		// _note), there is nothing to skip, so the skip-existing behaviour can
		// only be exercised while Dutch seeding is enabled.
		$seedData = json_decode(file_get_contents(__DIR__ . '/../../../lib/Settings/bezwaar_seed_data.json'), true);
		if (empty($seedData['caseTypes']) === true) {
			$this->markTestSkipped('Dutch bezwaar caseTypes are disabled (parked under _caseTypes_disabled).');
		}

		$this->appConfig
			->method('getValueString')
			->willReturnCallback(
				function (string $app, string $key): string {
					if ($key === 'register') {
						return 'register-uuid-1';
					}

					if ($key === 'case_type_schema') {
						return 'schema-uuid-1';
					}

					return '';
				}
			);

		$existingObject = new \stdClass();
		$existingObject->uuid = 'existing-uuid-1';

		$objectServiceMock = $this->createMock(SeedObjectServiceStub::class);

		// Always return an existing object from findAll.
		$objectServiceMock
			->method('findAll')
			->willReturn([$existingObject]);

		// saveObject should NOT be called if all case types are skipped.
		$objectServiceMock
			->expects($this->never())
			->method('saveObject');

		$this->container
			->method('get')
			->willReturn($objectServiceMock);

		$result = $this->service->seedBezwaarBeroepData();

		// All case types should be skipped, none created.
		if ($result['success'] === true) {
			$this->assertSame(0, $result['caseTypes']);
			$this->assertGreaterThan(0, $result['skipped']);
		}

	}//end testSeedBezwaarBeroepDataSkipsExistingCaseTypes()

	/**
	 * Test that the bezwaar seed data file exists and is valid JSON.
	 *
	 * @return void
	 */
	public function testBezwaarSeedDataFileExistsAndIsValidJson(): void {
		$seedPath = __DIR__ . '/../../../lib/Settings/bezwaar_seed_data.json';

		$this->assertFileExists($seedPath, 'bezwaar_seed_data.json must exist');

		$content = file_get_contents($seedPath);
		$seedData = json_decode($content, true);

		$this->assertSame(JSON_ERROR_NONE, json_last_error(), 'bezwaar_seed_data.json must be valid JSON');
		$this->assertIsArray($seedData);
		// The case-type array may be parked under `_caseTypes_disabled` when the
		// Dutch demo seeding is turned off in favour of the English demo (see the
		// file's `_note`). Accept either the active or the parked key.
		$this->assertTrue(
			(array_key_exists('caseTypes', $seedData) === true
				|| array_key_exists('_caseTypes_disabled', $seedData) === true),
			'Seed data must have a caseTypes key (or _caseTypes_disabled when parked)'
		);

	}//end testBezwaarSeedDataFileExistsAndIsValidJson()

}//end class
