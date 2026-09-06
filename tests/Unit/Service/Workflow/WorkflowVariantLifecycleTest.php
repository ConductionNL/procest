<?php

/**
 * Per-route publishing.
 *
 * 🔴 PUBLISHING USED TO DEPRECATE WHATEVER THE CASE TYPE HAD, AND TO TAKE THE
 * CASE TYPE'S PIN EVERY TIME. That is why `handhavingszaak` could only ever
 * carry one of its two enforcement workflows: whichever the seeder reached last
 * retired the other and became the workflow every new case followed.
 *
 * The rule is now one published definition per (case type, ROUTE). These tests
 * pin both halves of it: what a publish retires, and when a publish is entitled
 * to the case type's default route.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Workflow
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Workflow;

use OCA\Dossiq\Service\Workflow\TransitionAuthorizationStamper;
use OCA\Dossiq\Service\Workflow\WorkflowDefinitionRepository;
use OCA\Dossiq\Service\Workflow\WorkflowJsonProperty;
use OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard;
use OCA\Dossiq\Service\WorkflowDefinitionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What a publish retires, and which route it takes.
 *
 * @covers \OCA\Dossiq\Service\WorkflowDefinitionService
 * @covers \OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard
 * @uses \OCA\Dossiq\Service\Workflow\WorkflowJsonProperty
 */
class WorkflowVariantLifecycleTest extends TestCase {

	/**
	 * @var WorkflowDefinitionRepository&MockObject
	 */
	private WorkflowDefinitionRepository $repository;

	/**
	 * The rows the fake repository holds, by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * The saves the service performed, as [uuid, payload] pairs.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $saves = [];

	/**
	 * The definition ids pinned as a case type's default route, in order.
	 *
	 * @var array<int, string>
	 */
	private array $pins = [];

	/**
	 * The case type row the repository answers with.
	 *
	 * @var array<string, mixed>
	 */
	private array $caseType = ['id' => 'ct-handhaving'];

	/**
	 * The service under test.
	 *
	 * @var WorkflowDefinitionService
	 */
	private WorkflowDefinitionService $service;

	/**
	 * Wire a repository that stores rows in memory and records every write.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->repository = $this->createMock(WorkflowDefinitionRepository::class);

		$this->repository->method('findById')->willReturnCallback(
			fn (string $id): ?array => ($this->rows[$id] ?? null)
		);
		$this->repository->method('findCaseType')->willReturnCallback(
			fn (string $caseTypeId): ?array => (($this->caseType['id'] ?? '') === $caseTypeId) ? $this->caseType : null
		);
		$this->repository->method('listVersionsForCaseType')->willReturnCallback(
			fn (string $caseTypeId): array => array_values(
				array_filter(
					$this->rows,
					static fn (array $row): bool => (string)($row['caseType'] ?? '') === $caseTypeId
				)
			)
		);
		$this->repository->method('isConfiguredFor')->willReturn(true);
		$this->repository->method('nextVersionFor')->willReturn(2);
		$this->repository->method('save')->willReturnCallback(
			function (array $payload, ?string $uuid = null): array {
				$uuid = ($uuid ?? 'new-row');
				$this->saves[] = [$uuid, $payload];
				$this->rows[$uuid] = array_merge(($this->rows[$uuid] ?? ['id' => $uuid]), $payload);
				return $this->rows[$uuid];
			}
		);
		$this->repository->method('pinWorkflowDefinition')->willReturnCallback(
			function (string $caseTypeId, string $definitionId): void {
				$this->pins[] = $definitionId;
				$this->caseType['workflowDefinition'] = $definitionId;
			}
		);

		$stamper = $this->createMock(TransitionAuthorizationStamper::class);
		$stamper->method('stamp')->willReturn(null);

		$logger = $this->createMock(LoggerInterface::class);

		$this->service = new WorkflowDefinitionService(
			$this->repository,
			new WorkflowLifecycleGuard($this->repository, $logger),
			$stamper,
			new WorkflowJsonProperty(),
			$logger
		);
	}//end setUp()

	/**
	 * One definition row.
	 *
	 * @param string $id The uuid.
	 * @param string $variant The route, or '' for a row predating routes.
	 * @param string $lifecycleStatus draft|published|deprecated.
	 * @param bool $isActive Whether it backs new cases.
	 * @param int $version The version number.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row(
		string $id,
		string $variant,
		string $lifecycleStatus,
		bool $isActive,
		int $version = 1,
	): array {
		$row = [
			'id' => $id,
			'title' => $id,
			'caseType' => 'ct-handhaving',
			'lifecycleStatus' => $lifecycleStatus,
			'isActive' => $isActive,
			'version' => $version,
			'transitions' => '[]',
			'steps' => '[]',
		];

		if ($variant !== '') {
			$row['variant'] = $variant;
		}

		return $row;
	}//end row()

	/**
	 * The uuids this run moved to deprecated.
	 *
	 * @return array<int, string> The uuids, in order.
	 */
	private function deprecated(): array {
		$deprecated = [];
		foreach ($this->saves as [$uuid, $payload]) {
			if (($payload['lifecycleStatus'] ?? '') === WorkflowDefinitionService::STATUS_DEPRECATED) {
				$deprecated[] = $uuid;
			}
		}

		return $deprecated;
	}//end deprecated()

