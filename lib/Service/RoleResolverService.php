<?php

/**
 * Dossiq Role Resolver Service
 *
 * Central engine that resolves a `routingRule` plus a `case` to an ordered
 * set of participant references. Owns:
 *   - Legacy normalisation: `assigneeRole` -> single-role,
 *     `allowedRoles` -> or-set.
 *   - Strategy dispatch via StrategyRegistry.
 *   - Delegation substitution + cycle detection on `role.delegate`.
 *   - APCu cache layer keyed by `(ruleHash, caseId)` for 60s.
 *
 * Callers: task list builder, status-transition engine,
 * /api/cases/{id}/reroute controller.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Routing\RoleDelegationResolver;
use OCA\Dossiq\Service\Routing\RoutingStrategyMissingException;
use OCA\Dossiq\Service\Routing\StrategyRegistry;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Central role-routing engine.
 *
 * @spec openspec/changes/role-based-step-routing/tasks.md#T02
 */
class RoleResolverService {
	/**
	 * Default strategy name when normalising legacy fields.
	 */
	public const STRATEGY_SINGLE_ROLE = 'single-role';

	/**
	 * Strategy name used to normalise `allowedRoles`.
	 */
	public const STRATEGY_OR_SET = 'or-set';

	/**
	 * APCu cache TTL (seconds) for resolver results.
	 */
	private const CACHE_TTL = 60;

	/**
	 * The local cache instance (APCu when available).
	 *
	 * @var ICache
	 */
	private ICache $cache;

	/**
	 * Constructor.
	 *
	 * @param StrategyRegistry $registry Strategy registry
	 * @param SettingsService $settingsService Bridge to ObjectService + config
	 * @param ICacheFactory $cacheFactory Cache factory
	 * @param RoleDelegationResolver $delegation Active-window delegate substitution
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly StrategyRegistry $registry,
		private readonly SettingsService $settingsService,
		ICacheFactory $cacheFactory,
		private readonly RoleDelegationResolver $delegation,
		private readonly LoggerInterface $logger,
	) {
		$this->cache = $cacheFactory->createLocal(Application::APP_ID . '_routing');
	}//end __construct()

	/**
	 * Normalise a step or transition into a concrete routing rule.
	 *
	 * Order of precedence:
	 *   1. Explicit `routingRule` object on the step/transition.
	 *   2. Legacy `assigneeRole` (UUID) -> single-role.
	 *   3. Legacy `allowedRoles` (UUID array) -> or-set.
	 *
	 * Returns null when nothing routable is declared (caller decides default).
	 *
	 * @param array<string, mixed> $entry The step or transition payload
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/role-based-step-routing/spec.md
	 */
	public function normaliseRule(array $entry): ?array {
		$rule = $entry['routingRule'] ?? null;
		if (is_array($rule) === true && isset($rule['strategy']) === true) {
			return $rule;
		}

		$assigneeRole = (string)($entry['assigneeRole'] ?? '');
		if ($assigneeRole !== '') {
			return [
				'strategy' => self::STRATEGY_SINGLE_ROLE,
				'roleType' => $assigneeRole,
			];
		}

		$allowedRoles = $entry['allowedRoles'] ?? null;
		if (is_array($allowedRoles) === true && $allowedRoles !== []) {
			return [
				'strategy' => self::STRATEGY_OR_SET,
				'roleTypes' => array_values(
					array_map(
						static fn ($value): string => (string)$value,
						$allowedRoles,
					)
				),
			];
		}

		return null;
	}//end normaliseRule()

	/**
	 * Resolve a routing rule against a case.
	 *
	 * @param array<string, mixed> $rule The (already normalised) routing rule
	 * @param array<string, mixed> $case The case object (must include id, caseType)
	 *
	 * @return array<int, string> Ordered participant refs (post-delegation)
	 *
	 * @throws RoutingStrategyMissingException When the rule's strategy is unknown
	 *
	 * @spec openspec/specs/role-based-step-routing/spec.md
	 */
	public function resolve(array $rule, array $case): array {
		$strategyName = (string)($rule['strategy'] ?? '');
		if ($this->registry->has($strategyName) === false) {
			throw new RoutingStrategyMissingException(
				message: sprintf('Routing strategy "%s" is not registered', $strategyName)
			);
		}

		$caseId = (string)($case['id'] ?? ($case['uuid'] ?? ''));
		$cacheKey = $this->cacheKey(rule: $rule, caseId: $caseId);
		$cacheHit = null;
		if ($caseId !== '') {
			$cacheHit = $this->cache->get($cacheKey);
		}

		if (is_array($cacheHit) === true) {
			return array_values(
				array_map(
					static fn ($value): string => (string)$value,
					$cacheHit,
				)
			);
		}

		$roles = $this->loadCaseRoles(caseId: $caseId);
		$strategy = $this->registry->get($strategyName);
		$primary = $strategy->resolve($rule, $case, $roles);

		$fallback = (string)($rule['fallback'] ?? '');
		if ($primary === [] && $fallback !== '' && $strategyName !== 'hierarchical') {
			$primary = $this->registry
				->get(self::STRATEGY_SINGLE_ROLE)
				->resolve(['strategy' => self::STRATEGY_SINGLE_ROLE, 'roleType' => $fallback], $case, $roles);
		}

		$resolved = $this->delegation->apply(participants: $primary, roles: $roles);

		if ($caseId !== '') {
			$this->cache->set($cacheKey, $resolved, self::CACHE_TTL);
		}

		if ($resolved === []) {
			$this->logger->info(
				'Dossiq: routing rule resolved to empty set',
				[
					'event' => 'RoleRoutingEmpty',
					'rule' => $rule,
					'caseId' => $caseId,
					'app' => Application::APP_ID,
				],
			);
		}

		return $resolved;
	}//end resolve()

