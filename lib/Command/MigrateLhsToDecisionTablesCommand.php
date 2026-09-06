<?php

/**
 * Dossiq dossiq:actions:migrate-to-flows command.
 *
 * Projects stored `automaticAction` objects onto OpenRegister flows, so the
 * configuration becomes executable. It is a command rather than a repair step
 * because `FlowService` refuses to create a flow without a signed-in owner and
 * an active organisation, and an upgrade runs as nobody — see
 * {@see \OCA\Dossiq\Service\Vth\LhsMatrixDecisionTableMigrator}.
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
 *
 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Command;

use OCA\Dossiq\Service\Vth\LhsMatrixDecisionTableMigrator;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Project Dossiq workflow definitions onto OpenRegister flows.
 *
 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
 */
class MigrateLhsToDecisionTablesCommand extends Command {
	/**
	 * Wire the command against the migrator.
	 *
	 * @param LhsMatrixDecisionTableMigrator $migrator The migrator.
	 * @param IUserManager $userManager Resolves the acting user.
	 */
	public function __construct(
		private readonly LhsMatrixDecisionTableMigrator $migrator,
		private readonly IUserManager $userManager,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Define the command name, description and options.
	 *
	 * `--user` is REQUIRED and has no default. The created flows inherit that
	 * user's identity and organisation permanently, so guessing an owner would
	 * hand every migrated flow to whoever the guess landed on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'dossiq:lhs:migrate-to-decision-tables')
			->setDescription(
				'Project each LHS matrix onto a decision table (idempotent). The enforcement '
				. 'matrix is a three-axis lookup, which is a decision table, and OpenRegister '
				. 'already has one evaluator for those. A matrix whose cell names a value absent '
				. 'from its own axis is SKIPPED rather than projected, because the rule would be '
				. 'as unreachable in the table as the cell is in the matrix. The tables arrive '
				. 'DISABLED: the matrix still drives recommendations. Use --dry-run first.'
			)
			->addOption(
				name: 'user',
				mode: InputOption::VALUE_REQUIRED,
				description: 'UID the created flows belong to; also supplies the active organisation.'
			)
			->addOption(
				name: 'dry-run',
				mode: InputOption::VALUE_NONE,
				description: 'Report what would be created or updated, and write nothing.'
			);
	}//end configure()

	/**
	 * Run the migration and report per-action outcomes.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int Symfony command exit code.
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$uid = (string)($input->getOption('user') ?? '');
		if ($uid === '') {
			$output->writeln('<error>--user is required: a flow needs an owner and an organisation.</error>');
			return Command::INVALID;
		}

		$user = $this->userManager->get($uid);
		if ($user === null) {
			$output->writeln('<error>No such user: ' . $uid . '</error>');
			return Command::INVALID;
		}

		$dryRun = (bool)$input->getOption('dry-run');
		$summary = $this->migrator->migrate(user: $user, dryRun: $dryRun);

		return $this->report(summary: $summary, dryRun: $dryRun, output: $output);
	}//end execute()

	/**
	 * Print the summary, and decide the exit code from it.
	 *
	 * A failed row exits non-zero. Reporting success over a partial migration is
	 * how a caller ends up believing data moved that did not.
	 *
	 * @param array<string, mixed> $summary The migrator's summary.
	 * @param bool $dryRun Whether this was a dry run.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int Symfony command exit code.
	 */
	private function report(array $summary, bool $dryRun, OutputInterface $output): int {
		if (isset($summary['note']) === true && $summary['note'] !== '') {
			$output->writeln('<comment>' . (string)$summary['note'] . '</comment>');
			return Command::SUCCESS;
		}

		$prefix = 'dossiq:lhs:migrate-to-decision-tables';
		if ($dryRun === true) {
			$prefix .= ' (dry run, nothing was written)';
		}

		$output->writeln('<info>' . $prefix . '</info>');
		foreach (['total', 'created', 'updated', 'skipped', 'failed'] as $key) {
			$output->writeln('  ' . str_pad($key, 8) . ' = ' . (string)$summary[$key]);
		}

		foreach ($summary['rows'] as $row) {
			$output->writeln('  [' . $row['outcome'] . '] ' . $row['marker'] . ': ' . $row['detail']);
		}

		if ($summary['failed'] > 0) {
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}//end report()
}//end class
