<?php

/**
 * SubstitutionService Unit Tests.
 *
 * Covers validation branches (self-substitution, period, overlap), active
 * resolution date boundaries (start day, end day, day after), scope filtering,
 * and substituted-work resolution.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Substitution\SubstitutedWorkResolver;
use OCA\Dossiq\Service\Substitution\SubstitutionValidator;
use OCA\Dossiq\Service\SubstitutionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

if (interface_exists(SubstitutionObjectServiceStub::class) === false) {
	/**
	 * Mockable ObjectService surface used by the substitution services.
	 *
	 * Declares the real OpenRegister ObjectService entry points with typed,
	 * named-parameter-compatible signatures so PHPUnit mocks accept the
	 * named-argument calls made by the SearchesObjects trait.
	 */
	interface SubstitutionObjectServiceStub {
		/** @param int|string $id @param mixed ...$args @return mixed */
		public function find(int|string $id, ...$args): mixed;

		/** @param array<string,mixed> $query @return array<int,mixed>|int */
		public function searchObjects(array $query = []): array|int;

		/** @param string $r @param string $s @param array<string,mixed> $f @return array<int,mixed>|int */
		public function searchObjectsBySlug(string $r, string $s, array $f = []): array|int;

		/** @param mixed ...$args @return mixed */
		public function saveObject(...$args): mixed;

		/** @param mixed ...$args @return mixed */
		public function updateObject(...$args): mixed;
	}//end interface
}//end if

/**
 * Unit tests for SubstitutionService.
 *
 * @covers \OCA\Dossiq\Service\SubstitutionService
 *
 * @uses \OCA\Dossiq\Service\Substitution\SubstitutedWorkResolver
 * @uses \OCA\Dossiq\Service\Substitution\SubstitutionValidator
 */
class SubstitutionServiceTest extends TestCase {

