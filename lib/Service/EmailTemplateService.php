<?php

/**
 * Dossiq Email Template Service
 *
 * Owns the per-zaaktype emailTemplate registry: list / create / version-bump
 * update / variable catalog / draft prefill. Per ADR-022 / case-email-integration
 * design.md this is the ONLY net-new mail logic in dossiq — sending and
 * threading are owned by NC Mail via the `email` integration leaf.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-email-integration/tasks.md#T04
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Email\EmailTemplateRepository;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * CRUD + prefill for emailTemplate records.
 *
 * OpenRegister access is delegated to EmailTemplateRepository; what stays here
 * is the template domain itself — versioning, seeding and placeholder
 * resolution.
 *
 * @spec openspec/changes/case-email-integration/tasks.md#T04
 */
class EmailTemplateService {

	/**
	 * Default templates seeded when a caseType has no email templates.
	 *
	 * @var array<int, array<string, string>>
	 */
	private const DEFAULT_TEMPLATES = [
		[
			'slug' => 'ontvangstbevestiging',
			'name' => 'Ontvangstbevestiging',
			'subject' => 'Bevestiging ontvangst zaak {{zaakNummer}}',
			'body' => 'Geachte {{contactNaam}},\n\nWij hebben uw aanvraag {{zaakNummer}} ontvangen op {{startDatum}}.'
				. '\n\nMet vriendelijke groet,\n{{behandelaar}}',
		],
		[
			'slug' => 'informatieverzoek',
			'name' => 'Informatieverzoek',
			'subject' => 'Aanvullende informatie nodig voor zaak {{zaakNummer}}',
			'body' => 'Geachte {{contactNaam}},\n\nVoor de behandeling van zaak {{zaakNummer}} hebben wij aanvullende informatie nodig.'
				. '\n\nMet vriendelijke groet,\n{{behandelaar}}',
		],
		[
			'slug' => 'decision',
			'name' => 'Besluit',
			'subject' => 'Besluit zaak {{zaakNummer}}',
			'body' => 'Geachte {{contactNaam}},\n\nWij hebben besloten in uw zaak {{zaakNummer}}. Het besluit is op {{einddatum}} genomen.'
				. '\n\nMet vriendelijke groet,\n{{behandelaar}}',
		],
	];

	/**
	 * Constructor.
	 *
	 * @param EmailTemplateRepository $repository OpenRegister persistence for templates and cases.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly EmailTemplateRepository $repository,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Persist a new template (version 1).
	 *
	 * @param string $caseTypeId Owning caseType id/slug.
	 * @param array<string, mixed> $data Template payload (name/subject/body).
	 *
	 * @return array<string, mixed> The saved object.
	 *
	 * @spec openspec/changes/case-email-integration/tasks.md#T04
	 */
	public function createTemplate(string $caseTypeId, array $data): array {
		$payload = [
			'caseType' => $caseTypeId,
			'name' => (string)($data['name'] ?? 'Untitled'),
			'subject' => (string)($data['subject'] ?? ''),
			'body' => (string)($data['body'] ?? ''),
			'version' => 1,
			'isActive' => true,
		];

		return $this->repository->saveTemplate(payload: $payload);
	}//end createTemplate()

	/**
	 * Bump-version update.
	 *
	 * Creates a NEW object with `version + 1` rather than overwriting the
	 * existing one — old versions remain queryable for audit.
	 *
	 * @param string $templateId Existing template id/slug.
	 * @param array<string, mixed> $data New payload.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/case-email-integration/tasks.md#T04
	 */
	public function updateTemplate(string $templateId, array $data): array {
		$existing = $this->repository->findTemplate(templateId: $templateId);
		if ($existing === null) {
			throw new RuntimeException('Template not found');
		}

		$currentVersion = (int)($existing['version'] ?? 1);

		// Mark the prior version inactive so the active filter only sees the new copy.
		$previous = $existing;
		$previous['isActive'] = false;
		try {
			$this->repository->saveTemplate(payload: $previous);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Could not deactivate previous email template version',
				['template' => $templateId, 'error' => $e->getMessage()]
			);
		}

		$payload = [
			'caseType' => $existing['caseType'] ?? '',
			'name' => (string)($data['name'] ?? ($existing['name'] ?? '')),
			'subject' => (string)($data['subject'] ?? ($existing['subject'] ?? '')),
			'body' => (string)($data['body'] ?? ($existing['body'] ?? '')),
			'version' => ($currentVersion + 1),
			'isActive' => true,
			'previousVersion' => $existing['id'] ?? null,
		];

