<?php

/**
 * PublicationService Unit Tests
 *
 * Tests for the besluitvorming publication service: appending a publication
 * record to a case's publications[] array, channel validation, idempotent
 * per-channel upsert, the JSON-string publications contract, and the
 * OpenRegister persistence contract (find + saveObject named args).
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

use OCA\Dossiq\Service\PublicationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Integriq\Event\DeliveryRequestedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * ObjectService stub matching the named-argument signatures used by PublicationService.
 */
interface PublicationObjectServiceStub {
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
 * Unit tests for PublicationService.
 *
 * @covers \OCA\Dossiq\Service\PublicationService
 */
class PublicationServiceTest extends TestCase {

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * The mocked event dispatcher (integriq ADR-041 delivery seam).
	 *
	 * @var IEventDispatcher|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IEventDispatcher $eventDispatcher;

	/**
	 * The service under test.
	 *
	 * @var PublicationService
	 */
	private PublicationService $service;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(originalClassName: SettingsService::class);
		$this->eventDispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				if ($key === 'register') {
					return 'reg';
				}

				return 'schema-' . $key;
			}
		);

		$this->service = new PublicationService(
			settingsService: $this->settingsService,
			eventDispatcher: $this->eventDispatcher,
			logger: $logger
		);
	}//end setUp()

	/**
	 * Publish appends a publication record on a valid channel and persists it.
	 *
	 * @return void
	 */
	public function testPublishAppendsRecord(): void {
		$objectService = $this->createMock(originalClassName: PublicationObjectServiceStub::class);
		$objectService->method('find')->willReturn(['id' => 'c1']);

		$saved = null;
		$objectService->method('saveObject')->willReturnCallback(
			static function (array $object) use (&$saved): array {
				$saved = $object;
				return $object;
			}
		);

		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$result = $this->service->publish('c1', ['channel' => 'gemeenteblad', 'publishedAt' => '2026-07-01']);

		$this->assertSame(expected: 'c1', actual: $result['caseId']);
		$this->assertSame(expected: 'gemeenteblad', actual: $result['channel']);
		$this->assertSame(expected: '2026-07-01', actual: $result['publishedAt']);
		$this->assertCount(expectedCount: 1, haystack: $result['publications']);
		$this->assertNotNull(actual: $saved);
		$this->assertSame(expected: '2026-07-01', actual: $saved['publishedAt']);
	}//end testPublishAppendsRecord()

	/**
	 * Publish is idempotent per channel — re-publishing updates the timestamp.
	 *
	 * @return void
	 */
	public function testPublishUpsertsByChannel(): void {
		$objectService = $this->createMock(originalClassName: PublicationObjectServiceStub::class);
		$objectService->method('find')->willReturn(
			[
				'id' => 'c1',
				'publications' => [
					['channel' => 'gemeenteblad', 'publishedAt' => '2026-06-01', 'notes' => null],
				],
			]
		);
		$objectService->method('saveObject')->willReturnArgument(0);

		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$result = $this->service->publish('c1', ['channel' => 'gemeenteblad', 'publishedAt' => '2026-07-01']);

		$this->assertCount(expectedCount: 1, haystack: $result['publications']);
		$this->assertSame(expected: '2026-07-01', actual: $result['publications'][0]['publishedAt']);
	}//end testPublishUpsertsByChannel()

	/**
	 * Publish decodes a JSON-string publications field (dossiq string-encoding contract).
	 *
	 * @return void
	 */
	public function testPublishDecodesJsonStringPublications(): void {
		$objectService = $this->createMock(originalClassName: PublicationObjectServiceStub::class);
		$existing = json_encode([['channel' => 'website', 'publishedAt' => '2026-06-01']]);
		$objectService->method('find')->willReturn(['id' => 'c1', 'publications' => $existing]);
		$objectService->method('saveObject')->willReturnArgument(0);

		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$result = $this->service->publish('c1', ['channel' => 'gemeenteblad', 'publishedAt' => '2026-07-01']);

		$this->assertCount(expectedCount: 2, haystack: $result['publications']);
	}//end testPublishDecodesJsonStringPublications()

	/**
	 * Delivery fail-closed: an unhandled DeliveryRequestedEvent is recorded as
	 * a refusal on the publication record — and the publication itself still
	 * persists (a delivery failure never rolls back the publication).
	 *
	 * @return void
	 */
	public function testPublishRecordsRefusalWhenDeliveryNotHandled(): void {
		$objectService = $this->createMock(originalClassName: PublicationObjectServiceStub::class);
		$objectService->method('find')->willReturn(['id' => 'c1']);
		$saved = null;
		$objectService->method('saveObject')->willReturnCallback(
			static function (array $object) use (&$saved): array {
				$saved = $object;
				return $object;
			}
		);
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		// The dispatcher mock does nothing, so the event stays unhandled —
		// exactly what happens when integriq registers no listener.
		$result = $this->service->publish('c1', ['channel' => 'gemeenteblad']);

		$this->assertSame(expected: 'refused', actual: $result['delivery']['status']);
		$this->assertSame(expected: 'not_handled', actual: $result['delivery']['reason']);
		$this->assertNotNull(actual: $saved);
		$this->assertSame(expected: 'refused', actual: $saved['publications'][0]['delivery']['status']);
	}//end testPublishRecordsRefusalWhenDeliveryNotHandled()

	/**
	 * A handled, routed delivery is recorded as requested with integriq's
	 * event id and the correlation id the concluded projection will match on.
	 *
	 * @return void
	 */
	public function testPublishRecordsRequestedDelivery(): void {
		$objectService = $this->createMock(originalClassName: PublicationObjectServiceStub::class);
		$objectService->method('find')->willReturn(['id' => 'c1', 'title' => 'Kapvergunning']);
		$saved = null;
		$objectService->method('saveObject')->willReturnCallback(
			static function (array $object) use (&$saved): array {
				$saved = $object;
				return $object;
			}
		);
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$dispatched = null;
		$this->eventDispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use (&$dispatched): void {
				$dispatched = $event;
				if ($event instanceof DeliveryRequestedEvent) {
					$event->setResultId(resultId: 'evt-42');
					$event->setMatchedSubscriptions(matchedSubscriptions: 2);
					$event->setHandled(handled: true);
				}
			}
		);

		$result = $this->service->publish('c1', ['channel' => 'gemeenteblad']);

		$this->assertInstanceOf(expected: DeliveryRequestedEvent::class, actual: $dispatched);
		$this->assertSame(expected: 'dossiq', actual: $dispatched->getSourceApp());
		$this->assertSame(expected: 'besluit-publication', actual: $dispatched->getDeliveryKind());
		$this->assertSame(expected: 'gemeenteblad', actual: $dispatched->getChannel());
		$this->assertSame(expected: 'c1', actual: $dispatched->getSubjectId());
		$this->assertSame(expected: 'requested', actual: $result['delivery']['status']);
		$this->assertSame(expected: 'evt-42', actual: $result['delivery']['eventId']);
		$this->assertSame(expected: 2, actual: $result['delivery']['matchedSubscriptions']);
		$this->assertNotSame(expected: '', actual: (string)$result['delivery']['correlationId']);
		$this->assertSame(
			expected: $result['delivery']['correlationId'],
			actual: $saved['publications'][0]['delivery']['correlationId']
		);
	}//end testPublishRecordsRequestedDelivery()

	/**
	 * A handled delivery with zero matched subscriptions is recorded as
	 * unrouted — accepted, but nothing is configured to carry it, so the case
	 * never claims the publication travelled.
	 *
	 * @return void
	 */
	public function testPublishRecordsUnroutedDelivery(): void {
		$objectService = $this->createMock(originalClassName: PublicationObjectServiceStub::class);
		$objectService->method('find')->willReturn(['id' => 'c1']);
		$objectService->method('saveObject')->willReturnArgument(0);
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$this->eventDispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event): void {
				if ($event instanceof DeliveryRequestedEvent) {
					$event->setResultId(resultId: 'evt-7');
					$event->setHandled(handled: true);
				}
			}
		);

		$result = $this->service->publish('c1', ['channel' => 'website']);

		$this->assertSame(expected: 'unrouted', actual: $result['delivery']['status']);
		$this->assertSame(expected: 'evt-7', actual: $result['delivery']['eventId']);
	}//end testPublishRecordsUnroutedDelivery()

	/**
	 * A throwing dispatch is recorded as a refusal and never rolls back the
	 * publication.
	 *
	 * @return void
	 */
	public function testPublishRecordsRefusalWhenDispatchThrows(): void {
		$objectService = $this->createMock(originalClassName: PublicationObjectServiceStub::class);
		$objectService->method('find')->willReturn(['id' => 'c1']);
		$saved = null;
		$objectService->method('saveObject')->willReturnCallback(
			static function (array $object) use (&$saved): array {
				$saved = $object;
				return $object;
			}
		);
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$this->eventDispatcher->method('dispatchTyped')->willThrowException(new \RuntimeException('bus down'));

		$result = $this->service->publish('c1', ['channel' => 'pdc']);

		$this->assertSame(expected: 'refused', actual: $result['delivery']['status']);
		$this->assertSame(expected: 'dispatch_failed', actual: $result['delivery']['reason']);
		$this->assertNotNull(actual: $saved);
		$this->assertSame(expected: 'pdc', actual: $saved['publications'][0]['channel']);
	}//end testPublishRecordsRefusalWhenDispatchThrows()

	/**
	 * Publish rejects an unsupported channel.
	 *
	 * @return void
	 */
	public function testPublishRejectsInvalidChannel(): void {
		$objectService = $this->createMock(originalClassName: PublicationObjectServiceStub::class);
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$this->expectException(exception: \InvalidArgumentException::class);
		$this->service->publish('c1', ['channel' => 'verzonnen']);
	}//end testPublishRejectsInvalidChannel()

	/**
	 * Publish throws when OpenRegister is unavailable.
	 *
	 * @return void
	 */
	public function testPublishThrowsWhenObjectServiceMissing(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$this->expectException(exception: \RuntimeException::class);
		$this->service->publish('c1', ['channel' => 'website']);
	}//end testPublishThrowsWhenObjectServiceMissing()
}//end class
