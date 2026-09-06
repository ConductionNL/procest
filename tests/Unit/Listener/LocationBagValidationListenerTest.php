<?php

/**
 * LocationBagValidationListener Unit Tests
 *
 * Full validation matrix for the `location` schema's `source: bag` ⇒
 * `nummeraanduidingId` save-path enforcement (bag-location-save-validation).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/bag-location-save-validation/specs/bag-location-save-validation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\LocationBagValidationListener;
use OCA\Dossiq\Service\External\Bag\BagAdapterInterface;
use OCA\Dossiq\Service\External\Bag\BagLookupResult;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Listener\LocationBagValidationListener
 *
 * @uses \OCA\Dossiq\Service\External\Bag\BagLookupResult
 */
class LocationBagValidationListenerTest extends TestCase {

	/**
	 * The mocked schema-slug bridge.
	 *
	 * @var SettingsService
	 */
	private SettingsService $settingsService;

	/**
	 * The mocked BAG adapter.
	 *
	 * @var BagAdapterInterface
	 */
	private BagAdapterInterface $bagAdapter;

	/**
	 * The mocked translation service (passthrough).
	 *
	 * @var IL10N
	 */
	private IL10N $l10n;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * The listener under test.
	 *
	 * @var LocationBagValidationListener
	 */
	private LocationBagValidationListener $listener;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				return $key === 'location_schema' ? 'location-schema-id' : $default;
			}
		);

		$this->bagAdapter = $this->createMock(BagAdapterInterface::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => $text
		);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->listener = new LocationBagValidationListener(
			$this->settingsService,
			$this->bagAdapter,
			$this->l10n,
			$this->logger,
		);
	}//end setUp()

	/**
	 * Build a location ObjectEntity with the given payload.
	 *
	 * @param array<string, mixed> $payload Location fields.
	 * @param string $schemaId Schema id (`@self.schema`).
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $payload, string $schemaId = 'location-schema-id'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($payload);
		$entity->setSchema($schemaId);
		$entity->setUuid('11111111-1111-1111-1111-111111111111');

		return $entity;
	}//end entity()

	/**
	 * Build a creating-event wrapping the given entity.
	 *
	 * @param ObjectEntity $entity Entity being created.
	 *
	 * @return ObjectCreatingEvent
	 */
	private function creatingEvent(ObjectEntity $entity): ObjectCreatingEvent {
		return new ObjectCreatingEvent($entity);
	}//end creatingEvent()

	/**
	 * Test that a generic unrelated event is ignored.
	 *
	 * @return void
	 */
	public function testHandleIgnoresUnrelatedEvents(): void {
		$this->bagAdapter->expects($this->never())->method('lookupObject');
		$this->listener->handle(new Event());
		$this->addToAssertionCount(1);
	}//end testHandleIgnoresUnrelatedEvents()

	/**
	 * Test that a non-location schema object is ignored regardless of
	 * payload contents.
	 *
	 * @return void
	 */
	public function testNonLocationSchemaIsIgnored(): void {
		$entity = $this->entity(['source' => 'bag'], schemaId: 'some-other-schema');
		$event = $this->creatingEvent($entity);

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testNonLocationSchemaIsIgnored()

	/**
	 * Test that an absent `source` field is a no-op.
	 *
	 * @return void
	 */
	public function testAbsentSourceIsIgnored(): void {
		$entity = $this->entity(['case' => 'uuid-x']);
		$event = $this->creatingEvent($entity);

		$this->bagAdapter->expects($this->never())->method('lookupObject');
		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAbsentSourceIsIgnored()

	/**
	 * Test that a non-bag source is a no-op.
	 *
	 * @return void
	 */
	public function testNonBagSourceIsIgnored(): void {
		$entity = $this->entity(['source' => 'pdok-reverse', 'latitude' => 52.0, 'longitude' => 5.0]);
		$event = $this->creatingEvent($entity);

		$this->bagAdapter->expects($this->never())->method('lookupObject');
		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testNonBagSourceIsIgnored()

	/**
	 * Test that source=bag with a missing nummeraanduidingId is rejected.
	 *
	 * @return void
	 */
	public function testBagSourceWithMissingIdIsRejected(): void {
		$entity = $this->entity(['source' => 'bag', 'case' => 'uuid-x']);
		$event = $this->creatingEvent($entity);

		$this->bagAdapter->expects($this->never())->method('lookupObject');
		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(['nummeraanduidingId.required'], $event->getErrors()['codes']);
	}//end testBagSourceWithMissingIdIsRejected()

	/**
	 * Test that source=bag with a malformed (non-16-digit) id is rejected.
	 *
	 * @return void
	 */
	public function testBagSourceWithMalformedIdIsRejected(): void {
		$entity = $this->entity(['source' => 'bag', 'addressDesignationId' => '12345']);
		$event = $this->creatingEvent($entity);

		$this->bagAdapter->expects($this->never())->method('lookupObject');
		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(['nummeraanduidingId.invalid'], $event->getErrors()['codes']);
	}//end testBagSourceWithMalformedIdIsRejected()

	/**
	 * Test that source=bag with a well-formed id and a dormant (log-mode)
	 * adapter is accepted without ever calling the adapter.
	 *
	 * @return void
	 */
	public function testValidIdWithDormantAdapterIsAcceptedWithoutRemoteCall(): void {
		$entity = $this->entity(['source' => 'bag', 'addressDesignationId' => '0363010000123456']);
		$event = $this->creatingEvent($entity);

		$this->bagAdapter->method('isDormant')->willReturn(true);
		$this->bagAdapter->expects($this->never())->method('lookupObject');

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testValidIdWithDormantAdapterIsAcceptedWithoutRemoteCall()

	/**
	 * Test that a non-dormant adapter returning FOUND accepts the save.
	 *
	 * @return void
	 */
	public function testValidIdWithNonDormantFoundIsAccepted(): void {
		$entity = $this->entity(['source' => 'bag', 'addressDesignationId' => '0363010000123456']);
		$event = $this->creatingEvent($entity);

		$this->bagAdapter->method('isDormant')->willReturn(false);
		$this->bagAdapter->expects($this->once())
			->method('lookupObject')
			->with('nummeraanduiding', '0363010000123456')
			->willReturn(new BagLookupResult(lookupStatus: 'FOUND', address: [], dormant: false));

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testValidIdWithNonDormantFoundIsAccepted()

	/**
	 * Test that a non-dormant adapter returning NOT_FOUND rejects the save.
	 *
	 * @return void
	 */
	public function testValidIdWithNonDormantNotFoundIsRejected(): void {
		$entity = $this->entity(['source' => 'bag', 'addressDesignationId' => '0363010000123456']);
		$event = $this->creatingEvent($entity);

		$this->bagAdapter->method('isDormant')->willReturn(false);
		$this->bagAdapter->method('lookupObject')
			->willReturn(new BagLookupResult(lookupStatus: 'NOT_FOUND', address: [], dormant: false));

		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(['nummeraanduidingId.unknown'], $event->getErrors()['codes']);
	}//end testValidIdWithNonDormantNotFoundIsRejected()

	/**
	 * Test that a non-dormant adapter returning an inconclusive
	 * LOOKUP_ERROR result accepts the save (adapter-unavailable behaviour =
	 * accept-with-warning, not reject).
	 *
	 * @return void
	 */
	public function testValidIdWithLookupErrorAcceptsWithWarning(): void {
		$entity = $this->entity(['source' => 'bag', 'addressDesignationId' => '0363010000123456']);
		$event = $this->creatingEvent($entity);

		$this->bagAdapter->method('isDormant')->willReturn(false);
		$this->bagAdapter->method('lookupObject')
			->willReturn(new BagLookupResult(lookupStatus: 'LOOKUP_ERROR', address: [], dormant: false));

		$this->logger->expects($this->once())->method('info');

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testValidIdWithLookupErrorAcceptsWithWarning()

	/**
	 * Test that the adapter throwing accepts the save with a warning
	 * (adapter-unavailable behaviour = accept-with-warning, not reject).
	 *
	 * @return void
	 */
	public function testValidIdWithAdapterThrowingAcceptsWithWarning(): void {
		$entity = $this->entity(['source' => 'bag', 'addressDesignationId' => '0363010000123456']);
		$event = $this->creatingEvent($entity);

		$this->bagAdapter->method('isDormant')->willReturn(false);
		$this->bagAdapter->method('lookupObject')->willThrowException(new \RuntimeException('boom'));

		$this->logger->expects($this->once())->method('warning');

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testValidIdWithAdapterThrowingAcceptsWithWarning()

	/**
	 * Test that the listener also validates on ObjectUpdatingEvent (not
	 * just create).
	 *
	 * @return void
	 */
	public function testUpdatingEventWithMissingIdIsRejected(): void {
		$entity = $this->entity(['source' => 'bag']);
		$event = new ObjectUpdatingEvent($entity, null);

		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(['nummeraanduidingId.required'], $event->getErrors()['codes']);
	}//end testUpdatingEventWithMissingIdIsRejected()

	/**
	 * Test that the listener is constructed successfully.
	 *
	 * @return void
	 */
	public function testListenerConstructedSuccessfully(): void {
		$this->assertInstanceOf(LocationBagValidationListener::class, $this->listener);
	}//end testListenerConstructedSuccessfully()
}//end class
