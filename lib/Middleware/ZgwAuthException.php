<?php

/**
 * ZGW Authentication Exception
 *
 * Exception thrown when ZGW API authentication or authorization fails.
 *
 * @category Middleware
 * @package  OCA\Dossiq\Middleware
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

namespace OCA\Dossiq\Middleware;

/**
 * Exception for ZGW authentication and authorization failures.
 *
 * @spec openspec/specs/zgw-autorisaties-api/spec.md
 */
class ZgwAuthException extends \Exception {

	/**
	 * The HTTP status code for this auth failure.
	 *
	 * @var integer
	 */
	private int $statusCode;

	/**
	 * Constructor.
	 *
	 * @param string $message The error message
	 * @param int $statusCode The HTTP status code
	 */
	public function __construct(string $message, int $statusCode = 403) {
		parent::__construct(message: $message);
		$this->statusCode = $statusCode;
	}//end __construct()

	/**
	 * Get the HTTP status code.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	public function getStatusCode(): int {
		return $this->statusCode;
	}//end getStatusCode()
}//end class
