<?php

/**
 * ChecklistRunImmutabilityListener Unit Tests
 *
 * REQ-IC-8: a submitted `inspectionChecklistRun` is append-only. The listener
 * shipped subscribed to the POST-persist `ObjectUpdatedEvent` and was never
 * registered in any registrar, so the rule was enforced by nothing. These
 * tests pin the corrected behaviour: rejection happens on the PRE-persist
 * `ObjectUpdatingEvent`, via `stopPropagation()`, so the row never reaches the
 * database.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/inspection-checklists/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\ChecklistRunImmutabilityListener;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Listener\ChecklistRunImmutabilityListener
 */
class ChecklistRunImmutabilityListenerTest extends TestCase {
	/**
	 * Schema id the listener is configured to recognise.
	 */
	private const SCHEMA = 'checklist-run-schema-id';

	/**
	 * The listener under test.
	 *
	 * @var ChecklistRunImmutabilityListener
	 */
	private ChecklistRunImmutabilityListener $listener;

	/**
	 * Set up the listener.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				return $key === 'inspection_checklist_run_schema' ? self::SCHEMA : $default;
			}
		);

		$this->listener = new ChecklistRunImmutabilityListener(
			$settingsService,
			$this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * Build a checklist-run entity.
	 *
	 * @param array<string, mixed> $payload Run fields.
	 * @param string $schemaId Schema id.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $payload, string $schemaId = self::SCHEMA): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($payload);
		$entity->setSchema($schemaId);
		$entity->setUuid('33333333-3333-3333-3333-333333333333');

		return $entity;
	}//end entity()

	/**
	 * A run still in progress may be edited freely — positive control.
	 *
	 * @return void
	 */
	public function testRunInProgressMayBeEdited(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(['status' => 'in_execution', 'responses' => ['b']]),
			$this->entity(['status' => 'in_execution', 'responses' => ['a']])
		);

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testRunInProgressMayBeEdited()

	/**
	 * The first submit (`in_uitvoering → ingediend`) is allowed through.
	 *
	 * @return void
	 */
	public function testFirstSubmitIsAllowed(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(['status' => 'submitted', 'responses' => ['a']]),
			$this->entity(['status' => 'in_execution', 'responses' => ['a']])
		);

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testFirstSubmitIsAllowed()

	/**
	 * Editing a protected field on a submitted run is rejected BEFORE the row
	 * is written.
	 *
	 * @return void
	 */
	public function testEditingASubmittedRunIsRejectedPrePersist(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(['status' => 'submitted', 'responses' => ['tampered']]),
			$this->entity(['status' => 'submitted', 'responses' => ['original']])
		);

		$this->listener->handle($event);

		$this->assertTrue(
			$event->isPropagationStopped(),
			'A submitted checklist run must be append-only'
		);
		$this->assertSame('Checklist run is append-only', $event->getErrors()['message'] ?? null);
	}//end testEditingASubmittedRunIsRejectedPrePersist()

	/**
	 * A metadata-only refresh of a submitted run is not a material change and
	 * is allowed.
	 *
	 * @return void
	 */
	public function testMetadataOnlyRefreshOfASubmittedRunIsAllowed(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(['status' => 'submitted', 'responses' => ['a'], 'updatedAt' => '2026-08-05']),
			$this->entity(['status' => 'submitted', 'responses' => ['a'], 'updatedAt' => '2026-08-04'])
		);

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testMetadataOnlyRefreshOfASubmittedRunIsAllowed()

	/**
	 * Another schema's objects are untouched.
	 *
	 * @return void
	 */
	public function testForeignSchemaIsIgnored(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(['status' => 'submitted', 'responses' => ['x']], 'other-schema'),
			$this->entity(['status' => 'submitted', 'responses' => ['y']], 'other-schema')
		);

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testForeignSchemaIsIgnored()
}//end class
