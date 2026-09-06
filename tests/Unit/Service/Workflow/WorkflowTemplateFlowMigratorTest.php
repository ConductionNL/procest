<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Workflow
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Workflow;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Workflow\WorkflowTemplateFlowMigrator;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the projection of a workflow definition onto an OpenRegister flow.
 *
 * Two properties carry this migration and both are easy to get quietly wrong.
 *
 * The flow must arrive DISABLED: the definition still drives cases through
 * StatusTransitionService, so an enabled projection would move the same case
 * twice on every status change — and would look like it was working.
 *
 * Statuses must travel by NAME. A statusType uuid is minted per installation,
 * so a projection carrying ids is portable nowhere; `dossiq.setStatus` takes a
 * name for exactly that reason.
 */
class WorkflowTemplateFlowMigratorTest extends TestCase {

	/**
	 * Templates the fake register returns.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $templates = [];

	/**
	 * statusType rows the fake register holds.
	 *
	 * Separate from $templates because the migrator reads TWO schemas, and the
	 * uuid-to-name resolution under test depends on telling them apart. A fake
	 * answering both alike cannot express it.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $statusTypes = [];

	/**
	 * Whether reading statusTypes throws.
	 *
	 * @var bool
	 */
	private bool $statusTypesThrow = false;

	/**
	 * Whether the statusType schema is configured at all.
	 *
	 * @var bool
	 */
	private bool $statusSchemaConfigured = true;

	/**
	 * Flow documents the migrator wrote.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * Flows the fake FlowService already holds.
	 *
	 * @var array<int, object>
	 */
	private array $existingFlows = [];

	/**
	 * Whether the fake FlowService refuses to save.
	 *
	 * @var boolean
	 */
	private bool $saveThrows = false;

	/**
	 * Build the migrator.
	 *
	 * @param boolean $withFlowService Whether OpenRegister exposes FlowService.
	 * @param boolean $withStore       Whether OpenRegister is available at all.
	 *
	 * @return WorkflowTemplateFlowMigrator The migrator.
	 */
	private function migrator(bool $withFlowService = true, bool $withStore = true): WorkflowTemplateFlowMigrator {
		$templates = &$this->templates;
		$statusTypes = &$this->statusTypes;
		$statusTypesThrow = $this->statusTypesThrow;
		$written = &$this->written;
		$existing = &$this->existingFlows;
		$throws = &$this->saveThrows;

		$objectService = new class($templates, $statusTypes, $statusTypesThrow) {
			/**
			 * @param array<int, array<string, mixed>> $templates Templates.
			 */
			public function __construct(private array &$templates, private array &$statusTypes, private bool $statusTypesThrow) {
			}

			/**
			 * @param IUser    $user      The acting user.
			 * @param callable $operation The operation.
			 *
			 * @return mixed The result.
			 */
			public function runAs(IUser $user, callable $operation): mixed {
				return $operation();
			}

			/**
			 * @param string               $register The register slug.
			 * @param string               $schema   The schema slug.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				if ($schema === 'statusType') {
					if ($this->statusTypesThrow === true) {
						throw new \RuntimeException('status types unavailable');
					}

					return $this->statusTypes;
				}

				return $this->templates;
			}
		};

		$flowService = new class($written, $existing, $throws) {
			/**
			 * @param array<int, array<string, mixed>> $written  Writes.
			 * @param array<int, object>               $existing Existing flows.
			 * @param boolean                          $throws   Whether to refuse.
			 */
			public function __construct(private array &$written, private array &$existing, private bool &$throws) {
			}

			/**
			 * @param string      $app      The app id.
			 * @param string|null $a        Unused.
			 * @param string|null $b        Unused.
			 * @param integer     $limit    Page size.
			 * @param integer     $offset   Page offset.
			 *
			 * @return array<int, object> The page.
			 */
			public function findAll(string $app, ?string $a = null, ?string $b = null, int $limit = 100, int $offset = 0): array {
				if ($offset > 0) {
					return [];
				}

				return $this->existing;
			}

			/**
			 * @param array<string, mixed> $document The flow document.
			 * @param string|null          $uuid     The uuid, or null to create.
			 *
			 * @return object The stored flow.
			 */
			public function save(array $document, ?string $uuid = null): object {
				if ($this->throws === true) {
					throw new RuntimeException('flow refused');
				}

				$this->written[] = ($document + ['_uuid' => $uuid]);

				return new class($uuid) {
					/**
					 * @param string|null $uuid The uuid.
					 */
					public function __construct(private ?string $uuid) {
					}

					/**
					 * @return string The uuid.
					 */
					public function getUuid(): string {
						return ($this->uuid ?? 'flow-new');
					}
				};
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($withStore === true ? $objectService : null);
		$schemaConfigured = $this->statusSchemaConfigured;
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = '') use ($schemaConfigured): string {
				// The statusType schema must be DISTINGUISHABLE from the
				// template schema, or the fake register answers both with the
				// same rows and the resolution under test cannot be seen.
				return ($key === 'status_type_schema' ? ($schemaConfigured === true ? 'statusType' : '') : 'configured');
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($flowService, $withFlowService): object {
				if ($withFlowService === false) {
					throw new RuntimeException('not registered');
				}

				return $flowService;
			}
		);

		return new WorkflowTemplateFlowMigrator($settings, $container, $this->createMock(LoggerInterface::class));

	}//end migrator()

	/**
	 * A template with a two-transition state machine.
	 *
	 * @param string $id The template id.
	 *
	 * @return array<string, mixed> The template.
	 */
	private function template(string $id = 'wt-1'): array {
		return [
			'id' => $id,
			'title' => 'Aanvraag omgevingsvergunning',
			'version' => 2,
			'transitions' => json_encode([
				['slug' => 'a', 'fromStatus' => 'Ontvangen', 'toStatus' => 'Ontvankelijkheidstoets', 'label' => 'Start toets'],
				['slug' => 'b', 'fromStatus' => 'Ontvankelijkheidstoets', 'toStatus' => 'In behandeling', 'label' => 'Ontvankelijk'],
			]),
		];

	}//end template()

	/**
	 * The acting user.
	 *
	 * @return IUser The user.
	 */
	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		return $user;

	}//end user()

