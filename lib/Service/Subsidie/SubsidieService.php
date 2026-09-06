<?php

/**
 * Dossiq Subsidie Service.
 *
 * Core domain service for the subsidieverlening-keten — the end-to-end
 * grant lifecycle under AWB titel 4.2. Owns subsidieaanvraag CRUD, the
 * aanvraag status machine, beschikkingnummer generation, voorschot-schema
 * validation (REQ-SUB-001), AWB termijn binding (REQ-SUB-002) and
 * verplichting tracking (REQ-SUB-003). Every persistence call goes through
 * OpenRegister via SettingsService::getObjectService() using the real API
 * (find/findAll/saveObject) — never bespoke CRUD.
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
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Core subsidy lifecycle service.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) — aggregates CRUD,
 * the aanvraag status machine, voorschot/verplichting validation and
 * termijn math for the subsidy domain.
 *
 * @spec openspec/changes/subsidieverlening-keten/specs.md
 */
class SubsidieService {

	use SearchesObjects;

	/**
	 * Canonical aanvraag status values.
	 *
	 * @var array<int, string>
	 */
	public const STATUSES = [
		'received',
		'in_assessment',
		'assessed',
		'decision_prepared',
		'granted',
		'rejected',
		'withdrawn',
	];

	/**
	 * Allowed aanvraag status transitions (from => [to, ...]).
	 *
	 * @var array<string, array<int, string>>
	 */
	public const TRANSITIONS = [
		'received' => ['in_assessment', 'withdrawn'],
		'in_assessment' => ['assessed', 'rejected', 'withdrawn'],
		'assessed' => ['decision_prepared', 'rejected', 'withdrawn'],
		'decision_prepared' => ['granted', 'rejected', 'withdrawn'],
		'granted' => ['withdrawn'],
		'rejected' => [],
		'withdrawn' => [],
	];

	/**
	 * Default AWB 4:13 decision term in weeks when the regeling is silent.
	 */
	public const DEFAULT_AANVRAAG_TERMIJN_WEKEN = 13;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema/register bridge.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a status transition is permitted by the aanvraag state machine.
	 *
	 * @param string $from Current status.
	 * @param string $to Target status.
	 *
	 * @return bool True when the transition is allowed.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function isTransitionAllowed(string $from, string $to): bool {
		$allowed = self::TRANSITIONS[$from] ?? null;
		if ($allowed === null) {
			return false;
		}

		return in_array($to, $allowed, true);
	}//end isTransitionAllowed()

	/**
	 * Generate a deterministic beschikkingnummer (SUB-YYYY-NNNNNN).
	 *
	 * @param int $sequence The running sequence number.
	 * @param DateTimeImmutable|null $now Clock injection for tests.
	 *
	 * @return string The formatted beschikkingnummer.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function generateBeschikkingnummer(int $sequence, ?DateTimeImmutable $now = null): string {
		$now = ($now ?? new DateTimeImmutable());
		$year = $now->format('Y');

		return sprintf('SUB-%s-%06d', $year, max(1, $sequence));
	}//end generateBeschikkingnummer()

	/**
	 * Compute the AWB decision deadline for an aanvraag.
	 *
	 * @param DateTimeImmutable $registration The registration date.
	 * @param int $weken The regeling term in weeks.
	 *
	 * @return DateTimeImmutable The decision deadline.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function computeBeslistermijn(DateTimeImmutable $registration, int $weken): DateTimeImmutable {
		$weken = max(1, $weken);
		return $registration->add(new DateInterval('P' . ($weken * 7) . 'D'));
	}//end computeBeslistermijn()

	/**
	 * Validate that a voorschot-schema sums to the verleend bedrag
	 * (REQ-SUB-001). Tolerates sub-cent floating-point drift.
	 *
	 * @param array<int, array<string, mixed>> $advanceSchema Disbursement rows.
	 * @param float $grantedAmount The granted amount.
	 *
	 * @return bool True when the schedule reconciles to the granted amount.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function voorschotSchemaReconciles(array $advanceSchema, float $grantedAmount): bool {
		$sum = 0.0;
		foreach ($advanceSchema as $voorschot) {
			$sum += (float)($voorschot['amount'] ?? 0);
		}

		return abs($sum - $grantedAmount) < 0.01;
	}//end voorschotSchemaReconciles()

	/**
	 * Decide whether a conditional voorschot may be released (REQ-SUB-001).
	 *
	 * A voorschot with no voorwaarde is unconditional. A voorwaarde of the
	 * form "tussenrapportage:{id}" requires that id to appear in the set of
	 * approved tussenrapportage ids.
	 *
	 * @param array<string, mixed> $voorschot The disbursement row.
	 * @param array<int, string> $approvedReports Approved tussenrapportage ids.
	 *
	 * @return bool True when the voorschot is releasable.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function isVoorschotReleasable(array $voorschot, array $approvedReports): bool {
		$voorwaarde = trim((string)($voorschot['voorwaarde'] ?? ''));
		if ($voorwaarde === '' || $voorwaarde === 'unconditional') {
			return true;
		}

		if (str_starts_with($voorwaarde, 'tussenrapportage:') === true) {
			$required = substr($voorwaarde, strlen('tussenrapportage:'));
			return in_array($required, $approvedReports, true);
		}

		// Unknown condition shapes fail closed — never auto-release.
		return false;
	}//end isVoorschotReleasable()

	/**
	 * Identify verplichtingen that are not yet voldaan (REQ-SUB-003). These
	 * become korting-grounds at vaststelling.
	 *
	 * @param array<int, array<string, mixed>> $verplichtingen Condition rows.
	 *
	 * @return array<int, array<string, mixed>> The unmet conditions.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function unmetVerplichtingen(array $verplichtingen): array {
		$unmet = [];
		foreach ($verplichtingen as $commitment) {
			$status = (string)($commitment['status'] ?? 'open');
			if ($status !== 'voldaan') {
				$unmet[] = $commitment;
			}
		}

		return $unmet;
	}//end unmetVerplichtingen()

	/**
	 * Create a subsidieaanvraag in status "received", binding the AWB
	 * decision term (REQ-SUB-002).
	 *
	 * @param array<string, mixed> $payload The aanvraag properties.
	 * @param int $termWeken The regeling decision term.
	 *
	 * @return array<string, mixed> The created aanvraag record.
	 *
	 * @throws OCSBadRequestException When OpenRegister is unavailable/unconfigured.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-002-awb-termijn-binding-for-each-phase
	 */
	public function createAanvraag(array $payload, int $termWeken = self::DEFAULT_AANVRAAG_TERMIJN_WEKEN): array {
		[$objectService, $register, $schema] = $this->resolve(schemaConfigKey: 'subsidie_aanvraag_schema');

		if (((string)($payload['subsidyScheme'] ?? '')) === '') {
			throw new OCSBadRequestException('subsidieregeling is verplicht');
		}

		$now = new DateTimeImmutable();
		$record = array_merge(
			$payload,
			[
				'status' => 'received',
				'beslistermijn' => $this->computeBeslistermijn(registration: $now, weken: $termWeken)->format('Y-m-d'),
			]
		);
		// The aanvrager BSN is special-category data and is never persisted raw.
		if (isset($record['applicantBsnRef']) === true) {
			$record['applicantBsnRef'] = $this->maskBsn(bsn: (string)$record['applicantBsnRef']);
		}

		try {
			return ($this->saveObjectAsArray(objectService: $objectService, register: $register, schema: $schema, object: $record) ?? $record);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq subsidie: createAanvraag failed: ' . $e->getMessage());
			throw new OCSBadRequestException('Kon subsidieaanvraag niet aanmaken');
		}
	}//end createAanvraag()

