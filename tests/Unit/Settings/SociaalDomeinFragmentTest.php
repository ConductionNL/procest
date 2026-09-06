<?php

/**
 * Sociaal Domein Register Fragment Unit Tests
 *
 * Verifies that the register.d/50-sociaal-domein.json fragment unions its
 * schemas (WMO, Jeugdwet, Participatiewet + supporting AVG/consent entities),
 * register membership and seed objects onto the dossiq monolith via the
 * ADR-037 deep-merge loader, without colliding with the base register.
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
 * Integration-style unit tests for the sociaal-domein register fragment.
 *
 * @covers \OCA\Dossiq\Service\SettingsService
 *
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class SociaalDomeinFragmentTest extends TestCase {

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
	 * The three sociaal-domein zaaktype schemas are present after the merge.
	 *
	 * @return void
	 */
	public function testZaaktypeSchemasPresent(): void {
		$schemas = $this->merged['components']['schemas'];
		$this->assertArrayHasKey('wmoZaak', $schemas);
		$this->assertArrayHasKey('jeugdwetZaak', $schemas);
		$this->assertArrayHasKey('participatiewetZaak', $schemas);
	}//end testZaaktypeSchemasPresent()

	/**
	 * The supporting entities (Indicatiestelling, Gezinsplan, MdoOverleg,
	 * ReIntegratieTraject, Toestemming, AvgClassificatie, audit log, incident)
	 * are present after the merge.
	 *
	 * @return void
	 */
	public function testSupportingSchemasPresent(): void {
		$schemas = $this->merged['components']['schemas'];
		$this->assertArrayHasKey('indicatiestelling', $schemas);
		$this->assertArrayHasKey('gezinsplan', $schemas);
		$this->assertArrayHasKey('mdoOverleg', $schemas);
		$this->assertArrayHasKey('reIntegratieTraject', $schemas);
		$this->assertArrayHasKey('toestemming', $schemas);
		$this->assertArrayHasKey('gdprClassification', $schemas);
		$this->assertArrayHasKey('sociaalDomeinAuditLog', $schemas);
		$this->assertArrayHasKey('avgIncident', $schemas);
	}//end testSupportingSchemasPresent()

	/**
	 * The fragment does not duplicate or clobber an existing base schema; the
	 * pre-existing case/caseType schemas survive the union untouched.
	 *
	 * @return void
	 */
	public function testBaseSchemasPreserved(): void {
		$schemas = $this->merged['components']['schemas'];
		$this->assertArrayHasKey('case', $schemas);
		$this->assertArrayHasKey('caseType', $schemas);
	}//end testBaseSchemasPreserved()

	/**
	 * Every zaaktype embeds the mandatory AVG classification value-type,
	 * enforcing classification-at-creation per the AVG spec.
	 *
	 * @return void
	 */
	public function testZaaktypesRequireAvgClassificatie(): void {
		$schemas = $this->merged['components']['schemas'];

		foreach (['wmoZaak', 'jeugdwetZaak', 'participatiewetZaak'] as $caseType) {
			$this->assertContains(
				'gdprClassification',
				$schemas[$caseType]['required'],
				$caseType . ' must require an avgClassificatie block'
			);
			$this->assertArrayHasKey(
				'gdprClassification',
				$schemas[$caseType]['properties'],
				$caseType . ' must expose the avgClassificatie property'
			);
		}
	}//end testZaaktypesRequireAvgClassificatie()

	/**
	 * The classification block is declared inline as an object, and the three
	 * copies stay identical to the standalone `gdprClassification` schema.
	 *
	 * It used to be `{"$ref": "#/components/schemas/gdprClassification"}`.
	 * OpenRegister reads a `$ref` as a bare relation slug, so a JSON Pointer
	 * resolved to nothing: the property carried no `type`, imported as a
	 * string column, and every one of the nine shipped zaak objects — each
	 * writing an object there — was refused. The import still said success.
	 *
	 * Inlining the shape is what makes the column an object, so the three
	 * copies are frozen against the standalone schema that documents it.
	 *
	 * @return void
	 */
	public function testClassificationBlockIsDeclaredInlineAndMatchesTheSchema(): void {
		$schemas = $this->merged['components']['schemas'];

		$canonical = $schemas['gdprClassification'];
		unset($canonical['slug'], $canonical['icon'], $canonical['version']);

		foreach (['wmoZaak', 'jeugdwetZaak', 'participatiewetZaak'] as $caseType) {
			$property = $schemas[$caseType]['properties']['gdprClassification'];

			$this->assertSame(
				'object',
				($property['type'] ?? null),
				$caseType . '.gdprClassification must declare type object, or the column is a string'
			);
			$this->assertArrayNotHasKey(
				'$ref',
				$property,
				$caseType . '.gdprClassification must declare its shape inline, not by reference'
			);
			// assertEquals, not assertSame: the two are the same declaration,
			// and which of `type` and `description` a hand edit puts first is
			// not drift.
			$this->assertEquals(
				$canonical,
				$property,
				$caseType . '.gdprClassification has drifted from the gdprClassification schema'
			);
		}
	}//end testClassificationBlockIsDeclaredInlineAndMatchesTheSchema()

	/**
	 * The classification categories enum spells the financial category the way
	 * the spec and the seed data do.
	 *
	 * The rest of the enum is Dutch (`medisch`, `justitieel`, `etnisch`), and
	 * `openspec/specs/dossiq-sociaal-domein-participatiewet/spec.md` names
	 * `financieel`. One anglicised value left the three participatiewet seed
	 * objects unimportable for a second reason, behind the first.
	 *
	 * @return void
	 */
	public function testFinancialCategoryKeepsItsDutchEnumValue(): void {
		$enum = $this->merged['components']['schemas']['gdprClassification']
			['properties']['categories']['items']['enum'];

		$this->assertContains('financieel', $enum);
		$this->assertNotContains('financial', $enum);
	}//end testFinancialCategoryKeepsItsDutchEnumValue()

	/**
	 * The dossiq register lists every new sociaal-domein schema while keeping
	 * its existing membership (list concatenation per ADR-037).
	 *
	 * @return void
	 */
	public function testRegisterMembershipUnioned(): void {
		$schemas = $this->merged['components']['registers']['dossiq']['schemas'];

		foreach ([
			'wmoZaak',
			'indicatiestelling',
			'jeugdwetZaak',
			'gezinsplan',
			'mdoOverleg',
			'participatiewetZaak',
			'reIntegratieTraject',
			'toestemming',
			'gdprClassification',
			'sociaalDomeinAuditLog',
			'avgIncident',
		] as $name) {
			$this->assertContains($name, $schemas, $name . ' must be in the dossiq register membership');
		}

		// Existing membership preserved.
		$this->assertContains('caseType', $schemas);
	}//end testRegisterMembershipUnioned()

	/**
	 * Three seed cases per statutory pillar are appended to components.objects.
	 *
	 * @return void
	 */
	public function testSeedObjectsAppended(): void {
		$slugs = array_map(
			static function (array $object): string {
				return (string)($object['@self']['slug'] ?? '');
			},
			$this->merged['components']['objects']
		);

		// WMO (3).
		$this->assertContains('zaak-2026-wmo-04832', $slugs);
		$this->assertContains('zaak-2026-wmo-07415', $slugs);
		$this->assertContains('zaak-2026-wmo-05921', $slugs);
		// Jeugdwet (3).
		$this->assertContains('zaak-2026-jeugd-00921', $slugs);
		$this->assertContains('zaak-2026-jeugd-01847', $slugs);
		$this->assertContains('zaak-2026-jeugd-02456', $slugs);
		// Participatiewet (3).
		$this->assertContains('zaak-2026-pw-01278', $slugs);
		$this->assertContains('zaak-2026-pw-02641', $slugs);
		$this->assertContains('zaak-2026-pw-03502', $slugs);
	}//end testSeedObjectsAppended()

	/**
	 * Statutory retention terms match the selectielijst (WMO 15, Jeugdwet 20,
	 * Participatiewet 10) in the seed classification blocks.
	 *
	 * @return void
	 */
	public function testSeedRetentionTermsMatchSelectielijst(): void {
		$bySlug = [];
		foreach ($this->merged['components']['objects'] as $object) {
			$slug = (string)($object['@self']['slug'] ?? '');
			if ($slug !== '') {
				$bySlug[$slug] = $object;
			}
		}

		$this->assertSame(15, $bySlug['zaak-2026-wmo-04832']['gdprClassification']['retentionTermYears']);
		$this->assertSame(20, $bySlug['zaak-2026-jeugd-00921']['gdprClassification']['retentionTermYears']);
		$this->assertSame(10, $bySlug['zaak-2026-pw-01278']['gdprClassification']['retentionTermYears']);
	}//end testSeedRetentionTermsMatchSelectielijst()

	/**
	 * No raw BSN is shipped in the seed data — special-category identifiers are
	 * masked, never logged or seeded in the clear (ADR-005).
	 *
	 * @return void
	 */
	public function testSeedBsnIsMasked(): void {
		foreach ($this->merged['components']['objects'] as $object) {
			$schema = (string)($object['@self']['schema'] ?? '');
			if (in_array($schema, ['wmoZaak', 'participatiewetZaak'], true) === true) {
				$this->assertSame('***maskeren***', $object['bsn'] ?? null, $schema . ' seed BSN must be masked');
			}

			if ($schema === 'jeugdwetZaak') {
				$this->assertSame('***maskeren***', $object['jeugdigeBsn'] ?? null, 'jeugdwetZaak seed BSN must be masked');
			}
		}
	}//end testSeedBsnIsMasked()
}//end class
