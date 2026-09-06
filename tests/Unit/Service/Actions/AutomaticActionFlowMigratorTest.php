<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Actions
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Actions;

use OCA\Dossiq\Service\Actions\AutomaticActionFlowMigrator;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Covers the automaticAction → flow projection.
 *
 * The migration turns configuration that has never executed into something that
 * fires, so every branch that decides WHETHER to write is asserted here: an
 * unimplemented action type must be skipped rather than wrapped in a flow that
 * cannot run, and a re-run must update the flow it made last time instead of
 * creating a second one.
 */
class AutomaticActionFlowMigratorTest extends TestCase {
	/**
	 * A minimal stand-in for OpenRegister's FlowService.
	 *
	 * @return object The fake, exposing saves[] and a settable page.
	 */
	private function flowServiceFake(): object {
		return new class {
			/**
			 * @var array<int, array{document: array<string, mixed>, uuid: string|null}>
			 */
			public array $saves = [];

			/**
			 * @var array<int, object>
			 */
			public array $page = [];

			/**
			 * Record a save and hand back a flow-like object.
			 *
			 * @param array<string, mixed> $document The flow document.
			 * @param string|null $uuid The flow being updated.
			 *
			 * @return object The stored flow.
			 */
			public function save(array $document, ?string $uuid = null): object {
				$this->saves[] = ['document' => $document, 'uuid' => $uuid];

				return new class($uuid ?? 'new-flow-uuid') {
					/**
					 * @param string $uuid The uuid.
					 */
					public function __construct(private string $uuid) {
					}

					/**
					 * @return string The uuid.
					 */
					public function getUuid(): string {
						return $this->uuid;
					}
				};
			}

			/**
			 * Return the first page of flows, then nothing.
			 *
			 * @param string|null $app The owning app.
			 * @param string|null $applicationSlug Unused.
			 * @param bool|null $enabled Unused.
			 * @param int $limit Page size.
			 * @param int $offset Page offset.
			 *
			 * @return array<int, object> The page.
			 */
			public function findAll(
				?string $app = null,
				?string $applicationSlug = null,
				?bool $enabled = null,
				int $limit = 100,
				int $offset = 0,
			): array {
				if ($offset > 0) {
					return [];
				}

				return $this->page;
			}
		};
	}

	/**
	 * A flow-like row carrying a provenance marker.
	 *
	 * @param string $notes The notes field.
	 * @param string $uuid The flow uuid.
	 *
	 * @return object The row.
	 */
	private function flowRow(string $notes, string $uuid): object {
		return new class($notes, $uuid) {
			/**
			 * @param string $notes The notes.
			 * @param string $uuid The uuid.
			 */
			public function __construct(private string $notes, private string $uuid) {
			}

			/**
			 * @return string The notes.
			 */
			public function getNotes(): string {
				return $this->notes;
			}

			/**
			 * @return string The uuid.
			 */
			public function getUuid(): string {
				return $this->uuid;
			}
		};
	}

	/**
	 * An ObjectService stand-in returning the given actions.
	 *
	 * @param array<int, array<string, mixed>> $actions The stored actions.
	 *
	 * @return object The fake.
	 */
	private function objectServiceFake(array $actions): object {
		return new class($actions) {
			/**
			 * @param array<int, array<string, mixed>> $actions The actions.
			 */
			public function __construct(private array $actions) {
			}

			/**
			 * Run the callable straight through.
			 *
			 * @param IUser $user The acting user.
			 * @param callable $operation The operation.
			 *
			 * @return mixed The result.
			 */
			public function runAs(IUser $user, callable $operation) {
				return $operation();
			}

			/**
			 * Return the configured actions.
			 *
			 * @param array<string, mixed> $config The query config.
			 *
			 * @return array<int, array<string, mixed>> The actions.
			 */
			public function findAll(array $config): array {
				return $this->actions;
			}
		};
	}

