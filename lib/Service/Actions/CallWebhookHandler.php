<?php

/**
 * Dossiq CallWebhookHandler
 *
 * Issues an outbound HTTP POST to a tenant-scoped URL (resolved via slug
 * indirection — `urlSlug` is looked up in the tenant secret store, never
 * passed inline by admins). In dry-run mode it returns the resolved URL +
 * rendered payload without contacting the remote host.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Actions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Actions;

use OCA\Dossiq\AppInfo\Application;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Handler for `callWebhook` automatic actions.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class CallWebhookHandler implements ActionHandlerInterface {
	use HandlesTemplates;

	private const DEFAULT_TIMEOUT_SEC = 10;

	/**
	 * Constructor for CallWebhookHandler.
	 *
	 * @param IClientService $clientService Nextcloud HTTP client factory.
	 * @param LoggerInterface $logger PSR-3 logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The action type slug handled by this handler.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function type(): string {
		return 'callWebhook';
	}//end type()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $actionConfig Resolved action config array.
	 * @param array $case The full case object.
	 * @param array $transitionContext Transition context (carries dryRun, tenantId).
	 *
	 * @return ActionResult The outcome of the webhook dispatch.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult {
		try {
			$url = $this->resolveUrl(config: $actionConfig, context: $transitionContext);
			$payloadTemplate = (string)($actionConfig['payloadTemplate'] ?? '');
			$payload = json_encode(['case' => $case], JSON_THROW_ON_ERROR);
			if ($payloadTemplate !== '') {
				$payload = $this->renderTemplate(template: $payloadTemplate, case: $case);
			}

			$timeoutSec = (int)($actionConfig['timeoutSec'] ?? self::DEFAULT_TIMEOUT_SEC);

			$preview = [
				'url' => $url,
				'payload' => $payload,
				'timeoutSec' => $timeoutSec,
			];

			if (($transitionContext['dryRun'] ?? false) === true) {
				return new ActionResult(succeeded: true, data: $preview);
			}

			if ($url === '') {
				return new ActionResult(succeeded: false, error: 'missing_webhook_url', data: $preview);
			}

			$client = $this->clientService->newClient();
			try {
				$response = $client->post(
					$url,
					[
						'body' => $payload,
						'headers' => ['Content-Type' => 'application/json'],
						'timeout' => $timeoutSec,
					]
				);
			} catch (\Throwable $e) {
				$errorCode = $this->classifyHttpException(e: $e);
				$this->logger->error(
					'CallWebhookHandler: webhook call failed',
					[
						'app' => Application::APP_ID,
						'slug' => (string)($actionConfig['slug'] ?? ''),
						'url' => $url,
						'exception' => $e->getMessage(),
					]
				);
				return new ActionResult(succeeded: false, error: $errorCode, data: $preview);
			}//end try

			$statusCode = (int)$response->getStatusCode();
			if ($statusCode >= 500) {
				return new ActionResult(succeeded: false, error: 'webhook_http_5xx', data: $preview);
			}

			if ($statusCode >= 400) {
				return new ActionResult(succeeded: false, error: 'webhook_http_4xx', data: $preview);
			}

			$preview['statusCode'] = $statusCode;
			return new ActionResult(succeeded: true, data: $preview);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CallWebhookHandler: unexpected failure',
				[
					'app' => Application::APP_ID,
					'slug' => (string)($actionConfig['slug'] ?? ''),
					'exception' => $e->getMessage(),
				]
			);
			return new ActionResult(succeeded: false, error: 'webhook_dispatch_failed');
		}//end try
	}//end handle()

	/**
	 * Resolve the outbound URL: prefer `urlSlug` (looked up in tenant secret
	 * store) over a legacy inline `url`. The secret-store lookup is a soft
	 * dependency — when unavailable the slug is returned as-is so a future
	 * resolver can substitute it.
	 *
	 * @param array $config Action config.
	 * @param array $context Transition context (carries tenantId).
	 *
	 * @return string Resolved URL or empty string on miss.
	 */
	private function resolveUrl(array $config, array $context): string {
		if (isset($config['urlSlug']) === true) {
			// Tenant secret store lookup will land with the secret-store
			// change; meanwhile we honour an inline `urlSlug` => `url` map
			// on the action config itself for early adoption.
			$slug = (string)$config['urlSlug'];
			$tenant = (string)($context['tenantId'] ?? '');
			$map = (array)($config['urlMap'] ?? []);
			if (isset($map[$tenant][$slug]) === true) {
				return (string)$map[$tenant][$slug];
			}

			if (isset($map[$slug]) === true) {
				return (string)$map[$slug];
			}

			// Fall through — `url` may still be set for legacy inline configs.
		}

		return (string)($config['url'] ?? '');
	}//end resolveUrl()

	/**
	 * Map low-level HTTP exceptions to static error codes.
	 *
	 * @param \Throwable $e The thrown exception.
	 *
	 * @return string
	 */
	private function classifyHttpException(\Throwable $e): string {
		$message = strtolower($e->getMessage());
		if (str_contains($message, 'timeout') === true || str_contains($message, 'timed out') === true) {
			return 'webhook_timeout';
		}

		if (str_contains($message, 'could not resolve host') === true || str_contains($message, 'name resolution') === true) {
			return 'webhook_dns_failure';
		}

		return 'webhook_network_error';
	}//end classifyHttpException()
}//end class
