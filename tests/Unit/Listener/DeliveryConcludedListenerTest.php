<?php

/**
 * DeliveryConcludedListener Unit Tests
 *
 * Tests for the ADR-041 delivery-seam projection: integriq's terminal
 * DeliveryConcludedEvent lands on the case's publication record, filtered to
 * this app's own requests, idempotently, and never on a non-terminal outcome.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
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

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\DeliveryConcludedListener;
use OCA\Dossiq\Service\SettingsService;
use OCA\Integriq\Event\DeliveryConcludedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * ObjectService stub matching the named-argument signatures used by the listener.
 */
interface DeliveryObjectServiceStub {
	/**
	 * Find a single object by id.
	 *
	 * @param string $id The object id.
	 * @param string $register The register slug.
	 * @param string $schema The schema id.
	 *
	 * @return mixed
	 */
	public function find(string $id, string $register, string $schema): mixed;

	/**
	 * Save or update an object.
	 *
	 * @param array $object The object payload.
	 * @param string $register The register slug.
	 * @param string $schema The schema id.
	 *
	 * @return array
	 */
	public function saveObject(array $object, string $register, string $schema): array;
}//end interface

/**
 * Unit tests for DeliveryConcludedListener.
 *
 * @covers \OCA\Dossiq\Listener\DeliveryConcludedListener
 */
