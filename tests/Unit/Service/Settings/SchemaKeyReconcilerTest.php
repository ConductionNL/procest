<?php

/**
 * Dossiq Schema Key Reconciler Test
 *
 * Proves that a schema slug resolves to the schema DOSSIQ'S OWN REGISTER
 * references, not to whichever same-slug row OpenRegister's unscoped `find()`
 * happens to fetch first.
 *
 * The defect this pins down was live on a normal dev instance: three schemas
 * carried the slug `task` (ids 52, 146 and 173), only 173 belonged to Dossiq's
 * register, and the unscoped lookup returned 52 (an InterneTaak schema owned by
 * another app, in another register). `task_schema` therefore pointed outside
 * Dossiq's own register, and all seven consumers that create or read case tasks
 * wrote to a foreign schema. Nothing threw. The Tasks page simply stayed empty,
 * which reads as "no data" instead of "wrong schema".
 *
 * The second test is the guard rail on the fix: Dossiq deliberately points
 * `appointment`, `location` and `catalog` at schemas owned by OTHER apps, and
 * those slugs are unique instance-wide. A register-scoped lookup finds nothing
 * for them, so the unscoped fallback must survive or all three go blank.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Settings;

use OCA\Dossiq\Service\Settings\SchemaKeyReconciler;
use OCA\Dossiq\Service\Settings\SchemaSlugResolver;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Register-scoped schema slug resolution.
 */
final class SchemaKeyReconcilerTest extends TestCase {
	/**
	 * The three `task` rows observed on the dev instance, in the order the
	 * unscoped lookup returned them. Only 173 belongs to Dossiq's register.
	 *
	 * @var array<string, int>
	 */
	private const TASK_ROWS = [
		'foreign-internetaak' => 52,
		'foreign-duplicate' => 146,
		'dossiq' => 173,
	];

	/**
	 * Config values the spy currently holds, seeded per test.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * Config values the reconciler wrote during the run.
	 *
	 * @var array<string, string>
	 */
	private array $written = [];

	/**
	 * A slug carried by Dossiq's own register resolves to the register's schema,
	 * not to the first same-slug row instance-wide.
	 *
	 * @return void
	 */
	public function testRegisterScopedSlugWinsOverTheFirstGlobalMatch(): void {
		$appConfig = $this->appConfigSpy(['register' => '23']);

		$reconciler = $this->reconciler(
			appConfig: $appConfig,
			registerSchemaIds: [165, 166, 172, 173],
			// The unscoped lookup answers with the FOREIGN row, exactly as the
			// live instance did. If the fix regresses, this is what gets written.
			globalBySlug: ['caseTask' => self::TASK_ROWS['foreign-internetaak']],
			scopedBySlug: ['caseTask' => self::TASK_ROWS['dossiq']],
		);

		$reconciler->reconcile();

		$this->assertSame(
			(string)self::TASK_ROWS['dossiq'],
			$this->written['task_schema'] ?? '',
			'task_schema must resolve to the task schema inside Dossiq register 23, not to the foreign row 52.'
		);
	}

	/**
	 * A slug that Dossiq's register does not carry still resolves through the
	 * unscoped lookup, so the deliberately cross-register keys keep working.
	 *
	 * @return void
	 */
	public function testSlugOutsideTheRegisterFallsBackToTheGlobalLookup(): void {
		$appConfig = $this->appConfigSpy(['register' => '23']);

		$reconciler = $this->reconciler(
			appConfig: $appConfig,
			registerSchemaIds: [165, 166, 172, 173],
			globalBySlug: ['appointment' => 548],
			// Dossiq's register carries no `appointment` schema.
			scopedBySlug: [],
		);

		$reconciler->reconcile();

		$this->assertSame(
			'548',
			$this->written['appointment_schema'] ?? '',
			'A slug owned by another app must still resolve through the unscoped lookup.'
		);
	}

	/**
	 * With no register configured the reconciler behaves exactly as it did
	 * before scoping was added.
	 *
	 * @return void
	 */
	public function testUnconfiguredRegisterKeepsTheUnscopedBehaviour(): void {
		$appConfig = $this->appConfigSpy([]);

		$reconciler = $this->reconciler(
			appConfig: $appConfig,
			registerSchemaIds: [],
			globalBySlug: ['caseTask' => self::TASK_ROWS['foreign-internetaak']],
			scopedBySlug: ['caseTask' => self::TASK_ROWS['dossiq']],
		);

		$reconciler->reconcile();

		$this->assertSame(
			(string)self::TASK_ROWS['foreign-internetaak'],
			$this->written['task_schema'] ?? '',
			'Without a configured register there is nothing to scope to, so the unscoped answer stands.'
		);
	}

	/**
	 * A key that already holds the resolved id is not rewritten.
	 *
	 * @return void
	 */
	public function testAlreadyCorrectKeyIsNotRewritten(): void {
		$appConfig = $this->appConfigSpy(
			['register' => '23', 'task_schema' => (string)self::TASK_ROWS['dossiq']]
		);

		$reconciler = $this->reconciler(
			appConfig: $appConfig,
			registerSchemaIds: [173],
			globalBySlug: [],
			scopedBySlug: ['caseTask' => self::TASK_ROWS['dossiq']],
		);

		$reconciler->reconcile();

		$this->assertArrayNotHasKey(
			'task_schema',
			$this->written,
			'A key already holding the correct id must not be written again.'
		);
	}

