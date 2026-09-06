<?php

/**
 * Dossiq Bewijsstuk Service.
 *
 * Evidence-document (bewijsstuk) management (REQ-SUB-007): upload metadata
 * handling against a per-phase type whitelist, bewaartermijn assignment per
 * Selectielijst defaults, SHA-256 hash computation and verification, and
 * immutability once linked to a vaststelling. Persistence delegates to
 * OpenRegister via SettingsService; the hash/retention/whitelist logic is
 * pure and unit-tested.
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
 * Evidence document upload, retention, hashing and immutability.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/subsidieverlening-keten/specs.md
 */
class BewijsstukService {

	use SearchesObjects;

	/**
	 * Allowed bewijsstuk types per source phase (REQ-SUB-007).
	 *
	 * @var array<string, array<int, string>>
	 */
	public const TYPE_WHITELIST = [
		'request' => ['aanvraagdocument', 'budget', 'projectplan', 'cofinancieringsverklaring', 'ander'],
		'interimReport' => ['voortgangsrapport', 'timesheet', 'invoice', 'bankafschrift', 'deelnemerslijst', 'ander'],
		'determination' => ['eindrapport', 'auditorsStatement', 'invoice', 'bankafschrift', 'ander'],
		'verplichtingsbewijs' => ['deelnemerslijst', 'timesheet', 'invoice', 'ander'],
	];

	/**
	 * Default retention (years) per source phase, per Selectielijst 4.x.
	 *
	 * @var array<string, int>
	 */
	public const DEFAULT_BEWAARTERMIJN = [
		'request' => 7,
		'interimReport' => 7,
		'determination' => 10,
		'verplichtingsbewijs' => 7,
	];

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
	 * Whether a bewijsstuk type is allowed for a source phase (REQ-SUB-007).
	 *
	 * @param string $linkedIn The source phase.
	 * @param string $type The bewijsstuk type.
	 *
	 * @return bool True when the combination is on the whitelist.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function isTypeAllowed(string $linkedIn, string $type): bool {
		$allowed = self::TYPE_WHITELIST[$linkedIn] ?? null;
		if ($allowed === null) {
			return false;
		}

		return in_array($type, $allowed, true);
	}//end isTypeAllowed()

	/**
	 * Resolve the retention years for a source phase, preferring a
	 * regeling-configured override (REQ-SUB-007).
	 *
	 * @param string $linkedIn The source phase.
	 * @param int|null $override Regeling-configured retention, if any.
	 *
	 * @return int The retention years.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function bewaartermijnJaren(string $linkedIn, ?int $override = null): int {
		if ($override !== null && $override > 0) {
			return $override;
		}

		return (self::DEFAULT_BEWAARTERMIJN[$linkedIn] ?? 7);
	}//end bewaartermijnJaren()

	/**
	 * Compute the retention end date.
	 *
	 * @param DateTimeImmutable $from The reference date.
	 * @param int $jaren The retention years.
	 *
	 * @return DateTimeImmutable The retention end date.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function bewaartermijnEinde(DateTimeImmutable $from, int $jaren): DateTimeImmutable {
		return $from->add(new DateInterval('P' . max(1, $jaren) . 'Y'));
	}//end bewaartermijnEinde()

	/**
	 * Compute the SHA-256 hash of file contents (REQ-SUB-007).
	 *
	 * @param string $contents The raw file contents.
	 *
	 * @return string The lowercase hex digest.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function computeHash(string $contents): string {
		return hash('sha256', $contents);
	}//end computeHash()

	/**
	 * Verify file contents against a recorded hash (REQ-SUB-007).
	 *
	 * @param string $contents The raw file contents.
	 * @param string $expectedHash The recorded digest.
	 *
	 * @return bool True when the hash matches (constant-time compare).
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function verifyHash(string $contents, string $expectedHash): bool {
		return hash_equals($expectedHash, $this->computeHash(contents: $contents));
	}//end verifyHash()

	/**
	 * Create a bewijsstuk with type validation, retention assignment and a
	 * content hash (REQ-SUB-007).
	 *
	 * @param array<string, mixed> $payload The bewijsstuk metadata.
	 * @param string|null $contents Raw file contents to hash, if available.
	 * @param int|null $regelingRetention Regeling-configured retention override.
	 *
	 * @return array<string, mixed> The created bewijsstuk record.
	 *
	 * @throws OCSBadRequestException When validation/persistence fails.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-007-bewijsstukken-management-with-bewaartermijn
	 */
	public function create(array $payload, ?string $contents = null, ?int $regelingRetention = null): array {
		$linkedIn = (string)($payload['linkedIn'] ?? '');
		$type = (string)($payload['evidenceType'] ?? '');
		if ($this->isTypeAllowed(linkedIn: $linkedIn, type: $type) === false) {
			throw new OCSBadRequestException('Bewijsstuktype "' . $type . '" is niet toegestaan voor fase "' . $linkedIn . '"');
		}

		[$objectService, $register, $schema] = $this->resolve();

		$now = new DateTimeImmutable();
		$jaren = $this->bewaartermijnJaren(linkedIn: $linkedIn, override: $regelingRetention);
		$record = array_merge(
			$payload,
			[
				'retentionPeriodYears' => $jaren,
				'retentionPeriodEnd' => $this->bewaartermijnEinde(from: $now, jaren: $jaren)->format('Y-m-d'),
				'archiveStatus' => 'active',
				'immutable' => ($linkedIn === 'determination'),
			]
		);
		if ($contents !== null) {
			$record['fileHashSha256'] = $this->computeHash(contents: $contents);
		}

		try {
			return ($this->saveObjectAsArray(objectService: $objectService, register: $register, schema: $schema, object: $record) ?? $record);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq subsidie: bewijsstuk create failed: ' . $e->getMessage());
			throw new OCSBadRequestException('Kon bewijsstuk niet opslaan');
		}
	}//end create()

	/**
	 * Guard against mutating/deleting a bewijsstuk linked to a vaststelling
	 * (REQ-SUB-007 immutability).
	 *
	 * @param array<string, mixed> $bewijsstuk The bewijsstuk record.
	 *
	 * @return void
	 *
	 * @throws OCSBadRequestException When the document is immutable.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-007-bewijsstukken-management-with-bewaartermijn
	 */
	public function assertMutable(array $bewijsstuk): void {
		if (($bewijsstuk['immutable'] ?? false) === true) {
			throw new OCSBadRequestException('Dit bewijsstuk is gekoppeld aan een vaststelling en is onveranderlijk');
		}
	}//end assertMutable()

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
		$schema = $this->settingsService->getConfigValue('bewijsstuk_schema');
		if ($register === '' || $schema === '') {
			throw new OCSBadRequestException('Bewijsstuk-schema is niet geconfigureerd');
		}

		return [$objectService, $register, $schema];
	}//end resolve()
}//end class
