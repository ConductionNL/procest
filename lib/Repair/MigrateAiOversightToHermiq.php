<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Repair
 * @package   OCA\Dossiq\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Service\Ai\AiAuditLog;
use OCA\Dossiq\Service\Ai\AiOversightDelegationService;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Throwable;

/**
 * Replays existing AI oversight decisions into hermiq.
 *
 * Hermiq now owns the EU AI Act Art. 14 oversight record, and procest's own
 * oversight page is retired. The decisions already in procest's audit log are
 * evidence and cannot simply lose their surface, so they are sent through the
 * SAME event every new decision travels — old evidence takes the identical path
 * as new evidence, and hermiq never reads procest's register.
 *
 * ONLY DECISIONS MOVE. procest's log holds two kinds of entry: `action:
 * suggestion`, which records that the model ran, and `userAction:
 * accepted|rejected|modified`, which records that a human judged it. Only the
 * second is human-oversight evidence; sending the first would put rows in the
 * Art. 14 log that nobody decided. The suggestion entries stay in procest's own
 * log, which is not deleted by this change.
 *
 * IDEMPOTENT. Every delegated record carries a stable `externalRef` derived from
 * the source entry, so a second `occ upgrade` re-sends and hermiq recognises
 * them rather than duplicating the trail.
 *
 * NON-FATAL. A missing hermiq, an unreachable register or a single bad entry
 * logs and continues: an upgrade must not fail because an audit projection could
 * not complete, and the local copies are still there to replay from.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/ai-oversight-surface/spec.md
 */
class MigrateAiOversightToHermiq implements IRepairStep {
	use RunsUnderSystemIdentity;


	/**
	 * How many entries to pull per page.
	 *
	 * @var integer
	 */
	private const PAGE_SIZE = 200;

	/**
	 * Safety bound on pages walked, so a misbehaving backend cannot spin an
	 * upgrade forever.
	 *
	 * @var integer
	 */
	private const MAX_PAGES = 200;

	/**
	 * Constructor.
	 *
	 * @param AiAuditLog $audit Reads procest's own oversight log.
	 * @param AiOversightDelegationService $oversight Sends a decision to hermiq.
	 * @param SettingsService|null $settings Resolves OpenRegister for the system identity.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly AiAuditLog $audit,
		private readonly AiOversightDelegationService $oversight,
		private readonly ?SettingsService $settings=null,
	) {

	}//end __construct()

	/**
	 * Get the repair step name.
	 *
	 * @return string The name shown during upgrade.
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/ai-oversight-surface/spec.md
	 */
	public function getName(): string {
		return 'Replay recorded AI oversight decisions into hermiq';
	}//end getName()

	/**
	 * Run the replay.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/ai-oversight-surface/spec.md
	 */
	public function run(IOutput $output): void {
		// THE READ IS FAIL-CLOSED TOO, AND A REFUSED READ LOOKS LIKE AN EMPTY
		// LOG. A repair step has no session, so OpenRegister resolves the actor
		// as 'Anonymous' and refuses reads on any schema without an explicit
		// `public` grant. `AiAuditLog::list()` degrades gracefully to an empty
		// page on failure — correct for the oversight surface, wrong here,
		// because "the log is empty" and "the log was refused" then produce the
		// same "no audit entries to consider" line while the Art. 14 evidence
		// silently fails to move. The whole replay runs under the same system
		// identity the writing steps use.
		$this->withSystemIdentity(
			objectService: $this->settings?->getObjectService(),
			work: function () use ($output): void {
				$this->replayAll(output: $output);
			}
		);

	}//end run()

	/**
	 * Page through the audit log and replay every decision.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/ai-oversight-surface/spec.md
	 */
	private function replayAll(IOutput $output): void {
		$sent = 0;
		$skipped = 0;
		$offset = 0;

		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			try {
				$batch = $this->audit->list([], self::PAGE_SIZE, $offset);
			} catch (Throwable $e) {
				$output->warning('AI oversight replay: could not read the audit log — ' . $e->getMessage());
				return;
			}

			// `entries`, NOT `results`. AiAuditLog::list() returns
			// {entries, total, limit, offset}; reading `results` here silently
			// yielded [] on every page, so the replay would have reported
			// "no audit entries to consider" on an instance full of them.
			// phpstan caught it before it ever ran.
			$entries = $batch['entries'];
			if (empty($entries) === true) {
				break;
			}

			[$pageSent, $pageSkipped] = $this->replayPage(output: $output, entries: $entries);
			$sent += $pageSent;
			$skipped += $pageSkipped;

			if (count($entries) < self::PAGE_SIZE) {
				break;
			}

			$offset += self::PAGE_SIZE;
		}//end for

		$this->report(output: $output, sent: $sent, skipped: $skipped);

	}//end replayAll()

	/**
	 * Delegate one page of audit entries.
	 *
	 * Split out of run() so the paging loop and the per-entry handling can each
	 * be read on their own — phpmd flagged the combined method, and it was
	 * right that two concerns were tangled.
	 *
	 * @param IOutput $output The upgrade output.
	 * @param array<int, mixed> $entries The page of entries.
	 *
	 * @return array{0: int, 1: int} Sent and skipped counts for this page.
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/ai-oversight-surface/spec.md
	 */
	private function replayPage(IOutput $output, array $entries): array {
		$sent = 0;
		$skipped = 0;

		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				$skipped++;
				continue;
			}

			try {
				$delegated = $this->oversight->delegate(entry: $entry);
			} catch (Throwable $e) {
				// Cannot happen by contract, but an upgrade is the wrong place
				// to find out otherwise.
				$output->warning('AI oversight replay: entry failed — ' . $e->getMessage());
				$skipped++;
				continue;
			}

			if ($delegated === true) {
				$sent++;
				continue;
			}

			$skipped++;
		}//end foreach

		return [$sent, $skipped];
	}//end replayPage()

	/**
	 * Report what the replay did.
	 *
	 * @param IOutput $output The upgrade output.
	 * @param integer $sent Records delegated.
	 * @param integer $skipped Records not delegated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/ai-oversight-surface/spec.md
	 */
	private function report(IOutput $output, int $sent, int $skipped): void {
		if ($sent === 0 && $skipped === 0) {
			$output->info('AI oversight replay: no audit entries to consider.');
			return;
		}

		$output->info(
			sprintf(
				'AI oversight replay: %d decision(s) sent to hermiq, %d entry/entries skipped '
				. '(suggestion-only records, entries without a subject, or hermiq not installed).',
				$sent,
				$skipped
			)
		);

	}//end report()

}//end class