	/**
	 * A template becomes a flow whose nodes are its statuses.
	 *
	 * @return void
	 */
	public function testATemplateBecomesAFlowOfItsStatuses(): void {
		$this->templates = [$this->template()];

		$summary = $this->migrator()->migrate($this->user(), dryRun: false);

		$this->assertSame(1, $summary['total']);
		$this->assertSame(1, $summary['created']);
		$this->assertCount(1, $this->written);

		$doc = $this->written[0];
		$this->assertSame('Aanvraag omgevingsvergunning', $doc['name']);
		$this->assertCount(3, $doc['nodes'], 'three distinct statuses');
		$this->assertCount(2, $doc['edges'], 'one edge per transition');
		$this->assertSame(['dossiq.setStatus'], array_unique(array_column($doc['nodes'], 'type')));

	}//end testATemplateBecomesAFlowOfItsStatuses()

	/**
	 * 🔴 Statuses travel by NAME, never by a per-installation uuid.
	 *
	 * @return void
	 */
	public function testStatusesTravelByName(): void {
		$this->templates = [$this->template()];

		$this->migrator()->migrate($this->user(), dryRun: false);

		$names = array_map(
			static fn (array $n): string => (string)$n['config']['status'],
			$this->written[0]['nodes']
		);
		sort($names);
		$this->assertSame(['In behandeling', 'Ontvangen', 'Ontvankelijkheidstoets'], $names);

	}//end testStatusesTravelByName()

	/**
	 * 🔴 The flow arrives DISABLED, or every case moves twice.
	 *
	 * @return void
	 */
	public function testTheFlowArrivesDisabled(): void {
		$this->templates = [$this->template()];

		$this->migrator()->migrate($this->user(), dryRun: false);

		$this->assertFalse($this->written[0]['enabled']);

	}//end testTheFlowArrivesDisabled()

	/**
	 * Native-array transitions are accepted as well as JSON strings.
	 *
	 * @return void
	 */
	public function testNativeArrayTransitionsAreAccepted(): void {
		$template = $this->template();
		$template['transitions'] = json_decode($template['transitions'], true);
		$this->templates = [$template];

		$this->migrator()->migrate($this->user(), dryRun: false);

		$this->assertCount(2, $this->written[0]['edges']);

	}//end testNativeArrayTransitionsAreAccepted()

