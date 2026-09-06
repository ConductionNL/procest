<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Flow
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use OCA\Dossiq\Service\Flow\ShippedFlowAdoption;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The flows dossiq ships arrive inert, and something has to say so.
 *
 * 🔴 THE GAP THIS FILE CLOSES IS THAT NOTHING LOOKED AT THE FLOWS AS INSTALLED.
 * dossiq's two shipped flows reached a fresh rig with `owner = NULL` and
 * `enabled = false`, so OpenRegister's FlowLocator matched their trigger and
 * refused to dispatch — `matched trigger "object.created" but was not
 * dispatched: it has no owner` — and every unit test stayed green, because each
 * of them asserted something about the DECLARATION rather than about the row.
 *
 * The honest fix is not a literal in the JSON. `SchemaFlowImportListener` copies
 * name, description, trigger, triggerRegister, cron, executionMode, nodes, edges
 * and limits off a declaration and nothing else; `owner` and `enabled` are
 * hardcoded on create and never re-read on update, so a declared owner is
 * ignored in silence. These tests pin that: the declarations must not PRETEND to
 * provision what the engine will not read, and the app must ship the operator
 * act that does provision it.
 *
 * Every sweep here asserts it FOUND something before asserting it found nothing
 * wrong, so a glob that stops matching cannot read as a clean result.
 *
 * @covers \OCA\Dossiq\Service\Flow\ShippedFlowAdoption
 */
class ShippedFlowAdoptionTest extends TestCase {

	/**
	 * The register files that may declare flows.
	 *
	 * @return array<int, string> Absolute paths.
	 */
	private function registerFiles(): array {
		$root = dirname(__DIR__, 4);
		$files = [$root . '/lib/Settings/dossiq_register.json'];
		foreach ((array)glob($root . '/lib/Settings/register.d/*.json') as $file) {
			$files[] = (string)$file;
		}

		return $files;
	}

	/**
	 * Every `x-openregister-flows` declaration dossiq ships.
	 *
	 * @return array<int, array{file: string, flow: array<string, mixed>}>
	 */
	private function shippedDeclarations(): array {
		$found = [];
		foreach ($this->registerFiles() as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			if (is_array($data) === false) {
				continue;
			}

			$this->collectDeclarations(node: $data, file: basename($file), found: $found);
		}

		return $found;
	}

	/**
	 * Walk a decoded register file collecting flow declarations.
	 *
	 * @param mixed $node The JSON node.
	 * @param string $file The file being swept.
	 * @param array<int, array{file: string, flow: array<string, mixed>}> $found Accumulator.
	 *
	 * @return void
	 */
	private function collectDeclarations(mixed $node, string $file, array &$found): void {
		if (is_array($node) === false) {
			return;
		}

		$declared = ($node['x-openregister-flows'] ?? null);
		if (is_array($declared) === true) {
			foreach ($declared as $flow) {
				if (is_array($flow) === true) {
					$found[] = ['file' => $file, 'flow' => $flow];
				}
			}
		}

		foreach ($node as $key => $value) {
			if ($key === 'x-openregister-flows') {
				continue;
			}

			$this->collectDeclarations(node: $value, file: $file, found: $found);
		}
	}

	/**
	 * dossiq ships flows at all — the premise every other assertion rests on.
	 *
	 * @return void
	 */
	public function testTheShippedRegisterDeclaresFlows(): void {
		$declarations = $this->shippedDeclarations();

		self::assertNotEmpty(
			$declarations,
			'The sweep found no x-openregister-flows declaration anywhere in lib/Settings — '
			. 'the query is broken, not the data clean'
		);

		foreach ($declarations as $entry) {
			self::assertNotSame(
				'',
				trim((string)($entry['flow']['name'] ?? '')),
				$entry['file'] . ' declares a flow with no name; the importer refuses it and logs a warning'
			);
		}
	}

	/**
	 * No declaration pretends to ship an owner or an enabled state.
	 *
	 * Both keys are ignored by `SchemaFlowImportListener`. Writing one would
	 * put a claim in the file that the engine never reads — which is exactly
	 * the kind of statement that gets believed during a review and disproved on
	 * a rig.
	 *
	 * @return void
	 */
	public function testNoShippedFlowDeclarationClaimsAnOwnerOrEnabledState(): void {
		$declarations = $this->shippedDeclarations();
		self::assertNotEmpty($declarations, 'The sweep found no declarations to check');

		foreach ($declarations as $entry) {
			foreach (['owner', 'enabled'] as $ignored) {
				self::assertArrayNotHasKey(
					$ignored,
					$entry['flow'],
					sprintf(
						'%s declares "%s" on flow "%s". SchemaFlowImportListener never reads that key — it '
						. 'hardcodes owner=null and enabled=false on create and does not re-read either on '
						. 'update — so the declaration would provision nothing while looking like it did. '
						. 'Adoption is the act that sets an owner: occ dossiq:flows:adopt.',
						$entry['file'],
						$ignored,
						(string)($entry['flow']['name'] ?? '?')
					)
				);
			}
		}
	}

