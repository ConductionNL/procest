<?php

/**
 * Dossiq Termijn Definitie Controller
 *
 * The admin settings surface for statutory term definitions (AWB 4:13/4:14).
 *
 * Closes the `/api/termijn/definities` half of procest#794. `TermijnDefinitiesTab.vue`
 * has always called this collection; `appinfo/routes.php` declared
 * `/api/termijn/instances*` and nothing else, so every request was answered by
 * Nextcloud's own HTML page under HTTP 200. The tab uses the correct
 * `Array.isArray(x) ? x : (x?.results || [])` guard, which discarded the HTML
 * and left an empty table with no error — the guard is right, the route was
 * missing.
 *
 * Versioning is the caller's contract, per REQ-TERM-ADMIN-001: editing a
 * definition closes the prior version (`validUntil = today`) and creates a new
 * one (`validFrom = today + 1`), so cases in flight keep the definition they
 * started under. This controller persists what it is given and does not
 * re-derive those dates; `TermijnService::getTermijnDefinitie()` is what reads
 * them back, and it already selects the version active on a given day.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/termijn-verification-admin/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Support\ConfiguredRegistryService;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Admin CRUD for TermijnDefinities.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/termijn-verification-admin/spec.md
 */
class TermijnDefinitieController extends Controller {

	/**
	 * Config key naming the TermijnDefinitie schema.
	 *
	 * @var string
	 */
	private const SCHEMA_DEFINITIE = 'termijn_definitie_schema';

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param ConfiguredRegistryService $registry Generic configured-schema registry CRUD.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/specs/termijn-verification-admin/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ConfiguredRegistryService $registry,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List every TermijnDefinitie, all versions.
	 *
	 * The admin tab shows the full version history and decides for itself which
	 * rows are currently in force, so this deliberately does not filter by
	 * validity — unlike `TermijnService::getTermijnDefinitie()`, which resolves
	 * the one version active on a given day.
	 *
	 * @return JSONResponse The definitions as a JSON array.
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/specs/termijn-verification-admin/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function index(): JSONResponse {
		return new JSONResponse(
			data: $this->registry->list(schemaConfigKey: self::SCHEMA_DEFINITIE),
			statusCode: Http::STATUS_OK
		);
	}//end index()

	/**
	 * Create a TermijnDefinitie version.
	 *
	 * @return JSONResponse The created definition.
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/specs/termijn-verification-admin/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function create(): JSONResponse {
		return $this->persist(id: null, statusCode: Http::STATUS_CREATED);
	}//end create()

	/**
	 * Update a TermijnDefinitie — in practice, closing a prior version.
	 *
	 * @param string $id The definition id.
	 *
	 * @return JSONResponse The updated definition.
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/specs/termijn-verification-admin/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function update(string $id): JSONResponse {
		return $this->persist(id: $id, statusCode: Http::STATUS_OK);
	}//end update()

	/**
	 * Save a definition from the request body.
	 *
	 * @param string|null $id Existing id, or null to create.
	 * @param int $statusCode Status to return on success.
	 *
	 * @return JSONResponse The saved definition, or an error.
	 *
	 * @spec openspec/specs/termijn-verification-admin/spec.md
	 */
	private function persist(?string $id, int $statusCode): JSONResponse {
		$data = $this->request->getParams();
		unset($data['_route'], $data['id'], $data['uuid'], $data['@self']);

		try {
			$saved = $this->registry->save(
				schemaConfigKey: self::SCHEMA_DEFINITIE,
				data: $data,
				id: $id
			);
			return new JSONResponse(data: $saved, statusCode: $statusCode);
		} catch (RuntimeException $e) {
			return new JSONResponse(
				['message' => $e->getMessage()],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Failed to save TermijnDefinitie: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return new JSONResponse(
				['message' => 'Failed to save term definition: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end persist()
}//end class
