<?php

/**
 * Dossiq schema annotation reconciler.
 *
 * Merges each schema's declarative `x-openregister-*` annotation blocks
 * (calculations, references, lifecycle, aggregations, object-source) from the
 * fragment-merged register JSON onto the LIVE OpenRegister schema's
 * `configuration` column.
 *
 * OpenRegister's app-config import does not reliably round-trip those
 * schema-level blocks on an already-imported instance, and the status engine,
 * the declarative calculation engine and the reference resolver all read them
 * from `Schema::getConfiguration()` — so a dropped block silently disables
 * auto-deadline / auto-identifier / initial-status on create. The merge is
 * additive (never a replace), so existing keys such as `objectNameField` are
 * preserved, and idempotent: a schema whose live configuration already matches
 * is left untouched.
 *
 * Split out of {@see \OCA\Dossiq\Service\SettingsService}.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Settings;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reconciles declarative schema annotation blocks onto live OpenRegister schemas.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class SchemaAnnotationReconciler {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @param RegisterFragmentMerger $fragments The register fragment merger.
	 * @param LoggerInterface $logger The logger interface.
	 * @param SchemaSlugResolver $slugResolver Resolves a slug inside our own register.
	 *
	 * @return void
	 */
	public function __construct(
		private ContainerInterface $container,
		private RegisterFragmentMerger $fragments,
		private LoggerInterface $logger,
		private SchemaSlugResolver $slugResolver,
	) {
	}//end __construct()

	/**
	 * Reconcile the declarative annotation blocks of every declared schema.
	 *
	 * @return int The number of schemas whose configuration was (re)written.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function reconcile(): int {
		try {
			$schemaMapper = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: Could not access OpenRegister SchemaMapper for declarative reconcile',
				['exception' => $e->getMessage()]
			);
			return 0;
		}

		$schemas = $this->loadDeclarativeRegisterSchemas();
		if ($schemas === null) {
			return 0;
		}

		$written = 0;
		foreach ($schemas as $key => $schemaDef) {
			$written += $this->reconcileSchemaAnnotationBlocks(
				schemaMapper: $schemaMapper,
				key: $key,
				schemaDef: $schemaDef
			);
		}//end foreach

		$this->logger->info(
			'Dossiq: Reconciled declarative schema configuration from register JSON',
			['written' => $written]
		);

		return $written;
	}//end reconcile()

	/**
	 * Load the fragment-merged schema definitions from the register JSON.
	 *
	 * @return array<array-key, mixed>|null The schema definitions, or null when
	 *                                      the register JSON is missing or invalid.
	 */
	private function loadDeclarativeRegisterSchemas(): ?array {
		$configPath = __DIR__ . '/../../Settings/dossiq_register.json';
		if (file_exists($configPath) === false) {
			return null;
		}

		$configData = json_decode((string)file_get_contents($configPath), true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($configData) === false) {
			return null;
		}

		// Fold modular register fragments on top so a schema's annotation
		// blocks declared in a register.d fragment are reconciled too.
		[$configData] = $this->fragments->merge(
			base: $configData,
			fragmentDir: __DIR__ . '/../../Settings/register.d'
		);

		$schemas = ($configData['components']['schemas'] ?? []);
		if (is_array($schemas) === false) {
			return null;
		}

		return $schemas;
	}//end loadDeclarativeRegisterSchemas()

	/**
	 * Reconcile the declarative annotation blocks of one schema definition.
	 *
	 * @param object $schemaMapper The OpenRegister SchemaMapper.
	 * @param int|string $key The schema key in the register JSON.
	 * @param mixed $schemaDef The raw schema definition.
	 *
	 * @return int 1 when the configuration was (re)written, 0 otherwise.
	 */
	private function reconcileSchemaAnnotationBlocks(object $schemaMapper, int|string $key, mixed $schemaDef): int {
		if (is_array($schemaDef) === false) {
			return 0;
		}

		$fallbackSlug = '';
		if (is_string($key) === true) {
			$fallbackSlug = $key;
		}

		$slug = ($schemaDef['slug'] ?? $fallbackSlug);
		$declaredCfg = ($schemaDef['configuration'] ?? []);
		if ($slug === '' || is_array($declaredCfg) === false) {
			return 0;
		}

		// Collect only the declarative annotation blocks we own.
		$annotations = [];
		foreach (SchemaSlugMap::SCHEMA_ANNOTATION_KEYS as $annotationKey) {
			if (array_key_exists($annotationKey, $declaredCfg) === true) {
				$annotations[$annotationKey] = $declaredCfg[$annotationKey];
			}
		}

		if ($annotations === []) {
			return 0;
		}

		return $this->mergeOntoLiveSchema(
			schemaMapper: $schemaMapper,
			slug: (string)$slug,
			annotations: $annotations
		);
	}//end reconcileSchemaAnnotationBlocks()

	/**
	 * Merge one schema's declarative annotation blocks onto its live
	 * OpenRegister configuration. Idempotent — returns 0 when the live
	 * configuration already carries identical blocks.
	 *
	 * @param object $schemaMapper The OpenRegister SchemaMapper.
	 * @param string $slug The schema slug (e.g. 'case').
	 * @param array<string, mixed> $annotations The annotation blocks to merge.
	 *
	 * @return int 1 when the configuration was (re)written, 0 otherwise.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function mergeOntoLiveSchema(object $schemaMapper, string $slug, array $annotations): int {
		// 🔴 RESOLVE INSIDE OUR OWN REGISTER. An unscoped slug lookup here merged
		// Dossiq's task calculations onto ANOTHER APP'S `task` schema, so
		// isTerminalStatus and daysUntilDue were installed where nothing read
		// them while Dossiq's own task schema never got them. Completed tasks
		// then stayed in "My Tasks" and every due-date column rendered blank.
		$schema = $this->slugResolver->resolve(schemaMapper: $schemaMapper, slug: $slug);
		if ($schema === null) {
			// Slug not present in this OpenRegister instance, so skip it.
			return 0;
		}

		$current = ($schema->getConfiguration() ?? []);
		if (is_array($current) === false) {
			$current = [];
		}

		$merged = $current;
		$changed = false;
		foreach ($annotations as $annotationKey => $annotationValue) {
			if (($current[$annotationKey] ?? null) !== $annotationValue) {
				$merged[$annotationKey] = $annotationValue;
				$changed = true;
			}
		}

		if ($changed === false) {
			return 0;
		}

		try {
			$schema->setConfiguration($merged);
			$schemaMapper->update($schema);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: Failed to reconcile declarative configuration for schema ' . $slug,
				['exception' => $e->getMessage()]
			);
			return 0;
		}

		return 1;
	}//end mergeOntoLiveSchema()
}//end class
