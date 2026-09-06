<?php

/**
 * Dossiq schema slug resolver.
 *
 * Resolves an OpenRegister schema slug to the schema DOSSIQ'S OWN REGISTER
 * references, falling back to an instance-wide lookup only when our register
 * carries no schema with that slug.
 *
 * 🔴 A SCHEMA SLUG IS NOT UNIQUE ACROSS OPENREGISTER. Three schemas carried the
 * slug `task` on a normal dev instance and only one belonged to Dossiq. The
 * unscoped `SchemaMapper::find()` returns whichever row it fetches first, which
 * was an InterneTaak schema owned by another app, in another register. Two
 * separate call sites were resolving slugs that way and both landed on the
 * wrong schema:
 *
 * - {@see SchemaKeyReconciler} wrote `task_schema` pointing outside our register,
 *   so every consumer that creates or reads case tasks used a foreign schema and
 *   the Tasks page stayed empty.
 * - {@see SchemaAnnotationReconciler} merged Dossiq's `x-openregister-*`
 *   annotation blocks onto that foreign schema, so the `isTerminalStatus` and
 *   `daysUntilDue` calculations were installed on ANOTHER APP'S schema while
 *   Dossiq's own task schema never got them. Completed tasks then failed to
 *   filter out of "My Tasks", and every due-date column rendered blank.
 *
 * Neither failed loudly. That is why the rule lives in one class now: a second
 * call site that resolves slugs its own way is the whole defect returning.
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

use OCA\Dossiq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves a schema slug inside Dossiq's own register.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class SchemaSlugResolver {
	/**
	 * The register's schema ids, resolved once per instance.
	 *
	 * @var int[]|null
	 */
	private ?array $registerSchemaIds = null;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration service.
	 * @param ContainerInterface $container The DI container.
	 * @param LoggerInterface $logger The logger interface.
	 *
	 * @return void
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve one schema slug to a live schema.
	 *
	 * Two steps, and the ORDER is the whole point:
	 *
	 * 1. Match the slug among the schemas Dossiq's own register references.
	 *    When our register carries this slug, that schema is the answer, even
	 *    when other apps carry the same slug.
	 * 2. Only when our register carries NO schema with this slug, fall back to
	 *    the instance-wide lookup.
	 *
	 * 🔴 STEP 2 IS NOT DEAD CODE AND MUST NOT BECOME A HARD FAILURE. Dossiq
	 * deliberately points three keys at schemas owned by other apps
	 * (`appointment`, `location` and `catalog` are shared, not ours). Those
	 * slugs are unique instance-wide, so the unscoped lookup is right for them,
	 * and dropping the fallback would blank all three.
	 *
	 * @param object $schemaMapper The OpenRegister SchemaMapper.
	 * @param string $slug The schema slug, e.g. 'caseTask'.
	 *
	 * @return object|null The live schema, or null when the slug does not resolve.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function resolve(object $schemaMapper, string $slug): ?object {
		$ids = $this->registerSchemaIds();

		if ($ids !== [] && method_exists($schemaMapper, 'findBySlugInIds') === true) {
			try {
				$scoped = $schemaMapper->findBySlugInIds($slug, $ids);
				if ($scoped !== null) {
					return $scoped;
				}
			} catch (\Throwable $e) {
				$this->logger->debug(
					'Dossiq: Register-scoped schema lookup failed, falling back',
					['slug' => $slug, 'exception' => $e->getMessage()]
				);
			}
		}

		try {
			// Slug-aware lookup with RBAC + multi-tenancy disabled: this runs in
			// a system context with no active organisation, and the schema set is
			// app-owned config, not tenant data.
			// Signature is find($id, $_extend, $_rbac, $_multitenancy).
			return $schemaMapper->find($slug, [], false, false);
		} catch (\Throwable $e) {
			// Slug not present in this OpenRegister instance, so skip it.
			return null;
		}
	}//end resolve()

	/**
	 * The schema ids Dossiq's own register references.
	 *
	 * Returns an empty list when the register is not configured yet or
	 * OpenRegister cannot be reached, which makes {@see resolve()} behave
	 * exactly as the unscoped lookup did before scoping was added.
	 *
	 * @return int[] The register's schema ids, or [] when unknown.
	 */
	private function registerSchemaIds(): array {
		if ($this->registerSchemaIds !== null) {
			return $this->registerSchemaIds;
		}

		$this->registerSchemaIds = [];

		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($registerId === '') {
			return $this->registerSchemaIds;
		}

		try {
			$registerMapper = $this->container->get('OCA\OpenRegister\Db\RegisterMapper');
			$register = $registerMapper->find($registerId, false, false);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Dossiq: Could not read the register schema list for scoping',
				['register' => $registerId, 'exception' => $e->getMessage()]
			);
			return $this->registerSchemaIds;
		}

		$ids = [];
		foreach ($register->getSchemas() as $candidate) {
			if (is_numeric($candidate) === true && (int)$candidate > 0) {
				$ids[] = (int)$candidate;
			}
		}

		$this->registerSchemaIds = $ids;

		return $this->registerSchemaIds;
	}//end registerSchemaIds()
}//end class