	/**
	 * Whether the given user is permitted to execute against the rule.
	 *
	 * Convenience for status-transition guard evaluation.
	 *
	 * @param array<string, mixed> $rule The routing rule
	 * @param array<string, mixed> $case The case
	 * @param string $userId The candidate user id
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/role-based-step-routing/spec.md
	 */
	public function canExecute(array $rule, array $case, string $userId): bool {
		if ($userId === '') {
			return false;
		}

		try {
			$allowed = $this->resolve(rule: $rule, case: $case);
		} catch (RoutingStrategyMissingException $e) {
			$this->logger->warning(
				'Dossiq: routing guard rejected — missing strategy: ' . $e->getMessage(),
			);
			return false;
		}

		return in_array($userId, $allowed, true);
	}//end canExecute()

	/**
	 * Invalidate the cache for every rule against a case.
	 *
	 * Called by the role-mutation listener.
	 *
	 * @param string $caseId The case UUID/id
	 *
	 * @return void
	 *
	 * @spec openspec/specs/role-based-step-routing/spec.md
	 */
	public function invalidateCache(string $caseId): void {
		if ($caseId === '') {
			return;
		}

		// We cannot enumerate keys on ICache, so clear the whole local segment.
		// Acceptable: the cache is per-app, the segment is namespaced and
		// resolver hits are rebuilt within 60s anyway.
		$this->cache->clear();
	}//end invalidateCache()

	/**
	 * Build a cache key from rule + caseId.
	 *
	 * @param array<string, mixed> $rule The rule
	 * @param string $caseId The case id
	 *
	 * @return string
	 */
	private function cacheKey(array $rule, string $caseId): string {
		$hash = md5(serialize($rule));
		return sprintf('rrs.%s.%s', $hash, $caseId);
	}//end cacheKey()

	/**
	 * Load the role records bound to a case via ObjectService.
	 *
	 * @param string $caseId The case id
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadCaseRoles(string $caseId): array {
		if ($caseId === '') {
			return [];
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('role_schema');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			// OpenRegister's ObjectService::findAll() takes ONE config array.
			// This call used to pass ($register, $schema, $filters)
			// positionally, which is a TypeError against `array $config` — and
			// the catch below turned that TypeError into an empty role list, so
			// stored case roles were never loaded and rule resolution silently
			// fell through to its other sources.
			$records = $objectService->findAll(
				[
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'case' => $caseId,
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: failed to load roles for case ' . $caseId . ': ' . $e->getMessage(),
			);
			return [];
		}//end try

		$rows = [];
		foreach ((array)$records as $record) {
			$row = $this->toArray(value: $record);
			if ($row !== []) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end loadCaseRoles()

	/**
	 * Coerce ObjectService return to plain array.
	 *
	 * @param mixed $value The record (entity or array)
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true) {
			if (method_exists($value, 'jsonSerialize') === true) {
				$serialised = $value->jsonSerialize();
				if (is_array($serialised) === true) {
					return $serialised;
				}
			}

			if (method_exists($value, 'toArray') === true) {
				$arr = $value->toArray();
				if (is_array($arr) === true) {
					return $arr;
				}
			}

			return (array)$value;
		}

		return [];
	}//end toArray()

	/**
	 * Throw a runtime exception with a static message.
	 *
	 * Helper for callers that wrap resolution; kept for symmetry.
	 *
	 * @param string $message Failure label
	 *
	 * @return never
	 *
	 * @throws RuntimeException
	 *
	 * @spec openspec/specs/role-based-step-routing/spec.md
	 */
	public function fail(string $message): never {
		throw new RuntimeException($message);
	}//end fail()
}//end class
