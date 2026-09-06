<?php

/**
 * `occ dossiq:migrate-partners` — move ketenpartners onto OR Organisations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Command
 * @package  OCA\Dossiq\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/partners-are-organisations/specs/partner-organisations/spec.md
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Command;

use OCA\Dossiq\Service\PartnerMigrationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Migrate dossiq `partnerOrganization` objects to OR Organisations.
 *
 * @spec openspec/changes/partners-are-organisations/specs/partner-organisations/spec.md
 */
class MigratePartnersCommand extends Command {

	/**
	 * Wire the command against the migration service.
	 *
	 * @param PartnerMigrationService $migrationService Partner → Organisation migrator.
	 */
	public function __construct(
		private readonly PartnerMigrationService $migrationService,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Define command name + description.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/partners-are-organisations/specs/partner-organisations/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'dossiq:migrate-partners')
			->setDescription('Migrate dossiq partnerOrganization objects to OpenRegister Organisations (idempotent).');
	}//end configure()

	/**
	 * Execute the migration and report counts.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int Symfony command exit code.
	 *
	 * @spec openspec/changes/partners-are-organisations/specs/partner-organisations/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The command takes no input;
	 * Symfony's signature requires the parameter regardless.
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$summary = $this->migrationService->migrate();
		} catch (Throwable $e) {
			$output->writeln('<error>Partner migration failed: ' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}

		$output->writeln('<info>dossiq:migrate-partners done</info>');
		$output->writeln('  total    = ' . $summary['total']);
		$output->writeln('  migrated = ' . $summary['migrated']);
		$output->writeln('  skipped  = ' . $summary['skipped']);
		$output->writeln('  failed   = ' . $summary['failed']);

		foreach ($summary['mappings'] as $mapping) {
			$output->writeln('  ' . $mapping['partner'] . ' -> ' . $mapping['organisation']);
		}

		if ($summary['failed'] > 0) {
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}//end execute()
}//end class
