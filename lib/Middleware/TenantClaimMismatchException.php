<?php

/**
 * Dossiq Tenant Claim Mismatch Exception
 *
 * Thrown by `TenantClaimValidationMiddleware` when the JWT tenant_id
 * does not match the request-bound tenant. Always surfaces as 403.
 *
 * @category Middleware
 * @package  OCA\Dossiq\Middleware
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
 * @spec openspec/changes/tenant-zaaksysteem-saas-05-auth-jwt-tenant-claim/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Middleware;

use Exception;

/**
 * Tenant-claim mismatch exception (always 403).
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-05-auth-jwt-tenant-claim/tasks.md
 */
class TenantClaimMismatchException extends Exception {
}//end class
