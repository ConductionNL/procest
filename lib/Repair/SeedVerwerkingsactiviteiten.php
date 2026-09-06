<?php

/**
 * Dossiq Verwerkingsactiviteiten Catalogue Seed Repair Step
 *
 * Seeds dossiq's zaakgericht-werken processing-activity catalogue
 * (lib/Settings/verwerkingsactiviteiten.json) into OpenRegister's
 * AVG verwerkingsregister as drafts (status `concept`) for FG review.
 *
 * Upsert-by-code semantics: a missing activity is inserted as a draft;
 * an existing activity has its descriptive fields refreshed but its
 * lifecycle `status` is NEVER touched, so an FG activation (published)
 * or archival in OpenRegister is preserved across dossiq upgrades.
 *
 * Dossiq ships no processing-log, retention, export, or steward
 * machinery of its own — those are OpenRegister's (OR-PA-1..9); this
 * step only contributes the domain catalogue content. Note: OR has no
 * declarative activity import from register annotations yet, so this
 * repair step is the seed path until OR-PA-2's annotation-driven
 * seeding ships upstream.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/avg-verwerkingenlogging/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapper;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Seeds the dossiq verwerkingsactiviteiten catalogue into OpenRegister (draft, upsert-by-code).
 *
 * @spec openspec/specs/avg-verwerkingenlogging/spec.md
 */
class SeedVerwerkingsactiviteiten implements IRepairStep {
	/**
	 * Path of the catalogue JSON, relative to this file.
	 *
	 * @var string
	 */
	private const CATALOGUE_PATH = __DIR__ . '/../Settings/verwerkingsactiviteiten.json';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService OpenRegister availability check.
	 * @param ContainerInterface $container DI container (lazy OR mapper resolution).
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private SettingsService $settingsService,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/avg-verwerkingenlogging/spec.md
	 */
	public function getName(): string {
		return 'Seed dossiq verwerkingsactiviteiten catalogue into OpenRegister (draft, upsert-by-code)';
	}//end getName()

	/**
	 * Seed the catalogue.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/avg-verwerkingenlogging/spec.md
	 */
	public function run(IOutput $output): void {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->warning('OpenRegister is not installed or enabled. Skipping verwerkingsactiviteiten seed.');
			$this->logger->warning('Dossiq: OpenRegister not available, skipping verwerkingsactiviteiten seed');
			return;
		}

		$activities = $this->loadCatalogue();
		if ($activities === []) {
			$output->warning('Dossiq verwerkingsactiviteiten catalogue is empty or unreadable; nothing seeded.');
			return;
		}

		try {
			$mapper = $this->container->get(VerwerkingsactiviteitMapper::class);
		} catch (\Throwable $e) {
			// Deployed OR predates the verwerkingsregister (< 0.2.16): skip
			// gracefully — the seed re-runs on the next upgrade.
			$output->warning('OpenRegister verwerkingsregister not available (OR < 0.2.16?); skipping seed.');
			$this->logger->warning(
				'Dossiq: VerwerkingsactiviteitMapper unavailable, skipping catalogue seed',
				['exception' => $e->getMessage()]
			);
			return;
		}

		$created = 0;
		$updated = 0;
		$refused = [];
		foreach ($activities as $definition) {
			$code = (string)($definition['code'] ?? '');
			if ($code === '') {
				continue;
			}

			try {
				$existing = $mapper->findByCode(code: $code);
				if ($existing === null) {
					$entity = new Verwerkingsactiviteit();
					$entity->setCode($code);
					$this->hydrate(entity: $entity, definition: $definition);
					// Draft for FG review: OR defaults blank status to `concept`.
					$mapper->insert(entity: $entity);
					$created++;
					continue;
				}

				// Refresh descriptive fields; NEVER touch lifecycle status —
				// FG activation in OpenRegister survives dossiq upgrades.
				$this->hydrate(entity: $existing, definition: $definition);
				$mapper->update(entity: $existing);
				$updated++;
			} catch (\Throwable $e) {
				$refused[] = $code;
				$this->logger->error(
					'Dossiq: failed to seed verwerkingsactiviteit',
					['code' => $code, 'exception' => $e->getMessage()]
				);
			}//end try
		}//end foreach

