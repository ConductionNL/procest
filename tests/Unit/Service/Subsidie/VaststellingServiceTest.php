<?php

/**
 * VaststellingService Unit Tests.
 *
 * Exercises the settlement math (REQ-SUB-005): accountantsverklaring
 * threshold, final-bedrag capping, overpayment detection and the
 * terugvordering trigger boundary.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Subsidie
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Subsidie;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Subsidie\TerugvorderingService;
use OCA\Dossiq\Service\Subsidie\VaststellingService;
use OCA\Dossiq\Tests\Unit\Service\FakeStoredObject;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * In-memory ObjectService fake for VaststellingServiceTest, pinned to
 * OpenRegister's REAL contract like the shared FakeTermijnStore: find()
 * declares the real argument order, returns an entity-shaped object and
 * THROWS DoesNotExistException on a miss; saveObject() declares the real
 * signature and returns an entity-shaped object, never an array. It
 * still merges the given fields onto any existing row (matching
 * finalize()'s partial-patch call for the vaststelling object itself).
 */
class VaststellingFakeObjectService {

	/**
	 * Stored objects keyed by schema then id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $store = [];

	/**
	 * Find one object by id within a schema — entity-shaped return,
	 * DoesNotExistException on a miss, exactly like live.
	 *
	 * @param int|string $id Object id.
	 * @param array|null $_extend Relations to expand (ignored).
	 * @param bool $files Include file metadata (ignored).
	 * @param string|int|null $register Ignored (single in-memory register).
	 * @param string|int|null $schema Schema slug.
	 *
	 * @return FakeStoredObject
	 *
	 * @throws DoesNotExistException When the id is unknown.
	 */
	public function find(int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null): FakeStoredObject {
		$row = ($this->store[(string)$schema][(string)$id] ?? null);
		if ($row === null) {
			throw new DoesNotExistException('Object ' . $id . ' does not exist');
		}

		return new FakeStoredObject($row);
	}//end find()

	/**
	 * Save (merge) an object into the store — real signature, entity-shaped
	 * return.
	 *
	 * @param array<string, mixed> $object Fields to merge.
	 * @param array|null $extend Relations to expand (ignored).
	 * @param string|int|null $register Ignored.
	 * @param string|int|null $schema Schema slug.
	 * @param string|null $uuid Object id (null = generate one).
	 *
	 * @return FakeStoredObject The merged row.
	 */
	public function saveObject(array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null): FakeStoredObject {
		$schema = (string)$schema;
		$uuid = ($uuid ?? ('generated-' . count($this->store[$schema] ?? [])));
		$existing = ($this->store[$schema][$uuid] ?? []);
		$merged = array_merge($existing, $object, ['id' => $uuid]);
		$this->store[$schema][$uuid] = $merged;

		return new FakeStoredObject($merged);
	}//end saveObject()
}//end class

/**
 * @covers \OCA\Dossiq\Service\Subsidie\VaststellingService
 *
 * @uses \OCA\Dossiq\Service\Subsidie\TerugvorderingService
 *
 * @spec openspec/changes/subsidieverlening-keten/tasks.md#TASK-SUB-20
 * @spec openspec/changes/subsidie-settlement-case-costs/specs/subsidie-settlement-case-costs/spec.md
 */
class VaststellingServiceTest extends TestCase {

	private VaststellingService $service;

	/**
	 * The in-memory object store fake, shared with $settings for the
	 * finalize()-focused tests.
	 *
	 * @var VaststellingFakeObjectService
	 */
	private VaststellingFakeObjectService $objects;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new VaststellingFakeObjectService();

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			function (string $key, string $default = ''): string {
				return match ($key) {
					'register' => 'dossiq',
					'subsidie_vaststelling_schema' => 'subsidieVaststelling',
					'subsidie_uitvoering_schema' => 'subsidieUitvoering',
					'subsidie_aanvraag_schema' => 'subsidieAanvraag',
					'case_schema' => 'case',
					'terugvordering_schema' => 'terugvordering',
					default => $default,
				};
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$terugvordering = new TerugvorderingService($settings, $logger);
		$this->service = new VaststellingService($settings, $terugvordering, $logger);
	}//end setUp()

