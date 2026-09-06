<?php

/**
 * Dossiq MergeTemplateHandler
 *
 * Renders a text/markdown template into a case field via ObjectService.
 * In dry-run mode it returns the rendered content + target field without
 * persisting any update.
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
use OCA\Dossiq\Service\CaseFieldWriter;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Handler for `mergeTemplate` automatic actions.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class MergeTemplateHandler implements ActionHandlerInterface {
	use HandlesTemplates;

	/**
	 * Constructor for MergeTemplateHandler.
	 *
	 * @param ContainerInterface $container DI container — used to resolve
	 *                                      OpenRegister ObjectService.
	 * @param IAppConfig $appConfig App config — supplies register +
	 *                              case_schema keys for the save.
	 * @param CaseFieldWriter $caseWriter Applies ONLY the target field to the
	 *                                    stored case.
	 * @param LoggerInterface $logger PSR-3 logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly CaseFieldWriter $caseWriter,
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
		return 'mergeTemplate';
	}//end type()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $actionConfig Resolved action config array.
	 * @param array $case The full case object.
	 * @param array $transitionContext Transition context (carries dryRun).
	 *
	 * @return ActionResult The outcome of the template merge.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult {
		try {
			$template = (string)($actionConfig['template'] ?? ($actionConfig['templateSlug'] ?? ''));
			$targetField = (string)($actionConfig['targetField'] ?? '');
			$rendered = $this->renderTemplate(template: $template, case: $case);

			$preview = [
				'targetField' => $targetField,
				'rendered' => $rendered,
			];

			if (($transitionContext['dryRun'] ?? false) === true) {
				return new ActionResult(succeeded: true, data: $preview);
			}

			if ($targetField === '') {
				return new ActionResult(succeeded: false, error: 'missing_target_field', data: $preview);
			}

			$objectService = $this->resolveObjectService();
			if ($objectService === null) {
				return new ActionResult(succeeded: false, error: 'object_service_unavailable', data: $preview);
			}

			$register = $this->appConfig->getValueString(
				Application::APP_ID,
				'register',
				''
			);
			$schema = $this->appConfig->getValueString(
				Application::APP_ID,
				'case_schema',
				''
			);

			if ($register === '' || $schema === '') {
				return new ActionResult(succeeded: false, error: 'case_schema_unconfigured', data: $preview);
			}

			// On the flow path the engine's RegistryStepDispatcher already
			// runs this handler inside `ObjectService::runAs()` as the run's
			// acting identity (openregister#3332) — the seam that unstuck the
			// seeded case flow FlowRunWorker refused as 'Anonymous'. On the
			// interactive path the ambient session user answers the permission
			// checks. No local runAs wrap is needed here any more.
			//
			// ONLY the target field is written. `$case` is a snapshot of the
			// flow item; full-saving `array_merge($case, ...)` here wrote the
			// document over whatever other writers had stored since the
			// snapshot, and dropped every snapshot field the schema does not
			// declare (the commissieBesluit silent-drop, measured live).
			$this->caseWriter->write(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				case: $case,
				changes: [$targetField => $rendered]
			);

			// The rendered document ALSO travels on the result, so the flow
			// node can stamp it onto the outgoing item: the next step's
			// snapshot must already carry what this step just stored, or that
			// step reasons from a case that predates its own flow.
			return new ActionResult(
				succeeded: true,
				data: $preview,
				caseChanges: [$targetField => $rendered]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'MergeTemplateHandler: failed to merge template',
				[
					'app' => Application::APP_ID,
					'slug' => (string)($actionConfig['slug'] ?? ''),
					'exception' => $e->getMessage(),
				]
			);
			return new ActionResult(succeeded: false, error: 'merge_template_failed');
		}//end try
	}//end handle()

	/**
	 * Resolve OpenRegister ObjectService lazily.
	 *
	 * @return object|null
	 */
	private function resolveObjectService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			return null;
		}
	}//end resolveObjectService()
}//end class
