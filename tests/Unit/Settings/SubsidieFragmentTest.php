<?php

/**
 * Subsidie Register Fragment Unit Tests
 *
 * Verifies that the register.d/50-subsidie.json fragment unions its nine
 * schemas, register membership and seed objects onto the dossiq monolith via
 * the ADR-037 deep-merge loader, without disturbing the base configuration.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
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

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * Integration-style unit tests for the subsidie register fragment.
 *
 * @covers \OCA\Dossiq\Service\SettingsService
 *
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 *
 * @spec openspec/changes/subsidieverlening-keten/tasks.md#TASK-SUB-01
 * @spec openspec/changes/subsidieverlening-keten/tasks.md#TASK-SUB-02
 */
class SubsidieFragmentTest extends TestCase {

	/**
	 * The nine subsidie schema slugs introduced by the fragment.
	 *
	 * @var array<int, string>
	 */
	private const SCHEMA_SLUGS = [
		'subsidieRegeling',
		'subsidieAanvraag',
		'subsidieBeoordeling',
		'subsidieBeschikking',
		'subsidieUitvoering',
		'interimReport',
		'subsidieVaststelling',
		'terugvordering',
		'bewijsstuk',
	];

	/**
	 * @var array<string, mixed>
	 */
	private array $merged;

	/**
	 * Load the monolith and merge the real register.d fragments.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$base = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'),
			true
		);

		[$merged] = (new RegisterFragmentMerger())->merge(
			base: $base,
			fragmentDir: __DIR__ . '/../../../lib/Settings/register.d'
		);

		$this->merged = $merged;
	}//end setUp()

	/**
	 * All nine subsidie schemas are present after the merge.
	 *
	 * @return void
	 */
	public function testSubsidieSchemasPresent(): void {
		$schemas = $this->merged['components']['schemas'];
		foreach (self::SCHEMA_SLUGS as $slug) {
			$this->assertArrayHasKey($slug, $schemas, $slug . ' schema must be merged in');
		}
	}//end testSubsidieSchemasPresent()

	/**
	 * The base schemas survive the union (disjoint merge).
	 *
	 * @return void
	 */
	public function testBaseSchemasPreserved(): void {
		$schemas = $this->merged['components']['schemas'];
		$this->assertArrayHasKey('case', $schemas);
		$this->assertArrayHasKey('caseType', $schemas);
	}//end testBaseSchemasPreserved()

	/**
	 * A contact/person is NOT re-invented — no bespoke applicant schema.
	 *
	 * @return void
	 */
	public function testNoApplicantSchemaInvented(): void {
		$schemas = $this->merged['components']['schemas'];
		$this->assertArrayNotHasKey('applicant', $schemas);
		$this->assertArrayNotHasKey('subsidieContact', $schemas);
		$this->assertArrayNotHasKey('subsidiePersoon', $schemas);
	}//end testNoApplicantSchemaInvented()

	/**
	 * The dossiq register lists the new subsidie schemas (list concatenation).
	 *
	 * @return void
	 */
	public function testRegisterMembershipUnioned(): void {
		$schemas = $this->merged['components']['registers']['dossiq']['schemas'];
		foreach (self::SCHEMA_SLUGS as $slug) {
			$this->assertContains($slug, $schemas, $slug . ' must be a member of the dossiq register');
		}

		// Existing membership preserved.
		$this->assertContains('caseType', $schemas);
	}//end testRegisterMembershipUnioned()

	/**
	 * The two schemes are seeded as CASE TYPES, not as subsidieregelingen.
	 *
	 * A fresh install must not create rows in the schema this change retires.
	 * It used to: the migration is a post-migration repair step, so on a fresh
	 * install it ran BEFORE the seeder wrote these rows and had nothing to
	 * convert — every new install therefore came up already needing migrating,
	 * and the e2e specs correctly failed with "2 present, none migrated".
	 *
	 * @return void
	 */
	public function testSeedObjectsAppended(): void {
		$objects = $this->merged['components']['objects'];
		$slugs = array_map(
			static function (array $object): string {
				return (string)($object['@self']['slug'] ?? '');
			},
			$objects
		);

		$this->assertContains('zaaktype-innovatiefonds-2026', $slugs);
		$this->assertContains('zaaktype-cultuur-subsidie-2026', $slugs);

		// The point of the change, asserted rather than assumed: nothing is
		// seeded into subsidieRegeling any more.
		$schemas = array_map(
			static function (array $object): string {
				return (string)($object['@self']['schema'] ?? '');
			},
			$objects
		);
		$this->assertNotContains('subsidieRegeling', $schemas);
	}//end testSeedObjectsAppended()

