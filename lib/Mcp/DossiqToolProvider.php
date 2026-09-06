<?php

/**
 * Dossiq MCP Tool Provider
 *
 * Per-app implementation of OCA\OpenRegister\Mcp\IMcpToolProvider for the
 * Dossiq case-management app. Exposes a minimal, read-only MVP skeleton of
 * MCP tools so the AI Chat Companion (hydra ADR-034 / ADR-035) can surface
 * Dossiq capabilities — listing running process instances (cases) and
 * reading one process instance with its current step + history — to an LLM.
 *
 * @category Mcp
 * @package  OCA\Dossiq\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/mcp-integration/spec.md
 * @spec openspec/specs/mcp-integration/spec.md
 * @spec openspec/specs/mcp-integration/spec.md
 * @spec openspec/specs/mcp-integration/spec.md
 * @spec openspec/specs/mcp-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Mcp;

use OCA\Dossiq\Mcp\Tool\DossiqCaseAuthorizer;
use OCA\Dossiq\Mcp\Tool\DossiqCaseReader;
use OCA\OpenRegister\Mcp\IMcpToolProvider;

/**
 * Dossiq MCP Tool Provider.
 *
 * Implements IMcpToolProvider (from openregister PR #1466,
 * change ai-chat-companion-orchestrator) exposing 2 read-only tools to the
 * AI Chat Companion. This is an MVP skeleton — the full tool set tracked in
 * ConductionNL/procest#416 (startProcess, advanceStep, listMyTasks,
 * getTaskDetails) is intentionally out of scope of this change.
 *
 * Auth design (OWASP A01:2021 / ADR-005):
 * - Per-object authorisation runs inside invokeTool(), AFTER argument
 *   validation but BEFORE business logic. The helper actually runs — it does
 *   NOT return true unconditionally and is NOT wrapped in catch(\Throwable).
 * - isAdmin() resolves via IGroupManager (the dedicated procest-admin admin group OR
 *   the Nextcloud system admin group), mirroring StatusTransitionService.
 * - A non-admin caller may read a case only when they are its assignee
 *   (primary handler) or hold a role record linking them to the case.
 *
 * @spec openspec/specs/mcp-integration/spec.md
 */
class DossiqToolProvider implements IMcpToolProvider {

	/**
	 * Hard upper bound for the listProcesses limit argument.
	 *
	 * @var int
	 */
	private const LIMIT_MAX = 50;

	/**
	 * Tool catalogue — hard-coded so unit tests can assert it as a fixture.
	 *
	 * Exactly 2 read-only tools for the MVP skeleton.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const TOOL_DESCRIPTORS = [
		[
			'id' => 'dossiq.listProcesses',
			'subject' => 'process',
			'action' => 'list',
			'name' => 'List processes',
			'description' => 'List running process instances (cases). Optionally filter by status'
				. ' type id (status) and cap the result with limit (1-50, default 20).',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'limit' => [
						'type' => 'integer',
						'minimum' => 1,
						'maximum' => 50,
						'default' => 20,
					],
					'status' => [
						'type' => 'string',
						'description' => 'Optional status type id or slug to filter by.',
					],
				],
				'required' => [],
			],
		],
		[
			'id' => 'dossiq.getProcessDetails',
			'subject' => 'process',
			'action' => 'get',
			'name' => 'Get process details',
			'description' => 'Get one process instance (case) by id or uuid, including its'
				. ' current step (status) and chronological transition history.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'id' => [
						'type' => 'string',
						'description' => 'The process instance (case) id or uuid.',
					],
					'uuid' => [
						'type' => 'string',
						'description' => 'Alias for id — the process instance (case) uuid.',
					],
				],
				'required' => [],
			],
		],
	];

	/**
	 * Constructor for DossiqToolProvider.
	 *
	 * @param DossiqCaseReader $caseReader The OpenRegister case reader (lookup + shape normalisation)
	 * @param DossiqCaseAuthorizer $authorizer The per-object read authorisation check
	 *
	 * @return void
	 */
	public function __construct(
		private readonly DossiqCaseReader $caseReader,
		private readonly DossiqCaseAuthorizer $authorizer,
	) {
	}//end __construct()

