<?php

/**
 * Resolve an OpenRegister object payload's schema SLUG.
 *
 * OpenRegister object events carry the schema as an **id**, never as a slug.
 * `ObjectEntity::jsonSerialize()` builds `@self` from `getObjectArray()`, which
 * sets `'schema' => $this->schema`, and `$this->schema` is written by
 * `SaveObject` as `setSchema((string) $schemaId)`. There is no `schemaSlug`
 * key on `@self` and never has been.
 *
 * Listeners that read `@self.schema` and compared it against a slug literal
 * (`'objectionProceeding'`, `'case'`, …) therefore never matched, so their handler bodies
 * had never executed once — silently, with no exception and no log line. This
 * service is the single place that turns the id the payload actually carries
 * into the slug the handlers are written against, so the fix is one shared
 * lookup rather than a per-listener variant.
 *
 * The lookup goes through OpenRegister's `SchemaMapper::find()`, which keeps a
 * request-scoped cache, so repeated resolutions inside one request cost one
 * query. OpenRegister is resolved through the container rather than injected,
 * matching {@see SettingsService} — Dossiq degrades to "unknown schema" when
 * OpenRegister is absent instead of failing to boot.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns the schema id an OpenRegister object payload carries into its slug.
 *
 * @spec openspec/specs/bezwaar-lifecycle/spec.md
 */
class ObjectSchemaSlugResolver {

	/**
	 * Resolved slugs keyed by schema id, for the lifetime of the request.
	 *
	 * `SchemaMapper::find()` caches too, but memoising here also caches the
	 * misses, so a payload referencing a schema this instance does not have
	 * costs one failed lookup per request rather than one per event.
	 *
	 * @var array<string, string>
	 */
	private array $slugs = [];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container, used to reach
	 *                                      OpenRegister's SchemaMapper.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the schema slug for a serialised OpenRegister object.
	 *
	 * @param array<string, mixed> $payload The object payload, as produced by
	 *                                      `ObjectEntity::jsonSerialize()`.
	 *
	 * @return string The schema slug, or an empty string when it cannot be
	 *                resolved. An empty string never matches a slug literal,
	 *                so an unresolvable schema keeps the previous fail-closed
	 *                behaviour rather than invoking a handler blindly.
	 *
	 * @spec openspec/specs/bezwaar-lifecycle/spec.md
	 */
	public function resolveFromPayload(array $payload): string {
		return $this->resolve(schema: $this->readSchemaValue(payload: $payload));
	}//end resolveFromPayload()

	/**
	 * Resolve a schema slug from the raw schema value on an object.
	 *
	 * Accepts a slug straight through: a caller that already holds a slug (for
	 * instance because a future OpenRegister release starts emitting one) must
	 * not be forced through a lookup that would fail.
	 *
	 * @param string $schema The schema id or slug carried by the object.
	 *
	 * @return string The schema slug, or an empty string when unresolvable.
	 *
	 * @spec openspec/specs/bezwaar-lifecycle/spec.md
	 */
	public function resolve(string $schema): string {
		$schema = trim($schema);
		if ($schema === '') {
			return '';
		}

		// A non-numeric value is already a slug; ids are always digits.
		if (ctype_digit($schema) === false) {
			return $schema;
		}

		if (array_key_exists($schema, $this->slugs) === true) {
			return $this->slugs[$schema];
		}

		$slug = '';

		try {
			$schemaMapper = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
			// Signature is find($id, $_extend, $_rbac, $_multitenancy). RBAC and
			// multi-tenancy are disabled deliberately: this runs inside an event
			// handler that may have no active organisation, and the slug is
			// schema metadata rather than tenant data.
			$slug = (string)$schemaMapper->find($schema, [], false, false)->getSlug();
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Dossiq: could not resolve schema slug for id ' . $schema,
				['exception' => $e->getMessage()]
			);
		}

		$this->slugs[$schema] = $slug;

		return $slug;
	}//end resolve()

	/**
	 * Read the raw schema value out of an object payload.
	 *
	 * @param array<string, mixed> $payload The object payload.
	 *
	 * @return string The raw schema id or slug, or an empty string.
	 */
	private function readSchemaValue(array $payload): string {
		$self = ($payload['@self'] ?? null);
		if (is_array($self) === true) {
			// An extended payload carries the whole schema as an array.
			$schema = ($self['schema'] ?? null);
			if (is_array($schema) === true) {
				return (string)($schema['slug'] ?? ($schema['id'] ?? ''));
			}

			if (is_scalar($schema) === true) {
				return (string)$schema;
			}
		}

		$schema = ($payload['schema'] ?? null);
		if (is_scalar($schema) === true) {
			return (string)$schema;
		}

		return '';
	}//end readSchemaValue()
}//end class
