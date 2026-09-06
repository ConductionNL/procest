<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Flow
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Flow;

use OCA\Dossiq\Flow\DossiqEnsureCommitteeNode;
use OCA\Dossiq\Service\Bezwaar\CommitteeDelegationService;
use OCA\Dossiq\Service\SettingsService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * Covers the `dossiq.ensureCommittee` flow node.
 *
 * The node exists so a running bezwaar never reaches a committee that lives in
 * no shared register. Two behaviours carry that: it FAILS the step when the
 * decision app cannot hold the committee (rather than letting the run continue
 * past it), and it short-circuits on a committee already mapped so a heartbeat
 * does not re-dispatch on every pass.
 *
 * @covers \OCA\Dossiq\Flow\DossiqEnsureCommitteeNode
 *
 */
class DossiqEnsureCommitteeNodeTest extends TestCase {

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
	 * Whether the fake register throws on read.
	 *
	 * @var boolean
	 */
	private bool $readThrows = false;

	/**
	 * Whether the fake register throws on write.
	 *
	 * @var boolean
	 */
	private bool $saveThrows = false;

	/**
	 * Build the node.
	 *
	 * @param CommitteeDelegationService|null $delegation A delegation double, or null for a default.
	 * @param boolean                         $withStore  Whether OpenRegister is available.
	 *
	 * @return DossiqEnsureCommitteeNode The node.
	 */
	private function node(?CommitteeDelegationService $delegation = null, bool $withStore = true): DossiqEnsureCommitteeNode {
		$rows = &$this->rows;
		$saved = &$this->saved;
		$readThrows = &$this->readThrows;
		$saveThrows = &$this->saveThrows;

		$objectService = new class($rows, $saved, $readThrows, $saveThrows) {
			/**
			 * @param array<string, mixed> $rows       Stored rows.
			 * @param array<string, mixed>|null $saved Last save.
			 * @param boolean $readThrows             Whether reads throw.
			 * @param boolean $saveThrows             Whether writes throw.
			 */
			public function __construct(
				private array &$rows,
				private ?array &$saved,
				private bool &$readThrows,
				private bool &$saveThrows,
			) {
			}

			/**
			 * @param string $id       The id.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return array<string, mixed>|null The row.
			 */
			public function find(string $id, string $register = '', string $schema = ''): mixed {
				if ($this->readThrows === true) {
					throw new RuntimeException('register unavailable');
				}

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
				if ($this->saveThrows === true) {
					throw new RuntimeException('write refused');
				}

				$this->saved = $object;

				return $object;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($withStore === true ? $objectService : null);
		$settings->method('getConfigValue')->willReturn('configured');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new DossiqEnsureCommitteeNode(
			($delegation ?? $this->createMock(CommitteeDelegationService::class)),
			$settings,
			$l10n,
			$this->createMock(LoggerInterface::class),
		);

	}//end node()

	/**
	 * Seed a committee.
	 *
	 * @param string $mappedTo An already-recorded governance-body id, or ''.
	 *
	 * @return string The committee id.
	 */
	private function seed(string $mappedTo = ''): string {
		$this->rows['cmte-1'] = [
			'id' => 'cmte-1',
			'name' => 'Bezwaarcommissie sociaal domein',
			'active' => true,
			'governanceBodyId' => $mappedTo,
		];

		return 'cmte-1';
	}//end seed()

	/**
	 * One flow item carrying an advice request.
	 *
	 * @param mixed $committee The committee reference on the item.
	 *
	 * @return array<int, array<string, mixed>> The items.
	 */
	private function items(mixed $committee): array {
		return [['json' => ['id' => 'req-1', 'committee' => $committee]]];
	}//end items()

	/**
	 * The node identifies itself for the catalogue.
	 *
	 * @return void
	 */
	public function testItIdentifiesItself(): void {
		$this->assertSame('dossiq.ensureCommittee', $this->node()->getId());
		$this->assertNotSame('', $this->node()->getDisplayName());
		$this->assertNotSame('', $this->node()->getDescription());
		$this->assertNotSame('', $this->node()->getIcon());

	}//end testItIdentifiesItself()

	/**
	 * A blank committeeField is refused rather than silently defaulted.
	 *
	 * @return void
	 */
	public function testABlankCommitteeFieldIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->node()->validateConfig(['committeeField' => '  ']);

	}//end testABlankCommitteeFieldIsRefused()

	/**
	 * An absent committeeField is fine — the default applies.
	 *
	 * @return void
	 */
	public function testAnAbsentCommitteeFieldIsAccepted(): void {
		$this->node()->validateConfig([]);

		$this->addToAssertionCount(1);

	}//end testAnAbsentCommitteeFieldIsAccepted()

	/**
	 * The node raises the committee and stamps the id on the item.
	 *
	 * @return void
	 */
	public function testItRaisesTheCommitteeAndStampsTheItem(): void {
		$this->seed();
		$delegation = $this->createMock(CommitteeDelegationService::class);
		$delegation->expects($this->once())->method('ensureGovernanceBody')->willReturn('gb-9');

		$out = $this->node($delegation)->execute($this->items('cmte-1'), [], []);

		$this->assertSame('gb-9', $out[0]['json']['governanceBodyId']);
		$this->assertSame('gb-9', $this->saved['governanceBodyId'], 'the mapping is recorded locally');

	}//end testItRaisesTheCommitteeAndStampsTheItem()

	/**
	 * A committee already mapped does not dispatch again.
	 *
	 * Without this a heartbeat would ask the decision app to hold the same
	 * committee on every pass.
	 *
	 * @return void
	 */
	public function testAnAlreadyMappedCommitteeDoesNotDispatch(): void {
		$this->seed(mappedTo: 'gb-existing');
		$delegation = $this->createMock(CommitteeDelegationService::class);
		$delegation->expects($this->never())->method('ensureGovernanceBody');

		$out = $this->node($delegation)->execute($this->items('cmte-1'), [], []);

		$this->assertSame('gb-existing', $out[0]['json']['governanceBodyId']);

	}//end testAnAlreadyMappedCommitteeDoesNotDispatch()

	/**
	 * 🔴 It fails closed. A run must not continue past a committee it could not
	 * register, because that refers an objection to a body in no shared
	 * register and nothing reports it.
	 *
	 * @return void
	 */
	public function testItFailsClosedWhenTheCommitteeCannotBeRaised(): void {
		$this->seed();
		$delegation = $this->createMock(CommitteeDelegationService::class);
		$delegation->method('ensureGovernanceBody')
			->willThrowException(new RuntimeException('the decision app is not installed'));

		$this->expectException(RuntimeException::class);

		$this->node($delegation)->execute($this->items('cmte-1'), [], []);

	}//end testItFailsClosedWhenTheCommitteeCannotBeRaised()

	/**
	 * An item naming no committee passes through, so one flow serves both routes.
	 *
	 * @return void
	 */
	public function testAnItemWithNoCommitteePassesThrough(): void {
		$delegation = $this->createMock(CommitteeDelegationService::class);
		$delegation->expects($this->never())->method('ensureGovernanceBody');

		$out = $this->node($delegation)->execute($this->items(''), [], []);

		$this->assertArrayNotHasKey('governanceBodyId', $out[0]['json']);

	}//end testAnItemWithNoCommitteePassesThrough()

	/**
	 * An EXPANDED relation resolves to its id.
	 *
	 * OpenRegister returns a relation either as a bare uuid or as the inlined
	 * object. Reading the raw value would turn the expanded form into an empty
	 * id and silently skip the item.
	 *
	 * @return void
	 */
	public function testAnExpandedRelationIsResolved(): void {
		$this->seed();
		$delegation = $this->createMock(CommitteeDelegationService::class);
		$delegation->expects($this->once())->method('ensureGovernanceBody')->willReturn('gb-9');

		$out = $this->node($delegation)->execute($this->items(['id' => 'cmte-1', 'name' => 'X']), [], []);

		$this->assertSame('gb-9', $out[0]['json']['governanceBodyId']);

	}//end testAnExpandedRelationIsResolved()

	/**
	 * An unknown committee fails rather than being invented.
	 *
	 * @return void
	 */
	public function testAnUnknownCommitteeFails(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/Committee not found/');

		$this->node()->execute($this->items('no-such-committee'), [], []);

	}//end testAnUnknownCommitteeFails()

	/**
	 * Without OpenRegister the step fails rather than passing the item on.
	 *
	 * @return void
	 */
	public function testItFailsWithoutOpenRegister(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/OpenRegister is not available/');

		$this->node(null, withStore: false)->execute($this->items('cmte-1'), [], []);

	}//end testItFailsWithoutOpenRegister()

	/**
	 * A configured output key is honoured.
	 *
	 * @return void
	 */
	public function testTheOutputKeyIsConfigurable(): void {
		$this->seed(mappedTo: 'gb-existing');

		$out = $this->node()->execute($this->items('cmte-1'), ['outputKey' => 'bodyRef'], []);

		$this->assertSame('gb-existing', $out[0]['json']['bodyRef']);

	}//end testTheOutputKeyIsConfigurable()

	/**
	 * A non-array item is passed through untouched.
	 *
	 * @return void
	 */
	public function testANonArrayItemPassesThrough(): void {
		$out = $this->node()->execute(['not-an-array'], [], []);

		$this->assertSame(['not-an-array'], $out);

	}//end testANonArrayItemPassesThrough()

	/**
	 * The node is offered in the admin and user scopes, and nowhere else.
	 *
	 * @return void
	 */
	public function testItIsOfferedInTheExpectedScopes(): void {
		$node = $this->node();

		$this->assertTrue($node->isAvailableForScope(\OCP\WorkflowEngine\IManager::SCOPE_ADMIN));
		$this->assertTrue($node->isAvailableForScope(\OCP\WorkflowEngine\IManager::SCOPE_USER));
		$this->assertFalse($node->isAvailableForScope(999));

	}//end testItIsOfferedInTheExpectedScopes()

	/**
	 * An entity-shaped committee is normalised before it is read.
	 *
	 * OpenRegister hands a row back as an entity when the read did not flatten
	 * it; reading it as an array would find nothing and report the committee as
	 * missing.
	 *
	 * @return void
	 */
	public function testAnEntityShapedCommitteeIsNormalised(): void {
		$this->rows['cmte-1'] = new class {
			/**
			 * @return array<string, mixed> The row.
			 */
			public function jsonSerialize(): array {
				return ['id' => 'cmte-1', 'name' => 'BAC', 'active' => true, 'governanceBodyId' => 'gb-entity'];
			}
		};

		$out = $this->node()->execute($this->items('cmte-1'), [], []);

		$this->assertSame('gb-entity', $out[0]['json']['governanceBodyId']);

	}//end testAnEntityShapedCommitteeIsNormalised()

	/**
	 * A read that throws is reported as a failure naming the committee.
	 *
	 * @return void
	 */
	public function testAFailingReadIsReported(): void {
		$this->readThrows = true;

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/Could not read committee/');

		$this->node()->execute($this->items('cmte-1'), [], []);

	}//end testAFailingReadIsReported()

	/**
	 * Recording the mapping is BEST EFFORT: a failed write must not undo work
	 * the decision app has already done.
	 *
	 * @return void
	 */
	public function testAFailedMappingWriteDoesNotFailTheStep(): void {
		$this->seed();
		$this->saveThrows = true;
		$delegation = $this->createMock(CommitteeDelegationService::class);
		$delegation->method('ensureGovernanceBody')->willReturn('gb-9');

		$out = $this->node($delegation)->execute($this->items('cmte-1'), [], []);

		$this->assertSame('gb-9', $out[0]['json']['governanceBodyId'], 'the id is still handed on');

	}//end testAFailedMappingWriteDoesNotFailTheStep()

	/**
	 * A relation that is neither a scalar nor a reference resolves to nothing.
	 *
	 * @return void
	 */
	public function testAnUnusableRelationIsTreatedAsAbsent(): void {
		$delegation = $this->createMock(CommitteeDelegationService::class);
		$delegation->expects($this->never())->method('ensureGovernanceBody');

		$out = $this->node($delegation)->execute($this->items(new \stdClass()), [], []);

		$this->assertArrayNotHasKey('governanceBodyId', $out[0]['json']);

	}//end testAnUnusableRelationIsTreatedAsAbsent()

}//end class