	/**
	 * Seed the full subsidieUitvoering -> subsidieAanvraag -> case chain.
	 *
	 * @param string $determinationId Vaststelling id.
	 * @param string $uitvoeringId Execution id.
	 * @param string $requestId Application id.
	 * @param string|null $caseId Linked case id (null = no case link).
	 *
	 * @return void
	 */
	private function seedChain(string $determinationId, string $uitvoeringId, string $requestId, ?string $caseId): void {
		$this->objects->store['subsidieVaststelling'][$determinationId] = [
			'id' => $determinationId,
			'subsidieuitvoering' => $uitvoeringId,
			'status' => 'draft',
		];
		$this->objects->store['subsidieUitvoering'][$uitvoeringId] = [
			'id' => $uitvoeringId,
			'subsidieaanvraag' => $requestId,
		];
		$this->objects->store['subsidieAanvraag'][$requestId] = [
			'id' => $requestId,
			'case' => ($caseId ?? ''),
		];

		if ($caseId !== null) {
			$this->objects->store['case'][$caseId] = ['id' => $caseId, 'title' => 'Test case'];
		}
	}//end seedChain()

	/**
	 * @return void
	 */
	public function testAccountantsverklaringThreshold(): void {
		$this->assertTrue($this->service->accountantsverklaringVereist(150000.0, 125000.0));
		$this->assertFalse($this->service->accountantsverklaringVereist(125000.0, 125000.0));
		$this->assertFalse($this->service->accountantsverklaringVereist(100000.0, 125000.0));
	}//end testAccountantsverklaringThreshold()

	/**
	 * Final bedrag is capped at the granted amount and never above actual costs.
	 *
	 * @return void
	 */
	public function testVastgesteldBedragCapping(): void {
		// Actual costs below granted -> settle at actual costs.
		$this->assertSame(330000.0, $this->service->computeVastgesteldBedrag(450000.0, 330000.0));
		// Actual costs above granted -> capped at granted.
		$this->assertSame(450000.0, $this->service->computeVastgesteldBedrag(450000.0, 500000.0));
		// Negative actual costs guarded to zero.
		$this->assertSame(0.0, $this->service->computeVastgesteldBedrag(450000.0, -1.0));
	}//end testVastgesteldBedragCapping()

	/**
	 * REQ-SUB-005: overpayment is the positive difference between disbursed
	 * advances and the final settled amount.
	 *
	 * @return void
	 */
	public function testOverpaymentAndTrigger(): void {
		// €360.000 advances vs €330.000 settled -> €30.000 clawback.
		$this->assertSame(30000.0, $this->service->computeOverpayment(360000.0, 330000.0));
		$this->assertTrue($this->service->recoveryTrigger(360000.0, 330000.0));

		// Advances equal to settled -> no clawback.
		$this->assertSame(0.0, $this->service->computeOverpayment(330000.0, 330000.0));
		$this->assertFalse($this->service->recoveryTrigger(330000.0, 330000.0));

		// Settled above advances (under-disbursed) -> no clawback.
		$this->assertSame(0.0, $this->service->computeOverpayment(300000.0, 330000.0));
		$this->assertFalse($this->service->recoveryTrigger(300000.0, 330000.0));
	}//end testOverpaymentAndTrigger()

	/**
	 * subsidie-settlement-case-costs: no linked case — finalize() still
	 * succeeds (vaststelling is patched) and simply does not append kosten
	 * anywhere, rather than throwing.
	 *
	 * @return void
	 */
	public function testFinalizeWithNoLinkedCaseDoesNotThrow(): void {
		$this->seedChain(determinationId: 'vst-2', uitvoeringId: 'uitv-2', requestId: 'aanv-2', caseId: null);

		$result = $this->service->finalize(determinationId: 'vst-2', grantedAmount: 100000.0, actualCost: 80000.0, totalAdvances: 50000.0);

		$this->assertSame('determined', $result['determination']['status']);
		$this->assertSame([], $this->objects->store['case'] ?? []);
	}//end testFinalizeWithNoLinkedCaseDoesNotThrow()
}//end class
