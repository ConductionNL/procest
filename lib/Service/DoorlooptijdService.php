<?php

/**
 * Dossiq Doorlooptijd (throughput-time) Service
 *
 * Aggregates KPIs, monthly compliance, case-type breakdown and the open-case
 * list for the throughput-time dashboard. All metrics are computed from the
 * Dossiq `case` register via OpenRegister's ObjectService — no separate
 * analytics store. See `openspec/changes/doorlooptijd-dashboard/design.md`.
 *
 * This class is the read path and the orchestrator only. Deriving a case's
 * working fields lives in {@see CaseEnricher}; the deadline-based metrics
 * live in {@see DeadlineComplianceCalculator}; the elapsed-time breakdown
 * lives in {@see CaseTypeThroughputCalculator}. What remains here is loading
 * the two registers and assembling the four payload keys.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Doorlooptijd\CaseEnricher;
use OCA\Dossiq\Service\Doorlooptijd\CaseTypeThroughputCalculator;
use OCA\Dossiq\Service\Doorlooptijd\DeadlineComplianceCalculator;
use OCA\Dossiq\Service\Support\SearchesObjects;

/**
 * Computes throughput-time metrics for the case dashboard.
 *
 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#T01
 */
class DoorlooptijdService {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Shared settings/OR resolver.
	 * @param CaseEnricher $caseEnricher Derives the `_`-prefixed working fields.
	 * @param DeadlineComplianceCalculator $complianceCalculator KPI bands, on-time %, monthly compliance, RAG list.
	 * @param CaseTypeThroughputCalculator $throughputCalculator Average closed-case throughput per case-type.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseEnricher $caseEnricher,
		private readonly DeadlineComplianceCalculator $complianceCalculator,
		private readonly CaseTypeThroughputCalculator $throughputCalculator,
	) {
	}//end __construct()

	/**
	 * Compute the full metrics payload for the dashboard.
	 *
	 * @param array<string, mixed> $params Query parameters from the controller.
	 *
	 * @return array<string, mixed> The structured response body.
	 *
	 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#T01
	 */
	public function getMetrics(array $params): array {
		$caseTypeFilter = null;
		if (isset($params['caseType']) === true && is_string($params['caseType']) === true) {
			$caseTypeFilter = $params['caseType'];
		}

		$period = '12m';
		if (isset($params['period']) === true && is_string($params['period']) === true) {
			$period = $params['period'];
		}

		$atRiskDays = 5;
		if (isset($params['atRiskDays']) === true) {
			$atRiskDays = (int)$params['atRiskDays'];
		}

		if ($atRiskDays < 0) {
			$atRiskDays = 0;
		}

		$cases = $this->loadCases(caseTypeFilter: $caseTypeFilter);
		$caseTypes = $this->loadCaseTypes();

		$enriched = $this->caseEnricher->enrichCases(cases: $cases, caseTypes: $caseTypes);

		return [
			'kpi' => $this->complianceCalculator->computeKpi(cases: $enriched, atRiskDays: $atRiskDays),
			'compliance' => $this->complianceCalculator->computeMonthlyCompliance(cases: $enriched, period: $period),
			'caseTypeBreakdown' => $this->throughputCalculator->computeCaseTypeBreakdown(cases: $enriched, caseTypes: $caseTypes),
			'cases' => $this->complianceCalculator->buildCaseList(cases: $enriched, atRiskDays: $atRiskDays),
		];
	}//end getMetrics()

	/**
	 * Compute the four headline KPIs.
	 *
	 * Delegates to {@see DeadlineComplianceCalculator::computeKpi()}.
	 *
	 * @param array<int, array<string, mixed>> $cases Enriched cases.
	 * @param int $atRiskDays Threshold for at-risk band.
	 *
	 * @return array{open: int, atRisk: int, overdue: int, onTimePercent: int}
	 *
	 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#T01
	 */
	public function computeKpi(array $cases, int $atRiskDays): array {
		return $this->complianceCalculator->computeKpi(cases: $cases, atRiskDays: $atRiskDays);
	}//end computeKpi()

	/**
	 * Monthly on-time / late counts over the requested period.
	 *
	 * Delegates to {@see DeadlineComplianceCalculator::computeMonthlyCompliance()}.
	 *
	 * @param array<int, array<string, mixed>> $cases Enriched cases.
	 * @param string $period Period spec (e.g. `12m`, `6m`, `3m`).
	 *
	 * @return array<int, array{month: string, onTime: int, late: int, percent: int}>
	 *
	 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#T01
	 */
	public function computeMonthlyCompliance(array $cases, string $period): array {
		return $this->complianceCalculator->computeMonthlyCompliance(cases: $cases, period: $period);
	}//end computeMonthlyCompliance()

	/**
	 * Average closed-case throughput by case-type.
	 *
	 * Delegates to {@see CaseTypeThroughputCalculator::computeCaseTypeBreakdown()}.
	 *
	 * @param array<int, array<string, mixed>> $cases Enriched cases.
	 * @param array<int, array<string, mixed>> $caseTypes Indexed case-type metadata.
	 *
	 * @return array<int, array{id: string, title: string, avgDays: int, count: int}>
	 *
	 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#T01
	 */
	public function computeCaseTypeBreakdown(array $cases, array $caseTypes): array {
		return $this->throughputCalculator->computeCaseTypeBreakdown(cases: $cases, caseTypes: $caseTypes);
	}//end computeCaseTypeBreakdown()

	/**
	 * Build the sortable list of open cases with RAG status.
	 *
	 * Delegates to {@see DeadlineComplianceCalculator::buildCaseList()}.
	 *
	 * @param array<int, array<string, mixed>> $cases Enriched cases.
	 * @param int $atRiskDays Threshold for at-risk band.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#T01
	 */
	public function buildCaseList(array $cases, int $atRiskDays): array {
		return $this->complianceCalculator->buildCaseList(cases: $cases, atRiskDays: $atRiskDays);
	}//end buildCaseList()

	/**
	 * Enrich each raw case with derived fields used by the metric helpers.
	 *
	 * Delegates to {@see CaseEnricher::enrichCases()}.
	 *
	 * @param array<int, array<string, mixed>> $cases Raw cases.
	 * @param array<int, array<string, mixed>> $caseTypes Raw case-types.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#T01
	 */
	public function enrichCases(array $cases, array $caseTypes): array {
		return $this->caseEnricher->enrichCases(cases: $cases, caseTypes: $caseTypes);
	}//end enrichCases()

	/**
	 * Load every case record via OpenRegister.
	 *
	 * @param string|null $caseTypeFilter Optional caseType filter (UUID or slug).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadCases(?string $caseTypeFilter): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');
		if (empty($register) === true || empty($schema) === true) {
			return [];
		}

		$filters = ['_limit' => 1000];
		if ($caseTypeFilter !== null && $caseTypeFilter !== '') {
			$filters['caseType'] = $caseTypeFilter;
		}

		return $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: $filters,
		);
	}//end loadCases()

	/**
	 * Load all caseType definitions so the service can resolve titles and
	 * derived deadlines.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadCaseTypes(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_type_schema');
		if (empty($register) === true || empty($schema) === true) {
			return [];
		}

		return $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['_limit' => 500],
		);
	}//end loadCaseTypes()
}//end class