class DeliveryConcludedListenerTest extends TestCase {

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * The listener under test.
	 *
	 * @var DeliveryConcludedListener
	 */
	private DeliveryConcludedListener $listener;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(originalClassName: SettingsService::class);
		$this->settingsService->method('getConfigValue')->willReturn('reg');
		$this->listener = new DeliveryConcludedListener(
			settingsService: $this->settingsService,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * Build a concluded event.
	 *
	 * @param string $sourceApp The source app.
	 * @param string $status The terminal status.
	 * @param string $correlationId The correlation id.
	 *
	 * @return DeliveryConcludedEvent
	 */
	private function concludedEvent(string $sourceApp, string $status, string $correlationId): DeliveryConcludedEvent {
		return new DeliveryConcludedEvent(
			sourceApp: $sourceApp,
			correlationId: $correlationId,
			subjectId: 'c1',
			channel: 'gemeenteblad',
			status: $status,
			eventId: 'evt-1',
			messageId: 'msg-1',
			attempts: 3,
			error: null,
			concludedAt: '2026-09-02T12:00:00+00:00',
		);
	}//end concludedEvent()

	/**
	 * A case fixture with one publication carrying a requested delivery.
	 *
	 * @param string $correlationId The delivery correlation id.
	 * @param string $deliveryStatus The current delivery status.
	 *
	 * @return array<string, mixed>
	 */
	private function caseWithPublication(string $correlationId, string $deliveryStatus = 'requested'): array {
		return [
			'id' => 'c1',
			'publications' => [
				[
					'channel' => 'gemeenteblad',
					'publishedAt' => '2026-09-01',
					'notes' => null,
					'delivery' => [
						'status' => $deliveryStatus,
						'correlationId' => $correlationId,
						'eventId' => 'evt-1',
						'requestedAt' => '2026-09-01T10:00:00+00:00',
					],
				],
			],
		];
	}//end caseWithPublication()

	/**
	 * A delivered conclusion is projected onto the matching publication.
	 *
	 * @return void
	 */
	public function testProjectsDeliveredOutcomeOntoPublication(): void {
		$objectService = $this->createMock(originalClassName: DeliveryObjectServiceStub::class);
		$objectService->method('find')->willReturn($this->caseWithPublication(correlationId: 'corr-1'));
		$saved = null;
		$objectService->method('saveObject')->willReturnCallback(
			static function (array $object) use (&$saved): array {
				$saved = $object;
				return $object;
			}
		);
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$this->listener->handle($this->concludedEvent(sourceApp: 'dossiq', status: 'delivered', correlationId: 'corr-1'));

		$this->assertNotNull(actual: $saved);
		$delivery = $saved['publications'][0]['delivery'];
		$this->assertSame(expected: 'delivered', actual: $delivery['status']);
		$this->assertSame(expected: 3, actual: $delivery['attempts']);
		$this->assertSame(expected: '2026-09-02T12:00:00+00:00', actual: $delivery['concludedAt']);
	}//end testProjectsDeliveredOutcomeOntoPublication()

	/**
	 * Another app's conclusion is ignored (provenance filter).
	 *
	 * @return void
	 */
	public function testIgnoresOtherAppsConclusions(): void {
		$objectService = $this->createMock(originalClassName: DeliveryObjectServiceStub::class);
		$objectService->expects($this->never())->method('find');
		$objectService->expects($this->never())->method('saveObject');
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$this->listener->handle($this->concludedEvent(sourceApp: 'decidiq', status: 'delivered', correlationId: 'corr-1'));
	}//end testIgnoresOtherAppsConclusions()

	/**
	 * A non-terminal status is never projected.
	 *
	 * @return void
	 */
	public function testIgnoresNonTerminalStatus(): void {
		$objectService = $this->createMock(originalClassName: DeliveryObjectServiceStub::class);
		$objectService->expects($this->never())->method('saveObject');
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$this->listener->handle($this->concludedEvent(sourceApp: 'dossiq', status: 'pending', correlationId: 'corr-1'));
	}//end testIgnoresNonTerminalStatus()

	/**
	 * Re-delivering the same terminal state is an idempotent no-op write.
	 *
	 * @return void
	 */
	public function testIdempotentOnRepeatedTerminalState(): void {
		$objectService = $this->createMock(originalClassName: DeliveryObjectServiceStub::class);
		$objectService->method('find')->willReturn(
			$this->caseWithPublication(correlationId: 'corr-1', deliveryStatus: 'delivered')
		);
		$objectService->expects($this->never())->method('saveObject');
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$this->listener->handle($this->concludedEvent(sourceApp: 'dossiq', status: 'delivered', correlationId: 'corr-1'));
	}//end testIdempotentOnRepeatedTerminalState()

	/**
	 * An abandoned conclusion projects the failure with its error.
	 *
	 * @return void
	 */
	public function testProjectsAbandonedOutcome(): void {
		$objectService = $this->createMock(originalClassName: DeliveryObjectServiceStub::class);
		$objectService->method('find')->willReturn($this->caseWithPublication(correlationId: 'corr-2'));
		$saved = null;
		$objectService->method('saveObject')->willReturnCallback(
			static function (array $object) use (&$saved): array {
				$saved = $object;
				return $object;
			}
		);
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$event = new DeliveryConcludedEvent(
			sourceApp: 'dossiq',
			correlationId: 'corr-2',
			subjectId: 'c1',
			channel: 'gemeenteblad',
			status: 'abandoned',
			eventId: 'evt-1',
			messageId: 'msg-1',
			attempts: 5,
			error: 'HTTP 503',
			concludedAt: '2026-09-02T13:00:00+00:00',
		);
		$this->listener->handle($event);

		$this->assertNotNull(actual: $saved);
		$delivery = $saved['publications'][0]['delivery'];
		$this->assertSame(expected: 'abandoned', actual: $delivery['status']);
		$this->assertSame(expected: 'HTTP 503', actual: $delivery['error']);
	}//end testProjectsAbandonedOutcome()

	/**
	 * A conclusion matching no publication logs and does not save.
	 *
	 * @return void
	 */
	public function testNoSaveWhenNoPublicationMatches(): void {
		$objectService = $this->createMock(originalClassName: DeliveryObjectServiceStub::class);
		$objectService->method('find')->willReturn($this->caseWithPublication(correlationId: 'other'));
		$objectService->expects($this->never())->method('saveObject');
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$this->listener->handle($this->concludedEvent(sourceApp: 'dossiq', status: 'delivered', correlationId: 'corr-9'));
	}//end testNoSaveWhenNoPublicationMatches()
}//end class
