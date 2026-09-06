<?php

/**
 * Dossiq Tenant Context
 *
 * Request-scoped holder of the resolved tenant — UUID, slug, schema name,
 * full tenant row. Populated by `TenantContextMiddleware` early in the
 * request lifecycle and consumed by downstream services / controllers that
 * need to know which tenant they are operating on.
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
 * @spec openspec/changes/tenant-zaaksysteem-saas-04-tenant-context-isolation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use RuntimeException;

/**
 * Request-scoped tenant context.
 *
 * Implemented as a regular service whose lifetime is bound to the request
 * scope by the NC DI container (request-scoped via `IRequest` is sufficient
 * — every HTTP request gets a fresh container child).
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-04-tenant-context-isolation/tasks.md
 */
class TenantContext {

	/**
	 * Resolved tenant UUID.
	 *
	 * @var string|null
	 */
	private ?string $tenantId = null;

	/**
	 * Resolved tenant slug.
	 *
	 * @var string|null
	 */
	private ?string $slug = null;

	/**
	 * Resolved Postgres schema name.
	 *
	 * @var string|null
	 */
	private ?string $schemaName = null;

	/**
	 * Full tenant row as resolved from OR.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $tenant = null;

	/**
	 * Bind a resolved tenant to the current request.
	 *
	 * @param array<string,mixed> $tenant Tenant row.
	 * @param string $schemaName Tenant schema name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-04-tenant-context-isolation/tasks.md
	 */
	public function bind(array $tenant, string $schemaName): void {
		$this->tenant = $tenant;
		$this->tenantId = (string)($tenant['uuid'] ?? $tenant['id'] ?? '');
		$this->slug = (string)($tenant['slug'] ?? '');
		$this->schemaName = $schemaName;
	}//end bind()

	/**
	 * Whether a tenant has been bound to the request.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-04-tenant-context-isolation/tasks.md
	 */
	public function isBound(): bool {
		return $this->tenant !== null;
	}//end isBound()

	/**
	 * Get the bound tenant row.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws RuntimeException When no tenant is bound.
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-04-tenant-context-isolation/tasks.md
	 */
	public function getTenant(): array {
		$this->assertBound();
		return $this->tenant ?? [];
	}//end getTenant()

	/**
	 * Get the resolved tenant UUID.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When no tenant is bound.
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-04-tenant-context-isolation/tasks.md
	 */
	public function getTenantId(): string {
		$this->assertBound();
		return (string)$this->tenantId;
	}//end getTenantId()

	/**
	 * Get the resolved tenant slug.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When no tenant is bound.
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-04-tenant-context-isolation/tasks.md
	 */
	public function getSlug(): string {
		$this->assertBound();
		return (string)$this->slug;
	}//end getSlug()

	/**
	 * Get the resolved Postgres schema name.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When no tenant is bound.
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-04-tenant-context-isolation/tasks.md
	 */
	public function getSchemaName(): string {
		$this->assertBound();
		return (string)$this->schemaName;
	}//end getSchemaName()

	/**
	 * Reset the context. Used in tests + at the end of each request.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-04-tenant-context-isolation/tasks.md
	 */
	public function reset(): void {
		$this->tenant = null;
		$this->tenantId = null;
		$this->slug = null;
		$this->schemaName = null;
	}//end reset()

	/**
	 * Throw when no tenant is bound.
	 *
	 * @return void
	 *
	 * @throws RuntimeException
	 */
	private function assertBound(): void {
		if ($this->tenant === null) {
			throw new RuntimeException('No tenant bound to the current request');
		}
	}//end assertBound()
}//end class
