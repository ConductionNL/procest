<?php

/**
 * Projects dossiq `partnerOrganization` rows onto OpenRegister Organisations.
 *
 * A ketenpartner is an organisation. dossiq kept its own copy because
 * OpenRegister's Organisation had no room for the three fields case sharing
 * actually needs — `defaultPermissionLevel`, `qualityScore`, `qualityStatus` —
 * so a partner could not have been represented there without losing them.
 *
 * It can now. Organisation carries all three, plus `oin`, `contactEmail`,
 * `slug`, `name`, `groups` and `active`, which is every property
 * `partnerOrganization` declares. This is the check that had to come first:
 * the parafering projection shows what happens when a projection is enabled
 * onto a model that cannot hold what the source held, and the answer is a
 * silent loss of record. Here the target is a superset, field for field.
 *
 * Idempotent by the partner's own UUID, and this is where it differs from
 * TenantMigrationService, which keys on the slug. `partnerOrganization`
 * requires only `name` and `contactEmail`, so a slug-less partner is ordinary
 * data and keying on the slug would fail the migration on exactly the rows it
 * exists to move. Deriving a slug from the name is worse still: two partners
 * sharing a name derive one slug, and the second is then skipped as already
 * migrated, silently merging two organisations into one.
 *
 * This docblock previously claimed slug idempotency, which the code has never
 * done — see the marked comment at the resolution site. A docblock that
 * contradicts its own function is worse than none: it is what a reader checks
 * instead of the code.
 *
 * WHAT THIS DOES NOT DO. It does not retire the `partnerOrganization` schema or
 * the Partners settings page. Rows have to move before the surface that writes
 * them goes, or a migration lands on data still being created behind it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/partners-are-organisations/specs/partner-organisations/spec.md
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\OpenRegister\Db\Organisation;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Migrates dossiq `partnerOrganization` objects to OR Organisations.
 *
 * @spec openspec/changes/partners-are-organisations/specs/partner-organisations/spec.md
 */
class PartnerMigrationService {

	use SearchesObjects;

	/**
	 * The dossiq register slug.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'dossiq';

	/**
	 * The legacy partner schema slug.
	 *
	 * @var string
	 */
	private const PARTNER_SCHEMA_SLUG = 'partnerOrganization';

	/**
	 * The Organisation `type` a migrated partner carries.
	 *
	 * A partner is an EXTERNAL organisation this instance shares cases with,
	 * not a tenant of it. Recording that on the Organisation is what keeps the
	 * two tellable apart once both live in the same place.
	 *
	 * @var string
	 */
	private const PARTNER_TYPE = 'partner';

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService Register/schema configuration.
	 * @param ContainerInterface $container       DI container (resolves OR's OrganisationMapper).
	 * @param IAppManager        $appManager      Detects whether OpenRegister is installed.
	 * @param LoggerInterface    $logger          PSR-3 logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run the migration.
	 *
	 * @return array{migrated:int, skipped:int, failed:int, total:int, mappings:array<int,array{partner:string, organisation:string}>}
	 *                                                                                                                               The outcome tally.
	 *
	 * @spec openspec/changes/partners-are-organisations/specs/partner-organisations/spec.md
	 */
	public function migrate(): array {
		$summary = [
			'migrated' => 0,
			'skipped' => 0,
			'failed' => 0,
			'total' => 0,
			'mappings' => [],
		];

		$objectService = $this->settingsService->getObjectService();
		$mapper = $this->getOrganisationMapper();
		if ($objectService === null || $mapper === null) {
			$this->logger->warning('Dossiq: partner migration skipped — OpenRegister organisation services unavailable');
			return $summary;
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: self::REGISTER_SLUG,
				schema: self::PARTNER_SCHEMA_SLUG,
				filters: ['_limit' => 5000],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: partner migration found no partnerOrganization rows (schema absent or empty)',
				['exception' => $e->getMessage()],
			);
			return $summary;
		}

		$summary['total'] = count($rows);

		foreach ($rows as $row) {
			$result = $this->migrateOne(mapper: $mapper, row: $this->toRow(value: $row));
			if ($result === null) {
				$summary['failed']++;
				continue;
			}

			if ($result['created'] === false) {
				$summary['skipped']++;
				continue;
			}

			$summary['migrated']++;
			$summary['mappings'][] = [
				'partner' => $result['partnerUuid'],
				'organisation' => $result['organisationUuid'],
			];
		}

		$this->logger->info(
			'Dossiq: partner migration complete',
			[
				'total' => $summary['total'],
				'migrated' => $summary['migrated'],
				'skipped' => $summary['skipped'],
				'failed' => $summary['failed'],
			],
		);

		return $summary;
	}//end migrate()

