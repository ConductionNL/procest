<?php

/**
 * StoreController Wire-Contract Tests
 *
 * The store's risk is not that a card fails to render. It is that "Install"
 * writes something it should not, or that the registry token leaks back out of
 * the settings form. Both are asserted here rather than assumed:
 *
 *  - the schema allowlist is a SECURITY BOUNDARY, so a record schema must be
 *    refused, a configuration schema must be written, and a mixed item must do
 *    both rather than failing open or failing closed;
 *  - `getSettings()` must report WHETHER a token is set and never what it is,
 *    which is asserted over the whole serialised body rather than one key —
 *    a leak under a different key would pass a key-by-key check;
 *  - saving an empty token must leave the stored one alone, because the form
 *    cannot show it and therefore posts empty on every unrelated edit;
 *  - an anonymous caller gets an explicit 401 rather than a redirect.
 *
 * Mocks use `onlyMethods`, never `addMethods`: a mock that invents a method the
 * real class does not have cannot fail when the real call site is wrong.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/dossiq-store-surface/specs/dossiq-store-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\StoreController;
use OCA\Dossiq\Service\Support\ConfiguredRegistryService;
use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Wire-contract tests for the store surface.
 *
 * @spec openspec/changes/dossiq-store-surface/specs/dossiq-store-surface/spec.md
 */
class StoreControllerTest extends TestCase {
	/**
	 * The HTTP request.
	 *
	 * @var IRequest&MockObject
	 */
	private $request;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * The user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private $userSession;

	/**
	 * The app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private $appConfig;

	/**
	 * The engine-owned store client.
	 *
	 * @var GenericStoreService&MockObject
	 */
	private $storeService;

	/**
	 * The configured object-write seam.
	 *
	 * @var ConfiguredRegistryService&MockObject
	 */
	private $registry;

	/**
	 * The controller under test.
	 *
	 * @var StoreController
	 */
	private StoreController $controller;


	/**
	 * Build the controller with all collaborators mocked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

		$this->storeService = $this->getMockBuilder(GenericStoreService::class)
			->disableOriginalConstructor()
			->onlyMethods(['isConfigured', 'search', 'resolve'])
			->getMock();

		$this->registry = $this->getMockBuilder(ConfiguredRegistryService::class)
			->disableOriginalConstructor()
			->onlyMethods(['save'])
			->getMock();

		$this->controller = new StoreController(
			$this->request,
			$this->logger,
			$this->userSession,
			$this->appConfig,
			$this->storeService,
			$this->registry
		);
	}//end setUp()


	/**
	 * Pretend a user is signed in.
	 *
	 * @return void
	 */
	private function signIn(): void {
		$this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
	}//end signIn()