	/**
	 * Returns the app ID that namespaces every tool id.
	 *
	 * @return string "dossiq"
	 *
	 * @spec openspec/specs/mcp-integration/spec.md
	 */
	public function getAppId(): string {
		return 'dossiq';
	}//end getAppId()

	/**
	 * Returns the full tool catalogue (2 tools, always).
	 *
	 * The full catalogue is returned regardless of caller permissions.
	 * Per-object authorisation runs in invokeTool().
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/mcp-integration/spec.md
	 */
	public function getTools(): array {
		return self::TOOL_DESCRIPTORS;
	}//end getTools()

	/**
	 * Dispatch a tool call by id.
	 *
	 * Argument validation runs BEFORE authorisation, which runs BEFORE
	 * business logic. Unknown tool ids return a structured error; no
	 * exception is thrown.
	 *
	 * @param string $toolId The tool id (e.g. "dossiq.listProcesses")
	 * @param array<string, mixed> $arguments Tool arguments from the LLM call
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/mcp-integration/spec.md
	 */
	public function invokeTool(string $toolId, array $arguments): array {
		switch ($toolId) {
			case 'dossiq.listProcesses':
				return $this->handleListProcesses(args: $arguments);
			case 'dossiq.getProcessDetails':
				return $this->handleGetProcessDetails(args: $arguments);
			default:
				return $this->errorEnvelope(
					code: 'unknown_tool',
					message: "Unknown tool id '{$toolId}'. Available tools: "
						. implode(', ', array_column(self::TOOL_DESCRIPTORS, 'id')) . '.'
				);
		}//end switch

	}//end invokeTool()

	// =========================================================================
	// Private tool handlers
	// =========================================================================

	/**
	 * Handle dossiq.listProcesses.
	 *
	 * Lists running process instances (cases) the caller may read.
	 *
	 * @param array<string, mixed> $args Tool arguments
	 *
	 * @return array<string, mixed>
	 */
	private function handleListProcesses(array $args): array {
		$limit = $this->parseLimit(args: $args);
		if ($limit === null) {
			return $this->errorEnvelope(code: 'invalid_arguments', message: 'Invalid limit. Must be an integer between 1 and 50.');
		}

		$store = $this->caseReader->resolveCaseStore();
		if ($store['ok'] === false) {
			return $this->errorEnvelope(code: $store['code'], message: $store['message']);
		}

		$filters = $this->buildListFilters(args: $args);
		$rawCases = $this->caseReader->findCases(store: $store, filters: $filters, limit: $limit);
		if ($rawCases === null) {
			return $this->errorEnvelope(code: 'internal_error', message: 'Failed to list processes. See server log for details.');
		}

		$items = [];
		$sources = [];
		foreach ($rawCases as $raw) {
			$case = $this->caseReader->toArray(value: $raw);
			if ($this->mayRead(case: $case) === false) {
				continue;
			}

			$items[] = $case;
			$sources[] = $this->caseReader->buildCaseSource(case: $case);
		}

		return [
			'success' => true,
			'processes' => array_slice($items, 0, DossiqCaseReader::ITEMS_CAP),
			'sources' => array_slice($sources, 0, DossiqCaseReader::ITEMS_CAP),
		];

	}//end handleListProcesses()

