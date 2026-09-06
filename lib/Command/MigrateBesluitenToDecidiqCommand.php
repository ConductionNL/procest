<?php

/**
 * `occ dossiq:migrate-besluiten` — move besluiten onto decidiq's Decision.
 *
 * Reports by default and writes only with `--commit`, because it moves records
 * across an app boundary. See {@see \OCA\Dossiq\Service\BesluitMigrationService}
 * for why this is a command rather than a repair step.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/the-besluit-resolves-to-decidiqs-decision/specs/zgw-brc/spec.md#requirement-besluiten-move-onto-decidiq-only-when-asked-req-brc-021
 */

declare(strict_types=1);

namespace OCA\Dossiq\Command;

use OCA\Dossiq\Service\BesluitMigrationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Migrate this app's besluiten onto decidiq's `Decision` schema.
 *
 * @spec openspec/changes/the-besluit-resolves-to-decidiqs-decision/specs/zgw-brc/spec.md#requirement-besluiten-move-onto-decidiq-only-when-asked-req-brc-021
 */
class MigrateBesluitenToDecidiqCommand extends Command {

	/**
	 * Wire the command against the migration service.
	 *
	 * @param BesluitMigrationService $migrationService Besluit → decidiq Decision migrator.
	 */
	public function __construct(
		private readonly BesluitMigrationService $migrationService,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Define command name, description and options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-besluit-resolves-to-decidiqs-decision/specs/zgw-brc/spec.md#requirement-besluiten-move-onto-decidiq-only-when-asked-req-brc-021
	 */
	protected function configure(): void {
		$this->setName(name: 'dossiq:migrate-besluiten')
			->setDescription(
				'Migrate dossiq besluiten onto decidiq\'s Decision schema. Reports only unless --commit is given.'
			)
			->addOption(
				name: 'commit',
				mode: InputOption::VALUE_NONE,
				description: 'Actually write. Without it the command reports what it would do and changes nothing.'
			);
	}//end configure()

	/**
	 * Run the migration and report counts.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int Symfony command exit code.
	 *
	 * @spec openspec/changes/the-besluit-resolves-to-decidiqs-decision/specs/zgw-brc/spec.md#requirement-besluiten-move-onto-decidiq-only-when-asked-req-brc-021
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$commit = ($input->getOption('commit') === true);

		try {
			$summary = $this->migrationService->migrate(commit: $commit);
		} catch (Throwable $e) {
			$output->writeln('<error>Besluit migration failed: ' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}

		if ($summary['status'] !== 'ok') {
			// Not an error. A blocked run is the normal answer on an instance
			// that has nothing to migrate, and it must be distinguishable from
			// a run that migrated zero of zero.
			$output->writeln('<comment>' . $summary['message'] . '</comment>');
			return Command::SUCCESS;
		}

		$this->report(output: $output, summary: $summary, commit: $commit);

		if ($summary['failed'] > 0) {
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}//end execute()

	/**
	 * Print the counts and what they mean for the local schema key.
	 *
	 * @param OutputInterface $output Console output.
	 * @param array{total:int, migrated:int, skipped:int, failed:int, detached:bool} $summary The run.
	 * @param bool $commit Whether the run wrote.
	 *
	 * @return void
	 */
	private function report(OutputInterface $output, array $summary, bool $commit): void {
		$mode = 'dry run, nothing written';
		if ($commit === true) {
			$mode = 'committed';
		}

		$output->writeln('<info>dossiq:migrate-besluiten (' . $mode . ')</info>');
		$output->writeln('  besluiten found = ' . $summary['total']);
		$output->writeln('  to migrate      = ' . $summary['migrated']);
		$output->writeln('  already there   = ' . $summary['skipped']);
		$output->writeln('  failed          = ' . $summary['failed']);

		if ($commit === false) {
			if ($summary['migrated'] > 0) {
				$output->writeln('  Re-run with <info>--commit</info> to write.');
			}

			return;
		}

		if ($summary['detached'] === true) {
			$output->writeln('  <info>`decision_schema` removed; besluiten now resolve to decidiq.</info>');
			return;
		}

		$output->writeln(
			'  <comment>`decision_schema` kept: not every besluit is across yet, '
			. 'and detaching now would 404 the ones left behind.</comment>'
		);
	}//end report()
}//end class