	/**
	 * 🔴 PUBLISHING A SECOND ROUTE RETIRES NOTHING, AND DOES NOT TAKE THE
	 * DEFAULT. This is the whole change in one assertion. Scope the deprecation
	 * back to the case type and the first line goes red; pin unconditionally
	 * again and the second one does.
	 *
	 * @return void
	 */
	public function testPublishingASecondRouteRetiresNothingAndKeepsTheDefault(): void {
		$this->rows = [
			'reg-1' => $this->row('reg-1', 'regulier', WorkflowDefinitionService::STATUS_PUBLISHED, true),
			'spoed-1' => $this->row('spoed-1', 'spoedeisend', WorkflowDefinitionService::STATUS_DRAFT, false),
		];
		$this->caseType['workflowDefinition'] = 'reg-1';

		$published = $this->service->publish(id: 'spoed-1');

		self::assertIsArray($published);
		self::assertSame([], $this->deprecated());
		self::assertSame([], $this->pins);
		self::assertSame('reg-1', $this->caseType['workflowDefinition']);
		self::assertCount(2, $this->service->listActiveDefinitionsFor(caseTypeId: 'ct-handhaving'));
	}//end testPublishingASecondRouteRetiresNothingAndKeepsTheDefault()

	/**
	 * A new version of a route retires the previous version of that route, and
	 * of no other.
	 *
	 * @return void
	 */
	public function testANewVersionRetiresOnlyItsOwnRoute(): void {
		$this->rows = [
			'reg-1' => $this->row('reg-1', 'regulier', WorkflowDefinitionService::STATUS_PUBLISHED, true),
			'spoed-1' => $this->row('spoed-1', 'spoedeisend', WorkflowDefinitionService::STATUS_PUBLISHED, true),
			'reg-2' => $this->row('reg-2', 'regulier', WorkflowDefinitionService::STATUS_DRAFT, false, 2),
		];
		$this->caseType['workflowDefinition'] = 'reg-1';

		$this->service->publish(id: 'reg-2');

		self::assertSame(['reg-1'], $this->deprecated());
		self::assertSame(['reg-2'], $this->pins);
		self::assertSame(
			WorkflowDefinitionService::STATUS_PUBLISHED,
			$this->rows['spoed-1']['lifecycleStatus']
		);
	}//end testANewVersionRetiresOnlyItsOwnRoute()

	/**
	 * A case type whose definitions name no route behaves exactly as it did
	 * before routes existed: one active definition, and the previous one
	 * retired on publish.
	 *
	 * @return void
	 */
	public function testOneRoutePerCaseTypeBehavesAsBefore(): void {
		$this->rows = [
			'v1' => $this->row('v1', '', WorkflowDefinitionService::STATUS_PUBLISHED, true),
			'v2' => $this->row('v2', '', WorkflowDefinitionService::STATUS_DRAFT, false, 2),
		];
		$this->caseType['workflowDefinition'] = 'v1';

		$this->service->publish(id: 'v2');

		self::assertSame(['v1'], $this->deprecated());
		self::assertSame(['v2'], $this->pins);
		self::assertCount(1, $this->service->listActiveDefinitionsFor(caseTypeId: 'ct-handhaving'));
	}//end testOneRoutePerCaseTypeBehavesAsBefore()

	/**
	 * The first route published for a case type becomes its default.
	 *
	 * @return void
	 */
	public function testTheFirstPublishedRouteBecomesTheDefault(): void {
		$this->rows = [
			'reg-1' => $this->row('reg-1', 'regulier', WorkflowDefinitionService::STATUS_DRAFT, false),
		];

		$this->service->publish(id: 'reg-1');

		self::assertSame(['reg-1'], $this->pins);
	}//end testTheFirstPublishedRouteBecomesTheDefault()

	/**
	 * A default that no longer resolves is repaired by the next publish rather
	 * than leaving the case type pointing at nothing.
	 *
	 * @return void
	 */
	public function testAnUnresolvableDefaultIsRepairedByThePublish(): void {
		$this->rows = [
			'spoed-1' => $this->row('spoed-1', 'spoedeisend', WorkflowDefinitionService::STATUS_DRAFT, false),
		];
		$this->caseType['workflowDefinition'] = 'deleted-1';

		$this->service->publish(id: 'spoed-1');

		self::assertSame(['spoed-1'], $this->pins);
	}//end testAnUnresolvableDefaultIsRepairedByThePublish()