		// A SEED THAT SEEDED NOTHING MUST NOT PRODUCE SUCCESS-SHAPED OUTPUT.
		// Every row was refused on the Dutch legal-basis spellings and the step
		// still printed "0 created (draft), 0 refreshed" — which reads as an
		// idempotent re-run rather than an empty verwerkingsregister. The
		// refusals are counted and named, so an operator sees that the AVG art.
		// 30 catalogue is absent.
		if ($refused !== []) {
			$output->warning(
				sprintf(
					'Verwerkingsactiviteiten catalogue: %d created (draft), %d refreshed, %d REFUSED (%s). See the log for each refusal.',
					$created,
					$updated,
					count($refused),
					implode(', ', $refused)
				)
			);
			return;
		}

		$output->info(sprintf('Verwerkingsactiviteiten catalogue seeded: %d created (draft), %d refreshed.', $created, $updated));

	}//end run()

	/**
	 * Read and validate the catalogue JSON.
	 *
	 * @return array<int, array<string, mixed>> Activity definitions ([] on failure).
	 */
	private function loadCatalogue(): array {
		$content = file_get_contents(self::CATALOGUE_PATH);
		if ($content === false) {
			return [];
		}

		$decoded = json_decode($content, true);
		if (is_array($decoded) === false || is_array($decoded['activities'] ?? null) === false) {
			return [];
		}

		return array_values(array_filter($decoded['activities'], 'is_array'));
	}//end loadCatalogue()

	/**
	 * Copy the catalogue definition's descriptive fields onto the entity.
	 *
	 * Lifecycle `status` and identity (`uuid`) are intentionally NOT set
	 * here — status is FG-owned in OpenRegister after the initial insert.
	 *
	 * @param object $entity OR Verwerkingsactiviteit entity.
	 * @param array<string, mixed> $definition Catalogue definition.
	 *
	 * @return void
	 */
	private function hydrate(object $entity, array $definition): void {
		// OpenRegister renamed the entity's Dutch columns to English (naam ->
		// name, beschrijving -> description, ...). QBMapper entities implement
		// setters via __call over their DECLARED properties, so calling the
		// old setter throws "naam is not a valid attribute" — which is exactly
		// how all 7 catalogue rows failed on every fresh install. Each field
		// therefore lists its candidate entity properties, newest first, and
		// the one the deployed entity actually declares wins.
		$stringFields = [
			'name' => ['name', 'naam'],
			'beschrijving' => ['description', 'beschrijving'],
			'doelbinding' => ['purpose', 'doelbinding'],
			'rechtsgrond' => ['legalBasis', 'rechtsgrond'],
			'bewaartermijn' => ['retentionPeriod', 'bewaartermijn'],
		];
		foreach ($stringFields as $field => $candidates) {
			if (isset($definition[$field]) === true && is_string($definition[$field]) === true) {
				$this->setFirstDeclared(entity: $entity, candidates: $candidates, value: $definition[$field]);
			}
		}

		$arrayFields = [
			'categorieenBetrokkenen' => ['dataSubjectCategories', 'categorieenBetrokkenen'],
			'categorieenPersoonsgegevens' => ['personalDataCategories', 'categorieenPersoonsgegevens'],
			'ontvangers' => ['recipients', 'ontvangers'],
		];
		foreach ($arrayFields as $field => $candidates) {
			if (isset($definition[$field]) === true && is_array($definition[$field]) === true) {
				$this->setFirstDeclared(entity: $entity, candidates: $candidates, value: $definition[$field]);
			}
		}

	}//end hydrate()

	/**
	 * Set the first property the deployed entity actually declares.
	 *
	 * `method_exists()` cannot answer this (the setters are magic __call), so
	 * the DECLARED PROPERTY decides. A value none of the candidates fit is
	 * loud: seeding a catalogue row that silently loses its purpose or
	 * retention period would ship an incomplete verwerkingsregister.
	 *
	 * @param object $entity OR Verwerkingsactiviteit entity.
	 * @param array<int, string> $candidates Property names, newest first.
	 * @param string|array<int|string, mixed> $value The value to set.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When no candidate property is declared.
	 */
	private function setFirstDeclared(object $entity, array $candidates, string|array $value): void {
		foreach ($candidates as $property) {
			if (property_exists($entity, $property) === true) {
				$entity->{'set' . ucfirst($property)}($value);
				return;
			}
		}

		throw new RuntimeException(
			'The deployed Verwerkingsactiviteit entity declares none of: ' . implode(', ', $candidates)
		);
	}//end setFirstDeclared()
}//end class