	/**
	 * The app ships the operator act that makes an imported flow runnable.
	 *
	 * Registration is what actually shipped broken before, not behaviour:
	 * `ProvisionAssignedGroups` had a green behaviour test and was registered
	 * for a path a fresh install never takes. So this asserts the wiring in
	 * info.xml, both the command and the install-time report.
	 *
	 * @return void
	 */
	public function testTheAdoptionActIsRegistered(): void {
		$info = (string)file_get_contents(dirname(__DIR__, 4) . '/appinfo/info.xml');
		self::assertNotSame('', $info, 'appinfo/info.xml could not be read');

		self::assertStringContainsString(
			'OCA\Dossiq\Command\AdoptShippedFlowsCommand',
			$info,
			'The adoption command is not registered, so `occ dossiq:flows:adopt` does not exist and a '
			. 'shipped flow can only be adopted through the API by hand'
		);

		self::assertSame(
			2,
			substr_count($info, 'OCA\Dossiq\Repair\ReportShippedFlowAdoption'),
			'The install-time adoption report must be registered in BOTH the post-migration and the '
			. 'install block: Nextcloud runs only <install> on a fresh install, which is the very case '
			. 'where nothing has been adopted yet'
		);
	}

	/**
	 * An ownerless flow is reported as outstanding — the shipped state.
	 *
	 * @return void
	 */
	public function testAnOwnerlessFlowIsOutstanding(): void {
		$adoption = $this->adoption(flows: []);

		$pending = $adoption->outstanding([
			['uuid' => 'f-1', 'name' => 'Case behandeling', 'enabled' => false, 'owner' => ''],
		]);

		self::assertCount(1, $pending, 'A flow with no owner cannot dispatch and must be reported');
	}

	/**
	 * An adopted but DISABLED flow is outstanding too.
	 *
	 * Reporting only the ownerless half would leave an administrator believing
	 * a flow was armed when its trigger is not.
	 *
	 * @return void
	 */
	public function testAnAdoptedButDisabledFlowIsOutstanding(): void {
		$pending = $this->adoption(flows: [])->outstanding([
			['uuid' => 'f-1', 'name' => 'Case behandeling', 'enabled' => false, 'owner' => 'admin'],
		]);

		self::assertCount(1, $pending);
	}

	/**
	 * A flow that is owned and enabled is not reported.
	 *
	 * @return void
	 */
	public function testAnAdoptedAndEnabledFlowIsNotOutstanding(): void {
		$pending = $this->adoption(flows: [])->outstanding([
			['uuid' => 'f-1', 'name' => 'Case behandeling', 'enabled' => true, 'owner' => 'admin'],
		]);

		self::assertSame([], $pending);
	}

	/**
	 * The census reads the stored rows, not the declarations.
	 *
	 * @return void
	 */
	public function testTheCensusReportsWhatIsStored(): void {
		$census = $this->adoption(
			flows: [
				['uuid' => 'f-1', 'name' => 'Case behandeling', 'enabled' => false, 'owner' => null],
				['uuid' => 'f-2', 'name' => 'Bezwaar advies', 'enabled' => true, 'owner' => 'admin'],
			]
		)->census();

		self::assertTrue($census['available']);
		self::assertCount(2, $census['flows']);
		self::assertSame('', $census['flows'][0]['owner'], 'A NULL owner must normalise to an empty string');
		self::assertSame('admin', $census['flows'][1]['owner']);
	}

	/**
	 * An absent flow store is reported as unavailable, not as "no flows".
	 *
	 * The two are different facts and only one of them is a defect.
	 *
	 * @return void
	 */
	public function testAnAbsentFlowStoreIsNotReportedAsNoFlows(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not registered'));

		$census = (new ShippedFlowAdoption(
			container: $container,
			settingsService: $this->createMock(SettingsService::class),
			logger: new NullLogger()
		))->census();

		self::assertFalse($census['available']);
		self::assertSame([], $census['flows']);
		self::assertStringContainsString('no flow store', $census['note']);
	}

	/**
	 * Build the service over a fake flow mapper.
	 *
	 * @param array<int, array{uuid: string, name: string, enabled: bool, owner: string|null}> $flows Stored rows.
	 *
	 * @return ShippedFlowAdoption The service.
	 */
	private function adoption(array $flows): ShippedFlowAdoption {
		$entities = [];
		foreach ($flows as $flow) {
			$entities[] = new class($flow) {
				/**
				 * @param array<string, mixed> $row The stored row.
				 */
				public function __construct(private array $row) {
				}

				/**
				 * @return string The uuid.
				 */
				public function getUuid(): string {
					return (string)$this->row['uuid'];
				}

				/**
				 * @return string The name.
				 */
				public function getName(): string {
					return (string)$this->row['name'];
				}

				/**
				 * @return boolean Whether enabled.
				 */
				public function getEnabled(): bool {
					return (bool)$this->row['enabled'];
				}

				/**
				 * @return string|null The owner.
				 */
				public function getOwner(): ?string {
					return ($this->row['owner'] ?? null);
				}
			};
		}

		$mapper = new class($entities) {
			/**
			 * @param array<int, object> $entities The stored flows.
			 */
			public function __construct(private array $entities) {
			}

			/**
			 * @param string|null $app The app filter.
			 * @param string|null $applicationSlug Unused.
			 * @param string|null $organisation Unused.
			 * @param boolean|null $enabled Unused.
			 * @param integer $limit Page size.
			 * @param integer $offset Page offset.
			 *
			 * @return array<int, object> The rows.
			 */
			public function findAllFlows(
				?string $app = null,
				?string $applicationSlug = null,
				?string $organisation = null,
				?bool $enabled = null,
				int $limit = 100,
				int $offset = 0,
			): array {
				return $this->entities;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);

		return new ShippedFlowAdoption(
			container: $container,
			settingsService: $this->createMock(SettingsService::class),
			logger: new NullLogger()
		);
	}
}//end class
