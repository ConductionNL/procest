<?php

/**
 * CaseReassignmentService Unit Tests.
 *
 * Covers non-mutating preview (open only, closed/archived excluded), filtered
 * preview, full execute (per-item audit + single digest), and partial-failure
 * reporting.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Dossiq\Service\CaseReassignmentService;
use OCA\Dossiq\Service\SettingsService;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;


/**
 * Unit tests for CaseReassignmentService.
 *
 * @covers \OCA\Dossiq\Service\CaseReassignmentService
 *
 * @uses \OCA\Dossiq\Service\Support\ReassignmentBatch
 */
class CaseReassignmentServiceTest extends TestCase {

	/**
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $settingsService;

	/**
	 * @var IManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $notificationManager;

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
		$this->notificationManager = $this->createMock(IManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Configure SettingsService to expose the given ObjectService via the slug path.
	 *
	 * @param object|null $objectService The ObjectService mock or null.
	 *
	 * @return CaseReassignmentService
	 */
	private function makeService(?object $objectService): CaseReassignmentService {
		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				$map = [
					'register' => 'dossiq',
					'case_schema' => 'case',
					'task_schema' => 'caseTask',
					'status_type_schema' => 'statusType',
				];
				return ($map[$key] ?? $default);
			}
		);

		return new CaseReassignmentService($this->settingsService, $this->notificationManager, $this->logger);
	}//end makeService()


	/**
	 * Build a slug-aware ObjectService mock.
	 *
	 * @return \PHPUnit\Framework\MockObject\MockObject
	 */
	private function objectServiceMock() {
		return $this->createMock(SubstitutionObjectServiceStub::class);
	}//end objectServiceMock()

	/**
	 * Preview returns only open cases/tasks and never mutates.
	 *
	 * @return void
	 */
	public function testPreviewOpenOnlyNonMutating(): void {
		$os = $this->objectServiceMock();
		$os->expects($this->never())->method('updateObject');
		$os->method('searchObjectsBySlug')->willReturnCallback(
			function (string $reg, string $schema, array $filters) {
				if ($schema === 'statusType') {
					return [['id' => 'st-final', 'isFinal' => true]];
				}
				if ($schema === 'case') {
					return [
						['id' => 'c1', 'title' => 'Open 1', 'assignee' => 'jan', 'status' => 'st-open', 'caseType' => 'vth'],
						['id' => 'c2', 'title' => 'Closed', 'assignee' => 'jan', 'status' => 'st-final', 'caseType' => 'vth'],
					];
				}
				if ($schema === 'caseTask') {
					return [
						['id' => 't1', 'title' => 'Open task', 'assignee' => 'jan', 'status' => 'active', 'case' => 'c1'],
						['id' => 't2', 'title' => 'Done task', 'assignee' => 'jan', 'status' => 'completed', 'case' => 'c1'],
					];
				}
				return [];
			}
		);

		$preview = $this->makeService($os)->preview('jan');
		$this->assertCount(1, $preview['cases']);
		$this->assertSame('c1', $preview['cases'][0]['id']);
		$this->assertCount(1, $preview['tasks']);
		$this->assertSame('t1', $preview['tasks'][0]['id']);
	}//end testPreviewOpenOnlyNonMutating()

	/**
	 * Filtered preview limits to the requested case type (and its tasks).
	 *
	 * @return void
	 */
	public function testFilteredPreview(): void {
		$os = $this->objectServiceMock();
		$os->method('searchObjectsBySlug')->willReturnCallback(
			function (string $reg, string $schema, array $filters) {
				if ($schema === 'statusType') {
					return [];
				}
				if ($schema === 'case') {
					return [
						['id' => 'c1', 'assignee' => 'jan', 'status' => 'open', 'caseType' => 'vth'],
						['id' => 'c2', 'assignee' => 'jan', 'status' => 'open', 'caseType' => 'objectionProceeding'],
					];
				}
				if ($schema === 'caseTask') {
					return [
						['id' => 't1', 'assignee' => 'jan', 'status' => 'active', 'case' => 'c1'],
						['id' => 't2', 'assignee' => 'jan', 'status' => 'active', 'case' => 'c2'],
					];
				}
				return [];
			}
		);

		$preview = $this->makeService($os)->preview('jan', ['caseType' => 'vth']);
		$this->assertCount(1, $preview['cases']);
		$this->assertSame('c1', $preview['cases'][0]['id']);
		// Only the task belonging to the in-scope case is included.
		$this->assertCount(1, $preview['tasks']);
		$this->assertSame('t1', $preview['tasks'][0]['id']);
	}//end testFilteredPreview()

	/**
	 * Self-reassignment is rejected.
	 *
	 * @return void
	 */
	public function testSelfReassignmentRejected(): void {
		$service = $this->makeService($this->objectServiceMock());
		$this->expectException(InvalidArgumentException::class);
		$service->execute('jan', 'jan', null, 'coord');
	}//end testSelfReassignmentRejected()

	/**
	 * Execute transfers all open items, stamps audit entries with a shared
	 * batch id, and sends exactly one digest notification.
	 *
	 * @return void
	 */
	public function testExecuteFullReassignment(): void {
		$os = $this->objectServiceMock();
		$os->method('searchObjectsBySlug')->willReturnCallback(
			function (string $reg, string $schema, array $filters) {
				if ($schema === 'statusType') {
					return [];
				}
				if ($schema === 'case') {
					return [['id' => 'c1', 'title' => 'Case 1', 'assignee' => 'jan', 'status' => 'open', 'caseType' => 'vth', 'activity' => '[]']];
				}
				if ($schema === 'caseTask') {
					return [['id' => 't1', 'title' => 'Task 1', 'assignee' => 'jan', 'status' => 'active', 'case' => 'c1']];
				}
				return [];
			}
		);

		$updated = [];
		$os->method('updateObject')->willReturnCallback(
			function (string $r, string $s, string $id, array $payload) use (&$updated) {
				$updated[$id] = $payload;
				return $payload;
			}
		);

		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();
		$this->notificationManager->method('createNotification')->willReturn($notification);
		$this->notificationManager->expects($this->once())->method('notify');

		$result = $this->makeService($os)->execute('jan', 'pieter', null, 'coord');

		$this->assertSame(2, $result['succeeded']);
		$this->assertSame(0, $result['failed']);
		$this->assertStringStartsWith('batch-', $result['batchId']);
		// Case reassigned to pieter.
		$this->assertSame('pieter', $updated['c1']['assignee']);
		$this->assertSame('pieter', $updated['t1']['assignee']);
		// Audit entry on the case carries the batch id.
		$activity = json_decode($updated['c1']['activity'], true);
		$this->assertSame($result['batchId'], $activity[0]['batchId']);
		$this->assertSame('jan', $activity[0]['reassignedFrom']);
		$this->assertSame('coord', $activity[0]['reassignedBy']);
	}//end testExecuteFullReassignment()

	/**
	 * A failing item is reported as failed and stays on the original handler.
	 *
	 * @return void
	 */
	public function testPartialFailureReported(): void {
		$os = $this->objectServiceMock();
		$os->method('searchObjectsBySlug')->willReturnCallback(
			function (string $reg, string $schema, array $filters) {
				if ($schema === 'statusType') {
					return [];
				}
				if ($schema === 'case') {
					return [
						['id' => 'c1', 'assignee' => 'jan', 'status' => 'open', 'caseType' => 'vth', 'activity' => '[]'],
						['id' => 'c2', 'assignee' => 'jan', 'status' => 'open', 'caseType' => 'vth', 'activity' => '[]'],
					];
				}
				if ($schema === 'caseTask') {
					return [];
				}
				return [];
			}
		);
		$os->method('updateObject')->willReturnCallback(
			function (string $r, string $s, string $id, array $payload) {
				if ($id === 'c2') {
					throw new \RuntimeException('concurrent modification');
				}
				return $payload;
			}
		);

		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();
		$this->notificationManager->method('createNotification')->willReturn($notification);

		$result = $this->makeService($os)->execute('jan', 'pieter', null, 'coord');

		$this->assertSame(1, $result['succeeded']);
		$this->assertSame(1, $result['failed']);
		$byId = [];
		foreach ($result['results'] as $r) {
			$byId[$r['id']] = $r['success'];
		}
		$this->assertTrue($byId['c1']);
		$this->assertFalse($byId['c2']);
	}//end testPartialFailureReported()






}//end class
