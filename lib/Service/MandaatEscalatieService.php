<?php

/**
 * Dossiq MandaatEscalatieService.
 *
 * Escalation engine for mandate-denied decisions: creates an escalation
 * row pointing at the next-higher mandate holder, supports approval and
 * rejection, and auto-reroutes open escalations when the resolved
 * mandate holder changes.
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
 * @spec openspec/changes/mandaat-matrix-03-escalation-engine/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Mandate escalation lifecycle.
 *
 * @spec openspec/changes/mandaat-matrix-03-escalation-engine/tasks.md
 */
class MandaatEscalatieService {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create a new escalation.
	 *
	 * @param string $caseId Case id.
	 * @param string $decisionType Decision type.
	 * @param string $initiatorId Initiating user id.
	 * @param string $escalationReason Escalation reason.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/mandaat-matrix-03-escalation-engine/tasks.md
	 */
	public function createEscalatie(string $caseId, string $decisionType, string $initiatorId, string $escalationReason): array {
		$path = $this->resolveEscalatiePath(decisionType: $decisionType, escalationReason: $escalationReason);
		$row = [
			'caseId' => $caseId,
			'decisionType' => $decisionType,
			'initiatorId' => $initiatorId,
			'escalationReason' => $escalationReason,
			// Key = schema property (renamed). Value = an INTERNAL array key
			// returned by resolveEscalationPath() in this same class, which is
			// not a schema property and does not move here.
			'targetMandateId' => $path['mandaatId'],
			'targetUserId' => $path['userId'],
			'status' => 'open',
			'createdAt' => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
		];

		$saved = $this->save(schemaConfigKey: 'mandaat_escalatie_schema', object: $row);
		$this->logger->info(
			'Mandaat escalation created',
			[
				'caseId' => $caseId,
				'reason' => $escalationReason,
				'target' => $row['targetUserId'],
			]
		);
		return $saved;
	}//end createEscalatie()

	/**
	 * Resolve the next-higher mandate holder for a decision type.
	 *
	 * Walks Mandaat rows in descending plafond order; returns the first
	 * holder whose mandaat applies. Returns ['mandaatId'=>'', 'userId'=>'']
	 * when none is found.
	 *
	 * @param string $decisionType Decision type.
	 * @param string $escalationReason Reason, carried into the unresolved-path
	 *                                 warning so a dead-ended escalation is
	 *                                 traceable.
	 *
	 * @return array{mandaatId:string, userId:string}
	 *
	 * @spec openspec/changes/mandaat-matrix-03-escalation-engine/tasks.md
	 */
	public function resolveEscalatiePath(string $decisionType, string $escalationReason = ''): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$mSchema = (string)$this->settingsService->getConfigValue('mandaat_schema');
		$assignSchema = (string)$this->settingsService->getConfigValue('medewerker_rol_toewijzing_schema');
		$hasBlank = in_array('', [$register, $mSchema, $assignSchema], true);
		if ($objectService === null || $hasBlank === true) {
			return ['mandaatId' => '', 'userId' => ''];
		}

