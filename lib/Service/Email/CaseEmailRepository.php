<?php

/**
 * Dossiq case-email OpenRegister repository.
 *
 * Owns every OpenRegister read and write that case-integrated email performs:
 * loading templates, flattening a case into template variables, resolving a
 * case identifier back to its UUID, and journalling sent/received messages as
 * email-message objects.
 *
 * Split out of CaseEmailService so that service keeps only the concerns that
 * are actually about *mail* — from-address policy, recipient policy,
 * attachment resolution, dispatch and template rendering — while the knowledge
 * of which register/schema holds which record, and how a raw OpenRegister row
 * is shaped, lives here.
 *
 * A missing ObjectService or an unconfigured register/schema is a
 * pass-through, not an error: dossiq runs against instances where the email
 * registers are simply not provisioned, and callers treat the empty/null
 * return as "nothing recorded".
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;

/**
 * OpenRegister persistence and lookup for case-integrated email.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/case-management/spec.md
 */
class CaseEmailRepository {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service (register/schema resolution)
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * Load an email template.
	 *
	 * @param string $templateId The template UUID
	 *
	 * @return array<string, mixed>|null The template data
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function findTemplate(string $templateId): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('email_template_schema');

		if (empty($register) === true || empty($schema) === true) {
			return null;
		}

		return $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			id: $templateId
		);
	}//end findTemplate()

	/**
	 * Get email templates for a case type.
	 *
	 * @param string $caseTypeId The case type UUID
	 *
	 * @return array<int, array<string, mixed>> List of templates
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function findTemplatesForCaseType(string $caseTypeId): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('email_template_schema');

		if (empty($register) === true || empty($schema) === true) {
			return [];
		}

		return $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['caseType' => $caseTypeId, '_limit' => 100],
		);
	}//end findTemplatesForCaseType()

	/**
	 * Load case data for template variable resolution.
	 *
	 * The case is loaded through OpenRegister with RBAC enabled, so a case the
	 * caller may not read comes back as an empty array — which the caller
	 * treats as 403.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return array<string, mixed> Case data flattened for variable resolution
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function loadCaseVariables(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');

		$caseObj = $objectService->find($caseId, register: $register, schema: $schema);
		if ($caseObj === null) {
			return [];
		}

		if (is_object($caseObj) === true && method_exists($caseObj, 'jsonSerialize') === true) {
			$caseObj = $caseObj->jsonSerialize();
		}

		if (is_array($caseObj) === false) {
			return [];
		}

		// Flatten for variable resolution.
		return [
			'zaakNummer' => $caseObj['identifier'] ?? '',
			'title' => $caseObj['title'] ?? '',
			'startdatum' => $caseObj['startDate'] ?? '',
			'deadline' => $caseObj['deadline'] ?? '',
			'status' => $caseObj['status'] ?? '',
			'handler' => $caseObj['assignee'] ?? '',
		];
	}//end loadCaseVariables()

	/**
	 * Record a sent email as a case document.
	 *
	 * @param string $caseId Case UUID
	 * @param string $fromAddress The resolved envelope from-address
	 * @param string $to Recipient
	 * @param string $subject Subject
	 * @param string $body Body
	 *
	 * @return string The recorded message ID
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function recordSentEmail(
		string $caseId,
		string $fromAddress,
		string $to,
		string $subject,
		string $body,
	): string {
		// Store as activity on the case.
		$messageId = 'msg-' . uniqid();

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return $messageId;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('email_message_schema');

		if (empty($register) === false && empty($schema) === false) {
			$objectService->saveObject(
				object: [
					'case' => $caseId,
					'direction' => 'outbound',
					'from' => $fromAddress,
					'to' => $to,
					'subject' => $subject,
					'body' => $body,
					'messageId' => $messageId,
					'sentAt' => date('Y-m-d\TH:i:s'),
				],
				register: $register,
				schema: $schema,
			);
		}

		return $messageId;
	}//end recordSentEmail()

	/**
	 * Record a received email.
	 *
	 * @param string $caseId Case UUID
	 * @param string $from Sender
	 * @param string $recipient Recipient (the mailbox the message arrived on)
	 * @param string $subject Subject
	 * @param string $body Body
	 * @param string $inReplyTo Threading header
	 *
	 * @return string The recorded message ID
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function recordReceivedEmail(
		string $caseId,
		string $from,
		string $recipient,
		string $subject,
		string $body,
		string $inReplyTo,
	): string {
		$messageId = 'msg-' . uniqid();

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return $messageId;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('email_message_schema');

		if (empty($register) === false && empty($schema) === false) {
			$objectService->saveObject(
				object: [
					'case' => $caseId,
					'direction' => 'inbound',
					'from' => $from,
					'to' => $recipient,
					'subject' => $subject,
					'body' => $body,
					'messageId' => $messageId,
					'inReplyTo' => $inReplyTo,
					'receivedAt' => date('Y-m-d\TH:i:s'),
				],
				register: $register,
				schema: $schema,
			);
		}

		return $messageId;
	}//end recordReceivedEmail()

	/**
	 * Find a case UUID by its human-readable identifier.
	 *
	 * @param string $identifier The case identifier (e.g., 2026-0042)
	 *
	 * @return string|null The case UUID or null
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function findCaseIdByIdentifier(string $identifier): ?string {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');

		$results = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['identifier' => $identifier, '_limit' => 1],
		);

		if (count($results) > 0) {
			return $results[0]['id'] ?? $results[0]['uuid'] ?? null;
		}

		return null;
	}//end findCaseIdByIdentifier()
}//end class
