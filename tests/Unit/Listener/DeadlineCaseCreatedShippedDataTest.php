<?php

/**
 * Drives the case-created listener with the data dossiq actually ships.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\DeadlineCaseCreatedListener;
use OCA\Dossiq\Service\CaseTypeSlugResolver;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * THE SHIPPED SEED ROW AND THE SHIPPED CASE TYPE, NOT A FIXTURE PAIR.
 *
 * Every existing termijn test hand-builds both halves — it seeds a definition
 * with `caseType: 'omgevingsvergunning-regulier'` and then asks for
 * `'omgevingsvergunning-regulier'`. Both halves agreeing is the ONE thing
 * those tests cannot check, and it is exactly what was broken: a `case` object
 * carries its case type as a UUID, a `deadlineDefinition` binds by SLUG, and
 * no shipped case type used the slugs the shipped seed named. Result on two
 * fresh rigs: zero flow_timers and zero deadlineInstance rows over seven
 * cases, with the refusal logged at DEBUG so nothing said a word.
 *
 * So this file reads the real `termijnbewaking_seed_data.json` and the real
 * `case_flow_seed_data.json`, stores the case type the way the seed stores it,
 * hands the listener the uuid the way OpenRegister hands it over, and asserts
 * a term is bound and a timer armed.
 *
 * @covers \OCA\Dossiq\Listener\DeadlineCaseCreatedListener
 * @covers \OCA\Dossiq\Service\CaseTypeSlugResolver
 * @uses \OCA\Dossiq\Service\TermijnService
 */
class DeadlineCaseCreatedShippedDataTest extends TestCase {

	/**
	 * The fake OpenRegister store the listener writes through.
	 *
	 * @var FakeTermijnStore
	 */
	private FakeTermijnStore $objects;

	/**
	 * Timer ids the timer service was asked to arm, keyed by instance id.
	 *
	 * @var array<string, string>
	 */
	private array $armed = [];

	/**
	 * The shipped termijnbewaking seed rows.
	 *
	 * @return array<int, array<string, mixed>> The definitions.
	 */
	private function shippedDefinitions(): array {
		$seed = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/termijnbewaking_seed_data.json'),
			true
		);
		self::assertIsArray($seed, 'The shipped termijnbewaking seed must parse.');

