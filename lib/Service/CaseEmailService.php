<?php

/**
 * Dossiq Case Email Service
 *
 * Service for sending and receiving email within case context.
 * Supports template variable resolution, email-to-PDF conversion,
 * and automatic linking of inbound email to cases.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Email\CaseContactDirectory;
use OCA\Dossiq\Service\Email\CaseEmailAttachmentResolver;
use OCA\Dossiq\Service\Email\CaseEmailRepository;
use OCP\IAppConfig;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for case-integrated email functionality.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class CaseEmailService {

	/**
	 * Regex pattern for extracting case number from email subject.
	 */
	private const CASE_NUMBER_PATTERN = '/\[ZAAK-(\d{4}-\d{4,})\]/';

	/**
	 * Substitution mode that HTML-escapes every resolved value.
	 */
	private const ESCAPE_HTML = 'html';

	/**
	 * Substitution mode that writes resolved values through verbatim.
	 */
	private const ESCAPE_NONE = 'none';

	/**
	 * Constructor.
	 *
	 * @param IMailer $mailer Nextcloud mailer
	 * @param IAppConfig $appConfig Nextcloud app config
	 * @param LoggerInterface $logger Logger
	 * @param CaseEmailRepository $repository OpenRegister reads/writes for case email
	 * @param CaseContactDirectory $contactDirectory Contact addresses registered on a case
	 * @param CaseEmailAttachmentResolver $attachmentResolver User-folder-scoped attachment resolution
	 */
	public function __construct(
		private readonly IMailer $mailer,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly CaseEmailRepository $repository,
		private readonly CaseContactDirectory $contactDirectory,
		private readonly CaseEmailAttachmentResolver $attachmentResolver,
	) {
	}//end __construct()

	/**
	 * Send an email from case context.
	 *
	 * @param string $caseId The case UUID
	 * @param string $to Recipient email address
	 * @param string $subject Email subject
	 * @param string $body Email body (HTML or plain text)
	 * @param array<string> $attachments File paths to attach
	 *
	 * @return array<string, mixed> Send result with message ID
	 *
	 * @throws \RuntimeException If sending fails
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function sendEmail(
		string $caseId,
		string $to,
		string $subject,
		string $body,
		array $attachments = [],
	): array {
		// H6 / C4: Fail loudly if from-address is not configured — never fall back to
		// the reserved example.nl domain which would cause bounces and expose config errors.
		$fromAddress = $this->resolveFromAddress();

		$fromName = $this->appConfig->getValueString(
			Application::APP_ID,
			'email_from_name',
			'Dossiq',
		);

		// C4 IDOR: Load the case via OR with RBAC enabled to verify the current user
		// has read access. If the case is not found (or the user has no access), OR
		// returns null — we treat that as 403.
		$caseData = $this->repository->loadCaseVariables(caseId: $caseId);
		if (empty($caseData) === true) {
			throw new RuntimeException('Zaak niet gevonden of geen toegang.');
		}

		// H4: Validate the recipient against the case's registered contact emails.
		// This prevents open-relay abuse where any email address could be supplied.
		$this->assertRecipientAllowed(recipient: $to, caseData: $caseData, caseId: $caseId);

		$message = $this->mailer->createMessage();
		$message->setFrom([$fromAddress => $fromName]);
		$message->setTo([$to]);
		$message->setSubject($subject);
		$message->setHtmlBody($body);
		$message->setPlainBody(strip_tags($body));

		// H5: Resolve attachments via IUserFolder to restrict file access to the
		// calling user's own files and prevent path traversal outside their folder.
		$this->attachmentResolver->attach(message: $message, attachments: $attachments, caseId: $caseId);

		$this->dispatchMessage(message: $message, caseId: $caseId);

		// Record the sent email as a case document.
		$messageId = $this->repository->recordSentEmail(
			caseId: $caseId,
			fromAddress: $fromAddress,
			to: $to,
			subject: $subject,
			body: $body,
		);

		$this->logger->info(
			'Email sent for case {caseId}',
			['app' => Application::APP_ID, 'caseId' => $caseId],
		);

		return [
			'messageId' => $messageId,
			'to' => $to,
			'subject' => $subject,
			'sentAt' => date('Y-m-d\TH:i:s'),
		];
	}//end sendEmail()

	/**
	 * Resolve the configured envelope from-address.
	 *
	 * H6 / C4: fails loudly when the address is unset or still points at the
	 * reserved example.nl domain, rather than silently sending mail that bounces.
	 *
	 * @return string The configured from-address
	 *
	 * @throws \RuntimeException If no usable from-address is configured
	 */
	private function resolveFromAddress(): string {
		$fromAddress = $this->appConfig->getValueString(
			Application::APP_ID,
			'email_from_address',
			'',
		);
		if ($fromAddress === '' || str_ends_with($fromAddress, '@example.nl') === true) {
			throw new RuntimeException(
				'E-mail afzenderadres is niet geconfigureerd. '
				. 'Stel email_from_address in via de beheerdersinstellingen.'
			);
		}

		return $fromAddress;
	}//end resolveFromAddress()

	/**
	 * Assert that a recipient address is well-formed and registered on the case.
	 *
	 * H4: prevents open-relay abuse where any address could be supplied. When the
	 * case registers no contacts at all the address list is empty and no
	 * restriction applies.
	 *
	 * @param string $recipient The recipient email address
	 * @param array<string, mixed> $caseData The case data array
	 * @param string $caseId The case UUID (logging context)
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If the address is invalid or not a case contact
	 */
	private function assertRecipientAllowed(string $recipient, array $caseData, string $caseId): void {
		if ($recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
			throw new RuntimeException('Ongeldig e-mailadres opgegeven.');
		}

		$allowedEmails = $this->contactDirectory->collectAddresses(caseData: $caseData);
		if (count($allowedEmails) > 0) {
			if (in_array(strtolower($recipient), $allowedEmails, true) === false) {
				$this->logger->warning(
					'Blocked email to non-case-contact address',
					['app' => Application::APP_ID, 'to' => $recipient, 'caseId' => $caseId]
				);
				throw new RuntimeException('Ontvanger is geen geregistreerd contact bij deze zaak.');
			}
		}
	}//end assertRecipientAllowed()

	/**
	 * Hand a fully-built message to the mailer.
	 *
	 * M4: the full exception is logged server-side while the caller receives a
	 * generic message, so internal mail-server details (hostnames, credentials)
	 * are never leaked.
	 *
	 * @param IMessage $message The message to send
	 * @param string $caseId The case UUID (logging context)
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If the mailer rejects the message
	 */
	private function dispatchMessage(IMessage $message, string $caseId): void {
		try {
			$this->mailer->send($message);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to send email for case {caseId}: {error}',
				[
					'app' => Application::APP_ID,
					'caseId' => $caseId,
					'error' => $e->getMessage(),
					'exception' => $e,
				],
			);
			throw new RuntimeException('email_send_failed');
		}
	}//end dispatchMessage()

	/**
	 * Send an email using a template.
	 *
	 * @param string $caseId The case UUID
	 * @param string $templateId The email template UUID
	 * @param string $to Recipient email address
	 *
	 * @return array<string, mixed> Send result
	 *
	 * @throws \RuntimeException If template not found or sending fails
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function sendFromTemplate(
		string $caseId,
		string $templateId,
		string $to,
	): array {
		$template = $this->repository->findTemplate(templateId: $templateId);
		if ($template === null) {
			throw new RuntimeException('Email template not found');
		}

		// Load case data for variable resolution.
		$caseData = $this->repository->loadCaseVariables(caseId: $caseId);

		// Resolve template variables.
		$subject = $this->resolveVariables(template: $template['subjectPattern'] ?? '', data: $caseData);
		$body = $this->resolveVariables(template: $template['body'] ?? '', data: $caseData);

		return $this->sendEmail(caseId: $caseId, to: $to, subject: $subject, body: $body);
	}//end sendFromTemplate()

	/**
	 * Resolve template variables in a string, HTML-escaping every value.
	 *
	 * Variables use {{variableName}} syntax.
	 *
	 * H6 XSS: case data containing HTML/JS (e.g. from citizen-submitted forms)
	 * must not execute in an email client, so this is the default surface.
	 *
	 * @param string $template The template string
	 * @param array<string, mixed> $data Available data for resolution
	 *
	 * @return string The resolved string
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function resolveVariables(string $template, array $data): string {
		return $this->substituteVariables(
			template: $template,
			data: $data,
			escaping: self::ESCAPE_HTML
		);
	}//end resolveVariables()

	/**
	 * Resolve template variables in a string without escaping the values.
	 *
	 * Only for plain-text contexts, where HTML escaping would corrupt the
	 * rendered output and where no HTML parser ever sees the result.
	 *
	 * @param string $template The template string
	 * @param array<string, mixed> $data Available data for resolution
	 *
	 * @return string The resolved string
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function resolveVariablesRaw(string $template, array $data): string {
		return $this->substituteVariables(
			template: $template,
			data: $data,
			escaping: self::ESCAPE_NONE
		);
	}//end resolveVariablesRaw()

	/**
	 * Shared {{variable}} substitution for both escaping modes.
	 *
	 * @param string $template The template string
	 * @param array<string, mixed> $data Available data for resolution
	 * @param string $escaping One of self::ESCAPE_HTML or self::ESCAPE_NONE
	 *
	 * @return string The resolved string
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	private function substituteVariables(string $template, array $data, string $escaping): string {
		return preg_replace_callback(
			'/\{\{(\w+)\}\}/',
			static function (array $matches) use ($data, $escaping): string {
				$key = $matches[1];
				if (isset($data[$key]) === true && is_scalar($data[$key]) === true) {
					$value = (string)$data[$key];
					if ($escaping === self::ESCAPE_HTML) {
						return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
					}

					return $value;
				}

				return $matches[0];
				// Leave unresolved variables as-is.
			},
			$template,
		) ?? $template;
	}//end substituteVariables()

	/**
	 * Find unresolved variables in a template string.
	 *
	 * @param string $template The template string
	 * @param array<string, mixed> $data Available data
	 *
	 * @return array<string> List of unresolved variable names
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function findUnresolvedVariables(string $template, array $data): array {
		$unresolved = [];
		preg_match_all('/\{\{(\w+)\}\}/', $template, $matches);

		foreach ($matches[1] as $key) {
			if (isset($data[$key]) === false || is_scalar($data[$key]) === false) {
				$unresolved[] = $key;
			}
		}

		return array_unique($unresolved);
	}//end findUnresolvedVariables()

	/**
	 * Extract case number from email subject.
	 *
	 * @param string $subject The email subject
	 *
	 * @return string|null The extracted case identifier or null
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function extractCaseNumber(string $subject): ?string {
		if (preg_match(self::CASE_NUMBER_PATTERN, $subject, $matches) === 1) {
			return $matches[1];
		}

		return null;
	}//end extractCaseNumber()

	/**
	 * Process an inbound email and link it to a case.
	 *
	 * @param string $from Sender email address
	 * @param string $to Recipient email address
	 * @param string $subject Email subject
	 * @param string $body Email body
	 * @param string $inReplyTo In-Reply-To header (for threading)
	 *
	 * @return array<string, mixed> Processing result
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function processInbound(
		string $from,
		string $to,
		string $subject,
		string $body,
		string $inReplyTo = '',
	): array {
		$caseNumber = $this->extractCaseNumber(subject: $subject);

		if ($caseNumber !== null) {
			// Auto-link to case.
			$caseId = $this->repository->findCaseIdByIdentifier(identifier: $caseNumber);
			if ($caseId !== null) {
				$messageId = $this->repository->recordReceivedEmail(
					caseId: $caseId,
					from: $from,
					recipient: $to,
					subject: $subject,
					body: $body,
					inReplyTo: $inReplyTo,
				);

				$this->logger->info(
					'Inbound email auto-linked to case ' . $caseId,
					['app' => Application::APP_ID],
				);

				return [
					'linked' => true,
					'caseId' => $caseId,
					'messageId' => $messageId,
					'method' => 'auto',
				];
			}//end if
		}//end if

		// Could not auto-link; add to unlinked queue.
		return [
			'linked' => false,
			'caseNumber' => $caseNumber,
			'from' => $from,
			'subject' => $subject,
			'method' => 'unlinked',
		];
	}//end processInbound()

	/**
	 * Get email templates for a case type.
	 *
	 * @param string $caseTypeId The case type UUID
	 *
	 * @return array<int, array<string, mixed>> List of templates
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getTemplatesForCaseType(string $caseTypeId): array {
		return $this->repository->findTemplatesForCaseType(caseTypeId: $caseTypeId);
	}//end getTemplatesForCaseType()
}//end class
