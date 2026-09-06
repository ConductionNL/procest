<?php

/**
 * Dossiq shipped-flow adoption.
 *
 * Dossiq SHIPS two flows, as `x-openregister-flows` blocks on the `case` and
 * `bacAdviceRequest` schemas. OpenRegister's SchemaFlowImportListener
 * materialises them into `oc_openregister_flows` on schema save, and it stores
 * them `enabled = false, owner = NULL` — deliberately. Its own words: "a schema
 * save is not a person volunteering to run a graph as themselves", so a shipped
 * flow is "stored, visible and inert until somebody makes it theirs". The
 * counterpart act is `FlowService::adopt()` (POST /api/flows/{id}/adopt), which
 * makes the CALLING user the owner and refuses to take one that already has an
 * owner. Enabling stays a third, separate decision.
 *
 * 🔴 A LEAF APP CANNOT SHIP AN OWNER, AND MUST NOT TRY. The import listener
 * copies name, description, trigger, triggerRegister, cron, executionMode,
 * nodes, edges and limits off the declaration — and nothing else. `owner` and
 * `enabled` are hardcoded on create and are not re-read on update, precisely so
 * an app upgrade cannot silently re-enable a flow an administrator switched off
 * or re-point whose identity it runs as. Writing `"owner": "__system__"` into
 * dossiq_register.json therefore changes NOTHING: the key is never read.
 *
 * ⚠️ AND `__system__` IS NOT THE PRECEDENT IT LOOKS LIKE. A fresh rig does show
 * two openregister-owned rows carrying `owner = __system__` next to dossiq's two
 * ownerless ones. Read the rest of those rows: `nodes`, `edges`, `trigger` and
 * `trigger_schema` are all EMPTY. They are not shipped flows — they are register
 * objects that `MigrateRegisterFlowsToTable` copied into the flow table, and
 * `__system__` is what OpenRegister stamps as `_owner` on any object written
 * without a session, not an owner anybody declared. Copying that value would be
 * imitating an artefact of a sessionless write.
 *
 * So the fix for "dossiq's flows never dispatch" is not a different literal in a
 * JSON file. It is this: give an administrator the deliberate act the engine
 * asks for, and make the install SAY that it is outstanding
 * ({@see \OCA\Dossiq\Repair\ReportShippedFlowAdoption}).
 *
 * ⚠️ Note which of the two identity fields this sets. `owner` is what
 * `Flow::canDispatch()` requires — the FlowLocator refusal on a fresh install is
 * literally `matched trigger "object.created" but was not dispatched: it has no
 * owner`. It is NOT the same field as a schedule node's `runAs`: OpenRegister's
 * TriggerScheduleNode requires an explicit `runAs` and does not fall back to the
 * flow's owner. Both of dossiq's shipped flows are `object.created`-triggered
 * and neither contains a schedule node, so `runAs` does not arise here; setting
 * the owner is the whole of what they need.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Flow
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
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Flow;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCP\IUser;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reports and performs the adoption of dossiq's shipped flows.
 *
 * @spec openspec/specs/case-management/spec.md
 */
class ShippedFlowAdoption {

	/**
	 * OpenRegister's flow service, resolved by name.
	 *
	 * Named by string rather than type-hinted for the reason the sibling
	 * migrations already state: dossiq declares no `<app>` dependency on
	 * OpenRegister, so every class here must stay constructible on an instance
	 * where OpenRegister is absent.
	 *
	 * @var string
	 */
	private const FLOW_SERVICE = 'OCA\\OpenRegister\\Service\\Flow\\FlowService';

	/**
	 * OpenRegister's flow mapper, resolved by name.
	 *
	 * The CENSUS reads through the mapper and not through FlowService, on
	 * purpose. `FlowService::findAll()` is organisation-scoped and resolves the
	 * organisation from the session; a repair step has no session, so the same
	 * call that lists two flows for a signed-in administrator returns an empty
	 * list at install time. An install-time report that says "no shipped flows"
	 * because it could not see them is the exact failure shape this whole change
	 * is about, so the census reads the table directly and reports what is
	 * actually stored.
	 *
	 * @var string
	 */
	private const FLOW_MAPPER = 'OCA\\OpenRegister\\Db\\FlowMapper';

