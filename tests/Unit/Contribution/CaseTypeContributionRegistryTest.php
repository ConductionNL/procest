<?php

/**
 * Unit tests for the case-type contribution registry.
 *
 * Discovery is duck-typed by convention FQCN, so nothing here is enforced by
 * the compiler. These tests are the enforcement, and they cover the failures
 * that would otherwise be SILENT:
 *
 *   - a provider that throws must not cost every other app its case types;
 *   - a declaration with no identifier or title is skipped, not stored
 *     half-formed, because a case pointing at an identifier-less type has
 *     nothing to resolve;
 *   - `contributedBy` is stamped by the registry and never taken from the
 *     provider, or an app could claim a case type it does not own.
 *
 * 🔴 THE DOUBLES ARE DECLARED AT THE REAL CONVENTION FQCNs.
 * The registry resolves `OCA\{App}\Dossiq\CaseTypeContributionProvider` through
 * class_exists(), so a double under any other name is never found and every
 * test passes while exercising nothing. Naming them properly means these tests
 * run the actual resolution path.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Contribution
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://dossiq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Goodapp\Dossiq;

/**
 * A contributing app that declares one usable case type.
 */
class CaseTypeContributionProvider {

	/**
	 * Declare one case type.
	 *
	 * @return array<int,array<string,mixed>> The declarations.
	 */
	public function getCaseTypes(): array {
		return [
			[
				'identifier' => 'good-type',
				'title' => 'Good type',
				// Deliberately claims another app as the owner. The registry
				// must stamp over this rather than trust it.
				'contributedBy' => 'someoneelse',
			],
		];
	}//end getCaseTypes()
}//end class

namespace OCA\Throwingapp\Dossiq;

use RuntimeException;

/**
 * A contributing app whose provider explodes.
 */
class CaseTypeContributionProvider {

	/**
	 * Fail.
	 *
	 * @return array<int,array<string,mixed>> Never returns.
	 */
	public function getCaseTypes(): array {
		throw new RuntimeException('provider exploded');
	}//end getCaseTypes()
}//end class

namespace OCA\Malformedapp\Dossiq;

/**
 * A contributing app mixing unusable declarations with a usable one.
 */
class CaseTypeContributionProvider {

	/**
	 * Declare two unusable case types and one usable one.
	 *
	 * @return array<int,array<string,mixed>> The declarations.
	 */
	public function getCaseTypes(): array {
		return [
			['title' => 'No identifier'],
			['identifier' => 'no-title'],
			[
				'identifier' => 'usable',
				'title' => 'Usable',
			],
		];
	}//end getCaseTypes()
}//end class

namespace OCA\Silentapp\Dossiq;

/**
 * A class at the convention name that does not answer the probe.
 */
class CaseTypeContributionProvider {

	/**
	 * Not the method the registry looks for.
	 *
	 * @return array<int,mixed> Nothing useful.
	 */
	public function getTypes(): array {
		return [['identifier' => 'never', 'title' => 'Never']];
	}//end getTypes()
}//end class

namespace OCA\Dossiq\Tests\Unit\Contribution;

use OCA\Dossiq\Contribution\CaseTypeContributionRegistry;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for CaseTypeContributionRegistry.
 */
class CaseTypeContributionRegistryTest extends TestCase {

	/**
	 * Build a registry over the given installed apps.
	 *
	 * @param array<int,string> $apps Installed app ids.
	 *
	 * @return CaseTypeContributionRegistry The registry under test.
	 */
	private function makeRegistry(array $apps): CaseTypeContributionRegistry {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn($apps);

		// Mirrors the server container: constructs any autoloadable class by
		// reflection, and throws for anything else.
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id): object {
				if (class_exists($id) === false) {
					throw new RuntimeException('not found');
				}

				return new $id();
			}
		);

		return new CaseTypeContributionRegistry(
			$appManager,
			$container,
			$this->createMock(LoggerInterface::class),
		);
	}//end makeRegistry()

	/**
	 * A usable declaration is collected.
	 *
	 * @return void
	 */
	public function testCollectsAContributedCaseType(): void {
		$types = $this->makeRegistry(['goodapp'])->all();

		$this->assertCount(1, $types);
		$this->assertSame('good-type', $types[0]['identifier']);
	}//end testCollectsAContributedCaseType()

	/**
	 * The registry stamps the contributor and ignores what the provider claims.
	 *
	 * @return void
	 */
	public function testStampsTheRealContributor(): void {
		$types = $this->makeRegistry(['goodapp'])->all();

		$this->assertSame(
			'goodapp',
			$types[0]['contributedBy'],
			'a provider must not be able to attribute its case type to another app'
		);
	}//end testStampsTheRealContributor()

	/**
	 * A throwing provider is skipped and its neighbours still contribute.
	 *
	 * @return void
	 */
	public function testAThrowingProviderDoesNotCostOthersTheirCaseTypes(): void {
		$types = $this->makeRegistry(['throwingapp', 'goodapp'])->all();

		$this->assertCount(1, $types);
		$this->assertSame('good-type', $types[0]['identifier']);
	}//end testAThrowingProviderDoesNotCostOthersTheirCaseTypes()

	/**
	 * Unusable declarations are dropped and the usable sibling survives.
	 *
	 * @return void
	 */
	public function testDropsDeclarationsWithNoIdentifierOrTitle(): void {
		$types = $this->makeRegistry(['malformedapp'])->all();

		$this->assertCount(1, $types);
		$this->assertSame('usable', $types[0]['identifier']);
	}//end testDropsDeclarationsWithNoIdentifierOrTitle()

	/**
	 * A class at the convention name that lacks the probed method is ignored.
	 *
	 * @return void
	 */
	public function testIgnoresAClassThatDoesNotAnswerTheProbe(): void {
		$this->assertSame([], $this->makeRegistry(['silentapp'])->all());
	}//end testIgnoresAClassThatDoesNotAnswerTheProbe()

	/**
	 * An app with no provider class contributes nothing and does not error.
	 *
	 * @return void
	 */
	public function testAnAppWithoutAProviderIsIgnored(): void {
		$this->assertSame([], $this->makeRegistry(['appwithnothing'])->all());
	}//end testAnAppWithoutAProviderIsIgnored()

	/**
	 * find() resolves a contributed identifier and misses cleanly.
	 *
	 * @return void
	 */
	public function testFindResolvesByIdentifier(): void {
		$registry = $this->makeRegistry(['goodapp']);

		$this->assertSame('good-type', ($registry->find('good-type') ?? [])['identifier'] ?? null);
		$this->assertNull($registry->find('nope'));
	}//end testFindResolvesByIdentifier()
}//end class
