<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Vth\LhsDecisionTableLookup;
use OCA\Dossiq\Service\Vth\LhsRecommendationService;
use OCA\Dossiq\Service\Vth\LhsRecommendationStore;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The manager gate on an LHS override, and the store it has to read to enforce it.
 *
 * The Landelijke Handhavingsstrategie prescribes an intervention from
 * (ernst × gedrag × actorType). An inspector may deviate DOWNWARD with a
 * justification; deviating upward — a heavier measure against a citizen or a
 * business — requires a manager.
 *
 * That gate compares the requested intervention against what the MATRIX
 * recommended. The whole question is therefore where that value comes from. It
 * used to come from the request body, so an inspector could declare a harsh
 * "recommendation", call anything an override-down, and never meet the gate.
 * These tests pin that it now comes from the store.
 */
class LhsOverrideAuthorizationTest extends TestCase {

	/**
	 * Rows the fake register holds, keyed by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * What the fake register was last asked to save.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $saved = null;

	/**
	 * Build the service over a fake register.
	 *
	 * @param string $uid The signed-in user.
	 *
	 * @return LhsRecommendationService The service.
	 */
	private function service(string $uid = 'inspector1'): LhsRecommendationService {
		$rows = &$this->rows;
		$saved = &$this->saved;

		$objectService = new class($rows, $saved) {
			/**
			 * @param array<string, array<string, mixed>> $rows  Stored rows.
			 * @param array<string, mixed>|null           $saved Last save.
			 */
			public function __construct(private array &$rows, private ?array &$saved) {
			}

			/**
			 * @param string $id       The id.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return array<string, mixed>|null The row.
			 */
			public function find(string $id, string $register = '', string $schema = ''): ?array {
				return ($this->rows[$id] ?? null);
			}

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param string|null          $uuid     The uuid.
			 *
			 * @return array<string, mixed> The stored row.
			 */
			public function saveObject(array $object, string $register = '', string $schema = '', ?string $uuid = null): array {
				$this->saved = $object;

				return $object;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturn('configured');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$logger = $this->createMock(LoggerInterface::class);

		// The REAL store over the fake register, not a mock of it. A mocked
		// store would let the service's read be satisfied by whatever the test
		// wanted it to return, which is precisely the shape of the bug these
		// tests exist to pin.
		$store = new LhsRecommendationStore($settings, $logger);

		// The decision-table lookup answers null here, which is the state of an
		// instance that has not run the projection. These tests are about the
		// override guard, so they must exercise the matrix path deliberately
		// rather than by accident: a lookup that silently answered would move
		// the assertions onto a code path they were never written for.
		$tableLookup = $this->createMock(LhsDecisionTableLookup::class);
		$tableLookup->method('intervention')->willReturn(null);

		return new LhsRecommendationService($session, $store, $tableLookup);

	}//end service()

	/**
	 * Seed a stored recommendation.
	 *
	 * @param string $recommended What the matrix recommended.
	 *
	 * @return string The row id.
	 */
	private function seed(string $recommended = 'warning'): string {
		$this->rows['rec-1'] = [
			'id' => 'rec-1',
			'case' => 'case-1',
			'severity' => 'gering',
			'behaviour' => 'goedwillend',
			'actorType' => 'burger',
			'matrixVersion' => 3,
			'recommendedIntervention' => $recommended,
			'finalIntervention' => $recommended,
			'override' => false,
			'recommendedBy' => 'inspector1',
		];

		return 'rec-1';
	}//end seed()

	/**
	 * A justification long enough to clear the 20-character floor.
	 *
	 * @return string The text.
	 */
	private function justification(): string {
		return 'Gemotiveerde afwijking van de interventieladder.';
	}//end justification()

	/**
	 * 🔴 The bypass. An inspector claiming a harsh recommendation is refused.
	 *
	 * Before the fix, `override()` took the row from the caller and compared
	 * against ITS `recommendedIntervention`. Posting a body that claimed the
	 * matrix had recommended `bestuursdwang` made every lesser intervention an
	 * "override-down", so the manager gate never fired.
	 *
	 * @return void
	 */
	public function testAClaimedHarshRecommendationCannotUnlockAnEscalation(): void {
		$id = $this->seed(recommended: 'warning');

		// The caller would LIKE the baseline to be bestuursdwang. It is not
		// theirs to state, and the stored row says warning.
		$this->rows['rec-1']['recommendedIntervention'] = 'warning';

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Verzwaring vereist managerrol');

		$this->service()->override(
			recommendationId: $id,
			intervention: 'bestuursdwang',
			justification: $this->justification(),
			userRole: 'inspector',
		);

	}//end testAClaimedHarshRecommendationCannotUnlockAnEscalation()

	/**
	 * The escalation guard reads the STORED baseline, not a supplied one.
	 *
	 * @return void
	 */
	public function testTheGuardComparesAgainstTheStoredRecommendation(): void {
		$id = $this->seed(recommended: 'bestuursdwang');

		// Stored baseline is the harshest, so anything else is an override-down
		// and an inspector may do it.
		$result = $this->service()->override(
			recommendationId: $id,
			intervention: 'warning',
			justification: $this->justification(),
			userRole: 'inspector',
		);

		$this->assertSame('warning', $result['finalIntervention']);
		$this->assertSame('inspector', $result['overrideAuthority']);

	}//end testTheGuardComparesAgainstTheStoredRecommendation()

	/**
	 * A manager may escalate.
	 *
	 * @return void
	 */
	public function testAManagerMayEscalate(): void {
		$id = $this->seed(recommended: 'warning');

		$result = $this->service()->override(
			recommendationId: $id,
			intervention: 'bestuursdwang',
			justification: $this->justification(),
			userRole: 'manager',
		);

		$this->assertSame('bestuursdwang', $result['finalIntervention']);
		$this->assertSame('manager', $result['overrideAuthority']);

	}//end testAManagerMayEscalate()

	/**
	 * 🔴 The second half of the bypass: the audit fields are not rewritable.
	 *
	 * The old array_merge took the caller's whole row, so a request could
	 * restate `severity`, `behaviour`, `matrixVersion` and `recommendedBy` on
	 * its way past the guard — leaving the record of what the matrix said
	 * agreeing with whoever overrode it.
	 *
	 * @return void
	 */
	public function testTheStoredLookupFieldsSurviveAnOverride(): void {
		$id = $this->seed(recommended: 'warning');

		$this->service()->override(
			recommendationId: $id,
			intervention: 'herstelactie',
			justification: $this->justification(),
			userRole: 'manager',
		);

		$this->assertNotNull($this->saved);
		$this->assertSame('gering', $this->saved['severity']);
		$this->assertSame('goedwillend', $this->saved['behaviour']);
		$this->assertSame(3, $this->saved['matrixVersion']);
		$this->assertSame('warning', $this->saved['recommendedIntervention']);
		$this->assertSame('inspector1', $this->saved['recommendedBy']);

	}//end testTheStoredLookupFieldsSurviveAnOverride()

	/**
	 * The override is attributed to the SESSION, never to the request.
	 *
	 * @return void
	 */
	public function testOverrideIsAttributedToTheSession(): void {
		$id = $this->seed(recommended: 'warning');

		$result = $this->service(uid: 'someone-else')->override(
			recommendationId: $id,
			intervention: 'warning',
			justification: $this->justification(),
			userRole: 'inspector',
		);

		$this->assertSame('someone-else', $result['overrideBy']);

	}//end testOverrideIsAttributedToTheSession()

	/**
	 * An unknown recommendation is refused rather than invented.
	 *
	 * @return void
	 */
	public function testAnUnknownRecommendationIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('LHS-aanbeveling niet gevonden');

		$this->service()->override(
			recommendationId: 'no-such-row',
			intervention: 'warning',
			justification: $this->justification(),
			userRole: 'inspector',
		);

	}//end testAnUnknownRecommendationIsRefused()

	/**
	 * A blank id is refused before anything is read.
	 *
	 * @return void
	 */
	public function testABlankIdIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Recommendation ID ontbreekt voor override');

		$this->service()->override(
			recommendationId: '  ',
			intervention: 'warning',
			justification: $this->justification(),
			userRole: 'inspector',
		);

	}//end testABlankIdIsRefused()

	/**
	 * The 20-character justification floor still applies.
	 *
	 * @return void
	 */
	public function testAThinJustificationIsRefused(): void {
		$id = $this->seed();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Motivatie afwijking moet minimaal 20 tekens bevatten');

		$this->service()->override(
			recommendationId: $id,
			intervention: 'warning',
			justification: 'te kort',
			userRole: 'inspector',
		);

	}//end testAThinJustificationIsRefused()

}//end class