	/**
	 * A wildcard source is skipped: an edge with no source node is not drawable.
	 *
	 * @return void
	 */
	public function testAWildcardSourceIsSkipped(): void {
		$template = $this->template();
		$template['transitions'] = json_encode([
			['fromStatus' => '*', 'toStatus' => 'Afgebroken', 'label' => 'Annuleren'],
			['fromStatus' => 'Ontvangen', 'toStatus' => 'In behandeling', 'label' => 'Start'],
		]);
		$this->templates = [$template];

		$this->migrator()->migrate($this->user(), dryRun: false);

		$this->assertCount(1, $this->written[0]['edges']);
		$this->assertCount(2, $this->written[0]['nodes']);

	}//end testAWildcardSourceIsSkipped()

	/**
	 * A template with no usable transitions is skipped, not projected empty.
	 *
	 * A flow with nodes and no way between them looks like a migration that
	 * worked.
	 *
	 * @return void
	 */
	public function testATemplateWithNoTransitionsIsSkipped(): void {
		$this->templates = [['id' => 'wt-1', 'title' => 'Leeg', 'transitions' => '[]']];

		$summary = $this->migrator()->migrate($this->user(), dryRun: false);

		$this->assertSame(1, $summary['skipped']);
		$this->assertSame([], $this->written);

	}//end testATemplateWithNoTransitionsIsSkipped()

	/**
	 * A re-run updates the flow it already made rather than minting a second.
	 *
	 * @return void
	 */
	public function testARerunUpdatesRatherThanDuplicating(): void {
		$this->templates = [$this->template()];
		$this->existingFlows = [
			new class {
				/**
				 * @return string The notes.
				 */
				public function getNotes(): string {
					return 'dossiq:workflowTemplate:wt-1';
				}

				/**
				 * @return string The uuid.
				 */
				public function getUuid(): string {
					return 'flow-existing';
				}
			},
		];

		$summary = $this->migrator()->migrate($this->user(), dryRun: false);

		$this->assertSame(1, $summary['updated']);
		$this->assertSame(0, $summary['created']);
		$this->assertSame('flow-existing', $this->written[0]['_uuid']);

	}//end testARerunUpdatesRatherThanDuplicating()

	/**
	 * A dry run writes nothing but still reports what it would do.
	 *
	 * @return void
	 */
	public function testADryRunWritesNothing(): void {
		$this->templates = [$this->template()];

		$summary = $this->migrator()->migrate($this->user(), dryRun: true);

		$this->assertSame(1, $summary['created']);
		$this->assertSame([], $this->written);
		$this->assertStringContainsString('node(s)', $summary['rows'][0]['detail']);

	}//end testADryRunWritesNothing()

	/**
	 * One template that cannot be written does not abandon the rest.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsReportedAsFailed(): void {
		$this->templates = [$this->template()];
		$this->saveThrows = true;

		$summary = $this->migrator()->migrate($this->user(), dryRun: false);

		$this->assertSame(1, $summary['failed']);
		$this->assertSame(0, $summary['created']);

	}//end testAFailedWriteIsReportedAsFailed()

	/**
	 * A template with no id is failed rather than projected anonymously.
	 *
	 * @return void
	 */
	public function testATemplateWithNoIdFails(): void {
		$this->templates = [['title' => 'No id', 'transitions' => '[]']];

		$summary = $this->migrator()->migrate($this->user(), dryRun: false);

		$this->assertSame(1, $summary['failed']);

	}//end testATemplateWithNoIdFails()

	/**
	 * Without a FlowService the run reports why and writes nothing.
	 *
	 * @return void
	 */
	public function testWithoutAFlowServiceItReportsWhy(): void {
		$this->templates = [$this->template()];

		$summary = $this->migrator(withFlowService: false)->migrate($this->user(), dryRun: false);

		$this->assertStringContainsString('no FlowService', $summary['note']);
		$this->assertSame([], $this->written);

	}//end testWithoutAFlowServiceItReportsWhy()

