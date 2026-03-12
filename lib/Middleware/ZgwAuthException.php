<?php

/**
 * ZGW Authentication Exception
 *
 * Exception thrown when ZGW API authentication or authorization fails.
 *
 * @category Middleware
 * @package  OCA\Procest\Middleware
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Middleware;

/**
 * Exception for ZGW authentication and authorization failures.
 */
class ZgwAuthException extends \Exception
{
    /**
     * The HTTP status code for this auth failure.
     *
     * @var integer
     */
    private int $statusCode;

    /**
     * Constructor.
     *
     * @param string $message    The error message
     * @param int    $statusCode The HTTP status code
     */
    public function __construct(string $message, int $statusCode = 403)
    {
        parent::__construct(message: $message);
        $this->statusCode = $statusCode;
    }

    /**
     * Get the HTTP status code.
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
