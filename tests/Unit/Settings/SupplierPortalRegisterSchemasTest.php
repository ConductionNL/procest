<?php

/**
 * Supplier Portal Register Schemas Test
 *
 * Verifies the 7 supplier portal schemas + 4 supplier case types ship in
 * the register template with seed data idempotent across re-runs (no
 * duplicate slugs).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/leverancier-zaakportaal-01-schema-foundation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Repair\InitializeSettings
 */
class SupplierPortalRegisterSchemasTest extends TestCase {
	/**
	 * @var array<string,mixed>
	 */
	private array $register;

	protected function setUp(): void {
		parent::setUp();
		$path = __DIR__ . '/../../../lib/Settings/dossiq_register.json';
		$this->assertFileExists($path);
		$this->register = json_decode((string)file_get_contents($path), true);
	}

	public function testSevenSupplierSchemasDeclared(): void {
		$schemas = $this->register['components']['schemas'] ?? [];
		foreach (['supplier', 'supplierUser', 'supplierTender', 'supplierContract', 'caseSupplierInvoice', 'supplierMessage', 'supplierKpi'] as $slug) {
			$this->assertArrayHasKey($slug, $schemas, "supplier schema {$slug} must exist");
		}
	}

	public function testSupplierMessageIsWriteOnce(): void {
		$msg = $this->register['components']['schemas']['supplierMessage'] ?? [];
		$this->assertTrue(($msg['x-insert-only'] ?? false), 'supplierMessage must be write-once');
	}

	public function testSupplierUserEnumsMatchDesign(): void {
		$user = $this->register['components']['schemas']['supplierUser']['properties'] ?? [];
		$this->assertSame(['admin', 'finance', 'contracts', 'sales', 'read_only'], $user['role']['enum']);
		$this->assertSame(['invited', 'active', 'revoked'], $user['status']['enum']);
	}

	public function testRegisterListsSupplierSchemas(): void {
		$listed = $this->register['components']['registers']['dossiq']['schemas'] ?? [];
		foreach (['supplier', 'supplierUser', 'supplierTender', 'supplierContract', 'caseSupplierInvoice', 'supplierMessage', 'supplierKpi'] as $slug) {
			$this->assertContains($slug, $listed, "register must list {$slug}");
		}
	}

	public function testFourSupplierCaseTypesSeeded(): void {
		$objects = $this->register['components']['objects'] ?? [];
		$caseTypes = array_filter($objects, fn ($o) => ($o['@self']['schema'] ?? '') === 'caseType');
		$slugs = array_map(fn ($o) => $o['@self']['slug'] ?? '', $caseTypes);
		foreach (['leverancier-contractverlenging-verzoek', 'leverancier-iban-wijziging', 'leverancier-accreditatie-verificatie', 'leverancier-mutatie'] as $expected) {
			$this->assertContains($expected, $slugs, "case type {$expected} must be seeded");
		}
	}

	public function testSeedHasThreeSuppliersAndFiveTenders(): void {
		$objects = $this->register['components']['objects'] ?? [];
		$suppliers = array_filter($objects, fn ($o) => ($o['@self']['schema'] ?? '') === 'supplier');
		$tenders = array_filter($objects, fn ($o) => ($o['@self']['schema'] ?? '') === 'supplierTender');
		$users = array_filter($objects, fn ($o) => ($o['@self']['schema'] ?? '') === 'supplierUser');
		$contracts = array_filter($objects, fn ($o) => ($o['@self']['schema'] ?? '') === 'supplierContract');
		$invoices = array_filter($objects, fn ($o) => ($o['@self']['schema'] ?? '') === 'caseSupplierInvoice');
		$messages = array_filter($objects, fn ($o) => ($o['@self']['schema'] ?? '') === 'supplierMessage');

		$this->assertSame(3, count($suppliers));
		$this->assertSame(5, count($users));
		$this->assertSame(5, count($tenders));
		$this->assertSame(4, count($contracts));
		$this->assertSame(5, count($invoices));
		$this->assertGreaterThanOrEqual(1, count($messages));
	}

	public function testSeedSlugsAreUniqueWithinSchema(): void {
		$objects = $this->register['components']['objects'] ?? [];
		$byKey = [];
		foreach ($objects as $obj) {
			$key = ($obj['@self']['schema'] ?? '') . ':' . ($obj['@self']['slug'] ?? '');
			$this->assertArrayNotHasKey($key, $byKey, "duplicate seed key {$key} — would break idempotency");
			$byKey[$key] = true;
		}
	}
}
