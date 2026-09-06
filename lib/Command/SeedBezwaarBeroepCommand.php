<?php

/**
 * Dossiq dossiq:bezwaar:seed command.
 *
 * One-shot, idempotent seed of the Bezwaar & Beroep case types (plus their
 * status types and role types) onto OpenRegister, from
 * lib/Settings/bezwaar_seed_data.json. Mirrors the SeedBezwaarBeroepData repair
 * step but runs in a normal CLI context where OpenRegister is reachable —
 * repair steps run only on install/upgrade and silently skip when OpenRegister
 * is not resolvable in their session-less context, leaving existing instances
 * unseeded. Safe to re-run: case types whose identifier already exists are
 * skipped.
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

use OCA\Dossiq\Service\SeedDataService;
use OCP\IGroupManager;
use OCP\IUserSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Seed the Bezwaar & Beroep case types, status types and role types.
 *
 * @spec openspec/specs/bezwaar-beroep-workflow/spec.md#requirement-req-bbw-001-bezwaar-casetype-seed-shall-be-installed-with-awb-compliant-process-configuration
 */
class SeedBezwaarBeroepCommand extends Command {
	/**
	 * Wire the command against the seed data service and user/group managers.
	 *
	 * @param SeedDataService $seedDataService Bezwaar/beroep seeder.
	 * @param IUserSession $userSession Session used to impersonate an admin.
	 * @param IGroupManager $groupManager Resolves an admin to impersonate.
	 */
	public function __construct(
		private readonly SeedDataService $seedDataService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Define command name + description.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bezwaar-beroep-workflow/spec.md#requirement-req-bbw-001-bezwaar-casetype-seed-shall-be-installed-with-awb-compliant-process-configuration
	 */
	protected function configure(): void {
		$this->setName(name: 'dossiq:bezwaar:seed')
			->setDescription('Seed the Bezwaar & Beroep case types, status types and role types (idempotent).');
	}//end configure()

	/**
	 * Execute the seed and report counts.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int Symfony command exit code.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/bezwaar-beroep-workflow/spec.md#requirement-req-bbw-001-bezwaar-casetype-seed-shall-be-installed-with-awb-compliant-process-configuration
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		// OpenRegister enforces RBAC on saveObject against the current user.
		// occ runs with no session ("Anonymous"), which lacks create rights on
		// the Case Type schema, so impersonate an admin for the seed.
		if ($this->userSession->getUser() === null) {
			$admin = $this->resolveAdmin();
			if ($admin === null) {
				$output->writeln('<error>No admin user found to run the seed under.</error>');
				return Command::FAILURE;
			}

			$this->userSession->setUser($admin);
			$output->writeln('<comment>Seeding as admin user "' . $admin->getUID() . '".</comment>');
		}

		try {
			$result = $this->seedDataService->seedBezwaarBeroepData();
		} catch (\Throwable $e) {
			$output->writeln('<error>Bezwaar/beroep seed failed: ' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}

		if (($result['success'] ?? false) === false) {
			$output->writeln('<error>Bezwaar/beroep seed issue: ' . ($result['message'] ?? 'unknown error') . '</error>');
			return Command::FAILURE;
		}

		$output->writeln('<info>dossiq:bezwaar:seed done</info>');
		$output->writeln('  case types   = ' . ($result['caseTypes'] ?? 0));
		$output->writeln('  status types = ' . ($result['statusTypes'] ?? 0));
		$output->writeln('  role types   = ' . ($result['roleTypes'] ?? 0));
		$output->writeln('  workflows    = ' . ($result['workflows'] ?? 0));
		$output->writeln('  skipped      = ' . ($result['skipped'] ?? 0));

		return Command::SUCCESS;
	}//end execute()

	/**
	 * Resolve the first member of the admin group, if any.
	 *
	 * @return \OCP\IUser|null The admin user to impersonate, or null when none exists.
	 */
	private function resolveAdmin(): ?\OCP\IUser {
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