	/**
	 * Migrate one partner row.
	 *
	 * @param object               $mapper OR OrganisationMapper.
	 * @param array<string, mixed> $row    The partner object.
	 *
	 * @return array{created:bool, partnerUuid:string, organisationUuid:string}|null Result, or null on failure.
	 *
	 * @spec openspec/changes/partners-are-organisations/specs/partner-organisations/spec.md
	 */
	private function migrateOne(object $mapper, array $row): ?array {
		$partnerUuid = trim((string)($row['id'] ?? ($row['uuid'] ?? '')));
		if ($partnerUuid === '') {
			// 🔴 The uuid is the idempotency key, NOT the slug.
			//
			// `partnerOrganization` requires only `name` and `contactEmail`, so
			// a partner is free to carry no slug at all, and refusing those
			// would fail the migration on ordinary data. The uuid is always
			// there, is what case shares already reference, and is what this
			// migration preserves onto the Organisation — which makes it an
			// exact key rather than a derived one.
			//
			// Deriving a slug from the name was the alternative and is worse:
			// two partners sharing a name would derive the same slug, and the
			// second would be skipped as "already migrated", silently merging
			// two organisations into one.
			$this->logger->warning('Dossiq: partner migration skipped a row with no id');
			return null;
		}

		$slug = trim((string)($row['slug'] ?? ''));

		try {
			$existing = $this->findOrganisationByUuid(mapper: $mapper, uuid: $partnerUuid);
			if ($existing !== null) {
				return [
					'created' => false,
					'partnerUuid' => $partnerUuid,
					'organisationUuid' => (string)$existing->getUuid(),
				];
			}

			$saved = $mapper->insert($this->buildOrganisation(row: $row, slug: $slug, partnerUuid: $partnerUuid));

			$this->logger->info(
				'Dossiq: migrated partner to OR Organisation',
				['partner' => $partnerUuid, 'organisation' => (string)$saved->getUuid(), 'slug' => $slug],
			);

			return [
				'created' => true,
				'partnerUuid' => $partnerUuid,
				'organisationUuid' => (string)$saved->getUuid(),
			];
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: partner migration failed for one row',
				['partner' => $partnerUuid, 'slug' => $slug, 'exception' => $e->getMessage()],
			);
			return null;
		}//end try
	}//end migrateOne()

	/**
	 * Build an Organisation from a partner row.
	 *
	 * Every property `partnerOrganization` declares has a home here. The three
	 * that used to have none — defaultPermissionLevel, qualityScore,
	 * qualityStatus — are the reason dossiq kept its own copy, and are why this
	 * migration could not have been written before Organisation grew them.
	 *
	 * @param array<string, mixed> $row         The partner object.
	 * @param string               $slug        The partner slug.
	 * @param string               $partnerUuid The partner uuid, preserved.
	 *
	 * @return Organisation The unsaved Organisation.
	 *
	 * @spec openspec/changes/partners-are-organisations/specs/partner-organisations/spec.md
	 */
	private function buildOrganisation(array $row, string $slug, string $partnerUuid): Organisation {
		$organisation = new Organisation();

		// Preserved so anything already pointing at the partner by id keeps
		// resolving. Case shares reference partners; re-minting the id would
		// strand every one of them.
		if ($partnerUuid !== '') {
			$organisation->setUuid($partnerUuid);
		}

		// A partner may carry no slug. Organisation wants one, so it is derived
		// from the uuid rather than the name: derived-from-name collides for
		// two partners sharing a name, and a collision here is a merge.
		$resolvedSlug = $slug;
		if ($resolvedSlug === '') {
			$resolvedSlug = ('partner-' . $partnerUuid);
		}

		$name = trim((string)($row['name'] ?? ''));
		if ($name === '') {
			$name = $resolvedSlug;
		}

		$organisation->setSlug($resolvedSlug);
		$organisation->setName($name);
		$organisation->setType(self::PARTNER_TYPE);

		// An external party, by definition: a ketenpartner is somebody else's
		// organisation that this instance shares cases WITH.
		$organisation->setIsLocalTenant(false);

		$active = (bool)($row['isActive'] ?? true);
		$organisation->setActive($active);
		$status = 'suspended';
		if ($active === true) {
			$status = 'active';
		}

		$organisation->setStatus($status);

		$this->copyOptionalFields(row: $row, organisation: $organisation);

		return $organisation;
	}//end buildOrganisation()

	/**
	 * Copy the fields a partner may or may not carry.
	 *
	 * Split out of buildOrganisation() because that method's branch count
	 * crossed phpmd's cyclomatic and NPath thresholds, and a method whose only
	 * job is "copy across whatever is present" reads better alone anyway.
	 *
	 * @param array<string, mixed> $row          The partner object.
	 * @param Organisation         $organisation The organisation being built.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/partners-are-organisations/specs/partner-organisations/spec.md
	 */
	private function copyOptionalFields(array $row, Organisation $organisation): void {
		$strings = [
			'oin' => 'setOin',
			'contactEmail' => 'setContactEmail',
			'qualityStatus' => 'setQualityStatus',
			'defaultPermissionLevel' => 'setDefaultPermissionLevel',
		];

		foreach ($strings as $field => $setter) {
			$value = trim((string)($row[$field] ?? ''));
			if ($value !== '') {
				$organisation->{$setter}($value);
			}
		}

		if (is_numeric($row['qualityScore'] ?? null) === true) {
			$organisation->setQualityScore((int)$row['qualityScore']);
		}

		$groupId = trim((string)($row['groupId'] ?? ''));
		if ($groupId !== '') {
			// `groups` is a list where the partner held one id. Widening is
			// safe; narrowing later would not be, so the list is the shape.
			$organisation->setGroups([$groupId]);
		}
	}//end copyOptionalFields()

	/**
	 * Normalise a search result row to an array.
	 *
	 * @param mixed $value The row.
	 *
	 * @return array<string, mixed> The row as an array.
	 */
	private function toRow(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'getObject') === true) {
			$object = $value->getObject();
			if (is_array($object) === true) {
				return $object;
			}
		}

		if (is_object($value) === true) {
			return (array)$value;
		}

		return [];
	}//end toRow()

	/**
	 * Find an Organisation by uuid, returning null when absent.
	 *
	 * @param object $mapper OR OrganisationMapper.
	 * @param string $uuid   Uuid to look up.
	 *
	 * @return object|null The Organisation, or null when none matches.
	 */
	private function findOrganisationByUuid(object $mapper, string $uuid): ?object {
		try {
			return $mapper->findByUuid($uuid);
		} catch (Throwable $e) {
			// DoesNotExistException (and any other lookup failure) → absent.
			return null;
		}
	}//end findOrganisationByUuid()

	/**
	 * Resolve OR's OrganisationMapper from the DI container.
	 *
	 * @return object|null The OrganisationMapper, or null when OR is unavailable.
	 */
	private function getOrganisationMapper(): ?object {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\\OpenRegister\\Db\\OrganisationMapper');
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: could not get OrganisationMapper for partner migration',
				['exception' => $e->getMessage()],
			);
			return null;
		}
	}//end getOrganisationMapper()
}//end class
