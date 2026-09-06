<?php

/**
 * Unit tests for RenameCollidingSchemaSlugs.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://dossiq.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\RenameCollidingSchemaSlugs;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Guards the cross-app slug namespacing pass.
 *
 * Two things can go wrong quietly here: renaming nothing, so the import forks the
 * schema and orphans its objects; and renaming shillinq's row instead of this app's.
 * The second is why the application filter exists, so it is asserted on the SQL
 * rather than assumed.
 */
final class RenameCollidingSchemaSlugsTest extends TestCase {

	/**
	 * Mocked database connection.
	 *
	 * @var IDBConnection
	 */
	private $db;

	/**
	 * The step under test.
	 *
	 * @var RenameCollidingSchemaSlugs
	 */
	private RenameCollidingSchemaSlugs $step;

	/**
	 * Queries the step issued, as [sql, params].
	 *
	 * @var array<int, array{0:string,1:array}>
	 */
	private array $queries = [];

	/**
	 * Build the step with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->queries = [];
		$this->step = new RenameCollidingSchemaSlugs($this->db, $this->createMock(LoggerInterface::class));

	}//end setUp()

	/**
	 * Answer each slug lookup from a map and record the SQL.
	 *
	 * @param array<string, array<int, mixed>> $bySlug Slug => ids present.
	 *
	 * @return void
	 */
	private function lookups(array $bySlug): void {
		$this->db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params = []) use ($bySlug): IResult {
				$this->queries[] = [$sql, $params];
				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn(($bySlug[(string)($params[0] ?? '')] ?? []));
				return $result;
			}
		);

	}//end lookups()

	/**
	 * The slug is renamed in place, keeping the schema id.
	 *
	 * Keeping the id is the whole point: the shard table is named after it, so a
	 * new schema would leave every payroll record behind a slug nothing reads.
	 *
	 * @return void
	 */
	public function testRenamesTheSlugInPlace(): void {
		$this->lookups(['supplierInvoice' => [844]]);

		$statements = [];
		$this->db->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params = []) use (&$statements): int {
				$statements[] = [$sql, $params];
				return 1;
			}
		);

		$this->step->run($this->createMock(IOutput::class));

		self::assertCount(1, $statements, 'exactly one row may be rewritten');
		self::assertStringContainsString('openregister_schemas', $statements[0][0]);
		self::assertStringContainsString('SET slug', $statements[0][0]);
		self::assertSame(['caseSupplierInvoice', 844], $statements[0][1]);

	}//end testRenamesTheSlugInPlace()

	/**
	 * The lookup is scoped to this app's rows.
	 *
	 * Without the application filter the step would find shillinq's `supplierInvoice` and
	 * rename it, which is the exact damage it exists to prevent.
	 *
	 * @return void
	 */
	public function testTheLookupIsScopedToThisApp(): void {
		$this->lookups(['supplierInvoice' => [844]]);
		$this->db->method('executeStatement')->willReturn(1);

		$this->step->run($this->createMock(IOutput::class));

		self::assertNotEmpty($this->queries, 'the step must read before it writes');
		[$sql, $params] = $this->queries[0];
		self::assertStringContainsString('application = ?', $sql);
		self::assertContains('dossiq', $params);

	}//end testTheLookupIsScopedToThisApp()

	/**
	 * An install already namespaced is left alone.
	 *
	 * @return void
	 */
	public function testIsANoOpWhenTheOldSlugIsAbsent(): void {
		$this->lookups(['caseSupplierInvoice' => [844]]);
		$this->db->expects(self::never())->method('executeStatement');

		$this->step->run($this->createMock(IOutput::class));

	}//end testIsANoOpWhenTheOldSlugIsAbsent()

	/**
	 * Both slugs present is a refusal, not a merge.
	 *
	 * @return void
	 */
	public function testRefusesWhenBothSlugsExist(): void {
		$this->lookups(['supplierInvoice' => [844], 'caseSupplierInvoice' => [901]]);
		$this->db->expects(self::never())->method('executeStatement');

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning');

		$this->step->run($output);

	}//end testRefusesWhenBothSlugsExist()

	/**
	 * Duplicate old slugs are a refusal too. The step must not guess which row
	 * owns the payroll records.
	 *
	 * @return void
	 */
	public function testRefusesOnDuplicateOldSlugs(): void {
		$this->lookups(['supplierInvoice' => [844, 845]]);
		$this->db->expects(self::never())->method('executeStatement');

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning');

		$this->step->run($output);

	}//end testRefusesOnDuplicateOldSlugs()

	/**
	 * The register declares the namespaced slug and no longer the colliding one.
	 *
	 * A rename that reaches the repair step but not the register descriptor is a
	 * silent no-op: the step renames the row, the import then finds no schema
	 * under that slug and creates a second one.
	 *
	 * @return void
	 */
	public function testTheRegisterDeclaresTheNamespacedSlug(): void {
		$register = __DIR__ . '/../../../lib/Settings/dossiq_register.json';
		self::assertFileExists($register);

		$declared = json_decode((string)file_get_contents($register), true);
		$schemas = ($declared['components']['schemas'] ?? []);

		self::assertArrayHasKey('caseSupplierInvoice', $schemas, 'the register must declare the namespaced slug');
		self::assertArrayNotHasKey('supplierInvoice', $schemas, 'the colliding slug must be gone');
		self::assertSame(
			'caseSupplierInvoice',
			$schemas['caseSupplierInvoice']['slug'],
			'the descriptor must carry the slug, not only the key'
		);

	}//end testTheRegisterDeclaresTheNamespacedSlug()

}//end class
