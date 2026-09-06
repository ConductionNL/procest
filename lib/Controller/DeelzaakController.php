<?php

/**
 * Dossiq Deelzaak (sub-case) Controller
 *
 * Thin REST surface in front of {@see DeelzaakService}. Used by the case
 * detail view (parent + sub-case list) and the case list page (badge counts).
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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
 * @spec openspec/changes/deelzaak-support/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\DeelzaakService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for sub-case operations.
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */
class DeelzaakController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request Inbound request.
	 * @param DeelzaakService $deelzaakService Backend service.
	 * @param IUserSession $userSession Current user session.
	 * @param CaseAccessGuard $caseAccessGuard Per-case authorization (fails closed).
	 */
	public function __construct(
		IRequest $request,
		private readonly DeelzaakService $deelzaakService,
		private readonly IUserSession $userSession,
		private readonly CaseAccessGuard $caseAccessGuard,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List sub-cases of a parent.
	 *
	 * @param string $caseId Parent case UUID.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function list(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['message' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(
			[
				'results' => $this->deelzaakService->listSubCases(parentCaseUuid: $caseId),
			]
		);
	}//end list()

	/**
	 * Return the parent case object.
	 *
	 * @param string $caseId Parent case UUID.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function parent(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// Authorised on the CHILD case the caller names, not on the parent it
		// resolves to: the parent is the answer, so guarding on it would be
		// circular and would still let an unrelated caller walk the hierarchy.
		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['message' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$parent = $this->deelzaakService->getParentCase(childCaseUuid: $caseId);
		if ($parent === null) {
			return new JSONResponse(['message' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($parent);
	}//end parent()

	/**
	 * Batch sub-case counts for a list page.
	 *
	 * Accepts `ids` as a comma-separated query parameter or POST body.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse Map keyed by parent UUID.
	 *
	 * @spec openspec/changes/deelzaak-support/tasks.md#T03
	 */
	public function counts(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$raw = $this->request->getParam('ids', '');
		$ids = [];
		if (is_array($raw) === true) {
			$ids = $raw;
		}

		if (is_array($raw) === false && $raw !== '') {
			$ids = explode(',', (string)$raw);
		}

		$ids = array_values(array_filter(array_map('trim', $ids), static fn ($value): bool => $value !== ''));
		if ($ids === []) {
			return new JSONResponse(['message' => 'ids parameter is required'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['counts' => $this->deelzaakService->getSubCaseCounts(parentUuids: $ids)]);
	}//end counts()

	/**
	 * Pre-flight validate a sub-case creation request.
	 *
	 * Expects JSON body `{ parentCaseUuid, childCaseTypeId }`.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function validate(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$parent = (string)$this->request->getParam('parentCaseUuid', '');
		$child = (string)$this->request->getParam('childCaseTypeId', '');
		if ($parent === '' || $child === '') {
			return new JSONResponse(
				[
					'message' => 'parentCaseUuid and childCaseTypeId are required',
				],
				Http::STATUS_BAD_REQUEST
			);
		}

		// Without this the endpoint is an existence-and-type oracle over every
		// case on the instance: it answers whether an arbitrary parent uuid
		// exists and whether a given child type may hang off it.
		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $parent, user: $user) === false) {
			return new JSONResponse(['message' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$result = $this->deelzaakService->validateCreate(
			parentCaseUuid: $parent,
			childCaseTypeId: $child,
		);

		if ($result['ok'] === false) {
			return new JSONResponse($result, Http::STATUS_CONFLICT);
		}

		return new JSONResponse($result);
	}//end validate()

	/**
	 * Unlink every sub-case from the given parent.
	 *
	 * Used by the "delete parent with children" confirmation flow so the
	 * sub-cases survive deletion as orphans.
	 *
	 * The response now reports `unlinked`, `failed`, `total` and `complete`
	 * rather than a bare count. A partial unlink used to return `200 OK` with a
	 * count that under-reported silently, and the caller went on to delete the
	 * parent — orphaning the remaining children under a dead reference. The
	 * caller MUST check `complete` before deleting the parent (procest#793).
	 *
	 * @param string $caseId Parent case UUID.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 * @spec openspec/specs/deelzaak-support/spec.md
	 */
	public function unlink(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// Mass-detaches every sub-case of the named parent. Mutation access,
		// not read access: this is the most destructive endpoint in the file.
		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['message' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$result = $this->deelzaakService->unlinkSubCases(parentCaseUuid: $caseId);

		// A partial unlink is not a success. 207 keeps the body readable to the
		// existing caller while making the incomplete case distinguishable from
		// a clean one by status code alone.
		$status = Http::STATUS_OK;
		if ($result['complete'] === false) {
			$status = Http::STATUS_MULTI_STATUS;
		}

		return new JSONResponse($result, $status);
	}//end unlink()
}//end class
