<?php

/**
 * Dossiq CreateDocumentHandler
 *
 * Renders a document template against the case (merge fields) and attaches
 * the resulting file to the case folder. In dry-run mode it returns the
 * rendered output name + byte count without persisting any file.
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
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Handler for `createDocument` automatic actions.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class CreateDocumentHandler implements ActionHandlerInterface {
	use HandlesTemplates;

	/**
	 * Constructor for CreateDocumentHandler.
	 *
	 * @param ContainerInterface $container DI container — used to resolve
	 *                                      the document service lazily.
	 * @param LoggerInterface $logger PSR-3 logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
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
		return 'createDocument';
	}//end type()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $actionConfig Resolved action config array.
	 * @param array $case The full case object.
	 * @param array $transitionContext Transition context (carries dryRun).
	 *
	 * @return ActionResult The outcome of the document creation.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult {
		try {
			$templateSlug = (string)($actionConfig['templateSlug'] ?? '');
			$outputName = $this->renderTemplate(
				template: (string)($actionConfig['outputName'] ?? 'document.pdf'),
				case: $case
			);
			$mergeFields = (array)($actionConfig['mergeFields'] ?? []);
			$renderedFields = [];
			foreach ($mergeFields as $key => $tpl) {
				$renderedFields[(string)$key] = $this->renderTemplate(template: (string)$tpl, case: $case);
			}

			$preview = [
				'templateSlug' => $templateSlug,
				'outputName' => $outputName,
				'mergeFields' => $renderedFields,
			];

			if (($transitionContext['dryRun'] ?? false) === true) {
				return new ActionResult(succeeded: true, data: $preview);
			}

			if ($templateSlug === '') {
				return new ActionResult(succeeded: false, error: 'missing_template_slug', data: $preview);
			}

			$documentService = $this->resolveDocumentService();
			if ($documentService === null) {
				return new ActionResult(succeeded: false, error: 'document_service_unavailable', data: $preview);
			}

			// The document service is owned by status-transition-engine's
			// sibling feature `ZgwDocumentService`; signature is intentionally
			// soft-bound to avoid a hard dependency before that change lands.
			$documentId = null;
			if (method_exists($documentService, 'renderAndAttach') === true) {
				// @phpstan-ignore-next-line — signature owned by service.
				$documentId = $documentService->renderAndAttach(
					$templateSlug,
					(string)($case['id'] ?? ''),
					$outputName,
					$renderedFields
				);
			}

			$preview['documentId'] = $documentId;
			return new ActionResult(succeeded: true, data: $preview);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CreateDocumentHandler: failed to render document',
				[
					'app' => Application::APP_ID,
					'slug' => (string)($actionConfig['slug'] ?? ''),
					'exception' => $e->getMessage(),
				]
			);
			return new ActionResult(succeeded: false, error: 'document_create_failed');
		}//end try
	}//end handle()

	/**
	 * Resolve ZgwDocumentService lazily.
	 *
	 * @return object|null
	 */
	private function resolveDocumentService(): ?object {
		try {
			return $this->container->get('OCA\Dossiq\Service\ZgwDocumentService');
		} catch (\Throwable $e) {
			return null;
		}
	}//end resolveDocumentService()
}//end class
