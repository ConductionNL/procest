<?php

/**
 * The Besluit schema resolves to decidiq's Decision when this app has none.
 *
 * @category  Tests
 * @package   OCA\Dossiq\Tests\Unit\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/the-besluit-resolves-to-decidiqs-decision/specs/zgw-brc/spec.md#requirement-the-besluit-resolves-to-decidiqs-decision-req-brc-020
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Locks the ORDER, which is the whole change.
 *
 * decidiq's Decision resolves LAST, only when this app has no `decision_schema`
 * of its own. Preferring it unconditionally was the obvious shape and the wrong
 * one: every existing instance HAS that key, and its besluiten are in the schema
 * the key names. Pointing them at decidiq's empty schema would have made the BRC
 * answer 404 for every besluit it holds, with nothing saying why.
 *
 * So the two tests that matter are the same lookup with and without a local
 * value. That is the only pair that can tell "resolves last" from "resolves at
 * all".
 */
final class BesluitResolvesToDecidiqTest extends TestCase {

	/**
	 * Build a SettingsService over the given local value and peer state.
	 *
	 * @param string   $localValue  The stored `decision_schema`, or '' for none.
	 * @param int|null $decidiqId   The id decidiq's schema resolves to, or null
	 *                              for a mapper that finds nothing.
	 * @param bool     $decidiq     Whether decidiq is installed.
	 *
	 * @return SettingsService The service under test.
	 */
	private function service(string $localValue, ?int $decidiqId, bool $decidiq = true): SettingsService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($localValue): string {
				return ($key === 'decision_schema' ? $localValue : $default);
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($decidiq);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($decidiqId): object {
				if ($id !== 'OCA\OpenRegister\Db\SchemaMapper') {
					throw new \RuntimeException('unexpected service '.$id);
				}

				$schema = ($decidiqId === null ? null : new class($decidiqId) {

					/**
					 * @param int $id The schema id.
					 */
					public function __construct(private int $id) {
					}

					/**
					 * @return int The schema id.
					 */
					public function getId(): int {
						return $this->id;
					}
				});

				return new class($schema) {

					/**
					 * @param object|null $schema The schema to answer with.
					 */
					public function __construct(private ?object $schema) {
					}

					/**
					 * @param string $slug        The slug looked up.
					 * @param string $application The owning application.
					 *
					 * @return object|null The schema, or null.
					 */
					public function findByApplicationAndSlug(string $slug, string $application): ?object {
						// The PAIR is the point: slug alone would match this
						// app's own row as readily as decidiq's.
						if ($slug !== 'decision' || $application !== 'decidiq') {
							return null;
						}

						return $this->schema;
					}
				};
			}
		);

		return new SettingsService(
			$appConfig,
			$appManager,
			$container,
			$this->createMock(LoggerInterface::class)
		);

	}//end service()

	/**
	 * An instance that already has a `decision_schema` keeps it. Its besluiten
	 * are in that schema, and decidiq's is empty for them.
	 *
	 * @return void
	 */
	public function testALocalValueWinsOverDecidiq(): void {
		$this->assertSame(
			'166',
			$this->service('166', 339)->getConfigValue('decision_schema'),
			'the local schema must win while it is configured'
		);

	}//end testALocalValueWinsOverDecidiq()

	/**
	 * With nothing local — a fresh install, or one whose own schema has been
	 * retired — the lookup lands on decidiq's.
	 *
	 * @return void
	 */
	public function testWithNothingLocalItResolvesToDecidiq(): void {
		$this->assertSame(
			'339',
			$this->service('', 339)->getConfigValue('decision_schema'),
			'with nothing local, decidiq answers'
		);

	}//end testWithNothingLocalItResolvesToDecidiq()

	/**
	 * Without decidiq the peer is not even asked for. The container double
	 * throws on any other service id, so a lookup that reached it would surface
	 * as an error rather than as this assertion.
	 *
	 * @return void
	 */
	public function testWithoutDecidiqItResolvesToEmpty(): void {
		$this->assertSame(
			'',
			$this->service('', 339, decidiq: false)->getConfigValue('decision_schema'),
			'no decidiq, no fallback'
		);

	}//end testWithoutDecidiqItResolvesToEmpty()

	/**
	 * A decidiq too old to carry the schema resolves to '' rather than to a
	 * fabricated id, so the caller behaves as it did when the key was unset.
	 *
	 * @return void
	 */
	public function testAMissingPeerSchemaResolvesToEmpty(): void {
		$this->assertSame(
			'',
			$this->service('', null)->getConfigValue('decision_schema'),
			'a missing peer schema resolves to empty'
		);

	}//end testAMissingPeerSchemaResolvesToEmpty()

	/**
	 * Every OTHER config key is untouched, including the ones whose names
	 * contain `decision`. Without this, "the fallback works" and "the fallback
	 * fires on anything decision-shaped" look identical.
	 *
	 * @return void
	 */
	public function testOtherKeysAreUnaffected(): void {
		$service = $this->service('', 339);

		foreach (['decision_type_schema', 'decision_document_schema', 'case_decision_schema', 'case_schema'] as $key) {
			$this->assertSame(
				'',
				$service->getConfigValue($key),
				$key.' must not pick up the Besluit fallback'
			);
		}

	}//end testOtherKeysAreUnaffected()

	/**
	 * An explicit default is still honoured for other keys.
	 *
	 * @return void
	 */
	public function testAnExplicitDefaultStillWorks(): void {
		$this->assertSame(
			'fallback',
			$this->service('', 339)->getConfigValue('case_schema', 'fallback')
		);

	}//end testAnExplicitDefaultStillWorks()

}//end class
