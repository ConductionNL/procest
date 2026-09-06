<?php

/**
 * Dossiq TermijnNotificationService.
 *
 * Renders + routes the four AWB notification templates (ontvangstbevestiging,
 * extension, ingebrekestelling-receipt, dwangsom-payment) using the
 * application's translation layer (en/nl) and dispatches them to the
 * recipient via {@see BerichtenboxRoutingService} (or returns the
 * rendered payload when no router is wired).
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use InvalidArgumentException;
use OCA\Dossiq\BackgroundJob\DeadlineNotificationDispatchJob;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * Burger notification template renderer + dispatcher.
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
 */
class TermijnNotificationService {
	public const TEMPLATES = [
		'ontvangstbevestiging',
		'extension',
		'ingebrekestelling-receipt',
		'dwangsom-payment',
	];

	/**
	 * Constructor.
	 *
	 * @param TermijnService $termService Termijn service.
	 * @param BerichtenboxRoutingService $router Router (dossiq notification-router).
	 * @param LoggerInterface $logger Logger.
	 * @param IJobList|null $jobList Optional job list for async dispatch.
	 */
	public function __construct(
		private readonly TermijnService $termService,
		private readonly BerichtenboxRoutingService $router,
		private readonly LoggerInterface $logger,
		private readonly ?IJobList $jobList = null,
	) {
	}//end __construct()

	/**
	 * Enqueue a notification for asynchronous dispatch via NC's QueuedJob
	 * runner. The same payload contract as {@see sendTermijnNotification}
	 * but non-blocking on SMTP / berichtenbox-router failure — the job
	 * runner retries automatically.
	 *
	 * @param string $type Template type.
	 * @param string $termInstanceId Instance id.
	 * @param string $recipientUserId Recipient user id.
	 * @param array<string, mixed> $context Extra context.
	 *
	 * @return bool TRUE when the job was queued; FALSE when no job list is
	 *              wired (callers MAY fall back to synchronous send).
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
	 */
	public function queueTermijnNotification(
		string $type,
		string $termInstanceId,
		string $recipientUserId,
		array $context = [],
	): bool {
		if ($this->jobList === null) {
			return false;
		}

		if (in_array($type, self::TEMPLATES, true) === false) {
			throw new InvalidArgumentException('Unknown template: ' . $type);
		}

		$this->jobList->add(
			DeadlineNotificationDispatchJob::class,
			[
				'type' => $type,
				'termijnInstanceId' => $termInstanceId,
				'recipientUserId' => $recipientUserId,
				'context' => $context,
			]
		);
		$this->logger->info(
			'TermijnNotification queued',
			['type' => $type, 'recipient' => $recipientUserId, 'instance' => $termInstanceId]
		);
		return true;
	}//end queueTermijnNotification()

	/**
	 * Send a templated termijnbewaking notification.
	 *
	 * @param string $type Template type.
	 * @param string $termInstanceId Instance id.
	 * @param string $recipientUserId Recipient user id.
	 * @param array<string, mixed> $context Extra context (zaak ref, dates, amounts).
	 *
	 * @return array<string, mixed> Dispatched payload (with rendered subject +
	 *                              body and the `verzending` delivery record).
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
	 */
	public function sendTermijnNotification(
		string $type,
		string $termInstanceId,
		string $recipientUserId,
		array $context = [],
	): array {
		if (in_array($type, self::TEMPLATES, true) === false) {
			throw new InvalidArgumentException('Unknown template: ' . $type);
		}

		$instance = $this->termService->getTermijnInstance($termInstanceId);
		$payload = $this->renderTemplate(type: $type, instance: $instance ?? [], context: $context);

		$payload['recipient'] = $recipientUserId;
		$payload['deadlineInstance'] = $termInstanceId;
		$payload['template'] = $type;

		// Route the rendered notification through the dossiq notification
		// router so the burger actually receives it; the returned delivery
		// record (kanaal / berichtId / verzondenOp) is attached to the payload
		// and is what the caller persists as proof of dispatch.
		$payload['dispatch'] = $this->router->routeToBerichtenbox(
			[
				'reference' => $termInstanceId,
				'addressee' => (array)($context['addressee'] ?? []),
			]
		);

		$this->logger->info(
			'TermijnNotification dispatched',
			[
				'type' => $type,
				'recipient' => $recipientUserId,
				'instance' => $termInstanceId,
				'notificationChannel' => $payload['dispatch']['notificationChannel'],
			]
		);

		return $payload;
	}//end sendTermijnNotification()

	/**
	 * Render a template (nl) into a payload with subject + body.
	 *
	 * @param string $type Template type.
	 * @param array<string, mixed> $instance TermijnInstance (may be empty).
	 * @param array<string, mixed> $context Extra context.
	 *
	 * @return array{subject:string, body:string, locale:string}
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
	 */
	public function renderTemplate(string $type, array $instance, array $context): array {
		$locale = (string)($context['locale'] ?? 'nl');
		$case = (string)($instance['case'] ?? ($context['case'] ?? '–'));
		$end = (string)($instance['endDateCurrent'] ?? ($context['endDate'] ?? '–'));

		$subject = '';
		$body = '';

		switch ($type) {
			case 'ontvangstbevestiging':
				$subject = 'Ontvangstbevestiging zaak ' . $case;
				$body = "Beste aanvrager,\n\n"
					. 'Wij hebben uw aanvraag ontvangen onder zaaknummer ' . $case . ".\n"
					. 'De wettelijke termijn loopt af op ' . $end . ".\n"
					. 'Volg uw zaak via het burgerportaal of neem contact op met de gemeente.';
				break;
			case 'extension':
				$newEnd = (string)($context['newEinddatum'] ?? $end);
				$subject = 'Verlenging termijn zaak ' . $case;
				$body = "Beste aanvrager,\n\n"
					. 'De termijn voor zaak ' . $case . ' is verlengd. De nieuwe deadline is ' . $newEnd . ".\n"
					. 'U vindt de officiele verlengingsbrief in uw burgerportaal.';
				break;
			case 'ingebrekestelling-receipt':
				$graceEnd = (string)($context['graceEnd'] ?? '–');
				$subject = 'Bevestiging ingebrekestelling zaak ' . $case;
				$body = "Beste aanvrager,\n\n"
					. 'Wij hebben uw ingebrekestelling voor zaak ' . $case . " ontvangen.\n"
					. 'De wettelijke begunstigingstermijn (AWB 4:17) eindigt op ' . $graceEnd . ".\n"
					. 'Indien er voor dat moment een beschikking is afgegeven, vervalt de dwangsom.';
				break;
			case 'dwangsom-payment':
				$amountCents = (int)($context['bedragCents'] ?? 0);
				$amountEur = number_format($amountCents / 100, 2, ',', '.');
				$ref = (string)($context['betalingsreferentie'] ?? '–');
				$subject = 'Uitbetaling dwangsom zaak ' . $case;
				$body = "Beste aanvrager,\n\n"
					. 'De dwangsom van EUR ' . $amountEur . ' voor zaak ' . $case . " is overgemaakt.\n"
					. 'Onder betalingsreferentie ' . $ref . '.';
				break;
		}//end switch

		return ['subject' => $subject, 'body' => $body, 'locale' => $locale];
	}//end renderTemplate()
}//end class
