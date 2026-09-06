<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Service
 * @package   OCA\Dossiq\Service\Support
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

use OCA\Dossiq\AppInfo\Application;
use Throwable;

/**
 * The machinery every "project this onto an OpenRegister flow" migration needs.
 *
 * Dossiq had three of them (automatic actions, workflow definitions,
 * endorsement routes) and they were converging on the same 120 lines: resolve
 * FlowService without hard-depending on it, index the already-projected flows
 * by provenance marker, write one without letting a single failure abort the
 * rest. Copying that a third time is how the copies drift.
 *
 * Measured 2026-09-03: ONE class uses this trait, WorkflowTemplateFlowMigrator.
 * The endorsement-route migrator went with the parafering runtime
 * (proposals-are-cases). AutomaticActionFlowMigrator still carries its own
 * copy of the machinery and never adopted this, which is the drift the trait
 * was extracted to stop. Adopting it there is the open work; inlining the
 * trait back into its one consumer would remove the seam that makes adopting
 * it cheap.
 *
 * The consuming class supplies MARKER_PREFIX, a logger and a container.
 *
 * @spec openspec/changes/workflow-definitions-to-flow/specs/workflow-definitions-to-flow/spec.md
 */
trait ProjectsOntoFlows {

	/**
	 * How many flows to read per page when indexing by marker.
	 *
	 * @var integer
	 */
	/**
	 * OpenRegister's flow service, resolved by name.
	 *
	 * Named as a constant because getting it wrong is silent: the container
	 * throws, the migration reports "OpenRegister exposes no FlowService" and
	 * exits 0, which reads as "nothing to do" rather than "I looked in the
	 * wrong place". Extracting this trait did exactly that by dropping the
	 * `\Flow\` segment, and no unit test caught it, because the container is
	 * a mock that answers to any id.
	 *
	 * @var string
	 */
	private const FLOW_SERVICE = 'OCA\\OpenRegister\\Service\\Flow\\FlowService';

	private const FLOW_PAGE = 100;

	/**
	 * Resolve OpenRegister's FlowService, or null when it is absent.
	 *
	 * Resolved by name rather than type-hinted, so dossiq keeps building and
	 * testing on an instance where OpenRegister is not installed.
	 *
	 * @return object|null The service.
	 */
	private function flowService(): ?object {
		try {
			return $this->container->get(self::FLOW_SERVICE);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Flow projection could not resolve FlowService: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);

			return null;
		}

	}//end flowService()

	/**
	 * Map every already-projected flow by its provenance marker.
	 *
	 * Resolved by marker rather than by name: a name is editable in the flow
	 * editor, and a re-run matching on one would mint a second flow the moment
	 * somebody renamed the first.
	 *
	 * @param object $flowService OpenRegister's FlowService.
	 *
	 * @return array<string, string> Marker to flow uuid.
	 */
	private function existingByMarker(object $flowService): array {
		$map = [];
		$offset = 0;

		while (true) {
			$page = $flowService->findAll(Application::APP_ID, null, null, self::FLOW_PAGE, $offset);
			if (is_array($page) === false || $page === []) {
				return $map;
			}

			foreach ($page as $flow) {
				$notes = (string)($flow->getNotes() ?? '');
				if (str_starts_with($notes, self::MARKER_PREFIX) === true) {
					$map[$notes] = (string)$flow->getUuid();
				}
			}

			if (count($page) < self::FLOW_PAGE) {
				return $map;
			}

			$offset += self::FLOW_PAGE;
		}

	}//end existingByMarker()

	/**
	 * Write one flow, never letting a single failure abort the rest.
	 *
	 * @param object               $flowService OpenRegister's FlowService.
	 * @param array<string, mixed> $document    The flow document.
	 * @param string               $marker      The provenance marker.
	 * @param string|null          $uuid        The existing flow uuid, or null to create.
	 *
	 * @return array{outcome: string, marker: string, detail: string} The outcome row.
	 */
	private function writeFlow(object $flowService, array $document, string $marker, ?string $uuid): array {
		try {
			$flow = $flowService->save($document, $uuid);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: could not project onto a flow',
				['app' => Application::APP_ID, 'marker' => $marker, 'exception' => $e->getMessage()]
			);

			return ['outcome' => 'failed', 'marker' => $marker, 'detail' => $e->getMessage()];
		}

		return [
			'outcome' => $this->outcomeFor(uuid: $uuid),
			'marker' => $marker,
			'detail' => ('flow ' . (string)$flow->getUuid()),
		];

	}//end writeFlow()

	/**
	 * Whether writing against this uuid counts as a create or an update.
	 *
	 * @param string|null $uuid The existing flow uuid, or null.
	 *
	 * @return string Either `created` or `updated`.
	 */
	private function outcomeFor(?string $uuid): string {
		if ($uuid === null) {
			return 'created';
		}

		return 'updated';

	}//end outcomeFor()

	/**
	 * Decode a field the schema stores as a JSON-encoded string.
	 *
	 * `steps` and `transitions` are declared as strings holding JSON, which is
	 * ADR-065's named cost of this model: they are opaque to OpenRegister. Rows
	 * written before that were stored as native arrays, so both are accepted.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return array<int, array<string, mixed>> The decoded list.
	 */
	private function decodeList(mixed $value): array {
		if (is_string($value) === true) {
			$decoded = json_decode($value, true);
			if (is_array($decoded) === false) {
				return [];
			}

			$value = $decoded;
		}

		if (is_array($value) === false) {
			return [];
		}

		$rows = [];
		foreach ($value as $row) {
			if (is_array($row) === true) {
				$rows[] = $row;
			}
		}

		return $rows;

	}//end decodeList()

}//end trait
