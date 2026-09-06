<?php

/**
 * Dossiq Tussenrapportage Service.
 *
 * Interim-report (tussenrapportage) workflow within a grant execution
 * (REQ-SUB-004). Owns auto-creation cadence (jaarlijks/halfjaarlijks),
 * the report status lifecycle, assessment deadline (termijn) binding,
 * approval — which releases conditionally dependent voorschotten — and the
 * partial-approval (gedeeltelijk_goedgekeurd) resubmission path. The
 * cadence and termijn math are pure and unit-tested; persistence delegates
 * to OpenRegister via SettingsService.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Subsidie
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

namespace OCA\Dossiq\Service\Subsidie;

use DateInterval;
use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Interim-report cadence, termijn binding and approval.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/subsidieverlening-keten/specs.md
 */
class TussenrapportageService {

	use SearchesObjects;

	/**
	 * Valid report status values.
	 *
	 * @var array<int, string>
	 */
	public const STATUSES = [
		'expected',
		'submitted',
		'in_assessment',
		'approved',
		'rejected',
		'gedeeltelijk_approved',
	];

	/**
	 * Default assessment term for an interim report, in weeks.
	 */
	public const DEFAULT_TERMIJN_WEKEN = 22;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema/register bridge.
	 * @param IUserSession $userSession Acting identity source.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Compute the assessment deadline for an interim report (REQ-SUB-004):
	 * the reporting period end plus the regeling-configured term.
	 *
	 * @param DateTimeImmutable $periodEnd The reporting period end.
	 * @param int $termWeken The regeling assessment term.
	 *
	 * @return DateTimeImmutable The assessment deadline.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function computeBeoordelingstermijn(DateTimeImmutable $periodEnd, int $termWeken): DateTimeImmutable {
		$termWeken = max(1, $termWeken);
		return $periodEnd->add(new DateInterval('P' . ($termWeken * 7) . 'D'));
	}//end computeBeoordelingstermijn()

	/**
	 * Compute the reporting-period boundaries for a frequentie within a year
	 * (REQ-SUB-004). Returns one period per cadence step; "on_milestone" and
	 * "none" yield no automatic periods.
	 *
	 * @param string $frequency The cadence (jaarlijks/halfjaarlijks/...).
	 * @param int $year The calendar year.
	 *
	 * @return array<int, array{start: string, eind: string}> The reporting periods.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function periodsForFrequentie(string $frequency, int $year): array {
		if ($frequency === 'annually') {
			return [['start' => sprintf('%d-01-01', $year), 'eind' => sprintf('%d-12-31', $year)]];
		}

		if ($frequency === 'halfjaarlijks') {
			return [
				['start' => sprintf('%d-01-01', $year), 'eind' => sprintf('%d-06-30', $year)],
				['start' => sprintf('%d-07-01', $year), 'eind' => sprintf('%d-12-31', $year)],
			];
		}

		return [];
	}//end periodsForFrequentie()

	/**
	 * Create an interim report in status "expected" (REQ-SUB-004).
	 *
	 * @param string $uitvoeringId The execution id.
	 * @param array<string, mixed> $payload The report properties.
	 *
	 * @return array<string, mixed> The created report record.
	 *
	 * @throws OCSBadRequestException When OpenRegister is unavailable/unconfigured.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-004-tussenrapportage-as-typed-sub-zaak
	 */
	public function createExpected(string $uitvoeringId, array $payload): array {
		[$objectService, $register, $schema] = $this->resolve();

		$record = array_merge(
			$payload,
			[
				'subsidieuitvoering' => $uitvoeringId,
				'status' => 'expected',
				'amendmentNumerator' => 0,
			]
		);

		try {
			return ($this->saveObjectAsArray(objectService: $objectService, register: $register, schema: $schema, object: $record) ?? $record);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq subsidie: createExpected tussenrapportage failed: ' . $e->getMessage());
			throw new OCSBadRequestException('Kon tussenrapportage niet aanmaken');
		}
	}//end createExpected()

	/**
	 * Approve an interim report (REQ-SUB-004). Records the assessor (from
	 * session, never the body), the assessment date, and sets the status to
	 * goedgekeurd. The caller surfaces the report id so the voorschot engine
	 * can release conditionally dependent disbursements.
	 *
	 * @param string $reportId The report id.
	 * @param string|null $beoordelingsoordeel Optional assessment narrative.
	 * @param float|null $approvedAmount Optional approved amount.
	 *
	 * @return array<string, mixed> The approved report record.
	 *
	 * @throws OCSBadRequestException When unauthenticated or persistence fails.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-004-tussenrapportage-as-typed-sub-zaak
	 */
	public function approveReport(string $reportId, ?string $beoordelingsoordeel = null, ?float $approvedAmount = null): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSBadRequestException('Authenticatie vereist om te beoordelen');
		}

		[$objectService, $register, $schema] = $this->resolve();

		$patch = [
			'status' => 'approved',
			'assessor' => $user->getUID(),
			'beoordelingsdatum' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
		];
		if ($beoordelingsoordeel !== null) {
			$patch['beoordelingsoordeel'] = $beoordelingsoordeel;
		}

		if ($approvedAmount !== null) {
			$patch['approvedAmount'] = $approvedAmount;
		}

		try {
			return ($this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				object: $patch,
				uuid: (string)$reportId
			) ?? $patch);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq subsidie: approveReport failed: ' . $e->getMessage());
			throw new OCSBadRequestException('Kon tussenrapportage niet goedkeuren');
		}
	}//end approveReport()

	/**
	 * Partially approve an interim report with required corrections
	 * (REQ-SUB-004), permitting resubmission and incrementing the amendment
	 * counter.
	 *
	 * @param string $reportId The report id.
	 * @param string $correctionRequest The required-corrections text.
	 * @param int $currentTeller The current amendment count.
	 *
	 * @return array<string, mixed> The updated report record.
	 *
	 * @throws OCSBadRequestException When the corrections text is empty or persistence fails.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-004-tussenrapportage-as-typed-sub-zaak
	 */
	public function partialApprove(string $reportId, string $correctionRequest, int $currentTeller): array {
		if (trim($correctionRequest) === '') {
			throw new OCSBadRequestException('Een correctieverzoek is verplicht bij gedeeltelijke goedkeuring');
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSBadRequestException('Authenticatie vereist om te beoordelen');
		}

		[$objectService, $register, $schema] = $this->resolve();

		$patch = [
			'status' => 'gedeeltelijk_approved',
			'correctionRequest' => $correctionRequest,
			'amendmentNumerator' => ($currentTeller + 1),
			'assessor' => $user->getUID(),
			'beoordelingsdatum' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
		];

		try {
			return ($this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				object: $patch,
				uuid: (string)$reportId
			) ?? $patch);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq subsidie: partialApprove failed: ' . $e->getMessage());
			throw new OCSBadRequestException('Kon tussenrapportage niet gedeeltelijk goedkeuren');
		}
	}//end partialApprove()

	/**
	 * Resolve the ObjectService and register/schema ids.
	 *
	 * @return array{0: object, 1: string, 2: string} ObjectService, register, schema.
	 *
	 * @throws OCSBadRequestException When OpenRegister is unavailable or unconfigured.
	 */
	private function resolve(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new OCSBadRequestException('OpenRegister is niet beschikbaar');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('tussenrapportage_schema');
		if ($register === '' || $schema === '') {
			throw new OCSBadRequestException('Tussenrapportage-schema is niet geconfigureerd');
		}

		return [$objectService, $register, $schema];
	}//end resolve()
}//end class