		return $this->repository->saveTemplate(payload: $payload);
	}//end updateTemplate()

	/**
	 * List active templates for a caseType.
	 *
	 * @param string $caseTypeId CaseType id/slug.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/case-email-integration/tasks.md#T04
	 */
	public function listTemplates(string $caseTypeId): array {
		return $this->repository->findActiveByCaseType(caseTypeId: $caseTypeId);
	}//end listTemplates()

	/**
	 * Variable catalog grouped by source.
	 *
	 * The keys returned here are the supported `{{placeholder}}` names; the
	 * frontend renders them in the editor sidebar.
	 *
	 * @param string $caseTypeId CaseType id/slug (reserved for future per-type
	 *                           extension of the catalog).
	 *
	 * @return array<string, array<int, string>>
	 *
	 * @spec openspec/changes/case-email-integration/tasks.md#T04
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function getAvailableVariables(string $caseTypeId): array {
		return [
			'case' => [
				'zaakNummer',
				'titel',
				'startDate',
				'endDate',
				'deadline',
				'status',
				'handler',
			],
			'contact' => [
				'contactNaam',
				'contactEmail',
				'contactTelefoon',
			],
			'caseType' => [
				'zaaktypeNaam',
				'zaaktypeOmschrijving',
			],
		];
	}//end getAvailableVariables()

	/**
	 * Prefill a draft from a template against a case.
	 *
	 * Returns the rendered subject/body plus any unresolved variable names.
	 * Does NOT send mail — handing the draft off to NC Mail is the leaf's
	 * responsibility.
	 *
	 * @param string $caseId Case UUID.
	 * @param string $templateId Template id/slug.
	 *
	 * @return array{subject: string, body: string, unresolved: array<int, string>, caseId: string, templateId: string}
	 *
	 * @throws \RuntimeException When the case is final or inputs are missing.
	 *
	 * @spec openspec/changes/case-email-integration/tasks.md#T04
	 */
	public function prefillDraft(string $caseId, string $templateId): array {
		$template = $this->repository->findTemplate(templateId: $templateId);
		if ($template === null) {
			throw new RuntimeException('Template not found');
		}

		$case = $this->repository->findCase(caseId: $caseId);
		if ($case === null) {
			throw new RuntimeException('Case not found');
		}

		if (($case['_isFinal'] ?? false) === true) {
			throw new RuntimeException('Case is in a final state — drafting is disabled');
		}

		$vars = $this->buildVariableMap(case: $case);
		$rawSubject = (string)($template['subject'] ?? '');
		$rawBody = (string)($template['body'] ?? '');
		$subject = $this->resolve(text: $rawSubject, vars: $vars);
		$body = $this->resolve(text: $rawBody, vars: $vars);
		$unresolved = array_values(
			array_unique(
				array_merge(
					$this->collectUnresolved(text: $rawSubject, vars: $vars),
					$this->collectUnresolved(text: $rawBody, vars: $vars)
				)
			)
		);

		return [
			'subject' => $subject,
			'body' => $body,
			'unresolved' => $unresolved,
			'caseId' => $caseId,
			'templateId' => $templateId,
		];
	}//end prefillDraft()

	/**
	 * Seed the three Dutch defaults for a caseType (idempotent by slug).
	 *
	 * @param string $caseTypeId CaseType id/slug.
	 *
	 * @return int Number of templates created on this run.
	 *
	 * @spec openspec/changes/case-email-integration/tasks.md#T04
	 */
	public function seedDefaultTemplates(string $caseTypeId): int {
		$existing = $this->listTemplates(caseTypeId: $caseTypeId);
		$existingNames = array_column($existing, 'name');

		$created = 0;
		foreach (self::DEFAULT_TEMPLATES as $default) {
			if (in_array($default['name'], $existingNames, true) === true) {
				continue;
			}

			try {
				$this->createTemplate(
					caseTypeId: $caseTypeId,
					data: [
						'name' => $default['name'],
						'subject' => $default['subject'],
						'body' => $default['body'],
					]
				);
				$created++;
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Failed to seed default email template',
					['caseType' => $caseTypeId, 'template' => $default['name'], 'error' => $e->getMessage()]
				);
			}
		}//end foreach

		return $created;
	}//end seedDefaultTemplates()

	/**
	 * Resolve `{{name}}` placeholders against the variable map.
	 *
	 * @param string $text Source text.
	 * @param array<string, string> $vars Variable map.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/case-email-integration/tasks.md#T04
	 */
	public function resolve(string $text, array $vars): string {
		return (string)preg_replace_callback(
			'/{{\s*([a-zA-Z][a-zA-Z0-9_]*)\s*}}/',
			static function (array $match) use ($vars): string {
				$name = $match[1];
				if (isset($vars[$name]) === true) {
					return $vars[$name];
				}

				return $match[0];
			},
			$text,
		);
	}//end resolve()

	/**
	 * Collect placeholder names left unresolved after a render pass.
	 *
	 * @param string $text Source text.
	 * @param array<string, string> $vars Variable map.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/case-email-integration/tasks.md#T04
	 */
	public function collectUnresolved(string $text, array $vars): array {
		if (preg_match_all('/{{\s*([a-zA-Z][a-zA-Z0-9_]*)\s*}}/', $text, $matches) === false) {
			return [];
		}

		$unresolved = [];
		foreach ($matches[1] as $name) {
			if (isset($vars[$name]) === false) {
				$unresolved[] = $name;
			}
		}

		return $unresolved;
	}//end collectUnresolved()

	/**
	 * Build the variable map for a single case.
	 *
	 * @param array<string, mixed> $case Case data.
	 *
	 * @return array<string, string>
	 */
	private function buildVariableMap(array $case): array {
		return [
			'zaakNummer' => (string)($case['identifier'] ?? ''),
			'titel' => (string)($case['title'] ?? ''),
			'startDate' => (string)($case['startDate'] ?? ''),
			'endDate' => (string)($case['endDate'] ?? ''),
			'deadline' => (string)($case['deadline'] ?? ''),
			'status' => (string)($case['status'] ?? ''),
			'handler' => (string)($case['assignee'] ?? ''),
			'contactNaam' => (string)($case['contactName'] ?? ($case['contact']['name'] ?? '')),
			'contactEmail' => (string)($case['contactEmail'] ?? ($case['contact']['email'] ?? '')),
			'contactTelefoon' => (string)($case['contactPhone'] ?? ($case['contact']['phone'] ?? '')),
			'zaaktypeNaam' => (string)($case['caseTypeTitle'] ?? ''),
			'zaaktypeOmschrijving' => (string)($case['caseTypeDescription'] ?? ''),
		];
	}//end buildVariableMap()
}//end class