	/**
	 * Build a reconciler wired to fake OpenRegister mappers.
	 *
	 * @param IAppConfig $appConfig The app-config spy.
	 * @param int[] $registerSchemaIds The ids the fake register references.
	 * @param array<string, int> $globalBySlug Unscoped slug to id answers.
	 * @param array<string, int> $scopedBySlug Register-scoped slug to id answers.
	 *
	 * @return SchemaKeyReconciler The reconciler under test.
	 */
	private function reconciler(
		IAppConfig $appConfig,
		array $registerSchemaIds,
		array $globalBySlug,
		array $scopedBySlug,
	): SchemaKeyReconciler {
		$schemaMapper = new class($globalBySlug, $scopedBySlug) {
			/**
			 * @param array<string, int> $global Unscoped answers.
			 * @param array<string, int> $scoped Register-scoped answers.
			 */
			public function __construct(private array $global, private array $scoped) {
			}

			/**
			 * Unscoped slug lookup, mirroring SchemaMapper::find().
			 *
			 * @param string $id The slug.
			 * @param array $extend Unused.
			 * @param boolean $rbac Unused.
			 * @param boolean $multitenancy Unused.
			 *
			 * @return object The matching schema.
			 *
			 * @throws \RuntimeException When the slug is unknown.
			 */
			public function find(string $id, array $extend = [], bool $rbac = true, bool $multitenancy = true): object {
				if (isset($this->global[$id]) === false) {
					throw new \RuntimeException('no such schema: ' . $id);
				}

				return self::schema($this->global[$id]);
			}

			/**
			 * Register-scoped slug lookup, mirroring SchemaMapper::findBySlugInIds().
			 *
			 * @param string $slug The slug.
			 * @param array $schemaIds The candidate ids.
			 *
			 * @return object|null The matching schema, or null.
			 */
			public function findBySlugInIds(string $slug, array $schemaIds): ?object {
				$id = ($this->scoped[$slug] ?? null);
				if ($id === null || in_array($id, $schemaIds, true) === false) {
					return null;
				}

				return self::schema($id);
			}

			/**
			 * Wrap an id in the getId() shape the reconciler reads.
			 *
			 * @param integer $id The schema id.
			 *
			 * @return object The schema-like object.
			 */
			private static function schema(int $id): object {
				return new class($id) {
					/**
					 * @param integer $id The schema id.
					 */
					public function __construct(private int $id) {
					}

					/**
					 * @return integer The schema id.
					 */
					public function getId(): int {
						return $this->id;
					}
				};
			}
		};

		$registerMapper = new class($registerSchemaIds) {
			/**
			 * @param int[] $schemaIds The register's schema ids.
			 */
			public function __construct(private array $schemaIds) {
			}

			/**
			 * @param string $id The register id.
			 * @param boolean $rbac Unused.
			 * @param boolean $multitenancy Unused.
			 *
			 * @return object The register-like object.
			 */
			public function find(string $id, bool $rbac = true, bool $multitenancy = true): object {
				return new class($this->schemaIds) {
					/**
					 * @param int[] $schemaIds The register's schema ids.
					 */
					public function __construct(private array $schemaIds) {
					}

					/**
					 * @return int[] The register's schema ids.
					 */
					public function getSchemas(): array {
						return $this->schemaIds;
					}
				};
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($schemaMapper, $registerMapper): object {
				if ($id === 'OCA\OpenRegister\Db\RegisterMapper') {
					return $registerMapper;
				}

				return $schemaMapper;
			}
		);

		$resolver = new SchemaSlugResolver($appConfig, $container, new NullLogger());

		return new SchemaKeyReconciler($appConfig, $container, new NullLogger(), $resolver);
	}

	/**
	 * An IAppConfig that records every write, so a test can assert on what the
	 * reconciler DECIDED rather than on how many times it was called.
	 *
	 * Mocked from the interface rather than hand-rolled: a hand-rolled double
	 * would have to declare all of IAppConfig, and a typo in a method name
	 * would silently never be called instead of failing.
	 *
	 * @param array<string, string> $seed The values already stored.
	 *
	 * @return IAppConfig The spy, whose writes land in {@see self::$written}.
	 */
	private function appConfigSpy(array $seed): IAppConfig {
		$this->stored = $seed;
		$this->written = [];

		$appConfig = $this->createMock(IAppConfig::class);

		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '', bool $lazy = false): string {
				return ($this->stored[$key] ?? $default);
			}
		);

		$appConfig->method('setValueString')->willReturnCallback(
			function (
				string $app,
				string $key,
				string $value,
				bool $lazy = false,
				bool $sensitive = false,
			): bool {
				$this->written[$key] = $value;
				$this->stored[$key] = $value;
				return true;
			}
		);

		return $appConfig;
	}
}