	/**
	 * The grant-specific fields are seeded as property definitions.
	 *
	 * Carrying the case types across without them would produce two case types
	 * that look right in a list and hold none of the scheme's actual terms.
	 *
	 * @return void
	 */
	public function testGrantPropertiesAreSeededAsDefinitions(): void {
		$definitions = array_filter(
			$this->merged['components']['objects'],
			static function (array $object): bool {
				return ($object['@self']['schema'] ?? '') === 'propertyDefinition';
			}
		);

		$byName = [];
		foreach ($definitions as $definition) {
			$byName[(string)($definition['name'] ?? '')] = $definition;
		}

		foreach (['plafond', 'targetGroup', 'auditorsStatementThreshold'] as $property) {
			$this->assertArrayHasKey($property, $byName, $property . ' must be seeded as a property definition');
		}

		// An enum with no enumValues is indistinguishable from a string: the
		// value survives and the constraint does not.
		$this->assertSame('enum', $byName['interimReportFrequency']['propertyType']);
		$this->assertContains('halfjaarlijks', $byName['interimReportFrequency']['enumValues']);
	}//end testGrantPropertiesAreSeededAsDefinitions()

	/**
	 * The bewijsstuk schema masks special-category data: BSN lives on the
	 * aanvraag as a masked reference, never as a raw plaintext property.
	 *
	 * @return void
	 */
	public function testBsnIsMaskedReference(): void {
		$request = $this->merged['components']['schemas']['subsidieAanvraag']['properties'];
		$this->assertArrayHasKey('applicantBsnRef', $request);
		$this->assertStringContainsStringIgnoringCase(
			'never stored raw',
			(string)$request['applicantBsnRef']['description']
		);
	}//end testBsnIsMaskedReference()

	/**
	 * The seed data and the migration agree on case-type identity.
	 *
	 * These are two independent writers of the same objects: the seeder on a
	 * fresh install, the repair step on an upgrade. They agree today because
	 * two constants in two files happen to match, and nothing else would notice
	 * if one moved.
	 *
	 * The failure that would follow is quiet in the worst way. The seeded
	 * property definitions reference their case type by id — `caseType` is
	 * `format: uuid`, so it cannot be a slug — and a mismatch leaves twelve
	 * definitions that exist, validate, and belong to a case type that is not
	 * the one on screen. The scheme simply appears to have no terms.
	 *
	 * @return void
	 */
	public function testTheSeedDataAndTheMigrationAgreeOnCaseTypeIds(): void {
		$reflected = new \ReflectionClass(
			\OCA\Dossiq\Repair\MigrateSubsidieRegelingToCaseType::class
		);
		$canonical = $reflected->getConstant('CANONICAL_CASE_TYPE_IDS');
		$this->assertIsArray($canonical, 'the migration must still pin canonical ids');

		$seededById = [];
		foreach ($this->merged['components']['objects'] as $object) {
			if (($object['@self']['schema'] ?? '') !== 'caseType') {
				continue;
			}

			$seededById[(string)($object['@self']['slug'] ?? '')] = (string)($object['id'] ?? '');
		}

		foreach ($canonical as $slug => $id) {
			$this->assertArrayHasKey(
				$slug,
				$seededById,
				$slug . ' is pinned by the migration but not seeded — the two writers disagree'
			);
			$this->assertSame(
				$id,
				$seededById[$slug],
				$slug . ' has a different id in the seed data than the migration writes'
			);
		}
	}//end testTheSeedDataAndTheMigrationAgreeOnCaseTypeIds()

	/**
	 * Every seeded property definition points at a seeded case type.
	 *
	 * A dangling reference here is invisible: the definition is valid on its
	 * own and simply belongs to nothing.
	 *
	 * @return void
	 */
	public function testEverySeededPropertyPointsAtASeededCaseType(): void {
		$caseTypeIds = [];
		$definitions = [];
		foreach ($this->merged['components']['objects'] as $object) {
			$schema = ($object['@self']['schema'] ?? '');
			if ($schema === 'caseType') {
				$caseTypeIds[] = (string)($object['id'] ?? '');
			}

			if ($schema === 'propertyDefinition') {
				$definitions[] = $object;
			}
		}

		$this->assertNotEmpty($definitions);
		foreach ($definitions as $definition) {
			$this->assertContains(
				(string)($definition['caseType'] ?? ''),
				$caseTypeIds,
				$definition['name'] . ' references a case type that is not seeded'
			);
		}
	}//end testEverySeededPropertyPointsAtASeededCaseType()

}//end class
