<?php

/**
 * Dossiq Schema Slug Resolver Test
 *
 * Pins the one rule both schema reconcilers depend on: a slug resolves to the
 * schema DOSSIQ'S OWN REGISTER references, and only falls back to the
 * instance-wide lookup when our register carries no schema with that slug.
 *
 * Measured on a normal dev instance: three schemas carried the slug `task`
 * (ids 52, 146 and 173) and only 173 belonged to Dossiq's register. The
 * unscoped lookup returned 52, an InterneTaak schema owned by another app.
 * That single wrong answer caused two separate, silent failures, which is why
 * the rule is tested here once rather than at each call site.
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

use OCA\Dossiq\Service\Settings\SchemaSlugResolver;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Register-scoped schema slug resolution.
 */
final class SchemaSlugResolverTest extends TestCase {
	/**
	 * Dossiq's own register carries `task` as schema 173, so that is the answer
	 * even though the instance-wide lookup offers the foreign row 52 first.
	 *
	 * @return void
	 */
	public function testResolvesInsideOurOwnRegister(): void {
		$resolver = $this->resolver(
			registerId: '23',
			registerSchemaIds: [165, 166, 172, 173],
			globalBySlug: ['caseTask' => 52],
			scopedBySlug: ['caseTask' => 173],
		);

		$schema = $resolver->resolve($this->schemaMapper, 'caseTask');

		$this->assertNotNull($schema, 'The slug must resolve.');
		$this->assertSame(
			173,
			$schema->getId(),
			'A slug our register carries must resolve to our schema, not the first row instance-wide.'
		);
	}

	/**
	 * A slug our register does not carry still resolves instance-wide, so the
	 * deliberately shared schemas keep working.
	 *
	 * @return void
	 */
	public function testFallsBackForSlugsOwnedByOtherApps(): void {
		$resolver = $this->resolver(
			registerId: '23',
			registerSchemaIds: [165, 166, 172, 173],
			globalBySlug: ['appointment' => 548],
			scopedBySlug: [],
		);

		$schema = $resolver->resolve($this->schemaMapper, 'appointment');

		$this->assertNotNull($schema, 'A slug owned by another app must still resolve.');
		$this->assertSame(548, $schema->getId());
	}

	/**
	 * A slug nothing carries resolves to null rather than throwing.
	 *
	 * @return void
	 */
	public function testUnknownSlugResolvesToNull(): void {
		$resolver = $this->resolver(
			registerId: '23',
			registerSchemaIds: [173],
			globalBySlug: [],
			scopedBySlug: [],
		);

		$this->assertNull($resolver->resolve($this->schemaMapper, 'nothing-carries-this'));
	}

	/**
	 * With no register configured there is nothing to scope to, so the
	 * instance-wide answer stands.
	 *
	 * @return void
	 */
	public function testUnconfiguredRegisterKeepsTheUnscopedAnswer(): void {
		$resolver = $this->resolver(
			registerId: '',
			registerSchemaIds: [],
			globalBySlug: ['caseTask' => 52],
			scopedBySlug: ['caseTask' => 173],
		);

		$schema = $resolver->resolve($this->schemaMapper, 'caseTask');

		$this->assertNotNull($schema);
		$this->assertSame(52, $schema->getId());
	}

	/**
	 * The fake SchemaMapper handed to the resolver under test.
	 *
	 * @var object
	 */
	private object $schemaMapper;

	/**
	 * Build a resolver against fake OpenRegister mappers.
	 *
	 * @param string $registerId The configured register id, '' when unset.
	 * @param int[] $registerSchemaIds The ids the fake register references.
	 * @param array<string, int> $globalBySlug Instance-wide slug to id answers.
	 * @param array<string, int> $scopedBySlug Register-scoped slug to id answers.
	 *
	 * @return SchemaSlugResolver The resolver under test.
	 */
	private function resolver(
		string $registerId,
		array $registerSchemaIds,
		array $globalBySlug,
		array $scopedBySlug,
	): SchemaSlugResolver {
		$this->schemaMapper = new class($globalBySlug, $scopedBySlug) {
			/**
			 * @param array<string, int> $global Instance-wide answers.
			 * @param array<string, int> $scoped Register-scoped answers.
			 */
			public function __construct(private array $global, private array $scoped) {
			}

			/**
			 * Instance-wide slug lookup, mirroring SchemaMapper::find().
			 *
			 * @param string $id The slug.
			 * @param array $extend Unused.
			 * @param boolean $rbac Unused.
			 * @param boolean $multitenancy Unused.
			 *
			 * @return object The matching schema.
			 *
			 * @throws \RuntimeException When the slug is unknown, as the real mapper does.
			 */
			public function find(string $id, array $extend = [], bool $rbac = true, bool $multitenancy = true): object {
				if (isset($this->global[$id]) === false) {
					throw new \RuntimeException('no such schema: ' . $id);
				}

				return self::schema($this->global[$id]);
			}

			/**
			 * Register-scoped lookup, mirroring SchemaMapper::findBySlugInIds().
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
			 * Wrap an id in the getId() shape the resolver's callers read.
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

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '', bool $lazy = false) use ($registerId): string {
				if ($key === 'register') {
					return $registerId;
				}

				return $default;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($registerMapper);

		return new SchemaSlugResolver($appConfig, $container, new NullLogger());
	}
}
