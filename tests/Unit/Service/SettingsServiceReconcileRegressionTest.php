<?php

/**
 * SettingsService::reconcileSchemaConfig() Phase-0 Regression Tests
 *
 * Locks the Phase-0 fix where every `*_schema` appconfig key is provisioned
 * idempotently from OpenRegister's SchemaMapper, closing the gap left by
 * autoConfigureAfterImport() (which only persists schemas present in a fresh
 * import result and therefore skipped them on an already-imported instance).
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

use OCA\Dossiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Minimal OpenRegister SchemaMapper shape used by reconcileSchemaConfig().
 *
 * Declared as an interface so the `find($slug, [], false, false)` call resolves
 * against a createMock() instance. Mirrors the canonical OpenRegister
 * SchemaMapper::find($id, $_extend, $_rbac, $_multitenancy) signature (4 args).
 */
interface ReconcileSchemaMapperStub {
	public function find(string $slug, array $extend, bool $rbac, bool $multitenancy): object;
}//end interface

/**
 * A schema record exposing the live ID, as returned by SchemaMapper::find().
 */
interface ReconcileSchemaStub {
	public function getId(): int;
}//end interface

/**
 * Regression tests for SettingsService::reconcileSchemaConfig().
 *
 * @covers \OCA\Dossiq\Service\SettingsService
 *
 * @uses \OCA\Dossiq\Service\Settings\SchemaAnnotationReconciler
 * @uses \OCA\Dossiq\Service\Settings\SchemaKeyReconciler
 * @uses \OCA\Dossiq\Service\Settings\SchemaSlugResolver
 */
class SettingsServiceReconcileRegressionTest extends TestCase {

	/**
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * @var IAppManager&MockObject
	 */
	private IAppManager $appManager;

	/**
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The service under test.
	 *
	 * @var SettingsService
	 */
	private SettingsService $service;

	/**
	 * Set up the test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new SettingsService(
			$this->appConfig,
			$this->appManager,
			$this->container,
			$this->logger,
		);

	}//end setUp()

	/**
	 * When OpenRegister is unavailable, reconcile is a no-op returning 0.
	 *
	 * @return void
	 */
	public function testReconcileReturnsZeroWhenOpenRegisterAbsent(): void {
		$this->appManager->method('isEnabledForUser')->willReturn(false);
		$this->container->expects($this->never())->method('get');
		$this->appConfig->expects($this->never())->method('setValueString');

		$this->assertSame(0, $this->service->reconcileSchemaConfig());

	}//end testReconcileReturnsZeroWhenOpenRegisterAbsent()

	/**
	 * reconcile() writes a schema key whose current value differs from the live
	 * SchemaMapper ID, and the workflowTemplate slug syncs the alias key too.
	 *
	 * @return void
	 */
	public function testReconcileWritesAllResolvableSchemaKeysIdempotently(): void {
		$this->appManager->method('isEnabledForUser')->willReturn(true);

		$schema = $this->createMock(ReconcileSchemaStub::class);
		$schema->method('getId')->willReturn(101);

		$mapper = $this->createMock(ReconcileSchemaMapperStub::class);
		// Every slug resolves to the same live ID 101 for the purpose of the test.
		$mapper->method('find')->willReturn($schema);

		$this->container->method('get')
			->with('OCA\OpenRegister\Db\SchemaMapper')
			->willReturn($mapper);

		// Current stored value is empty (fresh deploy) for every key, so each
		// resolvable slug triggers exactly one write — the idempotent provisioning.
		$this->appConfig->method('getValueString')->willReturn('');

		$writtenKeys = [];
		$this->appConfig->method('setValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $value) use (&$writtenKeys): bool {
					$writtenKeys[$key] = $value;
					return true;
				}
			);

		$count = $this->service->reconcileSchemaConfig();

		$this->assertGreaterThan(0, $count);
		// A representative subset of the *_schema keys must be provisioned.
		$this->assertArrayHasKey('case_type_schema', $writtenKeys);
		$this->assertArrayHasKey('status_record_schema', $writtenKeys);
		$this->assertArrayHasKey('workflow_template_schema', $writtenKeys);
		$this->assertSame('101', $writtenKeys['case_type_schema']);
		// workflowTemplate slug also syncs the stable alias key.
		$this->assertArrayHasKey('workflow_definition_schema', $writtenKeys);

	}//end testReconcileWritesAllResolvableSchemaKeysIdempotently()

	/**
	 * A key already holding the correct live ID is left untouched (idempotency):
	 * no write occurs when current === resolved.
	 *
	 * @return void
	 */
	public function testReconcileSkipsKeysAlreadyCorrect(): void {
		$this->appManager->method('isEnabledForUser')->willReturn(true);

		$schema = $this->createMock(ReconcileSchemaStub::class);
		$schema->method('getId')->willReturn(55);

		$mapper = $this->createMock(ReconcileSchemaMapperStub::class);
		$mapper->method('find')->willReturn($schema);

		$this->container->method('get')->willReturn($mapper);

		// Every key already holds the resolved ID "55" — nothing to write.
		$this->appConfig->method('getValueString')->willReturn('55');
		$this->appConfig->expects($this->never())->method('setValueString');

		$this->assertSame(0, $this->service->reconcileSchemaConfig());

	}//end testReconcileSkipsKeysAlreadyCorrect()

	/**
	 * Slugs that do not resolve (SchemaMapper::find throws) are skipped, not fatal.
	 *
	 * @return void
	 */
	public function testReconcileSkipsUnresolvableSlugs(): void {
		$this->appManager->method('isEnabledForUser')->willReturn(true);

		$mapper = $this->createMock(ReconcileSchemaMapperStub::class);
		$mapper->method('find')
			->willThrowException(new \RuntimeException('slug not found'));

		$this->container->method('get')->willReturn($mapper);
		$this->appConfig->method('getValueString')->willReturn('');
		$this->appConfig->expects($this->never())->method('setValueString');

		$this->assertSame(0, $this->service->reconcileSchemaConfig());

	}//end testReconcileSkipsUnresolvableSlugs()
}//end class
