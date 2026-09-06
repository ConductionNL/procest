<?php

/**
 * Dossiq Mandate Validation Middleware
 *
 * Blocks mandate-requiring requests (edit, status_update, delete, create)
 * when the user's mandate-matrix entry does not authorise the action.
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
 * @spec openspec/changes/tenant-zaaksysteem-saas-06-mandate-validation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Middleware;

use OCA\Dossiq\Service\TenantAuthenticationService;
use OCA\Dossiq\Service\TenantContext;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Mandate-matrix middleware. Audit-logs every decision (allow + deny).
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-06-mandate-validation/tasks.md
 */
class MandateValidationMiddleware extends Middleware {
	/**
	 * Mapping of HTTP verb → matrix action key.
	 *
	 * @var array<string, string>
	 */
	private const VERB_ACTION_MAP = [
		'POST' => 'create',
		'PUT' => 'edit',
		'PATCH' => 'edit',
		'DELETE' => 'delete',
	];

	/**
	 * URL substrings that map to a status_update action.
	 *
	 * @var array<int, string>
	 */
	private const STATUS_PATH_HINTS = ['/transition', '/status'];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request Request.
	 * @param IUserSession $userSession User session.
	 * @param TenantContext $context Tenant context.
	 * @param TenantAuthenticationService $authService Auth service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly IUserSession $userSession,
		private readonly TenantContext $context,
		private readonly TenantAuthenticationService $authService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Enforce the mandate matrix for the bound tenant before the controller runs.
	 *
	 * @param \OCP\AppFramework\Controller $controller Controller.
	 * @param string $methodName Method name.
	 *
	 * @return void
	 *
	 * @throws MandateDeniedException When the action is denied.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $controller and $methodName are
	 * fixed by OCP\AppFramework\Middleware::beforeController(); this middleware
	 * dispatches on the request URI instead.
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-06-mandate-validation/tasks.md
	 */
	public function beforeController($controller, $methodName): void {
		if ($this->context->isBound() === false) {
			return;
		}

		$verb = strtoupper($this->request->getMethod());
		$action = $this->resolveAction(verb: $verb, path: $this->request->getRequestUri());
		if ($action === null) {
			return;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return;
		}

		$userId = $user->getUID();
		$tenantId = $this->context->getTenantId();

		$decision = $this->authService->validateMandateMatrix(
			tenantId: $tenantId,
			userId: $userId,
			action: $action
		);

		$this->logDecision(tenantId: $tenantId, userId: $userId, action: $action, decision: $decision);

		if ($decision['allowed'] === false) {
			throw new MandateDeniedException(
				(string)$decision['reason'],
				403
			);
		}
	}//end beforeController()

	/**
	 * Translate `MandateDeniedException` to a 403 JSON response.
	 *
	 * @param \OCP\AppFramework\Controller $controller Controller.
	 * @param string $methodName Method name.
	 * @param \Exception $exception Exception.
	 *
	 * @return \OCP\AppFramework\Http\Response
	 *
	 * @throws \Exception When not owned by this middleware.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $controller and $methodName are
	 * fixed by OCP\AppFramework\Middleware::afterException(); only $exception is
	 * inspected.
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-06-mandate-validation/tasks.md
	 */
	public function afterException($controller, $methodName, \Exception $exception): \OCP\AppFramework\Http\Response {
		if ($exception instanceof MandateDeniedException) {
			return new JSONResponse(
				['success' => false, 'error' => $exception->getMessage()],
				403
			);
		}

		throw $exception;
	}//end afterException()

	/**
	 * Resolve the matrix action key for the request.
	 *
	 * @param string $verb HTTP verb.
	 * @param string $path Request URI.
	 *
	 * @return string|null Action or null when no mandate gate applies.
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-06-mandate-validation/tasks.md
	 */
	public function resolveAction(string $verb, string $path): ?string {
		foreach (self::STATUS_PATH_HINTS as $hint) {
			if (str_contains($path, $hint) === true) {
				return 'status_update';
			}
		}

		return (self::VERB_ACTION_MAP[$verb] ?? null);
	}//end resolveAction()

	/**
	 * Audit-log a mandate decision (allow + deny).
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $userId NC user ID.
	 * @param string $action Action.
	 * @param array{allowed:bool,reason:string} $decision Decision.
	 *
	 * @return void
	 */
	private function logDecision(string $tenantId, string $userId, string $action, array $decision): void {
		$this->logger->info(
			'Dossiq mandate decision',
			[
				'tenantId' => $tenantId,
				'userId' => $userId,
				'action' => $action,
				'allowed' => (bool)$decision['allowed'],
				'reason' => (string)$decision['reason'],
			]
		);
	}//end logDecision()
}//end class
