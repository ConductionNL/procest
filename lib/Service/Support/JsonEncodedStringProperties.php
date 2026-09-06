<?php

/**
 * Dossiq JSON-encoded string property re-encoder.
 *
 * OpenRegister's magic-mapper read path JSON-DECODES a property the schema
 * declares as `type: string` whenever the stored text starts with `[` or `{`
 * and parses — see OpenRegister's `Service\Object\SchemaTypeConverter::
 * convertString()`, which does this deliberately for schemas that historically
 * declared `type: string` while storing array/object data. Dossiq's register
 * declares them on fourteen schemas (`case.statusHistory`,
 * `caseType.referenceProcess`, …): every one of them is written JSON-encoded
 * and read back DECODED.
 *
 * That asymmetry breaks the app's standard update idiom. `saveObject()` is
 * PUT-semantic, so an update loads the object and writes
 * `array_merge($loaded, $changes)` back — and the merge carries the decoded
 * array straight into a property the schema still says is a string:
 *
 *   Property 'routeSnapshot' should be type 'string or null' but is 'array'.
 *
 * The write is refused, and because the refusal happens inside a listener the
 * caller sees a conclusion that was announced, heard, and then silently never
 * recorded. Measured on a fresh rig: every concluded parafering stranded on
 * `in_parafering` / `currentStep: 1`, unable to reach `geaccordeerd`.
 *
 * THE ENCODED STRING IS THE DECLARED SHAPE, NOT A WORKAROUND. Fifteen
 * properties across the register are declared this way, the Vue side already
 * reads `routeSnapshot` as string-or-object, and turning one of them into a
 * real `array` would be a breaking schema change that migrates stored rows
 * while leaving its fourteen siblings broken. So the write side re-encodes,
 * and this class is the single place that knows which properties that is.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

/**
 * Restores the declared string shape of JSON-encoded properties before a write.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/case-management/spec.md
 */
class JsonEncodedStringProperties {

	/**
	 * Every property dossiq's register declares as `type: string` while
	 * describing it as JSON-encoded, keyed by schema slug.
	 *
	 * DERIVED, NOT CURATED. `JsonEncodedStringPropertiesTest` recomputes this
	 * map from `lib/Settings/dossiq_register.json` plus the ADR-037 fragments
	 * in `lib/Settings/register.d/` — a `type: string` property whose
	 * description says "JSON-encoded" — and fails on any difference, so a new
	 * JSON-encoded property cannot ship without landing here.
	 *
	 * @var array<string, array<int, string>>
	 */
	public const PROPERTIES = [
		'abonnement' => ['kanalen'],
		'advisoryReport' => ['committeeMembers'],
		'automaticAction' => ['config'],
		'case' => ['activity', 'geometry', 'relatedCases', 'statusHistory'],
		'caseShare' => ['fieldExclusions'],
		'caseType' => ['referenceProcess', 'relatedCaseTypes'],
		'document' => ['fileParts'],
		'documentType' => ['allowedMimeTypes'],
		'mapLayer' => ['style'],
		'notificationChannel' => ['filters'],
		'objection' => ['attachments'],
		'resultType' => ['sourceDateArchiveProcedure'],
		'caseTask' => ['checklist'],
		'workflowTemplate' => ['nodePositions', 'steps', 'transitions'],
	];

	/**
	 * Merge an update onto a loaded object, keeping the declared string shape.
	 *
	 * The replacement for the bare `array_merge($loaded, $changes)` at every
	 * site that reads an object and writes it back. The update wins over the
	 * loaded value exactly as `array_merge` does; only the properties this
	 * class knows to be JSON-encoded strings are re-encoded, and only where
	 * the value actually arrived decoded.
	 *
	 * @param array<string, mixed> $stored     The object as OpenRegister returned it.
	 * @param array<string, mixed> $updates    The fields to change.
	 * @param string               $schemaSlug The schema's slug in dossiq's register.
	 *
	 * @return array<string, mixed> The payload to hand to `saveObject()`.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function mergeForWrite(array $stored, array $updates, string $schemaSlug): array {
		return $this->reencode(object: array_merge($stored, $updates), schemaSlug: $schemaSlug);
	}//end mergeForWrite()

	/**
	 * Re-encode one object's JSON-encoded string properties.
	 *
	 * A value that is already a string is left untouched — including a string
	 * that does not parse, because repairing malformed stored data is not this
	 * class's job and silently rewriting it would erase the evidence.
	 *
	 * @param array<string, mixed> $object     The object.
	 * @param string               $schemaSlug The schema's slug in dossiq's register.
	 *
	 * @return array<string, mixed> The object with its declared shapes restored.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function reencode(array $object, string $schemaSlug): array {
		foreach ((self::PROPERTIES[$schemaSlug] ?? []) as $property) {
			if (array_key_exists($property, $object) === false) {
				continue;
			}

			$value = $object[$property];
			if (is_array($value) === false) {
				continue;
			}

			$encoded = json_encode($value);
			if ($encoded === false) {
				continue;
			}

			$object[$property] = $encoded;
		}//end foreach

		return $object;
	}//end reencode()

}//end class