	/**
	 * Flows read per page.
	 *
	 * @var integer
	 */
	private const PAGE = 100;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's services by name.
	 * @param SettingsService $settingsService Bridge to OpenRegister's object service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * What dossiq's shipped flows look like right now.
	 *
	 * Read-only and session-free, so a repair step and an occ command can both
	 * ask. `available` is false when OpenRegister exposes no flow store at all,
	 * which is a different fact from "no flows" and must not be reported as one.
	 *
	 * @return array{available: bool, note: string, flows: array<int, array{uuid: string, name: string, enabled: bool, owner: string}>}
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function census(): array {
		try {
			$mapper = $this->container->get(self::FLOW_MAPPER);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq: no OpenRegister flow store to inspect: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);

			return ['available' => false, 'note' => 'OpenRegister exposes no flow store on this instance.', 'flows' => []];
		}

		try {
			$flows = $mapper->findAllFlows(app: Application::APP_ID, limit: self::PAGE);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not read the shipped flows: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);

			return ['available' => false, 'note' => 'The flow store could not be read: ' . $e->getMessage(), 'flows' => []];
		}

		$rows = [];
		foreach ((array)$flows as $flow) {
			$rows[] = [
				'uuid' => (string)$flow->getUuid(),
				'name' => (string)$flow->getName(),
				'enabled' => (bool)$flow->getEnabled(),
				'owner' => trim((string)($flow->getOwner() ?? '')),
			];
		}

		return ['available' => true, 'note' => '', 'flows' => $rows];
	}//end census()

	/**
	 * The shipped flows that still need an administrator.
	 *
	 * A flow needs attention when it has no owner (it cannot dispatch at all)
	 * or is disabled (its trigger is not armed). Both are reported, because an
	 * adopted-but-disabled flow is just as inert as an ownerless one and an
	 * install that mentions only the first leaves the reader half-informed.
	 *
	 * @param array<int, array{uuid: string, name: string, enabled: bool, owner: string}> $flows The census rows.
	 *
	 * @return array<int, array{uuid: string, name: string, enabled: bool, owner: string}> The rows still outstanding.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function outstanding(array $flows): array {
		$pending = [];
		foreach ($flows as $flow) {
			if ($flow['owner'] === '' || $flow['enabled'] === false) {
				$pending[] = $flow;
			}
		}

		return $pending;
	}//end outstanding()

	/**
	 * Adopt (and optionally enable) every shipped flow, as the named user.
	 *
	 * The whole pass runs inside `ObjectService::runAs($user, ...)`, which is
	 * the seam dossiq's other flow migrations already use: `FlowService::adopt()`
	 * writes the CALLING user's uid and refuses outright when there is no acting
	 * user, and `find()` is organisation-scoped, so neither works from an occ
	 * command without it.
	 *
	 * A flow already owned by somebody else is REPORTED, never taken over —
	 * that refusal is the engine's, and routing around it would re-point whose
	 * identity existing runs resolve as.
	 *
	 * @param IUser $user The administrator volunteering to own the flows.
	 * @param bool $enable Also arm the trigger by enabling each adopted flow.
	 * @param bool $dryRun Report what would happen and write nothing.
	 *
	 * @return array{note: string, rows: array<int, array{name: string, uuid: string, outcome: string, detail: string}>}
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function adoptAll(IUser $user, bool $enable, bool $dryRun): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return ['note' => 'OpenRegister is not available.', 'rows' => []];
		}

		if (method_exists($objectService, 'runAs') === false) {
			return ['note' => 'OpenRegister exposes no runAs(); adoption needs an acting identity.', 'rows' => []];
		}

		try {
			$flowService = $this->container->get(self::FLOW_SERVICE);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not resolve FlowService for adoption: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);

			return ['note' => 'OpenRegister exposes no FlowService on this instance.', 'rows' => []];
		}

		$census = $this->census();
		if ($census['flows'] === []) {
			return [
				'note' => 'No dossiq flows are stored yet. They are imported when the register is, so run `occ maintenance:repair` first.',
				'rows' => [],
			];
		}

		return $objectService->runAs(
			$user,
			fn (): array => [
				'note' => '',
				'rows' => $this->adoptEach(
					flowService: $flowService,
					flows: $census['flows'],
					uid: $user->getUID(),
					enable: $enable,
					dryRun: $dryRun
				),
			]
		);
	}//end adoptAll()

	/**
	 * Adopt each flow in turn, never letting one failure end the pass.
	 *
	 * @param object $flowService OpenRegister's FlowService.
	 * @param array<int, array{uuid: string, name: string, enabled: bool, owner: string}> $flows The census rows.
	 * @param string $uid The adopting user.
	 * @param bool $enable Enable after adopting.
	 * @param bool $dryRun Write nothing.
	 *
	 * @return array<int, array{name: string, uuid: string, outcome: string, detail: string}>
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	private function adoptEach(object $flowService, array $flows, string $uid, bool $enable, bool $dryRun): array {
		$rows = [];
		foreach ($flows as $flow) {
			$rows[] = $this->adoptOne(
				flowService: $flowService,
				row: $flow,
				uid: $uid,
				enable: $enable,
				dryRun: $dryRun
			);
		}

		return $rows;
	}//end adoptEach()

	/**
	 * Adopt one flow.
	 *
	 * @param object $flowService OpenRegister's FlowService.
	 * @param array{uuid: string, name: string, enabled: bool, owner: string} $row The census row.
	 * @param string $uid The adopting user.
	 * @param bool $enable Enable after adopting.
	 * @param bool $dryRun Write nothing.
	 *
	 * @return array{name: string, uuid: string, outcome: string, detail: string}
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	private function adoptOne(object $flowService, array $row, string $uid, bool $enable, bool $dryRun): array {
		$skip = $this->skipReason(row: $row, uid: $uid, enable: $enable);
		if ($skip !== '') {
			return ['name' => $row['name'], 'uuid' => $row['uuid'], 'outcome' => 'skipped', 'detail' => $skip];
		}

		if ($dryRun === true) {
			return [
				'name' => $row['name'],
				'uuid' => $row['uuid'],
				'outcome' => 'adopted',
				'detail' => 'would be adopted by "' . $uid . '"' . $this->enabledClause(enable: $enable, dryRun: true),
			];
		}

		try {
			$flow = $flowService->find($row['uuid']);
			$flowService->adopt($flow);

			if ($enable === true) {
				$flowService->save(['enabled' => true], $row['uuid']);
			}
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: could not adopt a shipped flow',
				['app' => Application::APP_ID, 'flow' => $row['uuid'], 'exception' => $e->getMessage()]
			);

			return ['name' => $row['name'], 'uuid' => $row['uuid'], 'outcome' => 'failed', 'detail' => $e->getMessage()];
		}

		return [
			'name' => $row['name'],
			'uuid' => $row['uuid'],
			'outcome' => 'adopted',
			'detail' => 'owner "' . $uid . '"' . $this->enabledClause(enable: $enable, dryRun: false),
		];
	}//end adoptOne()

	/**
	 * Why this flow needs nothing done to it, or an empty string when it does.
	 *
	 * The already-owned case is a REPORT rather than a takeover: the engine
	 * refuses one, and routing around that refusal would re-point whose
	 * identity existing runs resolve as.
	 *
	 * @param array{uuid: string, name: string, enabled: bool, owner: string} $row The census row.
	 * @param string $uid The adopting user.
	 * @param bool $enable Whether enabling was asked for.
	 *
	 * @return string The reason to skip, or an empty string.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	private function skipReason(array $row, string $uid, bool $enable): string {
		if ($row['owner'] !== '' && $row['owner'] !== $uid) {
			return 'already owned by "' . $row['owner'] . '"; adoption is not a takeover';
		}

		if ($row['owner'] !== $uid) {
			return '';
		}

		if ($row['enabled'] === true || $enable === false) {
			return 'already adopted';
		}

		return '';
	}//end skipReason()

	/**
	 * The clause naming what happened to the flow's enabled state.
	 *
	 * @param bool $enable Whether enabling was asked for.
	 * @param bool $dryRun Whether this is a dry run, which changes the tense.
	 *
	 * @return string The clause.
	 */
	private function enabledClause(bool $enable, bool $dryRun): string {
		if ($enable === true && $dryRun === true) {
			return ' and enabled';
		}

		if ($enable === true) {
			return ', enabled';
		}

		if ($dryRun === true) {
			return '';
		}

		return ', still disabled';
	}//end enabledClause()
}//end class
