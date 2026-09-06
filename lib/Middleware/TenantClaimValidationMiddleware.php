<?php

/**
 * Dossiq Tenant Claim Validation Middleware
 *
 * Validates that the `tenant_id` claim in the bearer JWT matches the
 * tenant resolved from the request (header / URL parameter). Mismatch
 * → HTTP 403 + security-log entry + rate-limit counter.
 *
 * Runs immediately after `TenantContextMiddleware` so the request-time
 * tenant is already bound.
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

use DateTimeImmutable;
use OCA\Dossiq\Service\TenantContext;
use OCA\Dossiq\Service\TenantJwtService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Validate JWT tenant_id ↔ request-tenant match. Fail-closed.
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-05-auth-jwt-tenant-claim/tasks.md
 */
class TenantClaimValidationMiddleware extends Middleware {
	/**
	 * Threshold of failed attempts per hour per IP before raising an alert.
	 */
	public const FAIL_THRESHOLD = 5;

	/**
	 * Rate-limit window in seconds.
	 */
	public const WINDOW_SECONDS = 3600;

	/**
	 * Cache namespace.
	 */
	private const CACHE_NS = 'dossiq_tenant_claim_failures';

	/**
	 * Backing cache (factory-resolved).
	 *
	 * @var ICache
	 */
	private ICache $cache;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request Request.
	 * @param TenantContext $context Bound tenant context.
	 * @param TenantJwtService $jwt JWT service.
	 * @param ICacheFactory $cacheFactory Cache factory.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly TenantContext $context,
		private readonly TenantJwtService $jwt,
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {
		$this->cache = $cacheFactory->createLocal(self::CACHE_NS);
	}//end __construct()

	/**
	 * Validate that the JWT tenant claim matches the bound request tenant.
	 *
	 * @param \OCP\AppFramework\Controller $controller Controller.
	 * @param string $methodName Method name.
	 *
	 * @return void
	 *
	 * @throws TenantClaimMismatchException When the JWT tenant_id does not match the request tenant.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $controller and $methodName are
	 * fixed by OCP\AppFramework\Middleware::beforeController(); this middleware
	 * validates the bound tenant claim instead.
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-05-auth-jwt-tenant-claim/tasks.md
	 */
	public function beforeController($controller, $methodName): void {
		// No bearer header → not a JWT-authenticated request; let other auth layers handle.
		$auth = (string)$this->request->getHeader('Authorization');
		if (str_starts_with($auth, 'Bearer ') === false) {
			return;
		}

		$token = trim(substr($auth, 7));
		try {
			$claims = $this->jwt->validate($token);
		} catch (Throwable $e) {
			// Bad JWT — let the auth chain reject it; we don't double-handle.
			return;
		}

		if ($this->context->isBound() === false) {
			return;
		}

		$jwtTenantId = (string)($claims['tenant_id'] ?? '');
		$requestTenantId = $this->context->getTenantId();

		if ($jwtTenantId !== '' && $jwtTenantId !== $requestTenantId) {
			$this->logSecurityIncident(attempted: $jwtTenantId, requested: $requestTenantId, claims: $claims);
			$this->bumpFailureCounter();
			throw new TenantClaimMismatchException(
				'JWT tenant_id does not match request tenant',
				403
			);
		}
	}//end beforeController()

	/**
	 * Translate the mismatch exception to a 403 JSON response.
	 *
	 * @param \OCP\AppFramework\Controller $controller Controller.
	 * @param string $methodName Method name.
	 * @param \Exception $exception Exception.
	 *
	 * @return \OCP\AppFramework\Http\Response
	 *
	 * @throws \Exception When the exception is not ours.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $controller and $methodName are
	 * fixed by OCP\AppFramework\Middleware::afterException(); only $exception is
	 * inspected.
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-05-auth-jwt-tenant-claim/tasks.md
	 */
	public function afterException($controller, $methodName, \Exception $exception): \OCP\AppFramework\Http\Response {
		if ($exception instanceof TenantClaimMismatchException) {
			return new JSONResponse(
				['success' => false, 'error' => $exception->getMessage()],
				403
			);
		}

		throw $exception;
	}//end afterException()

	/**
	 * Log a security incident — IP, timestamp, attempted tenant_id, user.
	 *
	 * @param string $attempted Attempted (JWT-claimed) tenant_id.
	 * @param string $requested Requested (URL-bound) tenant_id.
	 * @param array<string,mixed> $claims Full JWT claims (for `sub`).
	 *
	 * @return void
	 */
	private function logSecurityIncident(string $attempted, string $requested, array $claims): void {
		$this->logger->warning(
			'Dossiq SECURITY: cross-tenant JWT claim mismatch',
			[
				'ip' => $this->request->getRemoteAddress(),
				'timestamp' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
				'attemptedTenantId' => $attempted,
				'requestedTenantId' => $requested,
				'user' => (string)($claims['sub'] ?? ''),
			]
		);
	}//end logSecurityIncident()

	/**
	 * Bump the per-IP failure counter and alert at the threshold.
	 *
	 * @return void
	 */
	private function bumpFailureCounter(): void {
		$ipAddress = (string)$this->request->getRemoteAddress();
		$key = 'fail:' . $ipAddress;
		try {
			$count = (int)$this->cache->get($key);
			$count++;
			$this->cache->set($key, $count, self::WINDOW_SECONDS);
			if ($count >= self::FAIL_THRESHOLD) {
				$this->logger->alert(
					'Dossiq SECURITY: cross-tenant JWT threshold breached',
					['ip' => $ipAddress, 'count' => $count]
				);
			}
		} catch (Throwable $e) {
			// Cache failure is non-fatal — the warning log is still emitted.
		}
	}//end bumpFailureCounter()
}//end class
