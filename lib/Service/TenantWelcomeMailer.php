<?php

/**
 * Dossiq Tenant Welcome Mailer
 *
 * Sends a welcome email to a freshly provisioned tenant administrator.
 * Wraps Nextcloud's IMailer so the orchestration service stays a pure
 * sequence of typed dependencies.
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
 * @spec openspec/changes/tenant-zaaksysteem-saas-03-schema-provisioning/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Welcome-mail dispatch for newly provisioned tenants.
 *
 * @spec openspec/specs/tenant-onboarding/spec.md#requirement-onboarding-checklist-and-progress-dashboard-req-003-a-req-003-d
 */
class TenantWelcomeMailer {
	/**
	 * Constructor.
	 *
	 * @param IMailer $mailer Nextcloud mailer.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IMailer $mailer,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send the welcome email to the tenant administrator.
	 *
	 * @param array<string,mixed> $tenant Tenant row (must carry adminEmail).
	 *
	 * @return bool True when the message was queued.
	 *
	 * @spec openspec/specs/tenant-onboarding/spec.md#requirement-onboarding-checklist-and-progress-dashboard-req-003-a-req-003-d
	 */
	public function sendWelcomeEmail(array $tenant): bool {
		$to = $this->resolveAdminEmail(tenant: $tenant);
		if ($to === null) {
			$this->logger->info(
				'Dossiq: no admin email on tenant — skipping welcome email',
				['tenant' => $tenant['slug'] ?? '']
			);
			return false;
		}

		try {
			$msg = $this->mailer->createMessage();
			$msg->setTo([$to]);
			$msg->setSubject('Welkom bij Dossiq — uw werkomgeving is klaar');
			$msg->setPlainBody($this->renderBody(tenant: $tenant));
			$this->mailer->send($msg);
			return true;
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: sendWelcomeEmail failed',
				['tenant' => $tenant['slug'] ?? '', 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end sendWelcomeEmail()

	/**
	 * Resolve the admin email address from a tenant row.
	 *
	 * @param array<string,mixed> $tenant Tenant row.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/tenant-onboarding/spec.md#requirement-onboarding-checklist-and-progress-dashboard-req-003-a-req-003-d
	 */
	public function resolveAdminEmail(array $tenant): ?string {
		$candidates = [
			$tenant['adminEmail'] ?? null,
			$tenant['contactEmail'] ?? null,
			$tenant['emailContact'] ?? null,
		];
		foreach ($candidates as $cand) {
			if (is_string($cand) === true && $cand !== '' && filter_var($cand, FILTER_VALIDATE_EMAIL) !== false) {
				return $cand;
			}
		}

		return null;
	}//end resolveAdminEmail()

	/**
	 * Build the welcome body. Plain text — HTML templating is rendered by NC's
	 * own EmailTemplate when the dossiq theme is available.
	 *
	 * @param array<string,mixed> $tenant Tenant row.
	 *
	 * @return string Plain-text body.
	 *
	 * @spec openspec/specs/tenant-onboarding/spec.md#requirement-onboarding-checklist-and-progress-dashboard-req-003-a-req-003-d
	 */
	public function renderBody(array $tenant): string {
		$name = (string)($tenant['displayName'] ?? $tenant['legalName'] ?? 'gemeente');
		$slug = (string)($tenant['slug'] ?? '');
		$domain = (string)($tenant['domain'] ?? '');
		$loginHint = 'uw dossiq-instance';
		if ($domain !== '') {
			$loginHint = 'https://' . $domain;
		}

		return <<<TXT
Beste beheerder,

Welkom bij Dossiq. De werkomgeving voor {$name} is succesvol klaargezet
en is bereikbaar op {$loginHint}.

Wat is er klaargezet:
- Een eigen schema voor uw zaak- en documentdata
- Standaard zaaktypen (bezwaar, beroep, klacht)
- De standaard mandaat-matrix
- De rollen tenant_admin, case_handler en viewer

Volgende stappen:
1. Log in als beheerder
2. Voltooi de onboarding-checklist op de Tenant-instellingenpagina
3. Importeer uw eigen mandaten en zaaktypen

Met vriendelijke groet,
Het Dossiq-team
(tenant slug: {$slug})
TXT;
	}//end renderBody()
}//end class
