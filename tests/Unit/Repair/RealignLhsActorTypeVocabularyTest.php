<?php

/**
 * RealignLhsActorTypeVocabulary Tests
 *
 * The step rewrites stored `lhsRecommendation.actorType` values of
 * `government` back to `overheid` (dossiq#1596).
 *
 * The risk it carries is not failing to fix a row. It is fixing the WRONG row:
 * the filter is sent to the backend, and a backend that cannot express it would
 * return every recommendation, at which point a naive rewrite would relabel a
 * `burger` as `overheid` — a far worse defect than the one being repaired. So
 * the test that matters most here is the one where the filter is ignored.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
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
 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\RealignLhsActorTypeVocabulary;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Repairing the split LHS actorType vocabulary.
 *
 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
 */
class RealignLhsActorTypeVocabularyTest extends TestCase {
	/**
	 * Rows the fake register holds.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * Rows the step wrote back.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * Whether the fake register honours the actorType filter.
	 *
	 * @var bool
	 */
	private bool $honoursFilter = true;

	/**
	 * Build the step over a fake register.
	 *
	 * @return RealignLhsActorTypeVocabulary The step.
	 */
	private function step(): RealignLhsActorTypeVocabulary {
		$rows = &$this->rows;
		$written = &$this->written;
		$honours = $this->honoursFilter;

		$objectService = new class($rows, $written, $honours) {
			/**
			 * @param array<int, array<string, mixed>> $rows    Stored rows.
			 * @param array<int, array<string, mixed>> $written Writes.
			 * @param bool                             $honours Whether filters apply.
			 */
			public function __construct(
				private array &$rows,
				private array &$written,
				private bool $honours,
			) {
			}

			/**
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				if ($this->honours === false || isset($filters['actorType']) === false) {
					return $this->rows;
				}

				return array_values(
					array_filter(
						$this->rows,
						static fn (array $r): bool => ($r['actorType'] ?? null) === $filters['actorType']
					)
				);
			}

			/**
			 * Real ObjectService::saveObject() signature: `$object` FIRST,
			 * then `$extend`, `$register`, `$schema`, `$uuid`. A caller
			 * still using the retired positional order fatals here as it
			 * does against the live service.
			 *
			 * @param array<string, mixed> $object   The object.
			 * @param array|null           $extend   Relations to expand (ignored).
			 * @param string|int|null      $register The register.
			 * @param string|int|null      $schema   The schema.
			 * @param string|null          $uuid     The uuid to update.
			 *
			 * @return array<string, mixed> The stored row.
			 */
			public function saveObject(
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			): array {
				$this->written[] = $object;

				return $object;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturn('configured');

		return new RealignLhsActorTypeVocabulary(
			$settings,
			$this->createMock(LoggerInterface::class)
		);
	}//end step()

	/**
	 * A mis-translated row is rewritten.
	 *
	 * @return void
	 */
	public function testAMistranslatedRowIsRepaired(): void {
		$this->rows = [['id' => 'r-1', 'actorType' => 'government']];

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertCount(1, $this->written);
		$this->assertSame('overheid', $this->written[0]['actorType']);
		$this->assertSame('r-1', $this->written[0]['id'], 'the repair must target the same row');
	}//end testAMistranslatedRowIsRepaired()

	/**
	 * A correct row is left alone, so a re-run is a no-op.
	 *
	 * @return void
	 */
	public function testACorrectRowIsUntouched(): void {
		$this->rows = [['id' => 'r-1', 'actorType' => 'overheid']];

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertSame([], $this->written);
	}//end testACorrectRowIsUntouched()

	/**
	 * 🔴 A backend that ignores the filter must not cause a mass relabel.
	 *
	 * The filter is sent server-side. If it cannot be expressed the search
	 * returns everything, and rewriting all of it would turn every citizen and
	 * business into a government actor — worse than the defect being repaired.
	 * The step re-checks each row before writing for exactly this reason.
	 *
	 * @return void
	 */
	public function testAnIgnoredFilterDoesNotRelabelEveryone(): void {
		$this->honoursFilter = false;
		$this->rows = [
			['id' => 'r-1', 'actorType' => 'government'],
			['id' => 'r-2', 'actorType' => 'burger'],
			['id' => 'r-3', 'actorType' => 'bedrijf'],
		];

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertCount(1, $this->written, 'only the mis-translated row may be written');
		$this->assertSame('r-1', $this->written[0]['id']);
	}//end testAnIgnoredFilterDoesNotRelabelEveryone()

	/**
	 * With OpenRegister absent the step reports and writes nothing.
	 *
	 * @return void
	 */
	public function testAnAbsentOpenRegisterIsReportedNotFatal(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);

		$step = new RealignLhsActorTypeVocabulary(
			$settings,
			$this->createMock(LoggerInterface::class)
		);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info');

		$step->run($output);

		$this->assertSame([], $this->written);
	}//end testAnAbsentOpenRegisterIsReportedNotFatal()

	/**
	 * The step names itself, which is what an administrator reads in the
	 * upgrade output.
	 *
	 * @return void
	 */
	public function testTheStepNamesItself(): void {
		$this->assertStringContainsString('actorType', $this->step()->getName());
	}//end testTheStepNamesItself()
}//end class
