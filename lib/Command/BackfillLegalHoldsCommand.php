<?php

/**
 * Dossiq dossiq:legal-hold:backfill command.
 *
 * Repairs the cases that passed through an Awb bezwaar/beroep proceeding while
 * {@see \OCA\Dossiq\Listener\BezwaarLegalHoldListener} was dead and therefore
 * never received the legal hold archiving law requires (procest#694).
 *
 * The listener was inert for its entire life: `resolveCaseObject()` called
 * `MagicMapper::findByUuid()`, a method that does not exist and has no
 * `__call()` fallback, so every invocation raised a fatal `\Error` that the
 * surrounding `catch (\Throwable)` swallowed — no hold, no exception, no log
 * line. procest#693 fixes it forward; this command repairs the backlog it left.
 *
 * Safety posture:
 * - Dry-run by DEFAULT. Nothing is written unless `--apply` is passed.
 * - Idempotent. A case that already carries an active hold is skipped, so the
 *   command is safe to re-run and safe to run after the listener is live.
 * - Additive only. It places holds; it never releases one and never deletes or
 *   range-updates anything. Retention data is legal-retention data.
 * - Only cases with at least one OPEN proceeding are held. A proceeding that
 *   already reached its terminal decision is closed, and placing a hold on it
 *   now only to release it would write a hold that never existed into the
 *   retention history. Those are reported, not written.
 * - The reason string names this remediation explicitly, so a backfilled hold
 *   is never mistaken for a contemporaneous one in an audit.
 *
 * @category Command
 * @package  OCA\Dossiq\Command
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Command;

use OCA\Dossiq\Command\Backfill\AwbProceedingScanner;
use OCA\Dossiq\Command\Backfill\LegalHoldApplier;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Backfill the Awb legal holds the dead bezwaar listener never placed.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Remediation spans several OpenRegister collaborators.
 *
 * @spec openspec/specs/archief-edepot-handover/spec.md#requirement-legal-proceedings-must-suspend-archival-via-or-legal-holds
 */
class BackfillLegalHoldsCommand extends Command {

	/**
	 * OpenRegister LegalHoldService FQN (resolved lazily).
	 *
	 * @var string
	 */
	private const LEGAL_HOLD_SERVICE = 'OCA\OpenRegister\Service\Archival\LegalHoldService';

	/**
	 * OpenRegister ObjectService FQN (resolved lazily).
	 *
	 * @var string
	 */
	private const OBJECT_SERVICE = 'OCA\OpenRegister\Service\ObjectService';

	/**
	 * OpenRegister object mapper FQN (resolved lazily).
	 *
	 * @var string
	 */
	private const OBJECT_MAPPER = 'OCA\OpenRegister\Db\MagicMapper';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (OpenRegister resolved lazily).
	 * @param IUserSession $userSession Session used to impersonate an admin.
	 * @param IGroupManager $groupManager Resolves an admin to impersonate.
	 * @param AwbProceedingScanner $scanner Finds the cases with an open Awb proceeding.
	 * @param LegalHoldApplier $applier Reports and (with --apply) places the holds.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly AwbProceedingScanner $scanner,
		private readonly LegalHoldApplier $applier,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Define command name, description and options.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archief-edepot-handover/spec.md#requirement-legal-proceedings-must-suspend-archival-via-or-legal-holds
	 */
	protected function configure(): void {
		$this->setName(name: 'dossiq:legal-hold:backfill')
			->setDescription(
				'Backfill Awb legal holds on cases with an open bezwaar/beroep proceeding (procest#694). Dry-run unless --apply.'
			)
			->addOption(
				name: 'apply',
				shortcut: null,
				mode: InputOption::VALUE_NONE,
				description: 'Actually place the holds. Without this flag the command only reports.'
			)
			->addOption(
				name: 'include-deleted',
				shortcut: null,
				mode: InputOption::VALUE_NONE,
				description: 'Also consider soft-deleted cases and proceedings (off by default).'
			);
	}//end configure()

	/**
	 * Run the backfill (or the dry-run report).
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int Symfony command exit code.
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$apply = (bool)$input->getOption('apply');
		$includeDeleted = (bool)$input->getOption('include-deleted');

		if ($this->impersonateAdmin(output: $output) === false) {
			return Command::FAILURE;
		}

		$objectService = $this->resolveOr(fqn: self::OBJECT_SERVICE);
		$objectMapper = $this->resolveOr(fqn: self::OBJECT_MAPPER);
		$legalHoldService = $this->resolveOr(fqn: self::LEGAL_HOLD_SERVICE);

		if ($objectService === null || $objectMapper === null || $legalHoldService === null) {
			$output->writeln('<error>OpenRegister is not available; cannot backfill legal holds.</error>');
			return Command::FAILURE;
		}

		$candidates = $this->scanner->scan(
			objectService: $objectService,
			includeDeleted: $includeDeleted,
			output: $output
		);

		foreach ($this->scanner->getScanErrors() as $scanError) {
			$output->writeln('  <error>[scan failed]</error> ' . $scanError);
		}

		if (count($candidates) === 0) {
			$output->writeln('<info>No cases with an open Awb proceeding were found. Nothing to backfill.</info>');
			return Command::SUCCESS;
		}

		return $this->applier->reportAndApply(
			candidates: $candidates,
			objectMapper: $objectMapper,
			legalHoldService: $legalHoldService,
			apply: $apply,
			includeDeleted: $includeDeleted,
			output: $output
		);
	}//end execute()

	/**
	 * Impersonate an admin so OpenRegister writes are permitted.
	 *
	 * The occ context has no session ("Anonymous"), and the hold write goes
	 * through MagicMapper::update(); without a user the audit trail would
	 * attribute the remediation to nobody.
	 *
	 * @param OutputInterface $output Console output.
	 *
	 * @return bool True when a session user is available.
	 */
	private function impersonateAdmin(OutputInterface $output): bool {
		if ($this->userSession->getUser() !== null) {
			return true;
		}

		$adminGroup = $this->groupManager->get('admin');
		if ($adminGroup === null) {
			$output->writeln('<error>No admin group found to run the backfill under.</error>');
			return false;
		}

		$users = $adminGroup->getUsers();
		if (count($users) === 0) {
			$output->writeln('<error>No admin user found to run the backfill under.</error>');
			return false;
		}

		$admin = reset($users);
		$this->userSession->setUser($admin);
		$output->writeln('<comment>Running as admin user "' . $admin->getUID() . '".</comment>');

		return true;
	}//end impersonateAdmin()

	/**
	 * Resolve an OpenRegister collaborator by FQN, or null when unavailable.
	 *
	 * @param string $fqn Fully-qualified class name.
	 *
	 * @return object|null The service, or null when OpenRegister is absent.
	 */
	private function resolveOr(string $fqn): ?object {
		if (class_exists($fqn) === false) {
			return null;
		}

		try {
			$service = $this->container->get($fqn);
			if (is_object($service) === true) {
				return $service;
			}

			return null;
		} catch (\Throwable $e) {
			return null;
		}//end try
	}//end resolveOr()
}//end class
