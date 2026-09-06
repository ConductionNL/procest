<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The Create case form does not offer a status picker.
 *
 * `CnFormDialog` fetches a reference field's options ONCE, when the dialog
 * opens, and never again. The `status` property scopes its picker with
 * `x-relation-filter: { caseType: "@object.caseType" }`, and on a create the
 * form is empty at that moment: the token resolves to nothing, the filter
 * entry is dropped, and the fetch returns the first hundred statusTypes in the
 * register regardless of case type. The acceptance proof saw four "Received"
 * options with nothing to tell them apart.
 *
 * Every one of them was also wrong. A case is born with its own case type's
 * `initialStatus`, prefilled by `x-openregister-prefill` on `caseType`, so
 * there is no status a person should be choosing on this form.
 *
 * Excluding it costs the index dialog's EDIT mode a working picker (there the
 * token does resolve), and that is the intended trade. A status is the output
 * of a transition, not a field: a raw dropdown writes it straight through, with
 * no statusRecord, no guard evaluation and none of the transition's
 * `automaticActions`. `CaseDetail` declares `lifecycleActions.field: status`,
 * which is the surface that runs the transition properly.
 */
class CaseFormStatusPickerTest extends TestCase {

	/**
	 * Repository root.
	 *
	 * @var string
	 */
	private const ROOT = __DIR__ . '/../../..';

	/**
	 * One manifest page by id.
	 *
	 * @param string $id The page id.
	 *
	 * @return array<string, mixed> The page.
	 */
	private function page(string $id): array {
		$manifest = json_decode((string)file_get_contents(self::ROOT . '/src/manifest.json'), true);
		$this->assertIsArray($manifest, 'src/manifest.json did not parse');

		foreach ((array)($manifest['pages'] ?? []) as $page) {
			if ((string)(((array)$page)['id'] ?? '') === $id) {
				return (array)$page;
			}
		}

		$this->fail('No manifest page with id "' . $id . '"');
	}

	/**
	 * The `case` schema as shipped.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function caseSchema(): array {
		$register = json_decode((string)file_get_contents(self::ROOT . '/lib/Settings/dossiq_register.json'), true);
		$schemas = (array)(((array)((array)$register)['components'] ?? [])['schemas'] ?? []);
		$this->assertArrayHasKey('case', $schemas, 'The case schema is gone, so this test is asserting nothing');

		return (array)$schemas['case'];
	}

	/**
	 * The Cases index keeps `status` out of its form dialog.
	 *
	 * @return void
	 */
	public function testTheCasesFormDialogDoesNotOfferStatus(): void {
		$config = (array)$this->page('Cases')['config'];

		$this->assertContains(
			'status',
			(array)($config['excludeFields'] ?? []),
			'A status picker on the Cases form lists every case type\'s statuses on create'
		);
	}

	/**
	 * Excluding the field did not hide the column.
	 *
	 * `excludeFields` is scoped to the form dialog. Its schema-level sibling
	 * `visible: false` is NOT: that one hides a property from the form, the
	 * data widget AND the table at once, which would have taken the Status
	 * column with it. This asserts the narrow lever was the one pulled.
	 *
	 * @return void
	 */
	public function testTheStatusColumnStillRendersOnTheList(): void {
		$config = (array)$this->page('Cases')['config'];

		$keys = [];
		foreach ((array)($config['columns'] ?? []) as $column) {
			$keys[] = is_array($column) === true ? (string)($column['key'] ?? '') : (string)$column;
		}

		$this->assertContains('status', $keys, 'The list must still show each case\'s status');

		$schema = $this->caseSchema();
		$status = (array)(((array)$schema['properties'])['status'] ?? []);
		$this->assertNotSame(
			false,
			($status['visible'] ?? null),
			'`visible: false` would hide status from the table and the detail widget too'
		);
	}

	/**
	 * A case created without the picker still gets a status.
	 *
	 * The positive control for the exclusion. Without the caseType prefill, or
	 * without an `initialStatus` to prefill from, dropping the field would
	 * create statusless cases and this test would be waving through a worse
	 * defect than the one it closes.
	 *
	 * @return void
	 */
	public function testTheChosenCaseTypeSuppliesTheStatusInstead(): void {
		$properties = (array)$this->caseSchema()['properties'];
		$prefill = (array)(((array)$properties['caseType'])['x-openregister-prefill'] ?? []);
		$fields = (array)($prefill['fields'] ?? []);

		$this->assertSame(
			'initialStatus',
			($fields['status'] ?? null),
			'Choosing a case type must prefill the case status from that type\'s initial status'
		);

		$register = json_decode((string)file_get_contents(self::ROOT . '/lib/Settings/dossiq_register.json'), true);
		$caseType = (array)(((array)((array)$register)['components'] ?? [])['schemas']['caseType'] ?? []);
		$this->assertArrayHasKey(
			'initialStatus',
			(array)($caseType['properties'] ?? []),
			'The case type has no initialStatus to prefill from'
		);
	}
}