	/**
	 * An anonymous caller gets an explicit 401, not a redirect.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dossiq-store-surface/specs/dossiq-store-surface/spec.md#requirement-req-dss-005-browsing-is-authenticated-installing-is-administrative
	 */
	public function testAnAnonymousCallerCannotSearch(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->storeService->expects($this->never())->method('search');

		$response = $this->controller->search();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testAnAnonymousCallerCannotSearch()


	/**
	 * With no registry the engine's `not_configured` is passed straight through,
	 * which is what lets the page fall back to the built-in templates.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dossiq-store-surface/specs/dossiq-store-surface/spec.md#requirement-req-dss-002-no-registry-means-no-network-call
	 */
	public function testAnUnconfiguredInstanceReportsNotConfigured(): void {
		$this->signIn();
		$this->request->method('getParam')->willReturn(null);
		$this->storeService->method('search')->willReturn(
			['outcome' => GenericStoreService::OUTCOME_NOT_CONFIGURED, 'cards' => []]
		);

		$response = $this->controller->search();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('not_configured', $data['outcome']);
		$this->assertSame([], $data['cards']);
	}//end testAnUnconfiguredInstanceReportsNotConfigured()


	/**
	 * The descriptor names dossiq's own schema and register, so one registry can
	 * serve several apps without them reading each other's items.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dossiq-store-surface/specs/dossiq-store-surface/spec.md#requirement-req-dss-001-discovery-goes-through-the-engine
	 */
	public function testSearchAsksTheEngineForDossiqsOwnSchema(): void {
		$this->signIn();
		$this->request->method('getParam')->willReturn(null);

		$this->storeService->expects($this->once())
			->method('search')
			->with(
				$this->callback(
					static function ($descriptor): bool {
						return $descriptor->appId === 'dossiq'
							&& $descriptor->schema === 'case-type-template'
							&& $descriptor->defaultRegister === 'dossiq';
					}
				),
				null,
				null
			)
			->willReturn(['outcome' => GenericStoreService::OUTCOME_OK, 'cards' => []]);

		$this->controller->search();
	}//end testSearchAsksTheEngineForDossiqsOwnSchema()


	/**
	 * A malformed slug never reaches the engine.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dossiq-store-surface/specs/dossiq-store-surface/spec.md#requirement-req-dss-003-install-accepts-configuration-and-refuses-records
	 */
	public function testAMalformedSlugIsRefusedBeforeResolving(): void {
		$this->storeService->expects($this->never())->method('resolve');

		$response = $this->controller->install(slug: '../etc/passwd');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testAMalformedSlugIsRefusedBeforeResolving()


	/**
	 * An unresolved item is a 404, not a silent success.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dossiq-store-surface/specs/dossiq-store-surface/spec.md#requirement-req-dss-003-install-accepts-configuration-and-refuses-records
	 */
	public function testAnUnresolvedItemIsNotFound(): void {
		$this->storeService->method('resolve')->willReturn(null);
		$this->registry->expects($this->never())->method('save');

		$response = $this->controller->install(slug: 'missing-item');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAnUnresolvedItemIsNotFound()


	/**
	 * A configuration component is written through the configured registry, and
	 * against the config key the schema slug maps to rather than the slug.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dossiq-store-surface/specs/dossiq-store-surface/spec.md#requirement-req-dss-003-install-accepts-configuration-and-refuses-records
	 */
	public function testAConfigurationComponentInstalls(): void {
		$this->storeService->method('resolve')->willReturn(
			[
				'slug' => 'vth-track',
				'components' => [
					['schema' => 'caseType', 'object' => ['title' => 'Handhaving']],
				],
			]
		);

		$this->registry->expects($this->once())
			->method('save')
			->with('case_type_schema', ['title' => 'Handhaving'])
			->willReturn(['id' => 'new-id']);

		$response = $this->controller->install(slug: 'vth-track');
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertSame('installed', $data['components'][0]['status']);
	}//end testAConfigurationComponentInstalls()


	/**
	 * 🔴 The boundary. A record schema is refused and NOTHING is written for it.
	 *
	 * Without this the install path is a remote write primitive against a
	 * municipality's live case records.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dossiq-store-surface/specs/dossiq-store-surface/spec.md#requirement-req-dss-003-install-accepts-configuration-and-refuses-records
	 */
	public function testARecordComponentIsRefusedAndNothingIsWritten(): void {
		$this->storeService->method('resolve')->willReturn(
			[
				'slug' => 'hostile-item',
				'components' => [
					['schema' => 'case', 'object' => ['title' => 'Injected case']],
				],
			]
		);

		$this->registry->expects($this->never())->method('save');

		$response = $this->controller->install(slug: 'hostile-item');
		$data = $response->getData();

		$this->assertFalse($data['success']);
		$this->assertSame('case', $data['components'][0]['schema']);
		$this->assertSame('refused', $data['components'][0]['status']);
	}//end testARecordComponentIsRefusedAndNothingIsWritten()


	/**
	 * A mixed item installs the half it may and names the half it refused.
	 *
	 * Failing the whole install would deny an administrator configuration they
	 * are entitled to; installing the whole item would cross the boundary. The
	 * partial outcome is the correct one, and it has to be reported as partial.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dossiq-store-surface/specs/dossiq-store-surface/spec.md#requirement-req-dss-003-install-accepts-configuration-and-refuses-records
	 */
	public function testAMixedItemInstallsOnlyTheConfigurationHalf(): void {
		$this->storeService->method('resolve')->willReturn(
			[
				'slug' => 'mixed-item',
				'components' => [
					['schema' => 'caseType', 'object' => ['title' => 'Handhaving']],
					['schema' => 'caseTask', 'object' => ['title' => 'Injected task']],
				],
			]
		);

		$this->registry->expects($this->once())
			->method('save')
			->with('case_type_schema', ['title' => 'Handhaving'])
			->willReturn(['id' => 'new-id']);

		$response = $this->controller->install(slug: 'mixed-item');
		$data = $response->getData();

		$this->assertFalse($data['success']);
		$this->assertSame('installed', $data['components'][0]['status']);
		$this->assertSame('refused', $data['components'][1]['status']);
	}//end testAMixedItemInstallsOnlyTheConfigurationHalf()


	/**
	 * A registry may ship the component list as a JSON string, the way dossiq's
	 * own workflowTemplate stores its steps.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dossiq-store-surface/specs/dossiq-store-surface/spec.md#requirement-req-dss-003-install-accepts-configuration-and-refuses-records
	 */
	public function testComponentsMayArriveAsAJsonString(): void {
		$this->storeService->method('resolve')->willReturn(
			[
				'slug' => 'string-item',
				'components' => json_encode(
					[['schema' => 'statusType', 'object' => ['title' => 'Ontvangen']]]
				),
			]
		);

		$this->registry->expects($this->once())
			->method('save')
			->with('status_type_schema', ['title' => 'Ontvangen'])
			->willReturn(['id' => 'new-id']);

		$response = $this->controller->install(slug: 'string-item');

		$this->assertTrue($response->getData()['success']);
	}//end testComponentsMayArriveAsAJsonString()


	/**
	 * A write that fails is reported as an error, and the message is withheld.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dossiq-store-surface/specs/dossiq-store-surface/spec.md#requirement-req-dss-003-install-accepts-configuration-and-refuses-records
	 */
	public function testAFailedWriteIsReportedWithoutLeakingTheReason(): void {
		$this->storeService->method('resolve')->willReturn(
			[
				'slug' => 'unconfigured-item',
				'components' => [['schema' => 'caseType', 'object' => ['title' => 'Handhaving']]],
			]
		);

		$this->registry->method('save')->willThrowException(
			new RuntimeException('Not configured: no register or schema for case_type_schema')
		);

		$response = $this->controller->install(slug: 'unconfigured-item');
		$data = $response->getData();

		$this->assertFalse($data['success']);
		$this->assertSame('error', $data['components'][0]['status']);
		$this->assertStringNotContainsString('case_type_schema', $data['components'][0]['message']);
	}//end testAFailedWriteIsReportedWithoutLeakingTheReason()


	/**
	 * 🔴 A component carrying an id must NOT overwrite a local object.
	 *
	 * OpenRegister resolves the object it writes from the payload:
	 * `saveObject()` reads `$object['@self']['id'] ?? $object['id']` and treats
	 * a match as the uuid to UPDATE. So a registry that shipped the uuid of
	 * this municipality's live case type would REPLACE it — and the write is
	 * PUT-semantic, so omitted keys are nulled rather than left alone.
	 *
	 * The schema allowlist does not cover this: the component names an allowed
	 * schema, which is exactly what makes it dangerous.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dossiq-store-surface/specs/dossiq-store-surface/spec.md#requirement-req-dss-003-install-accepts-configuration-and-refuses-records
	 */
	public function testAnInstalledComponentCannotOverwriteAnExistingObject(): void {
		$this->storeService->method('resolve')->willReturn(
			[
				'slug' => 'hostile-item',
				'components' => [
					[
						'schema' => 'caseType',
						'object' => [
							'id' => 'the-municipalitys-live-case-type',
							'uuid' => 'the-municipalitys-live-case-type',
							'@self' => ['id' => 'the-municipalitys-live-case-type'],
							'title' => 'Replaced',
						],
					],
				],
			]
		);

		$written = null;
		$this->registry->method('save')->willReturnCallback(
			static function (string $key, array $data) use (&$written): array {
				$written = $data;
				return $data;
			}
		);

		$this->controller->install(slug: 'hostile-item');

		$this->assertIsArray($written);
		$this->assertArrayNotHasKey('id', $written, 'a remote id must not address a local object');
		$this->assertArrayNotHasKey('uuid', $written, 'nor a remote uuid');
		$this->assertArrayNotHasKey('@self', $written, 'nor @self, which saveObject reads FIRST');
		$this->assertSame('Replaced', $written['title'], 'the rest of the payload still installs');
	}//end testAnInstalledComponentCannotOverwriteAnExistingObject()

	/**
	 * The token never comes back out.
	 *
	 * Asserted over the whole serialised body rather than key by key: a leak
	 * under a different key would pass a per-key check and is exactly the
	 * mistake this test exists to catch.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dossiq-store-surface/specs/dossiq-store-surface/spec.md#requirement-req-dss-004-the-registry-token-is-write-only
	 */
	public function testReadingTheSettingsNeverReturnsTheToken(): void {
		$this->appConfig->method('getValueString')->willReturnMap(
			[
				['dossiq', 'registry_url', '', false, 'https://registry.example.org'],
				['dossiq', 'registry_register', 'dossiq', false, 'dossiq'],
				['dossiq', 'registry_token', '', false, 'super-secret-token'],
			]
		);

		$data = $this->controller->getSettings()->getData();

		$this->assertTrue($data['registryTokenSet']);
		$this->assertStringNotContainsString(
			'super-secret-token',
			json_encode($data),
			'the registry token must not appear anywhere in the settings body'
		);
	}//end testReadingTheSettingsNeverReturnsTheToken()


	/**
	 * An unset token reports false rather than an empty string the form might
	 * render as "configured".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dossiq-store-surface/specs/dossiq-store-surface/spec.md#requirement-req-dss-004-the-registry-token-is-write-only
	 */
	public function testAnUnsetTokenReportsFalse(): void {
		$this->appConfig->method('getValueString')->willReturn('');

		$this->assertFalse($this->controller->getSettings()->getData()['registryTokenSet']);
	}//end testAnUnsetTokenReportsFalse()


	/**
	 * Saving with an empty token leaves the stored one untouched.
	 *
	 * The form cannot show the current token, so it posts empty whenever the
	 * administrator did not retype it. Treating that as "clear the credential"
	 * would disconnect the store on every unrelated edit to the URL.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dossiq-store-surface/specs/dossiq-store-surface/spec.md#requirement-req-dss-004-the-registry-token-is-write-only
	 */
	public function testSavingAnEmptyTokenPreservesTheStoredOne(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['registryUrl', null, 'https://registry.example.org'],
				['registryRegister', null, 'dossiq'],
				['registryToken', null, '   '],
			]
		);
		$this->appConfig->method('getValueString')->willReturn('');

		$written = [];
		$this->appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$written): bool {
				$written[$key] = $value;
				return true;
			}
		);

		$this->controller->saveSettings();

		$this->assertArrayNotHasKey(
			'registry_token',
			$written,
			'an empty token field must not overwrite the stored credential'
		);
		$this->assertSame('https://registry.example.org', $written['registry_url']);
	}//end testSavingAnEmptyTokenPreservesTheStoredOne()


	/**
	 * A supplied token IS written.
	 *
	 * The negative control for the test above: without it, a controller that
	 * never wrote the token at all would also pass.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dossiq-store-surface/specs/dossiq-store-surface/spec.md#requirement-req-dss-004-the-registry-token-is-write-only
	 */
	public function testASuppliedTokenIsWritten(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['registryUrl', null, 'https://registry.example.org'],
				['registryRegister', null, 'dossiq'],
				['registryToken', null, 'a-new-token'],
			]
		);
		$this->appConfig->method('getValueString')->willReturn('');

		$written = [];
		$this->appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$written): bool {
				$written[$key] = $value;
				return true;
			}
		);

		$this->controller->saveSettings();

		$this->assertSame('a-new-token', $written['registry_token']);
	}//end testASuppliedTokenIsWritten()
}//end class