	/**
	 * Without OpenRegister the run reports why and writes nothing.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterItReportsWhy(): void {
		$summary = $this->migrator(withStore: false)->migrate($this->user(), dryRun: false);

		$this->assertStringContainsString('not available', $summary['note']);

	}//end testWithoutOpenRegisterItReportsWhy()

	/**
	 * The provenance marker keys the flow, so renaming it does not duplicate.
	 *
	 * @return void
	 */
	public function testTheFlowCarriesItsProvenanceMarker(): void {
		$this->templates = [$this->template()];

		$this->migrator()->migrate($this->user(), dryRun: false);

		$this->assertSame('dossiq:workflowTemplate:wt-1', $this->written[0]['notes']);

	}//end testTheFlowCarriesItsProvenanceMarker()

	/**
	 * 🔴 A STORED template carries statusType UUIDs, and the node needs NAMES.
	 *
	 * `DossiqTxSetStatusNode` says so in its own description: it moves a case to
	 * a status of its case type "named rather than referenced by id", because a
	 * statusType uuid is minted per installation.
	 *
	 * The SEED files store names, which is what this migrator was written
	 * against. The STORED objects do not — the seeder resolves those names to
	 * uuids on import — so on a live instance every transition endpoint is a
	 * uuid. Measured on a running instance: 9 of 9.
	 *
	 * The projection was therefore writing uuids into a node that resolves
	 * names, and the flows it produced could not have moved a case. Nothing
	 * caught it because they arrive DISABLED: a disabled flow never runs, and
	 * the e2e asserts only that they exist and are switched off.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/workflow-definitions-to-flow/specs/workflow-definitions-to-flow/spec.md
	 */
	public function testStoredStatusUuidsProjectAsNames(): void {
		$this->statusTypes = [
			['id' => 'uuid-ontvangen', 'name' => 'Ontvangen'],
			['id' => 'uuid-toets', 'name' => 'Ontvankelijkheidstoets'],
		];
		$this->templates = [
			[
				'id' => 'wt-uuid',
				'title' => 'Bezwaar',
				'transitions' => [
					[
						'slug' => 'a',
						'fromStatus' => 'uuid-ontvangen',
						'toStatus' => 'uuid-toets',
						'label' => 'Start toets',
					],
				],
			],
		];

		$this->migrator()->migrate($this->user(), false);

		$nodes = ($this->written[0]['nodes'] ?? []);
		$statuses = array_map(static fn (array $n): string => (string)($n['config']['status'] ?? ''), $nodes);
		sort($statuses);

		$this->assertSame(
			['Ontvangen', 'Ontvankelijkheidstoets'],
			$statuses,
			'the projection must emit status NAMES; a uuid here cannot be resolved by dossiq.setStatus'
		);
	}//end testStoredStatusUuidsProjectAsNames()

	/**
	 * A SEED-shaped template, whose endpoints are already names, is unchanged.
	 *
	 * The resolution passes an unresolvable value through, so adding it must
	 * not rewrite the case it was built to preserve.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/workflow-definitions-to-flow/specs/workflow-definitions-to-flow/spec.md
	 */
	public function testASeedShapedTemplateStillProjectsItsNames(): void {
		$this->statusTypes = [['id' => 'uuid-ontvangen', 'name' => 'Ontvangen']];
		$this->templates = [
			[
				'id' => 'wt-uuid',
				'title' => 'Bezwaar',
				'transitions' => [
					['slug' => 'a', 'fromStatus' => 'Ontvangen', 'toStatus' => 'In behandeling', 'label' => 'Start'],
				],
			],
		];

		$this->migrator()->migrate($this->user(), false);

		$statuses = array_map(
			static fn (array $n): string => (string)($n['config']['status'] ?? ''),
			($this->written[0]['nodes'] ?? [])
		);
		sort($statuses);

		$this->assertSame(['In behandeling', 'Ontvangen'], $statuses);
	}//end testASeedShapedTemplateStillProjectsItsNames()