	/**
	 * Handle dossiq.getProcessDetails.
	 *
	 * Fetches one process instance (case) with its current step (status) and
	 * chronological transition history.
	 *
	 * @param array<string, mixed> $args Tool arguments
	 *
	 * @return array<string, mixed>
	 */
	private function handleGetProcessDetails(array $args): array {
		$caseId = $this->parseCaseId(args: $args);
		if ($caseId === null) {
			return $this->errorEnvelope(code: 'invalid_arguments', message: 'Required argument id (or uuid) is missing.');
		}

		$store = $this->caseReader->resolveCaseStore();
		if ($store['ok'] === false) {
			return $this->errorEnvelope(code: $store['code'], message: $store['message']);
		}

		$case = $this->caseReader->findCase(store: $store, caseId: $caseId);
		if ($case === null) {
			return $this->errorEnvelope(code: 'internal_error', message: 'Failed to load the process. See server log for details.');
		}

		if ($case === []) {
			return $this->errorEnvelope(code: 'not_found', message: 'Process not found.');
		}

		// Authorisation BEFORE business logic — actually runs, not wrapped in catch.
		if ($this->mayRead(case: $case) === false) {
			return $this->errorEnvelope(code: 'forbidden', message: 'You are not authorised to read this process.');
		}

		$caseUuid = $this->caseReader->extractUuid(item: $case);
		$history = $this->caseReader->loadHistory(store: $store, caseUuid: $caseUuid);

		return [
			'success' => true,
			'process' => $case,
			'currentStep' => ($case['status'] ?? null),
			'history' => array_slice($history, 0, DossiqCaseReader::ITEMS_CAP),
			'sources' => [$this->caseReader->buildCaseSource(case: $case)],
		];

	}//end handleGetProcessDetails()

	// =========================================================================
	// Private helpers — argument parsing
	// =========================================================================

	/**
	 * Parse and validate the optional `limit` argument.
	 *
	 * @param array<string, mixed> $args Tool arguments
	 *
	 * @return int|null The clamped limit, or null when the supplied value is out of range.
	 */
	private function parseLimit(array $args): ?int {
		if (isset($args['limit']) === false) {
			return DossiqCaseReader::ITEMS_CAP;
		}

		$limit = (int)$args['limit'];
		if ($limit < 1 || $limit > self::LIMIT_MAX) {
			return null;
		}

		return $limit;
	}//end parseLimit()

	/**
	 * Build the OpenRegister filter map for dossiq.listProcesses.
	 *
	 * @param array<string, mixed> $args Tool arguments
	 *
	 * @return array<string, mixed>
	 */
	private function buildListFilters(array $args): array {
		$filters = [];
		if (isset($args['status']) === true && $args['status'] !== '') {
			$filters['status'] = (string)$args['status'];
		}

		return $filters;
	}//end buildListFilters()

	/**
	 * Resolve the case id from either the `id` or `uuid` argument.
	 *
	 * @param array<string, mixed> $args Tool arguments
	 *
	 * @return string|null The case id, or null when neither argument is supplied.
	 */
	private function parseCaseId(array $args): ?string {
		if (isset($args['id']) === true && $args['id'] !== '') {
			return (string)$args['id'];
		}

		if (isset($args['uuid']) === true && $args['uuid'] !== '') {
			return (string)$args['uuid'];
		}

		return null;
	}//end parseCaseId()

	// =========================================================================
	// Private helpers — authorisation
	// =========================================================================

	/**
	 * Ask the authorizer whether the calling user may read a case.
	 *
	 * Authorisation is delegated, not skipped: the check actually runs and is
	 * not wrapped in catch(\Throwable) anywhere along this path.
	 *
	 * @param array<string, mixed> $case The case object as an associative array
	 *
	 * @return bool True when the caller may read the case.
	 */
	private function mayRead(array $case): bool {
		return $this->authorizer->canReadCase(
			case: $case,
			caseUuid: $this->caseReader->extractUuid(item: $case)
		);

	}//end mayRead()

	// =========================================================================
	// Private helpers — shaping
	// =========================================================================

	/**
	 * Build a structured error envelope (never thrown).
	 *
	 * @param string $code Machine-readable error code
	 * @param string $message Human-readable message
	 *
	 * @return array{error: array{code: string, message: string}}
	 */
	private function errorEnvelope(string $code, string $message): array {
		return [
			'error' => [
				'code' => $code,
				'message' => $message,
			],
		];

	}//end errorEnvelope()
}//end class
