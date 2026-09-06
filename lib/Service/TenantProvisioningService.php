<?php

/**
 * Dossiq Tenant Provisioning Service
 *
 * Orchestrates the schema-per-tenant provisioning flow for the SaaS chain.
 *
 * The actual `CREATE SCHEMA` + table-cloning DDL is delegated to
 * `TenantSchemaProvisioner` (which wraps OR's TenantLifecycleService for
 * the schema-creation primitive). This service owns the orchestration:
 * resolve tenant → build schema name → create schema → seed → welcome
 * email → rollback on failure.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-03-schema-provisioning/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Orchestrates tenant schema-per-tenant provisioning.
 *
 * Returns the provisioning result (schemaName + steps performed) or throws
 * after rolling back any partial work.
 *
 * @spec openspec/specs/tenant-provisioning/spec.md#requirement-schema-per-tenant-provisioning-req-001-b
 */
class TenantProvisioningService {
	/**
	 * PostgreSQL identifier cap.
	 */
	public const PG_IDENTIFIER_MAX_LENGTH = 63;

	/**
	 * Schema-name prefix (per design: tenant_{uuid8}_{slug}).
	 */
	public const SCHEMA_PREFIX = 'tenant_';

	/**
	 * Default role names seeded per tenant schema.
	 *
	 * @var array<int, string>
	 */
	private const DEFAULT_ROLES = ['tenant_admin', 'case_handler', 'viewer'];

	/**
	 * Constructor.
	 *
	 * @param TenantSaasService $tenantSaasService Tenant SaaS service (read tenant row).
	 * @param TenantSchemaProvisioner $schemaProvisioner Schema-create + clone + drop.
	 * @param TenantSeedService $seedService Templates/roles seeding.
	 * @param TenantWelcomeMailer $welcomeMailer Welcome email dispatch.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly TenantSaasService $tenantSaasService,
		private readonly TenantSchemaProvisioner $schemaProvisioner,
		private readonly TenantSeedService $seedService,
		private readonly TenantWelcomeMailer $welcomeMailer,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Provision a tenant — orchestrates schema create + clone + seed + welcome.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return array<string,mixed> Provisioning result.
	 *
	 * @throws InvalidArgumentException When the tenant is not found or wrong status.
	 * @throws RuntimeException On provisioning failure (after rollback).
	 *
	 * @spec openspec/specs/tenant-provisioning/spec.md#requirement-schema-per-tenant-provisioning-req-001-b
	 */
	public function provision(string $tenantId): array {
		$tenant = $this->tenantSaasService->getById($tenantId);
		if ($tenant === null) {
			throw new InvalidArgumentException('Tenant not found: ' . $tenantId);
		}

		$status = (string)($tenant['status'] ?? '');
		if ($status !== 'onboarding') {
			throw new InvalidArgumentException(
				'Tenant must be in onboarding to provision (got: ' . $status . ')'
			);
		}

		$schemaName = $this->buildSchemaName(
			uuid: (string)($tenant['uuid'] ?? $tenant['id'] ?? $tenantId),
			slug: (string)($tenant['slug'] ?? '')
		);
		$tier = (string)($tenant['tier'] ?? 'basic');

		$steps = [];

		try {
			$this->schemaProvisioner->createSchema($schemaName);
			$steps[] = 'createSchema';

			$this->schemaProvisioner->cloneApplicationTables($schemaName);
			$steps[] = 'cloneApplicationTables';

			$this->seedService->seedZaaktypeTemplates($schemaName, $tier);
			$steps[] = 'seedZaaktypeTemplates';

			$this->seedService->seedMandaatMatrix($schemaName);
			$steps[] = 'seedMandaatMatrix';

			$this->seedService->createDefaultRoles($schemaName, self::DEFAULT_ROLES);
			$steps[] = 'createDefaultRoles';

			$this->welcomeMailer->sendWelcomeEmail($tenant);
			$steps[] = 'sendWelcomeEmail';
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: tenant provisioning failed; rolling back',
				['tenantId' => $tenantId, 'schemaName' => $schemaName, 'steps' => $steps, 'exception' => $e->getMessage()]
			);

			$this->rollback(schemaName: $schemaName, steps: $steps);

			throw new RuntimeException(
				'Provisioning failed at step ' . ($steps[count($steps) - 1] ?? 'createSchema') . ': ' . $e->getMessage(),
				0,
				$e
			);
		}//end try

		return [
			'tenantId' => $tenantId,
			'schemaName' => $schemaName,
			'tier' => $tier,
			'roles' => self::DEFAULT_ROLES,
			'steps' => $steps,
			'provisioned' => true,
		];
	}//end provision()

	/**
	 * Build a PostgreSQL schema name from tenant UUID + slug.
	 *
	 * Shape: `tenant_{uuid8}_{slug}` where uuid8 is the first 8 chars of the
	 * UUID with hyphens stripped. Total length capped to 63 (PostgreSQL
	 * identifier max). The slug is truncated as needed and trailing hyphens
	 * are trimmed.
	 *
	 * @param string $uuid Tenant UUID.
	 * @param string $slug Tenant slug.
	 *
	 * @return string Schema name (≤63 chars, lowercase, identifier-safe).
	 *
	 * @throws InvalidArgumentException When uuid or slug is empty.
	 *
	 * @spec openspec/specs/tenant-provisioning/spec.md#requirement-schema-per-tenant-provisioning-req-001-b
	 */
	public function buildSchemaName(string $uuid, string $slug): string {
		if ($uuid === '' || $slug === '') {
			throw new InvalidArgumentException('Cannot build schema name from empty uuid/slug');
		}

		$uuidShort = substr(str_replace('-', '', $uuid), 0, 8);
		$prefix = self::SCHEMA_PREFIX . $uuidShort . '_';
		$room = self::PG_IDENTIFIER_MAX_LENGTH - strlen($prefix);

		// Sanitise slug to identifier-safe characters (alnum + hyphen → underscore).
		$safeSlug = strtolower((string)preg_replace('/[^a-z0-9_-]+/i', '', $slug));
		$safeSlug = str_replace('-', '_', $safeSlug);

		if ($room <= 0) {
			// Edge case: prefix already exceeds the cap — return prefix trimmed.
			return rtrim(substr($prefix, 0, self::PG_IDENTIFIER_MAX_LENGTH), '_');
		}

		$name = $prefix . substr($safeSlug, 0, $room);
		return rtrim($name, '_');
	}//end buildSchemaName()

	/**
	 * Roll back partial provisioning — drops the schema if it was created.
	 *
	 * @param string $schemaName Schema name.
	 * @param array<int, string> $steps Steps performed (used to decide what to undo).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/tenant-provisioning/spec.md#requirement-schema-per-tenant-provisioning-req-001-b
	 */
	public function rollback(string $schemaName, array $steps): void {
		if (in_array('createSchema', $steps, true) === false) {
			return;
		}

		try {
			$this->schemaProvisioner->dropSchema($schemaName);
			$this->logger->info(
				'Dossiq: rolled back tenant schema after provisioning failure',
				['schemaName' => $schemaName]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: rollback drop-schema failed — manual cleanup required',
				['schemaName' => $schemaName, 'exception' => $e->getMessage()]
			);
		}
	}//end rollback()

	/**
	 * Return the default roles seeded per tenant.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/tenant-provisioning/spec.md#requirement-schema-per-tenant-provisioning-req-001-b
	 */
	public function getDefaultRoles(): array {
		return self::DEFAULT_ROLES;
	}//end getDefaultRoles()
}//end class