	/**
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $settingsService;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the service with a configured ObjectService mock.
	 *
	 * @param object|null $objectService The ObjectService mock (slug-aware) or null.
	 *
	 * @return SubstitutionService
	 */
	private function makeService(?object $objectService): SubstitutionService {
		// Fresh SettingsService mock per service so repeated makeService() calls
		// in one test do not clobber each other's getObjectService() return.
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->settingsService->method('getObjectService')->willReturn($objectService);
		// Non-numeric register/schema -> trait uses the slug path.
		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				$map = [
					'register' => 'dossiq',
					'substitution_schema' => 'substitution',
					'case_schema' => 'case',
					'task_schema' => 'caseTask',
					'status_type_schema' => 'statusType',
				];
				return ($map[$key] ?? $default);
			}
		);

		return new SubstitutionService(
			$this->settingsService,
			$this->logger,
			new SubstitutionValidator($this->settingsService),
			new SubstitutedWorkResolver($this->settingsService)
		);
	}//end makeService()

	/**
	 * Build a slug-aware ObjectService mock.
	 *
	 * @return \PHPUnit\Framework\MockObject\MockObject
	 */
	private function objectServiceMock() {
		return $this->createMock(SubstitutionObjectServiceStub::class);
	}//end objectServiceMock()

	/**
	 * Self-substitution is rejected.
	 *
	 * @return void
	 */
	public function testSelfSubstitutionRejected(): void {
		$os = $this->objectServiceMock();
		$service = $this->makeService($os);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/own waarnemer/');
		$service->create(absentee: 'jan', substitute: 'jan', startDate: '2026-07-01', endDate: '2026-07-21');
	}//end testSelfSubstitutionRejected()

	/**
	 * Missing endDate is rejected.
	 *
	 * @return void
	 */
	public function testMissingEndDateRejected(): void {
		$service = $this->makeService($this->objectServiceMock());

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/endDate/');
		$service->create(absentee: 'jan', substitute: 'marieke', startDate: '2026-07-01', endDate: '');
	}//end testMissingEndDateRejected()

	/**
	 * endDate before startDate is rejected.
	 *
	 * @return void
	 */
	public function testEndBeforeStartRejected(): void {
		$service = $this->makeService($this->objectServiceMock());

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/before startDate/');
		$service->create(absentee: 'jan', substitute: 'marieke', startDate: '2026-07-21', endDate: '2026-07-01');
	}//end testEndBeforeStartRejected()

	/**
	 * Overlapping full-scope substitution is rejected; the conflict id is named.
	 *
	 * @return void
	 */
	public function testOverlappingFullScopeRejected(): void {
		$os = $this->objectServiceMock();
		$os->method('searchObjectsBySlug')->willReturn(
			[
				['id' => 'sub-1', 'absentee' => 'jan', 'scope' => 'all', 'status' => 'active', 'startDate' => '2026-07-01', 'endDate' => '2026-07-21'],
			]
		);
		$service = $this->makeService($os);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/sub-1/');
		$service->create(absentee: 'jan', substitute: 'marieke', startDate: '2026-07-10', endDate: '2026-07-25', scope: 'all');
	}//end testOverlappingFullScopeRejected()

	/**
	 * A disjoint-scope substitution for the same period is accepted.
	 *
	 * @return void
	 */
	public function testDisjointScopeAccepted(): void {
		$os = $this->objectServiceMock();
		// assertNoOverlappingFullScope only runs for scope 'all'; a caseTypes
		// scope skips the overlap probe and saves.
		$os->expects($this->once())->method('saveObject')->willReturnArgument(2);
		$service = $this->makeService($os);

		$result = $service->create(
			absentee: 'jan',
			substitute: 'pieter',
			startDate: '2026-07-01',
			endDate: '2026-07-21',
			scope: 'caseTypes',
			scopeRefs: ['objectionProceeding']
		);

		$this->assertSame('jan', $result['absentee']);
		$this->assertSame('caseTypes', $result['scope']);
		$this->assertSame('active', $result['status']);
	}//end testDisjointScopeAccepted()

	/**
	 * scopeRefs is required for a narrowed scope.
	 *
	 * @return void
	 */
	public function testNarrowedScopeRequiresRefs(): void {
		$service = $this->makeService($this->objectServiceMock());

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/scopeRefs/');
		$service->create(absentee: 'jan', substitute: 'marieke', startDate: '2026-07-01', endDate: '2026-07-21', scope: 'caseTypes', scopeRefs: []);
	}//end testNarrowedScopeRequiresRefs()

	/**
	 * Active resolution: inside the window the substitution resolves; on the
	 * day after endDate it is excluded (and lazily marked ended).
	 *
	 * @return void
	 */
	public function testActiveResolutionDateBoundaries(): void {
		$row = ['id' => 'sub-9', 'absentee' => 'jan', 'substitute' => 'marieke', 'scope' => 'all', 'status' => 'active', 'startDate' => '2026-07-01', 'endDate' => '2026-07-21'];

		// Start day — active.
		$osStart = $this->objectServiceMock();
		$osStart->method('searchObjectsBySlug')->willReturn([$row]);
		$start = $this->makeService($osStart)->getActiveSubstitutionsFor('marieke', new DateTimeImmutable('2026-07-01'));
		$this->assertCount(1, $start);

		// End day — active.
		$osEnd = $this->objectServiceMock();
		$osEnd->method('searchObjectsBySlug')->willReturn([$row]);
		$end = $this->makeService($osEnd)->getActiveSubstitutionsFor('marieke', new DateTimeImmutable('2026-07-21'));
		$this->assertCount(1, $end);

		// Day after — excluded + lazily marked ended.
		$osAfter = $this->objectServiceMock();
		$osAfter->method('searchObjectsBySlug')->willReturn([$row]);
		$osAfter->expects($this->once())->method('updateObject');
		$after = $this->makeService($osAfter)->getActiveSubstitutionsFor('marieke', new DateTimeImmutable('2026-07-22'));
		$this->assertCount(0, $after);

		// Day before — excluded, not yet started.
		$osBefore = $this->objectServiceMock();
		$osBefore->method('searchObjectsBySlug')->willReturn([$row]);
		$before = $this->makeService($osBefore)->getActiveSubstitutionsFor('marieke', new DateTimeImmutable('2026-06-30'));
		$this->assertCount(0, $before);
	}//end testActiveResolutionDateBoundaries()

	/**
	 * A revoked substitution never resolves as active.
	 *
	 * @return void
	 */
	public function testRevokedNeverActive(): void {
		$os = $this->objectServiceMock();
		$os->method('searchObjectsBySlug')->willReturn(
			[['id' => 'r', 'substitute' => 'marieke', 'absentee' => 'jan', 'scope' => 'all', 'status' => 'revoked', 'startDate' => '2026-07-01', 'endDate' => '2026-07-21']]
		);
		$active = $this->makeService($os)->getActiveSubstitutionsFor('marieke', new DateTimeImmutable('2026-07-10'));
		$this->assertCount(0, $active);
	}//end testRevokedNeverActive()

	/**
	 * Scope caseTypes routes only matching case types into the substituted work.
	 *
	 * @return void
	 */
	public function testGetSubstitutedWorkScopeFilter(): void {
		$sub = ['id' => 'sub-2', 'absentee' => 'jan', 'substitute' => 'marieke', 'scope' => 'caseTypes', 'scopeRefs' => ['objectionProceeding'], 'status' => 'active', 'startDate' => '2026-07-01', 'endDate' => '2026-07-21'];

		$os = $this->objectServiceMock();
		$os->method('searchObjectsBySlug')->willReturnCallback(
			function (string $reg, string $schema, array $filters) use ($sub) {
				if ($schema === 'substitution') {
					return [$sub];
				}
				if ($schema === 'statusType') {
					return [['id' => 'st-final', 'isFinal' => true]];
				}
				if ($schema === 'case') {
					return [
						['id' => 'case-a', 'caseType' => 'objectionProceeding', 'assignee' => 'jan', 'status' => 'st-open'],
						['id' => 'case-b', 'caseType' => 'vergunning', 'assignee' => 'jan', 'status' => 'st-open'],
						['id' => 'case-c', 'caseType' => 'objectionProceeding', 'assignee' => 'jan', 'status' => 'st-final'],
					];
				}
				if ($schema === 'caseTask') {
					return [];
				}
				return [];
			}
		);

		$work = $this->makeService($os)->getSubstitutedWorkFor('marieke', new DateTimeImmutable('2026-07-10'));

		// Only the open bezwaar case is routed; the vergunning and final-status are excluded.
		$this->assertCount(1, $work['cases']);
		$this->assertSame('case-a', $work['cases'][0]['id']);
		$this->assertSame('jan', $work['cases'][0]['_substituted']['absentee']);
	}//end testGetSubstitutedWorkScopeFilter()

	/**
	 * resolveActingCapacity returns the covering substitution for a third-party
	 * case and null for own work.
	 *
	 * @return void
	 */
	public function testResolveActingCapacity(): void {
		$sub = ['id' => 'sub-3', 'absentee' => 'jan', 'substitute' => 'marieke', 'scope' => 'all', 'status' => 'active', 'startDate' => '2026-07-01', 'endDate' => '2026-07-21'];
		$os = $this->objectServiceMock();
		$os->method('searchObjectsBySlug')->willReturn([$sub]);
		$service = $this->makeService($os);

		$cap = $service->resolveActingCapacity('marieke', 'jan', 'case-x', 'objectionProceeding', new DateTimeImmutable('2026-07-10'));
		$this->assertNotNull($cap);
		$this->assertSame('sub-3', $cap['id']);

		// Own work — actor equals absentee.
		$this->assertNull($service->resolveActingCapacity('marieke', 'marieke', 'case-y', null, new DateTimeImmutable('2026-07-10')));
	}//end testResolveActingCapacity()

	/**
	 * Revoke flips status to revoked.
	 *
	 * @return void
	 */
	public function testRevoke(): void {
		$os = $this->objectServiceMock();
		$os->method('find')->willReturn(['id' => 'sub-4', 'status' => 'active', 'absentee' => 'jan', 'substitute' => 'marieke']);
		$os->expects($this->once())->method('updateObject')->willReturnCallback(
			function (string $r, string $s, string $id, array $payload) {
				return $payload;
			}
		);
		$result = $this->makeService($os)->revoke('sub-4');
		$this->assertSame('revoked', $result['status']);
	}//end testRevoke()
}//end class
