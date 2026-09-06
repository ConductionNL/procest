<?php

/**
 * Dossiq Case Type Contribution Registry
 *
 * Dossiq owns case management. Other apps do not fork it — they REGISTER the
 * kind of work they handle as a case type, and their records become ordinary
 * dossiq cases. pipelinq's `ticket` is the first: a ticket is a case, not a
 * parallel thing that merely resembles one.
 *
 * Discovery follows the fleet's existing cross-app pattern (hydra ADR-046, as
 * proven by portaliq's PortalContributionRegistry): a contributing app ships
 * ONE class at the convention FQCN
 * `OCA\{Namespace}\Dossiq\CaseTypeContributionProvider`, and this registry
 * duck-types it via method_exists(), never instanceof.
 *
 * 🔴 THE CONTRACT IS DELIBERATELY ONE-WAY.
 * The provider must be plain: no dossiq imports, no `implements` clause, no
 * info.xml dependency, no constructor dependencies. That is what lets the
 * contributing app stay installable and inert WITHOUT dossiq. An interface
 * would be tidier to read and would make every contributing app hard-depend on
 * this one, which is the coupling the pattern exists to avoid.
 *
 * A provider that throws, returns the wrong shape, or names a case type with no
 * identifier is SKIPPED with a log line rather than allowed to take the
 * registry down. One app's bad declaration must not cost every other app its
 * case types.
 *
 * @category Contribution
 * @package  OCA\Dossiq\Contribution
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://dossiq.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Contribution;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Collects the case types other installed apps contribute.
 *
 * @spec openspec/specs/case-types/spec.md
 */
class CaseTypeContributionRegistry {

	/**
	 * Convention FQCN a contributing app must expose.
	 *
	 * Resolved through the container rather than `new`, so a provider MAY take
	 * constructor dependencies from its OWN app if it ever needs to — the
	 * server container constructs any autoloadable class by reflection,
	 * whereas a registerServiceAlias only resolves inside the registering
	 * app's container.
	 *
	 * @var string
	 */
	private const PROVIDER_CLASS = 'OCA\\%s\\Dossiq\\CaseTypeContributionProvider';

	/**
	 * The method a provider must expose to be considered one.
	 *
	 * @var string
	 */
	private const PROVIDER_METHOD = 'getCaseTypes';

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager Installed-app enumeration.
	 * @param ContainerInterface $container  Server container, used to construct providers.
	 * @param LoggerInterface    $logger     PSR logger.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve one app's provider, or null when it does not contribute.
	 *
	 * @param string $appId The app id.
	 *
	 * @return object|null The provider instance, or null.
	 */
	private function resolveProvider(string $appId): ?object {
		$candidate = sprintf(self::PROVIDER_CLASS, ucfirst($appId));
		if (class_exists($candidate) === false) {
			return null;
		}

		try {
			$instance = $this->container->get($candidate);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq: case-type provider not resolvable',
				[
					'app' => $appId,
					'reason' => $e->getMessage(),
				]
			);

			return null;
		}

		if (is_object($instance) === false) {
			return null;
		}

		if (method_exists($instance, self::PROVIDER_METHOD) === false) {
			return null;
		}

		return $instance;
	}//end resolveProvider()

	/**
	 * Normalise one declared case type, or reject it.
	 *
	 * `identifier` and `title` are the minimum: without an identifier the type
	 * cannot be referenced by a case, and without a title it cannot be shown.
	 * Everything else is optional and passed through, so a contributing app can
	 * carry richer definitions without this registry being taught each field.
	 *
	 * @param mixed  $declared The declaration as returned by the provider.
	 * @param string $appId    The contributing app, stamped onto the result.
	 *
	 * @return array<string,mixed>|null The normalised case type, or null when unusable.
	 */
	private function normalise(mixed $declared, string $appId): ?array {
		if (is_array($declared) === false) {
			return null;
		}

		$identifier = trim((string)($declared['identifier'] ?? ''));
		$title = trim((string)($declared['title'] ?? ''));
		if ($identifier === '' || $title === '') {
			return null;
		}

		$declared['identifier'] = $identifier;
		$declared['title'] = $title;

		// Stamped, not declarable: a provider naming a DIFFERENT app as the
		// contributor would make an unowned case type look owned.
		$declared['contributedBy'] = $appId;

		return $declared;
	}//end normalise()

	/**
	 * Every case type contributed by an installed app.
	 *
	 * @return array<int,array<string,mixed>> The contributed case types.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function all(): array {
		$out = [];

		foreach ($this->appManager->getInstalledApps() as $appId) {
			$appId = (string)$appId;
			$provider = $this->resolveProvider(appId: $appId);
			if ($provider === null) {
				continue;
			}

			try {
				$declared = $provider->getCaseTypes();
			} catch (Throwable $e) {
				$this->logger->error(
					'Dossiq: case-type provider failed',
					[
						'app' => $appId,
						'reason' => $e->getMessage(),
					]
				);
				continue;
			}

			if (is_array($declared) === false) {
				continue;
			}

			foreach ($declared as $caseType) {
				$normalised = $this->normalise(declared: $caseType, appId: $appId);
				if ($normalised === null) {
					$this->logger->warning(
						'Dossiq: skipping a case type with no identifier or title',
						['app' => $appId]
					);
					continue;
				}

				$out[] = $normalised;
			}
		}//end foreach

		return $out;
	}//end all()

	/**
	 * One contributed case type by identifier, or null.
	 *
	 * @param string $identifier The case-type identifier.
	 *
	 * @return array<string,mixed>|null The case type, or null when not contributed.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function find(string $identifier): ?array {
		foreach ($this->all() as $caseType) {
			if ($caseType['identifier'] === $identifier) {
				return $caseType;
			}
		}

		return null;
	}//end find()
}//end class
