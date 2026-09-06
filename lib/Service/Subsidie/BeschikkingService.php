<?php

/**
 * Dossiq Beschikking Service.
 *
 * Grant-decision (beschikking) lifecycle: drafting with a validated
 * voorschot-schema (sum must equal verleend bedrag, REQ-SUB-001),
 * verplichting (condition) management, beschikkingnummer generation,
 * digital-signature recording, and publication that stamps the
 * bezwaartermijn. Persistence delegates to OpenRegister via SettingsService.
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
 * Grant-decision drafting, validation, signing and publication.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/subsidieverlening-keten/specs.md
 */
class BeschikkingService {

	use SearchesObjects;

	/**
	 * Bezwaartermijn (objection window) in weeks (AWB 6:7).
	 */
	public const BEZWAARTERMIJN_WEKEN = 6;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema/register bridge.
	 * @param SubsidieService $subsidyService Core service (voorschot validation, nummers).
	 * @param IUserSession $userSession Acting identity source.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly SubsidieService $subsidyService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Compute the bezwaartermijn end date from a publication date.
	 *
	 * @param DateTimeImmutable $publication The publication date.
	 *
	 * @return DateTimeImmutable The bezwaartermijn end.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function computeBezwaartermijn(DateTimeImmutable $publication): DateTimeImmutable {
		return $publication->add(new DateInterval('P' . (self::BEZWAARTERMIJN_WEKEN * 7) . 'D'));
	}//end computeBezwaartermijn()

	/**
	 * Validate a draft beschikking payload (REQ-SUB-001).
	 *
	 * @param array<string, mixed> $payload The beschikking properties.
	 *
	 * @return void
	 *
	 * @throws OCSBadRequestException When validation fails.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function assertDraftValid(array $payload): void {
		$granted = (float)($payload['grantedAmount'] ?? 0);
		if ($granted <= 0.0) {
			throw new OCSBadRequestException('verleendBedrag moet positief zijn');
		}

		$schema = $payload['advanceSchema'] ?? [];
		if (is_string($schema) === true) {
			$schema = (json_decode($schema, true) ?? []);
		}

		if (is_array($schema) === true && $schema !== []) {
			if ($this->subsidyService->voorschotSchemaReconciles(advanceSchema: $schema, grantedAmount: $granted) === false) {
				throw new OCSBadRequestException(
					'De som van de voorschotten moet gelijk zijn aan het verleende bedrag'
				);
			}
		}
	}//end assertDraftValid()

	/**
	 * Create a draft beschikking with a generated beschikkingnummer.
	 *
	 * @param string $requestId The application id.
	 * @param array<string, mixed> $payload The beschikking properties.
	 * @param int $sequence The running beschikking sequence.
	 *
	 * @return array<string, mixed> The created beschikking record.
	 *
	 * @throws OCSBadRequestException When validation/persistence fails.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-001-multi-year-beschikking-with-voorschot-schema
	 */
	public function createDraft(string $requestId, array $payload, int $sequence): array {
		$this->assertDraftValid(payload: $payload);
		[$objectService, $register, $schema] = $this->resolve();

		$record = array_merge(
			$payload,
			[
				'subsidieaanvraag' => $requestId,
				'beschikkingnummer' => $this->subsidyService->generateBeschikkingnummer(sequence: $sequence),
				'beschikkingtype' => (string)($payload['beschikkingtype'] ?? 'verleningsbeschikking'),
				'status' => 'draft',
			]
		);
		unset($record['signedBy'], $record['signedOn'], $record['publicationDate']);

		try {
			return ($this->saveObjectAsArray(objectService: $objectService, register: $register, schema: $schema, object: $record) ?? $record);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq subsidie: createDraft beschikking failed: ' . $e->getMessage());
			throw new OCSBadRequestException('Kon beschikking niet aanmaken');
		}
	}//end createDraft()

	/**
	 * Record a digital signature on a beschikking (REQ — security policy).
	 * The signer identity is always derived from the session, never trusted
	 * from the request body.
	 *
	 * @param string $decisionId The beschikking id.
	 *
	 * @return array<string, mixed> The signed beschikking record.
	 *
	 * @throws OCSBadRequestException When unauthenticated or persistence fails.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-001-multi-year-beschikking-with-voorschot-schema
	 */
	public function sign(string $decisionId): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSBadRequestException('Authenticatie vereist om te ondertekenen');
		}

		[$objectService, $register, $schema] = $this->resolve();

		$patch = [
			'signedBy' => $user->getUID(),
			'signedOn' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
		];

		try {
			return ($this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				object: $patch,
				uuid: (string)$decisionId
			) ?? $patch);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq subsidie: sign beschikking failed: ' . $e->getMessage());
			throw new OCSBadRequestException('Kon beschikking niet ondertekenen');
		}
	}//end sign()

	/**
	 * Publish a beschikking, stamping the publicatiedatum and bezwaartermijn.
	 *
	 * @param string $decisionId The beschikking id.
	 *
	 * @return array<string, mixed> The published beschikking record.
	 *
	 * @throws OCSBadRequestException When the beschikking is unsigned or persistence fails.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-006-subsidieregister-publication-feed
	 */
	public function publish(string $decisionId): array {
		[$objectService, $register, $schema] = $this->resolve();

		// The lookup is INSIDE a try because OpenRegister's find() THROWS on a
		// missing object rather than returning a non-array. Outside one, that
		// throw escaped the controller as an HTML 500 — and the `is_array()`
		// guard below could never fire, so 'Beschikking niet gevonden' was a
		// message no caller could ever receive. sign(), directly below, has
		// always wrapped its call and answers a clean 400; this now matches it.
		try {
			$current = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $decisionId);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq subsidie: publish beschikking lookup failed: ' . $e->getMessage());
			throw new OCSBadRequestException('Beschikking niet gevonden');
		}

		if ($current === null) {
			throw new OCSBadRequestException('Beschikking niet gevonden');
		}

		if (((string)($current['signedBy'] ?? '')) === '') {
			throw new OCSBadRequestException('Beschikking moet eerst worden ondertekend');
		}

		$now = new DateTimeImmutable();
		$patch = [
			'status' => 'granted',
			'publicationDate' => $now->format('Y-m-d'),
			'objectionPeriodEnd' => $this->computeBezwaartermijn(publication: $now)->format('Y-m-d'),
		];

		try {
			return ($this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				object: $patch,
				uuid: (string)$decisionId
			) ?? array_merge($current, $patch));
		} catch (Throwable $e) {
			$this->logger->error('Dossiq subsidie: publish beschikking failed: ' . $e->getMessage());
			throw new OCSBadRequestException('Kon beschikking niet publiceren');
		}
	}//end publish()

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
		$schema = $this->settingsService->getConfigValue('subsidie_beschikking_schema');
		if ($register === '' || $schema === '') {
			throw new OCSBadRequestException('Beschikking-schema is niet geconfigureerd');
		}

		return [$objectService, $register, $schema];
	}//end resolve()
}//end class