		try {
			$mandaten = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $mSchema,
				filters: ['status' => 'active']
			);
		} catch (\Throwable $e) {
			return ['mandaatId' => '', 'userId' => ''];
		}

		$matching = $this->rankMandatenForDecisionType(
			mandaten: $mandaten,
			decisionType: $decisionType,
		);

		foreach ($matching as $m) {
			$roleId = (string)($m['mandateeRole'] ?? '');
			if ($roleId === '') {
				continue;
			}

			try {
				$assigns = $this->searchObjectsAsArrays(
					objectService: $objectService,
					register: $register,
					schema: $assignSchema,
					filters: ['roleId' => $roleId]
				);
			} catch (\Throwable $e) {
				continue;
			}

			// Prefer primair toewijzing.
			usort(
				$assigns,
				static function (array $a, array $b): int {
					$rank = static fn (array $r): int => match ((string)($r['allocationType'] ?? 'primair')) {
						'primair' => 0,
						'observer' => 1,
						'tijdelijk' => 2,
						default => 99,
					};

					return $rank($a) <=> $rank($b);
				}
			);

			foreach ($assigns as $a) {
				$userId = (string)($a['userId'] ?? '');
				if ($userId !== '') {
					return ['mandaatId' => (string)($m['id'] ?? ''), 'userId' => $userId];
				}
			}
		}//end foreach

		// No higher mandate holder exists for this decision type — surface it
		// with the reason so an escalation that silently lands nowhere is
		// traceable in the log rather than only visible as an empty target.
		$this->logger->warning(
			'Mandaat escalation path unresolved',
			['decisionType' => $decisionType, 'reason' => $escalationReason]
		);

		return ['mandaatId' => '', 'userId' => ''];
	}//end resolveEscalatiePath()

	/**
	 * Keep the mandaten that apply to a decision type, highest plafond first.
	 *
	 * @param array<int, array<string, mixed>> $mandaten Active mandaat rows.
	 * @param string $decisionType Decision type.
	 *
	 * @return array<int, array<string, mixed>> The applicable mandaten, ranked.
	 */
	private function rankMandatenForDecisionType(array $mandaten, string $decisionType): array {
		$matching = [];
		foreach ($mandaten as $m) {
			$decTypes = (array)(($m['terms'] ?? [])['decisionTypes'] ?? []);
			if (count($decTypes) > 0 && in_array($decisionType, $decTypes, true) === false) {
				continue;
			}

			$matching[] = $m;
		}//end foreach

		// Sort by plafondCents descending (null/missing → 0).
		usort(
			$matching,
			static fn (array $a, array $b): int
				=> ((int)(($b['terms'] ?? [])['plafondCents'] ?? 0)) <=> ((int)(($a['terms'] ?? [])['plafondCents'] ?? 0))
		);

		return $matching;
	}//end rankMandatenForDecisionType()

	/**
	 * Approve an open escalation.
	 *
	 * @param string $escalationId Escalation id.
	 * @param string $mandaathouderUserId Approving user id (must match targetUserId).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When unauthorized or escalation missing.
	 *
	 * @spec openspec/changes/mandaat-matrix-03-escalation-engine/tasks.md
	 */
	public function approveEscalatie(string $escalationId, string $mandaathouderUserId): array {
		$escalation = $this->findEscalation(escalationId: $escalationId);
		if ($escalation === null) {
			throw new RuntimeException('Escalation not found: ' . $escalationId);
		}

		if ((string)($escalation['targetUserId'] ?? '') !== $mandaathouderUserId) {
			throw new RuntimeException('Caller is not the resolved mandate holder');
		}

		if (($escalation['status'] ?? '') !== 'open') {
			throw new RuntimeException('Escalation not in open status');
		}

		$escalation['status'] = 'approved';
		$escalation['resolvedAt'] = (new DateTimeImmutable())->format('Y-m-d\TH:i:sP');
		return $this->save(schemaConfigKey: 'mandaat_escalatie_schema', object: $escalation);
	}//end approveEscalatie()

	/**
	 * Reject an open escalation.
	 *
	 * @param string $escalationId Escalation id.
	 * @param string $reason Rejection reason.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When the escalation is missing.
	 *
	 * @spec openspec/changes/mandaat-matrix-03-escalation-engine/tasks.md
	 */
	public function rejectEscalatie(string $escalationId, string $reason): array {
		$escalation = $this->findEscalation(escalationId: $escalationId);
		if ($escalation === null) {
			throw new RuntimeException('Escalation not found: ' . $escalationId);
		}

		if (($escalation['status'] ?? '') !== 'open') {
			throw new RuntimeException('Escalation not in open status');
		}

		$escalation['status'] = 'rejected';
		$escalation['rejectedReason'] = $reason;
		$escalation['resolvedAt'] = (new DateTimeImmutable())->format('Y-m-d\TH:i:sP');
		return $this->save(schemaConfigKey: 'mandaat_escalatie_schema', object: $escalation);
	}//end rejectEscalatie()

	/**
	 * Reroute all open escalations targeting `oldUserId` to `newUserId`.
	 *
	 * @param string $oldUserId Old user id.
	 * @param string $newUserId New user id.
	 *
	 * @return int Number of rerouted escalations.
	 *
	 * @spec openspec/changes/mandaat-matrix-03-escalation-engine/tasks.md
	 */
	public function autoRerouteOnPersonnelChange(string $oldUserId, string $newUserId): int {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('mandaat_escalatie_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return 0;
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['status' => 'open', 'targetUserId' => $oldUserId]
			);
		} catch (\Throwable $e) {
			return 0;
		}

		$count = 0;
		foreach ($rows as $row) {
			$row['targetUserId'] = $newUserId;
			try {
				$this->saveObjectAsArray(objectService: $objectService, register: $register, schema: $schema, object: $row);
				$count++;
			} catch (\Throwable $e) {
				$this->logger->warning('Mandaat reroute failed', ['id' => $row['id'] ?? '', 'error' => $e->getMessage()]);
			}
		}

		return $count;
	}//end autoRerouteOnPersonnelChange()

	/**
	 * Fetch a single escalation row by id.
	 *
	 * @param string $escalationId Id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findEscalation(string $escalationId): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('mandaat_escalatie_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		try {
			return $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $escalationId
			);
		} catch (\Throwable $e) {
			return null;
		}
	}//end findEscalatie()

	/**
	 * Persist a payload to the configured schema.
	 *
	 * @param string $schemaConfigKey Config key.
	 * @param array<string, mixed> $object Payload.
	 *
	 * @return array<string, mixed>
	 */
	private function save(string $schemaConfigKey, array $object): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue($schemaConfigKey);
		if ($objectService === null || $register === '' || $schema === '') {
			return $object;
		}

		try {
			$saved = $this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				object: $object
			);
			return ($saved ?? $object);
		} catch (\Throwable $e) {
			$this->logger->error('Mandaat persist failed', ['key' => $schemaConfigKey, 'error' => $e->getMessage()]);
			return $object;
		}
	}//end save()
}//end class
