<?php

/**
 * BewijsstukImmutabilityListener Unit Tests
 *
 * REQ-SUB-007: a bewijsstuk linked to a vaststelling is immutable. These
 * tests exercise the PRODUCTION call site of
 * `BewijsstukService::assertMutable()` — the listener — rather than the
 * service method in isolation, because the defect being closed was precisely
 * that the service method had no caller.
 *
 * Every reject assertion is paired with an accept assertion on the same
 * mechanism, so a listener that rejected unconditionally (or one that never
 * ran) would fail the suite instead of passing it.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/subsidieverlening-keten/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\BewijsstukImmutabilityListener;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Subsidie\BewijsstukService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Listener\BewijsstukImmutabilityListener
 *
 * @uses \OCA\Dossiq\Service\Subsidie\BewijsstukService
 */
class BewijsstukImmutabilityListenerTest extends TestCase {
	/**
	 * Schema id the listener is configured to recognise.
	 */
	private const SCHEMA = 'bewijsstuk-schema-id';

	/**
	 * The listener under test.
	 *
	 * @var BewijsstukImmutabilityListener
	 */
	private BewijsstukImmutabilityListener $listener;

	/**
	 * Set up the listener with the REAL BewijsstukService, so the test
	 * exercises the actual assertMutable() rule and not a mock of it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				return $key === 'bewijsstuk_schema' ? self::SCHEMA : $default;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);

		$this->listener = new BewijsstukImmutabilityListener(
			$settingsService,
			new BewijsstukService($this->createMock(SettingsService::class), $logger),
			$logger,
		);
	}//end setUp()

	/**
	 * Build a bewijsstuk entity.
	 *
	 * @param array<string, mixed> $payload Bewijsstuk fields.
	 * @param string $schemaId Schema id (`@self.schema`).
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $payload, string $schemaId = self::SCHEMA): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($payload);
		$entity->setSchema($schemaId);
		$entity->setUuid('22222222-2222-2222-2222-222222222222');

		return $entity;
	}//end entity()

	/**
	 * An update to a bewijsstuk that is NOT linked to a vaststelling is
	 * allowed through — the positive control for every reject case below.
	 *
	 * @return void
	 */
	public function testMutableBewijsstukUpdateIsAllowed(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(['immutable' => false, 'evidenceType' => 'invoice']),
			$this->entity(['immutable' => false, 'evidenceType' => 'timesheet'])
		);

		$this->listener->handle($event);

		$this->assertFalse(
			$event->isPropagationStopped(),
			'A mutable bewijsstuk must remain editable'
		);
		$this->assertSame([], $event->getErrors());
	}//end testMutableBewijsstukUpdateIsAllowed()

	/**
	 * An update to a vaststelling-linked bewijsstuk is rejected BEFORE the
	 * row is written (stopPropagation on the pre-persist event).
	 *
	 * @return void
	 */
	public function testImmutableBewijsstukUpdateIsRejected(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(['immutable' => true, 'evidenceType' => 'invoice']),
			$this->entity(['immutable' => true, 'evidenceType' => 'timesheet'])
		);

		$this->listener->handle($event);

		$this->assertTrue(
			$event->isPropagationStopped(),
			'An immutable bewijsstuk update must be stopped pre-persist'
		);
		$this->assertSame('bewijsstuk.immutable', $event->getErrors()['code'] ?? null);
		$this->assertStringContainsString('onveranderlijk', (string)($event->getErrors()['message'] ?? ''));
	}//end testImmutableBewijsstukUpdateIsRejected()

	/**
	 * The STORED state decides, not the incoming payload: clearing
	 * `immutable` in the same request that mutates the document must NOT
	 * unlock it. Without this the guard is trivially bypassable.
	 *
	 * @return void
	 */
	public function testPayloadCannotClearTheImmutableFlagToBypassTheGuard(): void {
		$event = new ObjectUpdatingEvent(
			// Attacker-supplied new state claims the document is mutable.
			$this->entity(['immutable' => false, 'evidenceType' => 'invoice']),
			// Stored state says otherwise.
			$this->entity(['immutable' => true, 'evidenceType' => 'timesheet'])
		);

		$this->listener->handle($event);

		$this->assertTrue(
			$event->isPropagationStopped(),
			'The guard must read the stored state, not the incoming payload'
		);
	}//end testPayloadCannotClearTheImmutableFlagToBypassTheGuard()

	/**
	 * Deleting a vaststelling-linked bewijsstuk is rejected too — an
	 * immutability rule that only covers UPDATE is bypassable by
	 * delete-and-recreate.
	 *
	 * @return void
	 */
	public function testImmutableBewijsstukDeleteIsRejected(): void {
		$event = new ObjectDeletingEvent($this->entity(['immutable' => true]));

		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('bewijsstuk.immutable', $event->getErrors()['code'] ?? null);
	}//end testImmutableBewijsstukDeleteIsRejected()

	/**
	 * Deleting a bewijsstuk that is not linked to a vaststelling is allowed.
	 *
	 * @return void
	 */
	public function testMutableBewijsstukDeleteIsAllowed(): void {
		$event = new ObjectDeletingEvent($this->entity(['immutable' => false]));

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testMutableBewijsstukDeleteIsAllowed()

	/**
	 * Objects of another schema are untouched — the listener must not
	 * freeze unrelated registers just because they carry an `immutable`
	 * field.
	 *
	 * @return void
	 */
	public function testForeignSchemaIsIgnored(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(['immutable' => true], 'some-other-schema'),
			$this->entity(['immutable' => true], 'some-other-schema')
		);

		$this->listener->handle($event);

		$this->assertFalse(
			$event->isPropagationStopped(),
			'Only the bewijsstuk schema is subject to REQ-SUB-007'
		);
	}//end testForeignSchemaIsIgnored()

	/**
	 * A create (no stored state) is never blocked: `getOldObject()` is null
	 * on first write.
	 *
	 * @return void
	 */
	public function testMissingStoredStateIsAllowed(): void {
		$event = new ObjectUpdatingEvent($this->entity(['immutable' => true]), null);

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testMissingStoredStateIsAllowed()
}//end class