	/**
	 * With no statusType rows the references pass through unchanged.
	 *
	 * An instance whose statusTypes cannot be read must still project SOMETHING
	 * rather than dropping the flow: passthrough is the documented degradation,
	 * and it is what keeps a seed-shaped template working.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/workflow-definitions-to-flow/specs/workflow-definitions-to-flow/spec.md
	 */
	public function testAnEmptyStatusIndexPassesReferencesThrough(): void {
		$this->statusTypes = [];
		$this->templates = [
			[
				'id' => 'wt-uuid',
				'title' => 'Bezwaar',
				'transitions' => [
					['slug' => 'a', 'fromStatus' => 'uuid-x', 'toStatus' => 'uuid-y', 'label' => 'Start'],
				],
			],
		];

		$this->migrator()->migrate($this->user(), false);

		$statuses = array_map(
			static fn (array $n): string => (string)($n['config']['status'] ?? ''),
			($this->written[0]['nodes'] ?? [])
		);
		sort($statuses);

		$this->assertSame(['uuid-x', 'uuid-y'], $statuses);
	}//end testAnEmptyStatusIndexPassesReferencesThrough()

	/**
	 * A statusType row missing its name is skipped, not indexed as empty.
	 *
	 * Indexing it would map the uuid to an EMPTY name, and an empty status is
	 * worse than an unresolved one: `dossiq.setStatus` would be handed nothing
	 * to resolve instead of something it can refuse.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/workflow-definitions-to-flow/specs/workflow-definitions-to-flow/spec.md
	 */
	public function testAStatusTypeWithoutANameIsNotIndexed(): void {
		$this->statusTypes = [
			['id' => 'uuid-x', 'name' => ''],
			['id' => 'uuid-y', 'name' => 'Afgehandeld'],
		];
		$this->templates = [
			[
				'id' => 'wt-uuid',
				'title' => 'Bezwaar',
				'transitions' => [
					['slug' => 'a', 'fromStatus' => 'uuid-x', 'toStatus' => 'uuid-y', 'label' => 'Start'],
				],
			],
		];

		$this->migrator()->migrate($this->user(), false);

		$statuses = array_map(
			static fn (array $n): string => (string)($n['config']['status'] ?? ''),
			($this->written[0]['nodes'] ?? [])
		);
		sort($statuses);

		// uuid-x stays itself rather than becoming '', uuid-y resolves.
		$this->assertSame(['Afgehandeld', 'uuid-x'], $statuses);
	}//end testAStatusTypeWithoutANameIsNotIndexed()

	/**
	 * An unconfigured statusType schema degrades to passthrough.
	 *
	 * The projection must still happen: a missing schema config is a reason to
	 * skip the RESOLUTION, never a reason to drop the flow.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/workflow-definitions-to-flow/specs/workflow-definitions-to-flow/spec.md
	 */
	public function testAnUnconfiguredStatusSchemaStillProjects(): void {
		$this->statusSchemaConfigured = false;
		$this->statusTypes = [['id' => 'uuid-x', 'name' => 'Ontvangen']];
		$this->templates = [
			[
				'id' => 'wt-uuid',
				'title' => 'Bezwaar',
				'transitions' => [
					['slug' => 'a', 'fromStatus' => 'uuid-x', 'toStatus' => 'uuid-y', 'label' => 'Start'],
				],
			],
		];

		$this->migrator()->migrate($this->user(), false);

		$this->assertCount(1, $this->written, 'the flow must still be projected');
		$statuses = array_map(
			static fn (array $n): string => (string)($n['config']['status'] ?? ''),
			($this->written[0]['nodes'] ?? [])
		);
		sort($statuses);
		$this->assertSame(['uuid-x', 'uuid-y'], $statuses);
	}//end testAnUnconfiguredStatusSchemaStillProjects()

	/**
	 * A statusType read that THROWS degrades to passthrough, not to a failure.
	 *
	 * Losing the resolution costs readability in the projected flow. Losing the
	 * projection costs the migration, which is worse.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/workflow-definitions-to-flow/specs/workflow-definitions-to-flow/spec.md
	 */
	public function testAFailedStatusTypeReadStillProjects(): void {
		$this->statusTypesThrow = true;
		$this->templates = [
			[
				'id' => 'wt-uuid',
				'title' => 'Bezwaar',
				'transitions' => [
					['slug' => 'a', 'fromStatus' => 'uuid-x', 'toStatus' => 'uuid-y', 'label' => 'Start'],
				],
			],
		];

		$summary = $this->migrator()->migrate($this->user(), false);

		$this->assertCount(1, $this->written, 'a failed status read must not lose the flow');
		$this->assertSame(0, $summary['failed']);
	}//end testAFailedStatusTypeReadStillProjects()

}//end class