	/**
	 * Asking for the active definition without naming a route answers with the
	 * default route, so every caller written before routes existed keeps reading
	 * what it read before. Naming a route answers for that route.
	 *
	 * @return void
	 */
	public function testTheUnqualifiedLookupAnswersWithTheDefaultRoute(): void {
		$this->rows = [
			'spoed-1' => $this->row('spoed-1', 'spoedeisend', WorkflowDefinitionService::STATUS_PUBLISHED, true),
			'reg-1' => $this->row('reg-1', 'regulier', WorkflowDefinitionService::STATUS_PUBLISHED, true),
		];
		$this->caseType['workflowDefinition'] = 'reg-1';

		self::assertSame(
			'reg-1',
			(string)($this->service->getActiveDefinitionFor(caseTypeId: 'ct-handhaving')['id'] ?? '')
		);
		self::assertSame(
			'spoed-1',
			(string)($this->service->getActiveDefinitionFor(
				caseTypeId: 'ct-handhaving',
				variant: 'spoedeisend'
			)['id'] ?? '')
		);
		self::assertNull(
			$this->service->getActiveDefinitionFor(caseTypeId: 'ct-handhaving', variant: 'onbekend')
		);
	}//end testTheUnqualifiedLookupAnswersWithTheDefaultRoute()

	/**
	 * A definition that names no route is on `standaard`, and asking for
	 * `standaard` finds it. No write makes that true.
	 *
	 * @return void
	 */
	public function testADefinitionWithNoRouteIsOnTheDefaultRouteName(): void {
		$this->rows = [
			'v1' => $this->row('v1', '', WorkflowDefinitionService::STATUS_PUBLISHED, true),
		];

		self::assertArrayNotHasKey('variant', $this->rows['v1']);
		self::assertSame(
			'v1',
			(string)($this->service->getActiveDefinitionFor(
				caseTypeId: 'ct-handhaving',
				variant: WorkflowDefinitionService::VARIANT_DEFAULT
			)['id'] ?? '')
		);
	}//end testADefinitionWithNoRouteIsOnTheDefaultRouteName()

	/**
	 * A clone stays on its source's route. Without this a clone of the
	 * spoedeisende route would be a draft of the ordinary one, and publishing it
	 * would retire the ordinary route.
	 *
	 * @return void
	 */
	public function testACloneStaysOnItsOwnRoute(): void {
		$this->rows = [
			'spoed-1' => $this->row('spoed-1', 'spoedeisend', WorkflowDefinitionService::STATUS_PUBLISHED, true),
		];

		$draft = $this->service->cloneDefinition(id: 'spoed-1');

		self::assertIsArray($draft);
		self::assertSame('spoedeisend', $draft['variant']);
	}//end testACloneStaysOnItsOwnRoute()

	/**
	 * A draft created without a route lands on `standaard`, so a seeder that
	 * declares nothing keeps the behaviour it had.
	 *
	 * @return void
	 */
	public function testADraftWithNoRouteLandsOnTheDefaultRouteName(): void {
		$draft = $this->service->createDraft(
			payload: ['title' => 'Toezichtbezoek', 'caseType' => 'ct-handhaving']
		);

		self::assertIsArray($draft);
		self::assertSame(WorkflowDefinitionService::VARIANT_DEFAULT, $draft['variant']);
	}//end testADraftWithNoRouteLandsOnTheDefaultRouteName()

	/**
	 * The default route is a decision, recorded on request, and refused for a
	 * definition that is not published.
	 *
	 * @return void
	 */
	public function testTheDefaultRouteIsSetExplicitlyAndRefusedForADraft(): void {
		$this->rows = [
			'reg-1' => $this->row('reg-1', 'regulier', WorkflowDefinitionService::STATUS_PUBLISHED, true),
			'spoed-1' => $this->row('spoed-1', 'spoedeisend', WorkflowDefinitionService::STATUS_DRAFT, false),
		];

		self::assertTrue($this->service->setDefaultDefinition(id: 'reg-1'));
		self::assertSame(['reg-1'], $this->pins);

		self::assertFalse($this->service->setDefaultDefinition(id: 'spoed-1'));
		self::assertFalse($this->service->setDefaultDefinition(id: 'no-such-row'));
		self::assertSame(['reg-1'], $this->pins);
	}//end testTheDefaultRouteIsSetExplicitlyAndRefusedForADraft()

	/**
	 * A case type with open cases still refuses to lose its last published
	 * definition, counted across all its routes. Retiring one route among
	 * several is legitimate; leaving a case type with no route is not.
	 *
	 * @return void
	 */
	public function testACaseTypeIsNeverLeftWithoutAnyRoute(): void {
		$this->repository->method('hasCasesFor')->willReturn(true);
		$this->rows = [
			'reg-1' => $this->row('reg-1', 'regulier', WorkflowDefinitionService::STATUS_PUBLISHED, true),
			'spoed-1' => $this->row('spoed-1', 'spoedeisend', WorkflowDefinitionService::STATUS_PUBLISHED, true),
		];

		self::assertIsArray($this->service->deprecate(id: 'spoed-1'));

		$this->rows['spoed-1']['lifecycleStatus'] = WorkflowDefinitionService::STATUS_DEPRECATED;
		$this->rows['spoed-1']['isActive'] = false;

		self::assertNull($this->service->deprecate(id: 'reg-1'));
	}//end testACaseTypeIsNeverLeftWithoutAnyRoute()
}//end class