		return ($seed['termijnDefinities'] ?? []);
	}//end shippedDefinitions()

	/**
	 * The case type the shipped case-flow seed creates.
	 *
	 * @return array<string, mixed> The case type as the seed declares it.
	 */
	private function shippedCaseType(): array {
		$seed = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/case_flow_seed_data.json'),
			true
		);
		self::assertIsArray($seed, 'The shipped case-flow seed must parse.');

		$caseType = (($seed['caseTypes'] ?? [])[0] ?? null);
		self::assertIsArray($caseType, 'The shipped case-flow seed must declare a case type.');

		return $caseType;
	}//end shippedCaseType()

	/**
	 * Build the listener over a store holding the shipped data.
	 *
	 * @return array{0: DeadlineCaseCreatedListener, 1: string} The listener and the stored case type's uuid.
	 */
	private function listener(): array {
		$this->objects = new FakeTermijnStore();

		foreach ($this->shippedDefinitions() as $definition) {
			$this->objects->seed('deadlineDefinition', $definition);
		}

		// Stored the way OpenRegister actually returns it. A top-level `slug`
		// in the case-flow seed's import payload becomes object METADATA, so
		// it reads back under `@self.slug` and NOT as a body property —
		// putting it in the body here would be a fixture agreeing with a
		// resolver that never has to find it where it really lives.
		$caseType = $this->objects->seed(
			'caseType',
			[
				'id' => '8f1a2b3c-4d5e-6f70-8192-a3b4c5d6e7f8',
				'title' => (string)$this->shippedCaseType()['title'],
				'@self' => ['slug' => (string)$this->shippedCaseType()['slug']],
			]
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'case_type_schema' => 'caseType',
					'termijn_definitie_schema' => 'deadlineDefinition',
					'termijn_instance_schema' => 'deadlineInstance',
					'termijn_gebeurtenis_schema' => 'termijnGebeurtenis',
					default => '',
				};
			},
		);

		$timers = $this->createMock(TermijnTimerService::class);
		$timers->method('armBeslistermijn')->willReturnCallback(
			function (array $instance, array $definitie): string {
				$timerId = 'timer-' . (string)($instance['id'] ?? '');
				$this->armed[(string)($instance['id'] ?? '')] = $timerId;

				return $timerId;
			}
		);

		$schemaSlugs = $this->createMock(ObjectSchemaSlugResolver::class);
		$schemaSlugs->method('resolveFromPayload')->willReturn('case');

		$logger = $this->createMock(LoggerInterface::class);

		$listener = new DeadlineCaseCreatedListener(
			new TermijnService($settings, $logger, $timers),
			$schemaSlugs,
			new CaseTypeSlugResolver($settings, $logger),
			$logger,
		);

		return [$listener, (string)$caseType['id']];
	}//end listener()

	/**
	 * The ObjectEntity OpenRegister hands to a create listener.
	 *
	 * Built as an `ObjectEntity` because that is the only thing
	 * `ObjectCreatedEvent::__construct()` accepts. These two call sites used to
	 * pass a bare `FakeStoredObject`, which the stub event took because its
	 * parameter was untyped and optional — so both fataled against a real
	 * OpenRegister and passed here. `caseType` goes in the OBJECT and the
	 * schema in `@self`, because that is where the entity itself puts them.
	 *
	 * @param string $caseId     The case uuid.
	 * @param string $caseTypeId The case type this case references.
	 *
	 * @return ObjectEntity The created case.
	 */
	private static function createdCase(string $caseId, string $caseTypeId): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($caseId);
		$entity->setSchema('case');
		$entity->setObject(['caseType' => $caseTypeId]);

		return $entity;
	}//end createdCase()

	/**
	 * A case of a shipped case type binds a term and arms a timer.
	 *
	 * @return void
	 */
	public function testAShippedCaseTypeBindsATermAndArmsATimer(): void {
		[$listener, $caseTypeId] = $this->listener();

		// The payload OpenRegister hands over: caseType is a UUID, because
		// the case schema declares it `format: uuid, $ref: caseType`.
		$listener->handle(
			new ObjectCreatedEvent(self::createdCase('case-1', $caseTypeId))
		);

		$instances = array_values($this->objects->store['deadlineInstance'] ?? []);
		self::assertCount(
			1,
			$instances,
			'A case of a SHIPPED case type must bind a TermijnInstance. Zero here is the fresh-install defect: '
			. 'the listener passed the case type UUID to a lookup that matches only a slug.'
		);

		$instance = $instances[0];
		self::assertSame('case-1', $instance['case']);
		self::assertSame('lopend', $instance['status']);
		self::assertSame(
			'td-omgevingsvergunning-kleinbouw',
			$instance['deadlineDefinition'],
			'The term must come from the shipped definition for this shipped case type.'
		);

		self::assertNotSame([], $this->armed, 'A bound term must arm a FlowTimer; zero flow_timers was the measured symptom.');
		self::assertSame(
			$this->armed[(string)$instance['id']],
			$instance['engineTimerId'],
			'The armed timer id must be written back onto the instance.'
		);
	}//end testAShippedCaseTypeBindsATermAndArmsATimer()

	/**
	 * A case type with no definition is refused LOUDLY, not at debug level.
	 *
	 * @return void
	 */
	public function testACaseTypeWithoutADefinitionWarns(): void {
		$this->objects = new FakeTermijnStore();
		$caseType = $this->objects->seed(
			'caseType',
			['id' => '11111111-2222-3333-4444-555555555555', 'title' => 'Toezichtzaak Bouw', '@self' => ['slug' => 'toezichtzaak-bouw']]
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'case_type_schema' => 'caseType',
				'termijn_definitie_schema' => 'deadlineDefinition',
				'termijn_instance_schema' => 'deadlineInstance',
				'termijn_gebeurtenis_schema' => 'termijnGebeurtenis',
				default => '',
			},
		);

		$schemaSlugs = $this->createMock(ObjectSchemaSlugResolver::class);
		$schemaSlugs->method('resolveFromPayload')->willReturn('case');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with(self::stringContains('no active TermijnDefinitie for case type "toezichtzaak-bouw"'));
		$logger->expects(self::never())->method('debug');

		$listener = new DeadlineCaseCreatedListener(
			new TermijnService($settings, $this->createMock(LoggerInterface::class)),
			$schemaSlugs,
			new CaseTypeSlugResolver($settings, $logger),
			$logger,
		);

		$listener->handle(
			new ObjectCreatedEvent(self::createdCase('case-2', (string)$caseType['id']))
		);

		self::assertSame([], ($this->objects->store['deadlineInstance'] ?? []), 'No definition means no term, which is a valid outcome — it just must not be silent.');
	}//end testACaseTypeWithoutADefinitionWarns()

}//end class
