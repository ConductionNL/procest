<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Vth
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Vth;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Vth\LhsMatrixDecisionTableMigrator;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Vth\LhsMatrixDecisionTableMigrator
 */
class LhsMatrixDecisionTableMigratorTest extends TestCase {

	/**
	 * Objects the fake register returns.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $matrices = [];

	/**
	 * Tables the migrator wrote.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * Decision tables the fake register already holds.
	 *
	 * Separate from $matrices because the migrator reads TWO schemas and the
	 * guard it grew depends on telling them apart. A fake that answered both
	 * with the same rows could not express "a table already exists", which is
	 * exactly the state the overwrite guard exists for.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $tables = [];

	/**
	 * Build the migrator over a fake object service.
	 *
	 * @param boolean $withRunAs Whether the store can scope to a user.
	 *
	 * @return LhsMatrixDecisionTableMigrator The migrator.
	 */
	private function migrator(bool $withRunAs = true): LhsMatrixDecisionTableMigrator {
		$matrices = &$this->matrices;
		$written = &$this->written;
		$tables = &$this->tables;

		$objectService = new class($matrices, $written, $tables, $withRunAs) {
			/**
			 * @param array<int, array<string, mixed>> $matrices  Matrices.
			 * @param array<int, array<string, mixed>> $written   Writes.
			 * @param boolean                          $withRunAs Whether runAs exists.
			 */
			public function __construct(
				private array &$matrices,
				private array &$written,
				private array &$tables,
				private bool $withRunAs,
			) {
			}

			/**
			 * @param IUser    $user      The user.
			 * @param callable $operation The operation.
			 *
			 * @return mixed The result.
			 */
			public function runAs(IUser $user, callable $operation): mixed {
				return $operation();
			}

			/**
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				// SCHEMA-AWARE on purpose. The migrator reads matrices from one
				// schema and existing decision tables from another; a fake that
				// returned the same rows for both would hand the overwrite
				// guard a list of matrices and it would never fire.
				if ($schema === 'decisionTable') {
					return $this->tables;
				}

				return $this->matrices;
			}

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 *
			 * @return array<string, mixed> The stored object.
			 */
			public function saveObject(array $object, string $register, string $schema): array {
				$this->written[] = $object;

				return $object;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				$map = ['register' => 'dossiq', 'lhs_matrix_schema' => 'lhsMatrix', 'decision_table_schema' => 'decisionTable'];

				return ($map[$key] ?? $default);
			}
		);

		return new LhsMatrixDecisionTableMigrator($settings, $this->createMock(LoggerInterface::class));

	}//end migrator()

	/**
	 * A small but complete 2 x 2 x 1 matrix.
	 *
	 * @return array<string, mixed> The matrix.
	 */
	private function matrix(): array {
		return [
			'id' => 'm-1',
			'name' => 'LHS 2024',
			'version' => 3,
			'severityAxis' => ['gering', 'ernstig'],
			'behaviourAxis' => ['goedwillend', 'calculerend'],
			'actorTypeAxis' => ['burger'],
			'cells' => [
				['severity' => 'gering', 'behaviour' => 'goedwillend', 'actorType' => 'burger', 'intervention' => 'warning'],
				['severity' => 'gering', 'behaviour' => 'calculerend', 'actorType' => 'burger', 'intervention' => 'fine'],
				['severity' => 'ernstig', 'behaviour' => 'goedwillend', 'actorType' => 'burger', 'intervention' => 'fine'],
				['severity' => 'ernstig', 'behaviour' => 'calculerend', 'actorType' => 'burger', 'intervention' => 'prosecute'],
			],
		];

	}//end matrix()

