<?php

/**
 * DossierCompiler Unit Tests.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\DossierCompiler;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for DossierCompiler.
 *
 * @covers \OCA\Dossiq\Service\DossierCompiler
 */
class DossierCompilerTest extends TestCase {

	/**
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var DossierCompiler
	 */
	private DossierCompiler $service;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new DossierCompiler($this->settingsService, $this->logger);
	}//end setUp()

	/**
	 * Configure SettingsService to return ids for register/schemas.
	 *
	 * @return void
	 */
	private function configureSchemas(): void {
		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => '1',
					'case_schema' => '10',
					'case_document_schema' => '11',
					default => '',
				};
			}
		);
	}//end configureSchemas()

	/**
	 * An empty case id is rejected.
	 *
	 * @return void
	 */
	public function testCompileRejectsEmptyCaseId(): void {
		$this->expectException(RuntimeException::class);
		$this->service->compile('  ');
	}//end testCompileRejectsEmptyCaseId()

	/**
	 * Missing OpenRegister throws.
	 *
	 * @return void
	 */
	public function testCompileThrowsWhenOpenRegisterUnavailable(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);
		$this->expectException(RuntimeException::class);
		$this->service->compile('bezwaar-1');
	}//end testCompileThrowsWhenOpenRegisterUnavailable()

	/**
	 * Documents from the primair besluit case precede the bezwaar case,
	 * and within that, the AWB-conventional order is enforced.
	 *
	 * @return void
	 */
	public function testCompileOrdersDocumentsAcrossLinkedCases(): void {
		$this->configureSchemas();

		$objectionCase = [
			'relatedCases' => ['primair-1'],
		];

		// Bezwaar-own documents (out of AWB order on purpose).
		$objectionDocs = [
			['title' => 'Decision on objection', 'document' => 'nc://b/beslissing.pdf'],
			['title' => 'Bezwaarschrift van indiener', 'document' => 'nc://b/bezwaar.pdf'],
			['title' => 'Hoorzittingverslag', 'document' => 'nc://b/verslag.pdf'],
		];

		// Primair besluit case documents.
		$primairDocs = [
			['title' => 'Primair besluit omgevingsvergunning', 'document' => 'nc://p/besluit.pdf'],
		];

		$objectService = new class($objectionCase, $objectionDocs, $primairDocs) {

			/**
			 * @param array<string, mixed> $case
			 * @param array<int, array<string, mixed>> $objectionDocs
			 * @param array<int, array<string, mixed>> $primairDocs
			 */
			public function __construct(
				private array $case,
				private array $objectionDocs,
				private array $primairDocs,
			) {
			}

			/**
			 * Entity-shaped find, mirroring the real ObjectService contract.
			 */
			public function find(int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null): FakeStoredObject {
				return new FakeStoredObject($this->case);
			}

			/**
			 * @param array<string, mixed> $query
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $query): array {
				$case = ($query['filters']['case'] ?? '');
				if ($case === 'primair-1') {
					return $this->primairDocs;
				}

				return $this->objectionDocs;
			}
		};

		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$result = $this->service->compile('bezwaar-1');

		$titles = array_map(static fn (array $d): string => (string)$d['title'], $result);

		$this->assertSame(
			[
				'Primair besluit omgevingsvergunning',
				'Bezwaarschrift van indiener',
				'Hoorzittingverslag',
				'Decision on objection',
			],
			$titles
		);

		// Inherited docs are tagged with their source case.
		$this->assertSame('primair-1', $result[0]['_sourceCase']);
		$this->assertSame('bezwaar-1', $result[1]['_sourceCase']);
	}//end testCompileOrdersDocumentsAcrossLinkedCases()

	/**
	 * A findAll failure on one case degrades to an empty contribution
	 * rather than aborting the whole compile.
	 *
	 * @return void
	 */
	public function testCompileToleratesDocumentListingFailure(): void {
		$this->configureSchemas();

		$objectService = new class {

			/**
			 * Entity-shaped find, mirroring the real ObjectService contract.
			 */
			public function find(int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null): FakeStoredObject {
				return new FakeStoredObject(['relatedCases' => []]);
			}

			/**
			 * @param array<string, mixed> $query
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $query): array {
				throw new \RuntimeException('boom');
			}
		};

		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$result = $this->service->compile('bezwaar-1');
		$this->assertSame([], $result);
	}//end testCompileToleratesDocumentListingFailure()
}//end class