	/**
	 * An empty node catalogue, built the way the real one must be.
	 *
	 * The registry takes a dispatcher and a logger — it collects contributed
	 * nodes through the first — and these tests register into it directly, so
	 * neither dependency does anything here. They are passed because leaving
	 * them out is a fatal against the real class, which is what a no-argument
	 * stub hid for six call sites.
	 *
	 * @return FlowNodeRegistry The catalogue.
	 */
	private function registry(): FlowNodeRegistry {
		return new FlowNodeRegistry(
			$this->createMock(IEventDispatcher::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end registry()

	/**
	 * Build the migrator with the given fakes.
	 *
	 * @param array<int, array<string, mixed>> $actions Stored actions.
	 * @param object $flowService The flow-service fake.
	 * @param array<int, string> $nodeIds Node ids the registry knows.
	 *
	 * @return AutomaticActionFlowMigrator The migrator.
	 */
	private function migrator(array $actions, object $flowService, array $nodeIds): AutomaticActionFlowMigrator {
		$registry = $this->registry();
		foreach ($nodeIds as $id) {
			$node = $this->createMock(IFlowNode::class);
			$node->method('getId')->willReturn($id);
			$registry->register($node);
		}

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objectServiceFake($actions));

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($flowService, $registry) {
				if ($id === 'OCA\OpenRegister\Service\Flow\FlowService') {
					return $flowService;
				}

				return $registry;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'register') ? '17' : '115'
		);

		return new AutomaticActionFlowMigrator(
			$settings,
			$container,
			$appConfig,
			$this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * One well-formed action becomes one enabled, runnable flow.
	 *
	 * @return void
	 */
	public function testItCreatesAnEnabledFlowPerAction(): void {
		$flowService = $this->flowServiceFake();
		$migrator = $this->migrator(
			[
				[
					'tenantId' => 'tenant-a',
					'slug' => 'send-decision-email',
					'title' => 'Send decision email',
					'type' => 'sendEmail',
					'config' => '{"subject":"Uw besluit"}',
				],
			],
			$flowService,
			['dossiq.action.sendEmail'],
		);

		$summary = $migrator->migrate(user: $this->createMock(IUser::class), dryRun: false);

		$this->assertSame(1, $summary['total']);
		$this->assertSame(1, $summary['created']);
		$this->assertCount(1, $flowService->saves);

		$document = $flowService->saves[0]['document'];
		$this->assertNull($flowService->saves[0]['uuid'], 'A first migration must CREATE, not update.');
		$this->assertTrue($document['enabled']);
		$this->assertSame('manual', $document['trigger']);
		$this->assertSame(
			['openregister.trigger-manual', 'dossiq.action.sendEmail', 'openregister.end'],
			array_column($document['nodes'], 'type'),
			'A flow OpenRegister will run needs an entry and an exit around the action.'
		);
		$this->assertSame(['subject' => 'Uw besluit'], $document['nodes'][1]['config']);
		$this->assertSame('dossiq:automaticAction:tenant-a:send-decision-email', $document['notes']);
	}

	/**
	 * An action type no node implements is SKIPPED, never wrapped in a flow.
	 *
	 * Writing it would rebuild the exact defect this programme already fixed in
	 * the VTH catalog: a stored step naming a handler nothing answers to, which
	 * reports success and does nothing.
	 *
	 * @return void
	 */
	public function testItSkipsAnActionTypeNoNodeImplements(): void {
		$flowService = $this->flowServiceFake();
		$migrator = $this->migrator(
			[['tenantId' => 't', 'slug' => 'carrier-pigeon', 'title' => 'Pigeon', 'type' => 'sendCarrierPigeon']],
			$flowService,
			['dossiq.action.sendEmail'],
		);

		$summary = $migrator->migrate(user: $this->createMock(IUser::class), dryRun: false);

		$this->assertSame(1, $summary['skipped']);
		$this->assertSame(0, $summary['created']);
		$this->assertSame([], $flowService->saves);
	}

	/**
	 * A re-run updates the flow it created, rather than making a second one.
	 *
	 * @return void
	 */
	public function testItUpdatesTheFlowItAlreadyCreated(): void {
		$flowService = $this->flowServiceFake();
		$flowService->page = [
			$this->flowRow('dossiq:automaticAction:tenant-a:send-decision-email', 'existing-uuid'),
		];

		$migrator = $this->migrator(
			[
				[
					'tenantId' => 'tenant-a',
					'slug' => 'send-decision-email',
					'title' => 'Send decision email',
					'type' => 'sendEmail',
					'config' => '{}',
				],
			],
			$flowService,
			['dossiq.action.sendEmail'],
		);

		$summary = $migrator->migrate(user: $this->createMock(IUser::class), dryRun: false);

		$this->assertSame(1, $summary['updated']);
		$this->assertSame(0, $summary['created']);
		$this->assertSame('existing-uuid', $flowService->saves[0]['uuid']);
	}

	/**
	 * An action missing tenantId or slug FAILS rather than sharing a marker.
	 *
	 * Defaulting the missing half would collapse several actions onto one
	 * marker, and each migration would overwrite the previous one's flow.
	 *
	 * @return void
	 */
	public function testItFailsAnActionItCannotIdentify(): void {
		$flowService = $this->flowServiceFake();
		$migrator = $this->migrator(
			[['slug' => 'no-tenant', 'title' => 'Orphan', 'type' => 'sendEmail']],
			$flowService,
			['dossiq.action.sendEmail'],
		);

		$summary = $migrator->migrate(user: $this->createMock(IUser::class), dryRun: false);

		$this->assertSame(1, $summary['failed']);
		$this->assertSame([], $flowService->saves);
	}

	/**
	 * A dry run reports the same outcomes and writes nothing.
	 *
	 * @return void
	 */
	public function testADryRunWritesNothing(): void {
		$flowService = $this->flowServiceFake();
		$migrator = $this->migrator(
			[['tenantId' => 't', 'slug' => 's', 'title' => 'T', 'type' => 'sendEmail', 'config' => '{}']],
			$flowService,
			['dossiq.action.sendEmail'],
		);

		$summary = $migrator->migrate(user: $this->createMock(IUser::class), dryRun: true);

		$this->assertSame(1, $summary['created']);
		$this->assertSame([], $flowService->saves, 'A dry run that writes is not a dry run.');
	}

	/**
	 * A save that throws is reported as failed and does not abort the rest.
	 *
	 * @return void
	 */
	public function testOneFailingActionDoesNotAbortTheRest(): void {
		$flowService = new class($this->flowServiceFake()) {
			/**
			 * @var array<int, array{document: array<string, mixed>, uuid: string|null}>
			 */
			public array $saves = [];

			/**
			 * @param object $inner Unused; keeps the shape symmetric.
			 */
			public function __construct(private object $inner) {
			}

			/**
			 * Throw for the first action, succeed for the second.
			 *
			 * @param array<string, mixed> $document The flow document.
			 * @param string|null $uuid The flow being updated.
			 *
			 * @return object The stored flow.
			 */
			public function save(array $document, ?string $uuid = null): object {
				if ($document['name'] === 'Boom') {
					throw new \RuntimeException('storage exploded');
				}

				$this->saves[] = ['document' => $document, 'uuid' => $uuid];

				return new class {
					/**
					 * @return string The uuid.
					 */
					public function getUuid(): string {
						return 'ok-uuid';
					}
				};
			}

			/**
			 * No pre-existing flows.
			 *
			 * @param string|null $app The owning app.
			 * @param string|null $applicationSlug Unused.
			 * @param bool|null $enabled Unused.
			 * @param int $limit Page size.
			 * @param int $offset Page offset.
			 *
			 * @return array<int, object> The page.
			 */
			public function findAll(
				?string $app = null,
				?string $applicationSlug = null,
				?bool $enabled = null,
				int $limit = 100,
				int $offset = 0,
			): array {
				return [];
			}
		};

		$migrator = $this->migrator(
			[
				['tenantId' => 't', 'slug' => 'boom', 'title' => 'Boom', 'type' => 'sendEmail', 'config' => '{}'],
				['tenantId' => 't', 'slug' => 'fine', 'title' => 'Fine', 'type' => 'sendEmail', 'config' => '{}'],
			],
			$flowService,
			['dossiq.action.sendEmail'],
		);

		$summary = $migrator->migrate(user: $this->createMock(IUser::class), dryRun: false);

		$this->assertSame(1, $summary['failed']);
		$this->assertSame(1, $summary['created']);
		$this->assertCount(1, $flowService->saves);
	}
}
