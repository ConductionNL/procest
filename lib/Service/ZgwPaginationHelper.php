<?php

/**
 * Procest ZGW Pagination Helper
 *
 * Wraps OpenRegister's pagination into ZGW HAL-style format.
 *
 * @category Service
 * @package  OCA\Procest\Service
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

namespace OCA\Procest\Service;

/**
 * ZGW pagination helper
 *
 * Wraps standard pagination results into the ZGW HAL-style format:
 * { "count": N, "next": url|null, "previous": url|null, "results": [...] }
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @psalm-suppress UnusedClass
 */
class ZgwPaginationHelper
{
    /**
     * Wrap paginated results in ZGW format.
     *
     * @param array  $mappedObjects The mapped objects for the current page
     * @param int    $totalCount    The total number of matching objects
     * @param int    $page          The current page number (1-based)
     * @param int    $pageSize      The page size
     * @param string $baseUrl       The base URL for pagination links
     * @param array  $queryParams   The original query parameters
     *
     * @return array ZGW-formatted paginated response
     */
    public function wrapResults(
        array $mappedObjects,
        int $totalCount,
        int $page,
        int $pageSize,
        string $baseUrl,
        array $queryParams
    ): array {
        $totalPages = 1;
        if ($pageSize > 0) {
            $totalPages = (int) ceil($totalCount / $pageSize);
        }

        // Remove pagination and framework params from query string.
        $filteredParams = array_diff_key(
            $queryParams,
            [
                'page'     => 1,
                '_page'    => 1,
                '_route'   => 1,
                'zgwApi'   => 1,
                'resource' => 1,
                'uuid'     => 1,
            ]
        );
        $queryString    = http_build_query(data: $filteredParams);

        $separator = '?';
        if ($queryString !== '') {
            $separator = '?' . $queryString . '&';
        }

        $next     = null;
        $previous = null;

        if ($page < $totalPages) {
            $next = $baseUrl . $separator . 'page=' . ($page + 1);
        }

        if ($page > 1) {
            $previous = $baseUrl . $separator . 'page=' . ($page - 1);
        }

        return [
            'count'    => $totalCount,
            'next'     => $next,
            'previous' => $previous,
            'results'  => $mappedObjects,
        ];
    }
}
