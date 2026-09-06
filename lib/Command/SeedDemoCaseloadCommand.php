<?php

/**
 * Dossiq dossiq:demo:seed command.
 *
 * Seeds a demonstrable caseload from lib/Settings/demo_caseload_seed_data.json:
 * cases across the shipped case types plus their tasks, positioned so every
 * dashboard widget has rows to show.
 *
 * Safe to re-run. A case whose title is already present is skipped, and its
 * tasks with it.
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

use OCA\Dossiq\Service\DemoCaseloadReport;
use OCA\Dossiq\Service\DemoCaseloadSeedDataService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Seed the demo caseload.
 *
 * @spec openspec/specs/dossiq-app-scaffold/spec.md
 */
class SeedDemoCaseloadCommand extends Command {
	/**
	 * Wire the command against the seeder and the user/group managers.
	 *
	 * @param DemoCaseloadSeedDataService $seeder The demo caseload seeder.
	 * @param DemoCaseloadReport $report Reads the caseload buckets back.
	 * @param IUserSession $userSession Session used to impersonate an admin.
	 * @param IGroupManager $groupManager Resolves an admin to impersonate.
	 */
	public function __construct(
		private readonly DemoCaseloadSeedDataService $seeder,
		private readonly DemoCaseloadReport $report,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Define the command name, description and options.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dossiq-app-scaffold/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'dossiq:demo:seed')
			->setDescription('Seed a demo caseload of cases and tasks, so the dashboard has something to show.')
			->addOption(
				name: 'verify-only',
				shortcut: null,
				mode: InputOption::VALUE_NONE,
				description: 'Report what is already in the register and create nothing.'
			);
	}//end configure()

	/**
	 * Run the seed and report what landed.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int Symfony command exit code.
	 *
	 * @spec openspec/specs/dossiq-app-scaffold/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		// OpenRegister enforces RBAC on saveObject against the current user.
		// occ runs with no session ("Anonymous"), which lacks create rights on
		// the Case schema, so impersonate an admin for the seed.
		if ($this->userSession->getUser() === null) {
			$admin = $this->resolveAdmin();
			if ($admin === null) {
				$output->writeln('<error>No admin user found to run the seed under.</error>');
				return Command::FAILURE;
			}

			$this->userSession->setUser($admin);
			$output->writeln('<comment>Seeding as admin user "' . $admin->getUID() . '".</comment>');
		}

		$verifyOnly = (bool)$input->getOption('verify-only');

		try {
			if ($verifyOnly === false) {
				$result = $this->seeder->seed();
				$output->writeln('<info>dossiq:demo:seed done</info>');
				$output->writeln('  cases created = ' . $result['cases']);
				$output->writeln('  tasks created = ' . $result['tasks']);
				$output->writeln('  cases skipped = ' . $result['skipped'] . ' (already present)');
			}

			$buckets = $this->report->buckets();
		} catch (\Throwable $e) {
			$output->writeln('<error>Demo caseload seed failed: ' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}

		// Read back from the register, because the deadline a case ends up with
		// is materialised by OpenRegister, not written by the seed.
		$output->writeln('');
		$output->writeln('<info>What the dashboard will read</info>');
		$output->writeln('  open cases          = ' . $buckets['open']);
		$output->writeln('  overdue             = ' . $buckets['overdue']);
		$output->writeln('  deadline within 3d  = ' . $buckets['dueSoon']);
		$output->writeln('  closed              = ' . $buckets['closed']);
		$output->writeln('  open tasks          = ' . $buckets['tasksOpen']);
		$output->writeln('  tasks due within 3d = ' . $buckets['tasksDue']);

		if ($buckets['tasksOpen'] === 0) {
			$output->writeln('');
			$output->writeln('<error>No open tasks landed. Check that task_schema points inside the dossiq register.</error>');
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}//end execute()

	/**
	 * Resolve the first member of the admin group, if any.
	 *
	 * @return IUser|null The admin user to impersonate, or null when none exists.
	 */
	private function resolveAdmin(): ?IUser {
		$adminGroup = $this->groupManager->get('admin');
		if ($adminGroup === null) {
			return null;
		}

		$users = $adminGroup->getUsers();
		if (count($users) === 0) {
			return null;
		}

		return reset($users);
	}//end resolveAdmin()
}//end class
