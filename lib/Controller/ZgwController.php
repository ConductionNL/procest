<?php

/**
 * Dossiq ZGW Base Controller
 *
 * Abstract base class for all ZGW API controllers. Serves as the identity
 * marker used by ZgwAuthMiddleware to decide which controllers require JWT
 * validation and scope enforcement.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Support\NormalisesObjectRows;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Abstract base class for all ZGW API controllers.
 *
 * ZgwAuthMiddleware uses `instanceof ZgwController` to identify which
 * controllers fall under ZGW JWT authentication and scope enforcement.
 * Any controller that handles a ZGW API endpoint must extend this class
 * so the middleware's guard is actually exercised.
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
abstract class ZgwController extends Controller {
	use NormalisesObjectRows;

	/**
	 * Build a standardised 403 response for missing ZGW scopes.
	 *
	 * @param string $scope The required scope identifier for the log / detail.
	 *
	 * @return JSONResponse
	 */
	protected function scopeDeniedResponse(string $scope): JSONResponse {
		return new JSONResponse(
			data: [
				'type' => 'PermissionDenied',
				'code' => 'permission_denied',
				'title' => 'Insufficient scope.',
				'status' => Http::STATUS_FORBIDDEN,
				'detail' => 'Scope ' . $scope . ' is required for this operation.',
			],
			statusCode: Http::STATUS_FORBIDDEN
		);
	}//end scopeDeniedResponse()
}//end class
