<?php

/**
 * Unit tests for SetStatusHandler — moving a case to a NAMED status.
 *
 * The behaviour worth protecting is the refusal. A status that cannot be
 * resolved must FAIL the step, not skip it: a run that completes while the case
 * never moved is a case frozen at "received" for the applicant and a flow that
 * reports success for the handler. Every "did not move" path below asserts a
 * named error rather than a bare false.
 *
 * WHERE THE runAs TESTS WENT. This suite used to assert that the handler
 * wrapped its resolve-and-write in dossiq's FlowRunAsScope. That duty moved
 * into the engine: RegistryStepDispatcher executes every contributed node —
 * and therefore the handlers those nodes delegate to — inside
 * `ObjectService::runAs()` as the run's validated acting identity
 * (openregister#3332, proven by its RegistryStepDispatcherRunAsTest). The
 * local wrap is deleted, so asserting it here would re-encode the retired
 * requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\CaseFieldWriter;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\SetStatusHandler;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SetStatusHandlerTest extends TestCase {

	/**
	 * A lookup that resolves one name to one id.
	 *
	 * @param string $resolvesTo The id to return, or '' for "no such status".
	 *
	 * @return StatusTypeLookup The lookup double.
	 */
	private function lookup(string $resolvesTo): StatusTypeLookup {
		$lookup = $this->createMock(StatusTypeLookup::class);
		$lookup->method('idForName')->willReturn($resolvesTo);

		return $lookup;
	}//end lookup()

	/**
	 * Settings wired to a recording object service.
	 *
	 * The fake's saveObject returns an ObjectEntity BECAUSE THE REAL ONE DOES.
	 * A fake returning the caller's array encodes the caller's assumption and
	 * can never fail it — which is exactly how the ask node's "could not
	 * identify the task it created" shipped green.
	 *
	 * @param array|null $saved Receives the saved case.
	 *
	 * @return SettingsService The settings double.
	 */
	private function settings(?array &$saved): SettingsService {
		$objectService = new class($saved) {
			public function __construct(private ?array &$sink) {
			}

			public function saveObject(array $object, string $register, string $schema): ObjectEntity {
				$this->sink = $object;

				return $this->entity();
			}

			public function patchObject(string $objectId, array $data, ?string $register = null, ?string $schema = null): ObjectEntity {
				$this->sink = array_merge(($this->sink ?? []), $data);

				return $this->entity();
			}

			private function entity(): ObjectEntity {
				$entity = new ObjectEntity();
				$entity->setUuid('case-entity-uuid');
				$entity->setObject(($this->sink ?? []));

				return $entity;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'register' ? 'dossiq' : 'case')
		);

		return $settings;
	}//end settings()

	/**
	 * A handler over these settings and this lookup.
	 *
	 * @param SettingsService $settings The settings double.
	 * @param StatusTypeLookup $lookup  The lookup double.
	 *
	 * @return SetStatusHandler The handler under test.
	 */
	private function handler(SettingsService $settings, StatusTypeLookup $lookup): SetStatusHandler {
		return new SetStatusHandler($settings, $lookup, new CaseFieldWriter(), new NullLogger());
	}//end handler()

	public function testTheCaseIsMovedToTheResolvedStatus(): void {
		$saved = null;
		$handler = $this->handler($this->settings($saved), $this->lookup('status-uuid-3'));

		$result = $handler->handle(
			actionConfig: ['type' => 'setStatus', 'status' => 'In behandeling'],
			case: ['id' => 'case-1', 'caseType' => 'ct-1'],
			transitionContext: []
		);

		self::assertTrue($result->succeeded);
		self::assertSame('status-uuid-3', $saved['status']);
		self::assertSame('In behandeling', $result->data['statusName']);
	}//end testTheCaseIsMovedToTheResolvedStatus()

	/**
	 * 🔴 An unresolvable status FAILS rather than silently leaving the case.
	 */
	public function testAnUnknownStatusFailsTheStep(): void {
		$saved = null;
		$handler = $this->handler($this->settings($saved), $this->lookup(''));

		$result = $handler->handle(
			actionConfig: ['type' => 'setStatus', 'status' => 'Verzonnen'],
			case: ['id' => 'case-1', 'caseType' => 'ct-1'],
			transitionContext: []
		);

		self::assertFalse($result->succeeded);
		self::assertSame('status_not_found_on_case_type', $result->error);
		self::assertNull($saved, 'The case must not be written when the status did not resolve.');
	}//end testAnUnknownStatusFailsTheStep()

	public function testAStepWithNoStatusNamedFails(): void {
		$saved = null;
		$handler = $this->handler($this->settings($saved), $this->lookup('x'));

		$result = $handler->handle(
			actionConfig: ['type' => 'setStatus'],
			case: ['id' => 'case-1', 'caseType' => 'ct-1'],
			transitionContext: []
		);

		self::assertFalse($result->succeeded);
		self::assertSame('set_status_missing_status', $result->error);
	}//end testAStepWithNoStatusNamedFails()

	/**
	 * A case with no type has nothing to resolve the name WITHIN.
	 */
	public function testACaseWithoutACaseTypeFails(): void {
		$saved = null;
		$handler = $this->handler($this->settings($saved), $this->lookup('x'));

		$result = $handler->handle(
			actionConfig: ['type' => 'setStatus', 'status' => 'Ontvangen'],
			case: ['id' => 'case-1'],
			transitionContext: []
		);

		self::assertFalse($result->succeeded);
		self::assertSame('case_has_no_case_type', $result->error);
	}//end testACaseWithoutACaseTypeFails()

	public function testFailsWhenStorageIsUnavailable(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);

		$handler = $this->handler($settings, $this->lookup('status-1'));

		$result = $handler->handle(
			actionConfig: ['type' => 'setStatus', 'status' => 'Ontvangen'],
			case: ['id' => 'case-1', 'caseType' => 'ct-1'],
			transitionContext: []
		);

		self::assertFalse($result->succeeded);
		self::assertSame('storage_unavailable', $result->error);
	}//end testFailsWhenStorageIsUnavailable()

	public function testFailsWhenTheCaseSchemaIsNotConfigured(): void {
		$objectService = new class {
			public function saveObject(array $object, string $register, string $schema): array {
				return $object;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturn('');

		$handler = $this->handler($settings, $this->lookup('status-1'));

		$result = $handler->handle(
			actionConfig: ['type' => 'setStatus', 'status' => 'Ontvangen'],
			case: ['id' => 'case-1', 'caseType' => 'ct-1'],
			transitionContext: []
		);

		self::assertFalse($result->succeeded);
		self::assertSame('case_schema_not_configured', $result->error);
	}//end testFailsWhenTheCaseSchemaIsNotConfigured()

	public function testTheActionIdIsSetStatus(): void {
		$saved = null;
		$handler = $this->handler($this->settings($saved), $this->lookup('x'));

		self::assertSame('setStatus', $handler->type());
	}//end testTheActionIdIsSetStatus()

	/**
	 * A lookup answering the role direction, the name direction, or neither.
	 *
	 * @param string $byRole The id `idForRole()` returns, or '' for no match.
	 * @param string $byName The id `idForName()` returns, or '' for no match.
	 *
	 * @return StatusTypeLookup The lookup double.
	 */
	private function roleLookup(string $byRole, string $byName): StatusTypeLookup {
		$lookup = $this->createMock(StatusTypeLookup::class);
		$lookup->method('idForRole')->willReturn($byRole);
		$lookup->method('idForName')->willReturn($byName);

		return $lookup;
	}//end roleLookup()

	/**
	 * A step's role reaches a case type whose literal name does not match.
	 *
	 * The permit calls its working phase "In behandeling" and the subsidy calls
	 * it "Beoordeling"; the step says `in-progress` and moves both.
	 */
	public function testTheRoleMovesACaseWhoseStatusIsNamedSomethingElse(): void {
		$saved = null;
		$handler = $this->handler($this->settings($saved), $this->roleLookup(byRole: 'subs-2', byName: ''));

		$result = $handler->handle(
			actionConfig: ['type' => 'setStatus', 'status' => 'In behandeling', 'role' => 'in-progress'],
			case: ['id' => 'case-1', 'caseType' => 'subsidy'],
			transitionContext: []
		);

		self::assertTrue($result->succeeded);
		self::assertSame('subs-2', $saved['status']);
		self::assertSame('in-progress', $result->data['statusRole']);
	}//end testTheRoleMovesACaseWhoseStatusIsNamedSomethingElse()

	/**
	 * 🔴 THE ROLE WINS OVER THE NAME, and the name is still the fallback.
	 *
	 * Both directions answer here with DIFFERENT ids, which is the only shape
	 * that can tell the two orderings apart: a test where both answer the same
	 * id passes whichever way round the handler tries them.
	 */
	public function testTheRoleIsPreferredAndTheNameIsTheFallback(): void {
		$saved = null;
		$byRole = $this->handler($this->settings($saved), $this->roleLookup(byRole: 'by-role', byName: 'by-name'));

		$result = $byRole->handle(
			actionConfig: ['type' => 'setStatus', 'status' => 'In behandeling', 'role' => 'in-progress'],
			case: ['id' => 'case-1', 'caseType' => 'ct-1'],
			transitionContext: []
		);
		self::assertSame('by-role', $saved['status']);

		// A case type nobody has annotated: the role finds nothing and the
		// literal name resolves it, exactly as it did before roles existed.
		$fallbackSaved = null;
		$byName = $this->handler($this->settings($fallbackSaved), $this->roleLookup(byRole: '', byName: 'by-name'));

		$result = $byName->handle(
			actionConfig: ['type' => 'setStatus', 'status' => 'In behandeling', 'role' => 'in-progress'],
			case: ['id' => 'case-1', 'caseType' => 'ct-1'],
			transitionContext: []
		);

		self::assertTrue($result->succeeded);
		self::assertSame('by-name', $fallbackSaved['status']);
	}//end testTheRoleIsPreferredAndTheNameIsTheFallback()

	/**
	 * A step naming only a role is a complete instruction.
	 */
	public function testAStepMayNameOnlyARole(): void {
		$saved = null;
		$handler = $this->handler($this->settings($saved), $this->roleLookup(byRole: 's-1', byName: ''));

		$result = $handler->handle(
			actionConfig: ['type' => 'setStatus', 'role' => 'intake'],
			case: ['id' => 'case-1', 'caseType' => 'ct-1'],
			transitionContext: []
		);

		self::assertTrue($result->succeeded);
		self::assertSame('s-1', $saved['status']);
	}//end testAStepMayNameOnlyARole()

	/**
	 * 🔴 A PHASE THE CASE TYPE DOES NOT MODEL IS AN EXPLICIT SKIP, NOT A CRASH
	 * — and only when the step SAID it was optional.
	 *
	 * Both halves in one test, because the property is the DIFFERENCE between
	 * them: `required: false` skips and records why, and the same unresolvable
	 * step without it still fails. Neither writes the case.
	 */
	public function testAnOptionalPhaseIsSkippedAndARequiredOneStillFails(): void {
		$skipped = null;
		$optional = $this->handler($this->settings($skipped), $this->roleLookup(byRole: '', byName: ''));

		$result = $optional->handle(
			actionConfig: ['type' => 'setStatus', 'status' => 'Bij commissie', 'role' => 'review', 'required' => false],
			case: ['id' => 'case-1', 'caseType' => 'melding'],
			transitionContext: []
		);

		self::assertTrue($result->succeeded, 'A declared-optional phase must not kill the run.');
		self::assertTrue($result->data['skipped']);
		self::assertSame('case_type_models_no_such_phase', $result->data['reason']);
		self::assertSame('review', $result->data['statusRole']);
		self::assertNull($skipped, 'A skipped phase must not write the case.');
		self::assertSame([], $result->caseChanges ?? []);

		// The same unresolvable step, not declared optional.
		$notSaved = null;
		$required = $this->handler($this->settings($notSaved), $this->roleLookup(byRole: '', byName: ''));

		$hard = $required->handle(
			actionConfig: ['type' => 'setStatus', 'status' => 'In behandeling', 'role' => 'in-progress'],
			case: ['id' => 'case-1', 'caseType' => 'melding'],
			transitionContext: []
		);

		self::assertFalse($hard->succeeded);
		self::assertSame('status_not_found_on_case_type', $hard->error);
		self::assertNull($notSaved);
	}//end testAnOptionalPhaseIsSkippedAndARequiredOneStillFails()

	/**
	 * A step that forgets to say is REQUIRED. Only an explicit `false` opts out,
	 * because the cost of a wrongly-required step is a visible failure and the
	 * cost of a wrongly-optional one is a case that never moves and says nothing.
	 */
	public function testOnlyAnExplicitFalseMakesAPhaseOptional(): void {
		foreach ([null, true, 'false', 0] as $value) {
			$saved = null;
			$handler = $this->handler($this->settings($saved), $this->roleLookup(byRole: '', byName: ''));
			$config = ['type' => 'setStatus', 'role' => 'review'];
			if ($value !== null) {
				$config['required'] = $value;
			}

			$result = $handler->handle(
				actionConfig: $config,
				case: ['id' => 'case-1', 'caseType' => 'ct-1'],
				transitionContext: []
			);

			self::assertFalse(
				$result->succeeded,
				'required=' . var_export($value, true) . ' must not be read as optional.'
			);
		}
	}//end testOnlyAnExplicitFalseMakesAPhaseOptional()

	/**
	 * A step naming neither a role nor a name still fails.
	 */
	public function testAStepNamingNeitherARoleNorANameFails(): void {
		$saved = null;
		$handler = $this->handler($this->settings($saved), $this->roleLookup(byRole: 'x', byName: 'x'));

		$result = $handler->handle(
			actionConfig: ['type' => 'setStatus', 'required' => false],
			case: ['id' => 'case-1', 'caseType' => 'ct-1'],
			transitionContext: []
		);

		self::assertFalse($result->succeeded);
		self::assertSame('set_status_missing_status', $result->error);
	}//end testAStepNamingNeitherARoleNorANameFails()
}//end class
