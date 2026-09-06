<?php

/**
 * BesluitvormingTemplateService Unit Tests
 *
 * Tests for the service that seeds the besluitvorming zaaktype templates into
 * OpenRegister.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Besluitvorming\TemplateBundleSeeder;
use OCA\Dossiq\Service\Besluitvorming\WorkflowReferenceResolver;
use OCA\Dossiq\Service\BesluitvormingTemplateService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\JsonEncodedStringProperties;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Minimal ObjectService stub matching the named-argument signatures used by
 * BesluitvormingTemplateService.
 */
interface BvwTemplateObjectServiceStub {
	/**
	 * Save or update an object.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema id.
	 * @param array $object The object payload.
	 * @param string $id Optional id for update.
	 *
	 * @return object
	 */
	public function saveObject(string $register, string $schema, array $object, string $id = ''): object;

	/**
	 * Find objects.
	 *
	 * @param array $params The query params.
	 *
	 * @return array
	 */
	public function findAll(array $params = []): array;
}//end interface

/**
 * Unit tests for BesluitvormingTemplateService.
 *
 * @covers \OCA\Dossiq\Service\BesluitvormingTemplateService
 *
 * @uses \OCA\Dossiq\Service\Besluitvorming\TemplateBundleSeeder
 * @uses \OCA\Dossiq\Service\Besluitvorming\WorkflowReferenceResolver
 */
class BesluitvormingTemplateServiceTest extends TestCase {
	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The service under test.
	 *
	 * @var BesluitvormingTemplateService
	 */
	private BesluitvormingTemplateService $service;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new BesluitvormingTemplateService(
			$this->settingsService,
			$this->logger,
			new TemplateBundleSeeder($this->logger, new WorkflowReferenceResolver(), new JsonEncodedStringProperties()),
		);
	}//end setUp()

	/**
	 * Unknown slugs are rejected.
	 *
	 * @return void
	 */
	public function testActivateRejectsUnknownSlug(): void {
		$this->expectException(RuntimeException::class);
		$this->service->activate('niet-bestaand');
	}//end testActivateRejectsUnknownSlug()

	/**
	 * Activation throws when OpenRegister is unavailable.
	 *
	 * @return void
	 */
	public function testActivateThrowsWithoutObjectService(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);
		$this->expectException(RuntimeException::class);
		$this->service->activate('college-besluit');
	}//end testActivateThrowsWithoutObjectService()

	/**
	 * Activation is idempotent — an existing caseType short-circuits seeding.
	 *
	 * @return void
	 */
	public function testActivateIsIdempotent(): void {
		$existing = new \stdClass();
		$existing->id = 'existing-uuid';

		$objectService = $this->createMock(BvwTemplateObjectServiceStub::class);
		$objectService->method('findAll')->willReturn(['results' => [['id' => 'existing-uuid', 'identifier' => 'bvw-college-besluit']]]);
		// saveObject must never be called when the caseType already exists.
		$objectService->expects($this->never())->method('saveObject');

		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => $key === 'register' ? 'reg' : 'schema-' . $key,
		);

		$result = $this->service->activate('college-besluit');
		$this->assertTrue($result['skipped']);
	}//end testActivateIsIdempotent()

	/**
	 * A fresh activation seeds the full College-besluit bundle.
	 *
	 * @return void
	 */
	public function testActivateSeedsCollegeBesluitBundle(): void {
		$counter = 0;
		$objectService = $this->createMock(BvwTemplateObjectServiceStub::class);
		// No existing caseType.
		$objectService->method('findAll')->willReturn(['results' => []]);
		$objectService->method('saveObject')->willReturnCallback(
			static function () use (&$counter): object {
				$counter++;
				$obj = new \stdClass();
				$obj->id = 'created-' . $counter;
				return $obj;
			},
		);

		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => $key === 'register' ? 'reg' : 'schema-' . $key,
		);

		$result = $this->service->activate('college-besluit');

		$this->assertTrue($result['success']);
		$this->assertSame(1, $result['caseType']);
		$this->assertSame(9, $result['statusTypes']);
		$this->assertSame(5, $result['roleTypes']);
		$this->assertSame(6, $result['propertyDefinitions']);
		$this->assertSame(3, $result['documentTypes']);
		$this->assertSame(3, $result['resultTypes']);
		$this->assertSame(1, $result['workflowTemplate']);
	}//end testActivateSeedsCollegeBesluitBundle()

	/**
	 * Raadsbesluit bundle carries the Griffier roleType and P60D deadline.
	 *
	 * @return void
	 */
	public function testRaadsbesluitBundleHasGriffierAndDeadline(): void {
		$caseTypePayloads = [];
		$roleNames = [];

		$objectService = $this->createMock(BvwTemplateObjectServiceStub::class);
		$objectService->method('findAll')->willReturn(['results' => []]);
		$objectService->method('saveObject')->willReturnCallback(
			static function (string $register, string $schema, array $object) use (&$caseTypePayloads, &$roleNames): object {
				if (($object['processingDeadline'] ?? '') !== '') {
					$caseTypePayloads[] = $object;
				}

				if (isset($object['name']) === true && isset($object['caseType']) === true) {
					$roleNames[] = $object['name'];
				}

				$obj = new \stdClass();
				$obj->id = 'x';
				return $obj;
			},
		);

		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => $key === 'register' ? 'reg' : 'schema-' . $key,
		);

		$this->service->activate('raadsbesluit');

		$this->assertNotEmpty($caseTypePayloads);
		$this->assertSame('P60D', $caseTypePayloads[0]['processingDeadline']);
		$this->assertContains('Griffier', $roleNames);
	}//end testRaadsbesluitBundleHasGriffierAndDeadline()

	/**
	 * Mandaatbesluit bundle is intern and not published.
	 *
	 * @return void
	 */
	public function testMandaatbesluitBundleIsInternAndNotPublished(): void {
		$caseTypePayload = null;
		$objectService = $this->createMock(BvwTemplateObjectServiceStub::class);
		$objectService->method('findAll')->willReturn(['results' => []]);
		$objectService->method('saveObject')->willReturnCallback(
			static function (string $register, string $schema, array $object) use (&$caseTypePayload): object {
				if (($object['identifier'] ?? '') === 'bvw-mandaatbesluit') {
					$caseTypePayload = $object;
				}

				$obj = new \stdClass();
				$obj->id = 'x';
				return $obj;
			},
		);

		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => $key === 'register' ? 'reg' : 'schema-' . $key,
		);

		$this->service->activate('mandaatbesluit');

		$this->assertNotNull($caseTypePayload);
		$this->assertSame('intern', $caseTypePayload['confidentiality']);
		$this->assertFalse($caseTypePayload['publicationRequired']);
	}//end testMandaatbesluitBundleIsInternAndNotPublished()
}//end class
