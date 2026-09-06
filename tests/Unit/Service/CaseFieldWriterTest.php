<?php

/**
 * CaseFieldWriter unit tests: both seams, and every refusal.
 *
 * The writer is the one place that turns "save the snapshot" into "apply my
 * fields to the stored case". It prefers OpenRegister's PATCH seam
 * (`patchObject`), falls back to read-then-save on an older service, and
 * refuses loudly whenever it cannot address the stored case — because the
 * quiet alternatives are exactly the defect class it exists to close.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseFieldWriter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CaseFieldWriterTest extends TestCase {

	/**
	 * The writer under test; stateless, so one is enough.
	 *
	 * @var CaseFieldWriter
	 */
	private CaseFieldWriter $writer;

	protected function setUp(): void {
		$this->writer = new CaseFieldWriter();
	}//end setUp()

	/**
	 * The PATCH seam is preferred when the service has one.
	 */
	public function testWritesThroughPatchObjectWhenAvailable(): void {
		$objectService = new class {
			/** @var array<string, mixed>|null */
			public ?array $patched = null;

			/** @var string */
			public string $patchedId = '';

			public function patchObject(string $objectId, array $data, ?string $register = null, ?string $schema = null): array {
				$this->patchedId = $objectId;
				$this->patched = $data;

				return $data;
			}

			public function saveObject(array $object, string $register, string $schema): array {
				throw new RuntimeException('saveObject must not be used while patchObject exists');
			}
		};

		$this->writer->write(
			objectService: $objectService,
			register: 'dossiq',
			schema: 'case',
			case: ['id' => 'case-1', 'title' => 'stale snapshot title'],
			changes: ['status' => 'status-uuid-9']
		);

		self::assertSame('case-1', $objectService->patchedId);
		self::assertSame(
			['status' => 'status-uuid-9'],
			$objectService->patched,
			'ONLY the handler\'s own fields may travel: a snapshot field in the payload is the clobber.'
		);
	}//end testWritesThroughPatchObjectWhenAvailable()

	/**
	 * Without a PATCH seam the writer re-reads the stored case and applies
	 * only its own fields, so the fresh read is the base, never the snapshot.
	 */
	public function testFallsBackToReadThenSaveOnAnOlderObjectService(): void {
		$objectService = new class {
			/** @var array<string, mixed> */
			public array $stored = [
				'id' => 'case-1',
				'title' => 'Dakkapel Kerkstraat 14',
				'besluitDocument' => 'Besluit op de aanvraag',
			];

			public function find(int|string $id, ?array $_extend = [], bool $files = false, ?string $register = null, ?string $schema = null): array {
				return $this->stored;
			}

			public function saveObject(array $object, string $register, string $schema): array {
				$this->stored = $object;

				return $object;
			}
		};

		// The snapshot predates besluitDocument; the stored case must keep it.
		$this->writer->write(
			objectService: $objectService,
			register: 'dossiq',
			schema: 'case',
			case: ['id' => 'case-1', 'title' => 'Dakkapel Kerkstraat 14'],
			changes: ['status' => 'status-uuid-9']
		);

		self::assertSame('status-uuid-9', $objectService->stored['status']);
		self::assertSame(
			'Besluit op de aanvraag',
			$objectService->stored['besluitDocument'],
			'The fallback must base its save on the FRESH read, not the snapshot.'
		);
	}//end testFallsBackToReadThenSaveOnAnOlderObjectService()

	/**
	 * Nothing to write is a no-op, not a save.
	 */
	public function testEmptyChangesTouchNothing(): void {
		$objectService = new class {
			public function patchObject(string $objectId, array $data, ?string $register = null, ?string $schema = null): array {
				throw new RuntimeException('no write may happen for empty changes');
			}
		};

		$this->writer->write(
			objectService: $objectService,
			register: 'dossiq',
			schema: 'case',
			case: ['id' => 'case-1'],
			changes: []
		);

		// Reaching this line IS the assertion: the double throws on any write.
		$this->addToAssertionCount(1);
	}//end testEmptyChangesTouchNothing()

	/**
	 * A snapshot with no identity is refused: saving it would CREATE a
	 * duplicate case, which is the quiet failure this writer exists to remove.
	 */
	public function testASnapshotWithoutAnIdIsRefused(): void {
		$objectService = new class {
			public function patchObject(string $objectId, array $data, ?string $register = null, ?string $schema = null): array {
				return $data;
			}
		};

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('case_snapshot_has_no_id');

		$this->writer->write(
			objectService: $objectService,
			register: 'dossiq',
			schema: 'case',
			case: ['title' => 'no id here'],
			changes: ['status' => 'x']
		);
	}//end testASnapshotWithoutAnIdIsRefused()

	/**
	 * The id may also live under `@self`, where OpenRegister serialises it.
	 */
	public function testTheIdIsFoundUnderSelf(): void {
		$objectService = new class {
			/** @var string */
			public string $patchedId = '';

			public function patchObject(string $objectId, array $data, ?string $register = null, ?string $schema = null): array {
				$this->patchedId = $objectId;

				return $data;
			}
		};

		$this->writer->write(
			objectService: $objectService,
			register: 'dossiq',
			schema: 'case',
			case: ['@self' => ['id' => 'case-uuid-7'], 'title' => 'x'],
			changes: ['status' => 'y']
		);

		self::assertSame('case-uuid-7', $objectService->patchedId);
	}//end testTheIdIsFoundUnderSelf()

	/**
	 * A service with neither seam cannot be written without clobbering, so the
	 * writer refuses rather than full-saving the stale snapshot.
	 */
	public function testAServiceWithNoUsableSeamIsRefused(): void {
		$objectService = new class {
			public function saveObject(array $object, string $register, string $schema): array {
				return $object;
			}
		};

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('object_service_cannot_write_partially');

		$this->writer->write(
			objectService: $objectService,
			register: 'dossiq',
			schema: 'case',
			case: ['id' => 'case-1'],
			changes: ['status' => 'x']
		);
	}//end testAServiceWithNoUsableSeamIsRefused()

	/**
	 * A stored case the fallback cannot re-read is refused: writing blind
	 * would save the handler's fields onto nothing.
	 */
	public function testAnUnreadableStoredCaseIsRefusedOnTheFallback(): void {
		$objectService = new class {
			public function find(int|string $id, ?array $_extend = [], bool $files = false, ?string $register = null, ?string $schema = null): ?array {
				return null;
			}

			public function saveObject(array $object, string $register, string $schema): array {
				throw new RuntimeException('nothing may be saved when the read found nothing');
			}
		};

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('case_not_found_for_partial_write');

		$this->writer->write(
			objectService: $objectService,
			register: 'dossiq',
			schema: 'case',
			case: ['id' => 'case-1'],
			changes: ['status' => 'x']
		);
	}//end testAnUnreadableStoredCaseIsRefusedOnTheFallback()
}//end class
