<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for SelectionReassignmentService — reassigning the cases a user ticked
 * on the Cases page, as opposed to emptying one handler's whole queue.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\SelectionReassignmentService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\SelectionReassignmentService
 *
 * @uses \OCA\Dossiq\Service\Support\ReassignmentBatch
 */
class SelectionReassignmentServiceTest extends TestCase {

	/**
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $settingsService;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * A slug-aware ObjectService double.
	 *
	 * @return \PHPUnit\Framework\MockObject\MockObject The double.
	 */
	private function objectServiceMock() {
		return $this->createMock(\OCA\Dossiq\Tests\Unit\Service\SubstitutionObjectServiceStub::class);

	}//end objectServiceMock()

	/**
	 * A selection moves to the receiving handler.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reassignment-is-a-bulk-action/specs/reassignment-bulk-action/spec.md
	 */
	public function testASelectionIsReassigned(): void {
		$written = [];
		$os = $this->objectServiceMock();
		$os->method('find')->willReturnCallback(
			static fn (int|string $id, ...$args): array => ['id' => (string)$id, 'assignee' => 'jan', 'title' => 'Case ' . $id]
		);
		$os->method('updateObject')->willReturnCallback(
			static function (string $r, string $s, string $id, array $item) use (&$written): array {
				$written[$id] = $item;

				return $item;
			}
		);

		$result = $this->selectionService($os)->executeForCases(['c1', 'c2'], 'piet', 'admin');

		$this->assertSame(2, $result['requested']);
		$this->assertSame(2, $result['succeeded']);
		$this->assertSame('piet', $written['c1']['assignee']);
		$this->assertSame('piet', $written['c2']['assignee']);

	}//end testASelectionIsReassigned()

	/**
	 * The audit records where each case actually came FROM.
	 *
	 * The selection is hand-picked, so its rows may have different assignees.
	 * A batch-level `from` would name one person for all of them, which is a
	 * false audit trail on every row that came from somebody else.
	 *
	 * @return void
	 */
	public function testTheAuditRecordsEachCasesOwnPreviousAssignee(): void {
		$written = [];
		$os = $this->objectServiceMock();
		$os->method('find')->willReturnCallback(
			static fn (int|string $id, ...$args): array => [
				'id' => (string)$id,
				'assignee' => ($id === 'c1' ? 'jan' : 'klaas'),
				'activity' => '[]',
			]
		);
		$os->method('updateObject')->willReturnCallback(
			static function (string $r, string $s, string $id, array $item) use (&$written): array {
				$written[$id] = $item;

				return $item;
			}
		);

		$this->selectionService($os)->executeForCases(['c1', 'c2'], 'piet', 'admin');

		$from = [];
		foreach (['c1', 'c2'] as $id) {
			$entries = json_decode($written[$id]['activity'], true);
			$last = end($entries);
			$from[$id] = $last['reassignedFrom'];
		}

		$this->assertSame(['c1' => 'jan', 'c2' => 'klaas'], $from);

	}//end testTheAuditRecordsEachCasesOwnPreviousAssignee()

	/**
	 * The rows of one selection share a batch id.
	 *
	 * @return void
	 */
	public function testOneSelectionIsOneBatch(): void {
		$written = [];
		$os = $this->objectServiceMock();
		$os->method('find')->willReturnCallback(
			static fn (int|string $id, ...$args): array => ['id' => (string)$id, 'assignee' => 'jan', 'activity' => '[]']
		);
		$os->method('updateObject')->willReturnCallback(
			static function (string $r, string $s, string $id, array $item) use (&$written): array {
				$written[$id] = $item;

				return $item;
			}
		);

		$result = $this->selectionService($os)->executeForCases(['c1', 'c2'], 'piet', 'admin');

		$batches = [];
		foreach (['c1', 'c2'] as $id) {
			$entries = json_decode($written[$id]['activity'], true);
			$last = end($entries);
			$batches[] = $last['batchId'];
		}

		$this->assertSame($result['batchId'], $batches[0]);
		$this->assertSame($batches[0], $batches[1]);

	}//end testOneSelectionIsOneBatch()

	/**
	 * A case already assigned to the receiver is left alone.
	 *
	 * Rewriting it would add an audit entry saying it moved from somebody to
	 * themselves.
	 *
	 * @return void
	 */
	public function testACaseAlreadyAssignedToTheReceiverIsNotRewritten(): void {
		$os = $this->objectServiceMock();
		$os->method('find')->willReturnCallback(
			static fn (int|string $id, ...$args): array => ['id' => (string)$id, 'assignee' => 'piet']
		);
		$os->expects($this->never())->method('updateObject');

		$result = $this->selectionService($os)->executeForCases(['c1'], 'piet', 'admin');

		$this->assertSame(1, $result['succeeded']);
		$this->assertSame('already assigned', $result['results'][0]['reason']);

	}//end testACaseAlreadyAssignedToTheReceiverIsNotRewritten()