	/**
	 * The acting user.
	 *
	 * @return IUser The user.
	 */
	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		return $user;

	}//end user()

	/**
	 * Each cell becomes one rule, keyed by its own triple.
	 *
	 * @return void
	 */
	public function testEachCellBecomesARule(): void {
		$this->matrices = [$this->matrix()];

		$this->migrator()->migrate($this->user(), false);

		$this->assertCount(1, $this->written);
		$table = $this->written[0];
		$this->assertCount(4, $table['rules']);
		$this->assertSame(['severity', 'behaviour', 'actorType'], array_column($table['inputs'], 'name'));
		$this->assertSame(['intervention'], array_column($table['outputs'], 'name'));
		$this->assertSame('gering:goedwillend:burger', $table['rules'][0]['id']);
		$this->assertSame(['gering', 'goedwillend', 'burger'], $table['rules'][0]['inputEntries']);
		$this->assertSame(['warning'], $table['rules'][0]['outputEntries']);

	}//end testEachCellBecomesARule()

	/**
	 * The table declares UNIQUE, so an overlap is refused rather than resolved.
	 *
	 * A grid has exactly one cell per triple. The hand-rolled dictionary the
	 * matrix is read with today silently keeps the LAST cell for a repeated
	 * triple; UNIQUE makes that a refusal instead.
	 *
	 * @return void
	 */
	public function testTheTableDeclaresUnique(): void {
		$this->matrices = [$this->matrix()];

		$this->migrator()->migrate($this->user(), false);

		$this->assertSame('UNIQUE', $this->written[0]['hitPolicy']);

	}//end testTheTableDeclaresUnique()

	/**
	 * The projection arrives ENABLED.
	 *
	 * INVERTED, not deleted. This test used to assert `enabled === false`, and
	 * it was correct when written: phase 1 projected the table while the matrix
	 * was still the lookup, and a table that also answered would have been a
	 * second source of truth for an enforcement decision.
	 *
	 * Phase 2 removed that condition. `LhsRecommendationService` now evaluates
	 * the table and reads the matrix only where no projection exists, so a
	 * disabled table is not the cautious choice: it silently means the matrix
	 * answers instead, and the migration becomes a no-op that reports success.
	 *
	 * The test is kept rather than dropped because the flag still matters. It
	 * just matters the other way round now.
	 *
	 * @return void
	 */
	public function testTheProjectionArrivesEnabled(): void {
		$this->matrices = [$this->matrix()];

		$this->migrator()->migrate($this->user(), false);

		$this->assertTrue($this->written[0]['enabled']);

	}//end testTheProjectionArrivesEnabled()

	/**
	 * The projected table, as the fake register would hold it after a run.
	 *
	 * @param array<int, array<string, mixed>>|null $rules Override the rules.
	 *
	 * @return array<string, mixed> The stored table.
	 */
	private function storedTable(?array $rules = null): array {
		$this->matrices = [$this->matrix()];
		$this->migrator()->migrate($this->user(), false);
		$table = $this->written[0];
		$this->written = [];

		$table['id'] = 'table-1';
		if ($rules !== null) {
			$table['rules'] = $rules;
		}

		return $table;

	}//end storedTable()

	/**
	 * A re-run UPDATES the existing table instead of writing a second one.
	 *
	 * The migrator used to saveObject() with no id, so every run minted
	 * another table carrying the same provenance marker. The lookup resolves a
	 * table BY that marker and takes the first match, so which of the
	 * duplicates answered an enforcement question was arbitrary.
	 *
	 * @return void
	 */
	public function testARerunUpdatesTheExistingTable(): void {
		$existing = $this->storedTable();
		$this->tables = [$existing];
		$this->matrices = [$this->matrix()];

		$summary = $this->migrator()->migrate($this->user(), false);

		$this->assertCount(1, $this->written, 'a re-run must not mint a second table');
		$this->assertSame('table-1', $this->written[0]['id'], 'the write must target the existing table');
		$this->assertSame(1, $summary['updated']);
		$this->assertSame(0, $summary['created']);

	}//end testARerunUpdatesTheExistingTable()

	/**
	 * 🔴 A table whose rules were edited is NOT overwritten.
	 *
	 * The projection is one-way, and the matrix no longer has a settings page.
	 * A re-run that rewrote edited rules would replace an administrator's work
	 * with a source they cannot even read.
	 *
	 * @return void
	 */
	public function testAnEditedTableIsRefusedRatherThanOverwritten(): void {
		$edited = $this->storedTable(
			rules: [
				[
					'id' => 'gering:goedwillend:burger',
					'inputEntries' => ['gering', 'goedwillend', 'burger'],
					'outputEntries' => ['prosecute'],
				],
			]
		);
		$this->tables = [$edited];
		$this->matrices = [$this->matrix()];

		$summary = $this->migrator()->migrate($this->user(), false);

		$this->assertSame([], $this->written, 'an edited table must not be written over');
		$this->assertSame(1, $summary['skipped']);
		$this->assertStringContainsString('edited', $summary['rows'][0]['detail']);

	}//end testAnEditedTableIsRefusedRatherThanOverwritten()

	/**
	 * 🔴 A cell naming a value absent from its axis refuses the whole matrix.
	 *
	 * This is dossiq#1596 in miniature: the shipped matrix labelled its twelve
	 * government cells `government` while the axis said `overheid`, so a
	 * quarter of the strategy was unreachable and nothing noticed. Projecting
	 * such a matrix would carry the defect across while looking like a
	 * migration that worked.
	 *
	 * @return void
	 */
	public function testACellOffItsAxisRefusesTheMatrix(): void {
		$matrix = $this->matrix();
		$matrix['cells'][0]['actorType'] = 'government';
		$this->matrices = [$matrix];

		$summary = $this->migrator()->migrate($this->user(), false);

		$this->assertSame([], $this->written);
		$this->assertSame(1, $summary['skipped']);
		$this->assertStringContainsString('not on its axis', $summary['rows'][0]['detail']);

	}//end testACellOffItsAxisRefusesTheMatrix()

	/**
	 * A dry run writes nothing and still reports the rule count.
	 *
	 * @return void
	 */
	public function testADryRunWritesNothing(): void {
		$this->matrices = [$this->matrix()];

		$summary = $this->migrator()->migrate($this->user(), true);

		$this->assertSame([], $this->written);
		$this->assertSame(1, $summary['created']);
		$this->assertStringContainsString('4 rule(s)', $summary['rows'][0]['detail']);

	}//end testADryRunWritesNothing()

	/**
	 * The axes and cells are accepted in their JSON-string form too.
	 *
	 * OpenRegister stores these as strings holding JSON on some rows and as
	 * native arrays on others, and a reader that handled one shape would
	 * report every matrix unprojectable against the other.
	 *
	 * @return void
	 */
	public function testTheJsonStringFormIsAccepted(): void {
		$matrix = $this->matrix();
		foreach (['severityAxis', 'behaviourAxis', 'actorTypeAxis', 'cells'] as $key) {
			$matrix[$key] = json_encode($matrix[$key]);
		}

		$this->matrices = [$matrix];

		$this->migrator()->migrate($this->user(), false);

		$this->assertCount(4, $this->written[0]['rules']);

	}//end testTheJsonStringFormIsAccepted()

}//end class
