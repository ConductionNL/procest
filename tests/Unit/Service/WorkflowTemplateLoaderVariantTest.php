<?php

/**
 * WorkflowTemplateLoader route-resolution tests.
 *
 * 🔴 THIS CLASS TOOK `$templates[0]` AND CALLED IT THE ANSWER.
 * The loader searched `caseType = X AND isActive = true` and used the first row
 * the store returned. With exactly one active definition per case type that was
 * right by accident. A case type may now carry several ROUTES, each with its own
 * active definition, and under that rule the first row is a coin flip: a case on
 * the spoedeisende route would be offered the ordinary route's transitions, with
 * no error and nothing in the log.
 *
 * These tests pin the four branches of the resolution, and the warning that
 * fires on the only one of them that is a misconfiguration.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Workflow\WorkflowDefinitionRepository;
use OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard;
use OCA\Dossiq\Service\WorkflowTemplateLoader;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The OpenRegister ObjectService shape this loader searches through.
 *
 * Reads by uuid go through the definition repository instead, which is mocked
 * directly, so `searchObjects()` is all this fake owes.
 */
interface RoutingObjectServiceStub {
	public function searchObjects(array $query): array;
}//end interface

/**
 * Which workflow a case runs on.
 *
 * @covers \OCA\Dossiq\Service\WorkflowTemplateLoader
 * @uses \OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard
 */
class WorkflowTemplateLoaderVariantTest extends TestCase {

	/**
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The two active definitions on one case type, in the order the store
	 * happens to return them. `spoedeisend` first, deliberately: if resolution
	 * silently takes the first row, every test below that expects `regulier`
	 * goes red rather than passing by luck.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const TWO_ROUTES = [
		[
			'id' => 'spoed-1',
			'caseType' => 'ct-handhaving',
			'variant' => 'spoedeisend',
			'version' => 1,
			'transitions' => '[{"id":"spoed-t1"}]',
			'steps' => '[]',
		],
		[
			'id' => 'reg-1',
			'caseType' => 'ct-handhaving',
			'variant' => 'regulier',
			'version' => 1,
			'transitions' => '[{"id":"reg-t1"}]',
			'steps' => '[]',
		],
	];

	/**
	 * Wire the settings service both schema contexts resolve through.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => '7',
				'workflow_template_schema' => '42',
				'case_type_schema' => '22',
				default => '',
			}
		);
	}//end setUp()

	/**
	 * A loader over one set of rows, with the real route resolver behind it.
	 *
	 * The guard is real rather than mocked: the resolution rule under test is
	 * its `defaultAmong()`, and a mock of it would only assert that this test
	 * agrees with itself.
	 *
	 * @param array<string, array<string, mixed>> $byId Definitions and case types, keyed by uuid.
	 * @param array<int, array<string, mixed>>|null $active The active definitions, or the two routes.
	 *
	 * @return WorkflowTemplateLoader The loader under test.
	 */
	private function loader(array $byId, ?array $active = null): WorkflowTemplateLoader {
		$objectService = $this->createMock(RoutingObjectServiceStub::class);
		$objectService->method('searchObjects')->willReturn($active ?? self::TWO_ROUTES);
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$repository = $this->createMock(WorkflowDefinitionRepository::class);
		$repository->method('findById')->willReturnCallback(
			static fn (string $id): ?array => ($byId[$id] ?? null)
		);
		$repository->method('findCaseType')->willReturnCallback(
			static fn (string $caseTypeId): ?array => ($byId[$caseTypeId] ?? null)
		);

		return new WorkflowTemplateLoader(
			$this->settingsService,
			$repository,
			new WorkflowLifecycleGuard($repository, $this->logger),
			$this->logger
		);
	}//end loader()

	/**
	 * 🔴 A PINNED CASE RUNS ITS OWN ROUTE.
	 *
	 * This is the assertion the whole change rests on. Remove the pin lookup
	 * from getTemplateForCase() and this goes red, because the case type's
	 * default route is the ordinary one.
	 *
	 * @return void
	 */
	public function testAPinnedCaseRunsItsOwnRouteAndNotTheDefault(): void {
		$loader = $this->loader(
			[
				'spoed-1' => self::TWO_ROUTES[0],
				'ct-handhaving' => ['id' => 'ct-handhaving', 'workflowDefinition' => 'reg-1'],
			]
		);

		$template = $loader->getTemplateForCase(
			case: ['id' => 'case-1', 'caseType' => 'ct-handhaving', 'workflowTemplate' => 'spoed-1']
		);

		self::assertIsArray($template);
		self::assertSame('spoed-1', $template['id']);
		self::assertSame([['id' => 'spoed-t1']], $template['transitions']);
	}//end testAPinnedCaseRunsItsOwnRouteAndNotTheDefault()

