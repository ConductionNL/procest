<?php

/**
 * Dossiq dossiq:flows:adopt command.
 *
 * The operator act OpenRegister's flow engine asks for. A flow dossiq SHIPS
 * (an `x-openregister-flows` block on a schema) is imported `enabled = false,
 * owner = NULL` by design, and `Flow::canDispatch()` fails closed on the
 * missing owner — which is why a fresh install logs `matched trigger
 * "object.created" but was not dispatched: it has no owner` and nothing runs.
 *
 * A command rather than a repair step, for the reason its two siblings already
 * carry: `FlowService::adopt()` writes the CALLING user's uid and refuses when
 * there is no acting user, and `find()` is organisation-scoped. An upgrade runs
 * as nobody, so the identity has to be named — which is the point, not an
 * obstacle. Adoption is a volunteering; an install cannot volunteer on an
 * administrator's behalf.
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Command;

use OCA\Dossiq\Service\Flow\ShippedFlowAdoption;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Adopt (and optionally enable) the flows dossiq ships.
 *
 * @spec openspec/specs/case-management/spec.md
 */
class AdoptShippedFlowsCommand extends Command {
	/**
	 * Wire the command against the adoption service.
	 *
	 * @param ShippedFlowAdoption $adoption Reports and performs the adoption.
	 * @param IUserManager $userManager Resolves the acting user.
	 */
	public function __construct(
		private readonly ShippedFlowAdoption $adoption,
		private readonly IUserManager $userManager,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Define the command name, description and options.
	 *
	 * `--user` is REQUIRED and has no default, for the same reason
	 * `dossiq:workflows:migrate-to-flows` requires one: the adopted flows run as
	 * that identity from then on, and guessing an owner hands every shipped flow
	 * to whoever the guess landed on.
	 *
	 * `--enable` is a SEPARATE flag because it is a separate decision.
	 * Publishing answers "which graph would run", adoption answers "whose
	 * identity", enabling answers "may it run at all". Collapsing the last two
	 * would make naming an owner a consent to run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'dossiq:flows:adopt')
			->setDescription(
				'Adopt the flows Dossiq ships, so they can dispatch (idempotent). They are imported '
				. 'ownerless and disabled by OpenRegister\'s design, and a flow with no owner is never '
				. 'dispatched. Add --enable to arm their triggers as well. Use --dry-run first.'
			)
			->addOption(
				name: 'user',
				mode: InputOption::VALUE_REQUIRED,
				description: 'UID that becomes the flows\' owner; their runs resolve as this identity.'
			)
			->addOption(
				name: 'enable',
				mode: InputOption::VALUE_NONE,
				description: 'Also enable each adopted flow, which arms its trigger.'
			)
			->addOption(
				name: 'dry-run',
				mode: InputOption::VALUE_NONE,
				description: 'Report what would be adopted, and write nothing.'
			);
	}//end configure()

	/**
	 * Run the adoption and report per-flow outcomes.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int Symfony command exit code.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$uid = (string)($input->getOption('user') ?? '');
		if ($uid === '') {
			$output->writeln('<error>--user is required: adopting a flow makes that user its owner.</error>');
			return Command::INVALID;
		}

		$user = $this->userManager->get($uid);
		if ($user === null) {
			$output->writeln('<error>No such user: ' . $uid . '</error>');
			return Command::INVALID;
		}

		$dryRun = (bool)$input->getOption('dry-run');
		$summary = $this->adoption->adoptAll(
			user: $user,
			enable: (bool)$input->getOption('enable'),
			dryRun: $dryRun
		);

		return $this->report(summary: $summary, dryRun: $dryRun, output: $output);
	}//end execute()

	/**
	 * Print the outcome rows, and decide the exit code from them.
	 *
	 * A failed row exits non-zero. Reporting success over a partial adoption is
	 * how an administrator ends up believing the shipped flows are armed when
	 * one of them still is not.
	 *
	 * @param array{note: string, rows: array<int, array{name: string, uuid: string, outcome: string, detail: string}>} $summary The service summary.
	 * @param bool $dryRun Whether this was a dry run.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int Symfony command exit code.
	 */
	private function report(array $summary, bool $dryRun, OutputInterface $output): int {
		if ($summary['note'] !== '') {
			$output->writeln('<comment>' . $summary['note'] . '</comment>');
			return Command::SUCCESS;
		}

		$prefix = 'dossiq:flows:adopt';
		if ($dryRun === true) {
			$prefix .= ' (dry run — nothing was written)';
		}

		$failed = 0;
		foreach ($summary['rows'] as $row) {
			if ($row['outcome'] === 'failed') {
				$failed++;
			}

			$output->writeln(
				sprintf('  %-9s %s — %s', $row['outcome'], $row['name'], $row['detail'])
			);
		}

		$output->writeln(
			sprintf('%s: %d flow(s), %d failed.', $prefix, count($summary['rows']), $failed)
		);

		if ($failed > 0) {
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}//end report()
}//end class