	/**
	 * A case that cannot be read is reported, not silently skipped.
	 *
	 * @return void
	 */
	public function testAMissingCaseIsReported(): void {
		$os = $this->objectServiceMock();
		$os->method('find')->willReturnCallback(
			static function (int|string $id, ...$args): mixed {
				throw new \RuntimeException('gone');
			}
		);

		$result = $this->selectionService($os)->executeForCases(['c1'], 'piet', 'admin');

		$this->assertSame(0, $result['succeeded']);
		$this->assertFalse($result['results'][0]['success']);
		$this->assertSame('not found', $result['results'][0]['reason']);

	}//end testAMissingCaseIsReported()

	/**
	 * An empty selection or a missing receiver is refused.
	 *
	 * @return void
	 */
	public function testItRefusesAnEmptySelectionOrNoReceiver(): void {
		$service = $this->selectionService($this->objectServiceMock());

		$this->expectException(\InvalidArgumentException::class);
		$service->executeForCases([], 'piet', 'admin');

	}//end testItRefusesAnEmptySelectionOrNoReceiver()

	/**
	 * The sibling service that reassigns a hand-picked selection.
	 *
	 * @param object|null $objectService The ObjectService mock or null.
	 *
	 * @return SelectionReassignmentService The service.
	 */
	private function selectionService(?object $objectService): SelectionReassignmentService {
		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				$map = ['register' => 'dossiq', 'case_schema' => 'case', 'task_schema' => 'caseTask'];

				return ($map[$key] ?? $default);
			}
		);

		return new SelectionReassignmentService($this->settingsService, $this->logger);

	}//end selectionService()
	/**
	 * A write failure is reported per case, not swallowed and not fatal.
	 *
	 * One case that cannot be written must not abort the rest of the
	 * selection: the user ticked them all, and a silent stop leaves them
	 * believing every one moved.
	 *
	 * @return void
	 */
	public function testAWriteFailureIsReportedAndDoesNotAbortTheRest(): void {
		$os = $this->objectServiceMock();
		$os->method('find')->willReturnCallback(
			static fn (int|string $id, ...$args): array => ['id' => (string)$id, 'assignee' => 'jan']
		);
		$os->method('updateObject')->willReturnCallback(
			static function (string $r, string $s, string $id, array $item): array {
				if ($id === 'c1') {
					throw new \RuntimeException('write refused');
				}

				return $item;
			}
		);

		$result = $this->selectionService($os)->executeForCases(['c1', 'c2'], 'piet', 'admin');

		$this->assertSame(2, $result['requested']);
		$this->assertSame(1, $result['succeeded']);
		$this->assertFalse($result['results'][0]['success']);
		$this->assertTrue($result['results'][1]['success']);

	}//end testAWriteFailureIsReportedAndDoesNotAbortTheRest()

	/**
	 * Without a receiving handler the call is refused.
	 *
	 * @return void
	 */
	public function testItRefusesWithoutAReceiver(): void {
		$service = $this->selectionService($this->objectServiceMock());

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('toUser is required');

		$service->executeForCases(['c1'], '  ', 'admin');

	}//end testItRefusesWithoutAReceiver()

	/**
	 * Without OpenRegister the call refuses rather than reporting a clean run.
	 *
	 * @return void
	 */
	public function testItRefusesWhenOpenRegisterIsUnavailable(): void {
		$service = $this->selectionService(null);

		$this->expectException(\RuntimeException::class);

		$service->executeForCases(['c1'], 'piet', 'admin');

	}//end testItRefusesWhenOpenRegisterIsUnavailable()

	/**
	 * A case the store returns as an entity, not an array, is still read.
	 *
	 * OpenRegister's find() returns an ObjectEntity in production and an array
	 * in some code paths; a reader that handled only one shape would report
	 * every case "not found" against the other.
	 *
	 * @return void
	 */
	public function testACaseReturnedAsAnEntityIsRead(): void {
		$written = [];
		$os = $this->objectServiceMock();
		$os->method('find')->willReturnCallback(
			static fn (int|string $id, ...$args): object => new class((string)$id) {
				/**
				 * @param string $id The id.
				 */
				public function __construct(private string $id) {
				}

				/**
				 * @return array<string, mixed> The object.
				 */
				public function getObject(): array {
					return ['id' => $this->id, 'assignee' => 'jan'];
				}
			}
		);
		$os->method('updateObject')->willReturnCallback(
			static function (string $r, string $s, string $id, array $item) use (&$written): array {
				$written[$id] = $item;

				return $item;
			}
		);

		$result = $this->selectionService($os)->executeForCases(['c1'], 'piet', 'admin');

		$this->assertSame(1, $result['succeeded']);
		$this->assertSame('piet', $written['c1']['assignee']);

	}//end testACaseReturnedAsAnEntityIsRead()

}//end class