	/**
	 * An unpinned case follows the route the case type records as its default,
	 * not whichever active definition the store lists first.
	 *
	 * @return void
	 */
	public function testAnUnpinnedCaseFollowsTheCaseTypeDefaultRoute(): void {
		$loader = $this->loader(
			['ct-handhaving' => ['id' => 'ct-handhaving', 'workflowDefinition' => 'reg-1']]
		);

		$template = $loader->getTemplateForCase(case: ['id' => 'case-2', 'caseType' => 'ct-handhaving']);

		self::assertIsArray($template);
		self::assertSame('reg-1', $template['id']);
	}//end testAnUnpinnedCaseFollowsTheCaseTypeDefaultRoute()

	/**
	 * A case type recording its default as an expanded object resolves the same
	 * as one recording a plain uuid. OpenRegister answers a reference either way.
	 *
	 * @return void
	 */
	public function testAnExpandedDefaultReferenceResolvesTheSame(): void {
		$loader = $this->loader(
			[
				'ct-handhaving' => [
					'id' => 'ct-handhaving',
					'workflowDefinition' => ['id' => 'reg-1', 'title' => 'Handhavingstraject'],
				],
			]
		);

		$template = $loader->getTemplateForCase(case: ['id' => 'case-3', 'caseType' => 'ct-handhaving']);

		self::assertIsArray($template);
		self::assertSame('reg-1', $template['id']);
	}//end testAnExpandedDefaultReferenceResolvesTheSame()

	/**
	 * 🔴 AN AMBIGUOUS CASE TYPE ANSWERS THE SAME WAY TWICE, AND SAYS SO.
	 *
	 * No usable default and several active definitions is a misconfiguration.
	 * The loader's job there is to be reproducible and loud, not to be silently
	 * random. Ordered by route slug, so `regulier` beats `spoedeisend`.
	 *
	 * @return void
	 */
	public function testAnAmbiguousCaseTypeResolvesDeterministicallyAndWarns(): void {
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('no usable default route'),
				$this->callback(
					static fn (array $context): bool => ($context['chose'] ?? '') === 'reg-1'
						&& ($context['routes'] ?? []) === ['regulier', 'spoedeisend']
				)
			);

		$loader = $this->loader(['ct-handhaving' => ['id' => 'ct-handhaving']]);

		$first = $loader->getTemplateForCase(case: ['caseType' => 'ct-handhaving']);
		$second = $loader->getTemplateForCase(case: ['caseType' => 'ct-handhaving']);

		self::assertIsArray($first);
		self::assertSame('reg-1', $first['id']);
		self::assertSame($first, $second);
	}//end testAnAmbiguousCaseTypeResolvesDeterministicallyAndWarns()

	/**
	 * One active definition needs no default and raises no warning. This is
	 * every case type on every instance that carries a single route, which is
	 * every instance until this change ships.
	 *
	 * @return void
	 */
	public function testASingleActiveDefinitionNeedsNoDefaultAndIsSilent(): void {
		$this->logger->expects($this->never())->method('warning');

		$loader = $this->loader([], [self::TWO_ROUTES[1]]);

		$template = $loader->getTemplateForCase(case: ['caseType' => 'ct-handhaving']);

		self::assertIsArray($template);
		self::assertSame('reg-1', $template['id']);
	}//end testASingleActiveDefinitionNeedsNoDefaultAndIsSilent()

	/**
	 * A pin that resolves to nothing falls back to the default route, and says
	 * that it could not read what the case named. Falling back silently is how a
	 * deleted definition becomes an unexplained change of behaviour.
	 *
	 * @return void
	 */
	public function testAPinThatResolvesToNothingFallsBackAndSaysSo(): void {
		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('names a workflow definition that could not be read'));

		$loader = $this->loader(
			['ct-handhaving' => ['id' => 'ct-handhaving', 'workflowDefinition' => 'reg-1']]
		);

		$template = $loader->getTemplateForCase(
			case: ['caseType' => 'ct-handhaving', 'workflowTemplate' => 'deleted-1']
		);

		self::assertIsArray($template);
		self::assertSame('reg-1', $template['id']);
	}//end testAPinThatResolvesToNothingFallsBackAndSaysSo()

	/**
	 * A transition is looked up inside the route the case runs on, so two routes
	 * may reuse a transition id without answering for each other.
	 *
	 * @return void
	 */
	public function testATransitionIsLookedUpInsideTheRouteTheCaseRuns(): void {
		$loader = $this->loader(
			[
				'spoed-1' => self::TWO_ROUTES[0],
				'ct-handhaving' => ['id' => 'ct-handhaving', 'workflowDefinition' => 'reg-1'],
			]
		);

		$case = ['caseType' => 'ct-handhaving', 'workflowTemplate' => 'spoed-1'];

		self::assertSame(
			['id' => 'spoed-t1'],
			$loader->getTransitionForCase(case: $case, transitionId: 'spoed-t1')
		);
		self::assertNull($loader->getTransitionForCase(case: $case, transitionId: 'reg-t1'));
	}//end testATransitionIsLookedUpInsideTheRouteTheCaseRuns()
}//end class