	/**
	 * Transition an aanvraag to a new status, enforcing the state machine.
	 *
	 * @param string $id The aanvraag id.
	 * @param string $toStatus The target status.
	 *
	 * @return array<string, mixed> The updated aanvraag record.
	 *
	 * @throws OCSBadRequestException When the transition is illegal or persistence fails.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-002-awb-termijn-binding-for-each-phase
	 */
	public function transitionAanvraag(string $id, string $toStatus): array {
		[$objectService, $register, $schema] = $this->resolve(schemaConfigKey: 'subsidie_aanvraag_schema');

		if (in_array($toStatus, self::STATUSES, true) === false) {
			throw new OCSBadRequestException('Onbekende status: ' . $toStatus);
		}

		$current = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: (string)$id);
		if ($current === null) {
			throw new OCSBadRequestException('Subsidieaanvraag niet gevonden');
		}

		$from = (string)($current['status'] ?? 'received');
		if ($this->isTransitionAllowed(from: $from, to: $toStatus) === false) {
			throw new OCSBadRequestException('Statusovergang ' . $from . ' -> ' . $toStatus . ' is niet toegestaan');
		}

		try {
			return ($this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				object: ['status' => $toStatus],
				uuid: (string)$id
			) ?? array_merge($current, ['status' => $toStatus]));
		} catch (Throwable $e) {
			$this->logger->error('Dossiq subsidie: transitionAanvraag failed: ' . $e->getMessage());
			throw new OCSBadRequestException('Kon status niet bijwerken');
		}
	}//end transitionAanvraag()

	/**
	 * List subsidieaanvragen, optionally filtered.
	 *
	 * @param array<string, mixed> $filters Optional status/regeling/handler filters.
	 *
	 * @return array<int, array<string, mixed>> The aanvragen.
	 *
	 * @throws OCSBadRequestException When OpenRegister is unavailable/unconfigured.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-002-awb-termijn-binding-for-each-phase
	 */
	public function listAanvragen(array $filters = []): array {
		[$objectService, $register, $schema] = $this->resolve(schemaConfigKey: 'subsidie_aanvraag_schema');

		$query = ['register' => (int)$register, 'schema' => (int)$schema];
		foreach (['status', 'subsidyScheme', 'handler'] as $field) {
			if (isset($filters[$field]) === true && $filters[$field] !== '') {
				$query[$field] = (string)$filters[$field];
			}
		}

		return $objectService->findAll(['filters' => $query]);
	}//end listAanvragen()

	/**
	 * Mask a BSN, keeping only the trailing three digits for audit linkage.
	 *
	 * @param string $bsn The raw BSN.
	 *
	 * @return string The masked reference.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-002-awb-termijn-binding-for-each-phase
	 */
	public function maskBsn(string $bsn): string {
		$digits = preg_replace('/\D/', '', $bsn);
		if ($digits === null || strlen($digits) < 3) {
			return '***';
		}

		return str_repeat('*', (strlen($digits) - 3)) . substr($digits, -3);
	}//end maskBsn()

	/**
	 * Resolve the ObjectService and register/schema ids for a config key.
	 *
	 * @param string $schemaConfigKey The schema config key.
	 *
	 * @return array{0: object, 1: string, 2: string} ObjectService, register, schema.
	 *
	 * @throws OCSBadRequestException When OpenRegister is unavailable or unconfigured.
	 */
	private function resolve(string $schemaConfigKey): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new OCSBadRequestException('OpenRegister is niet beschikbaar');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue($schemaConfigKey);
		if ($register === '' || $schema === '') {
			throw new OCSBadRequestException('Subsidie-schema is niet geconfigureerd');
		}

		return [$objectService, $register, $schema];
	}//end resolve()
}//end class
