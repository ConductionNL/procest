<?php

/**
 * Dossiq Range Stream Response
 *
 * A Nextcloud AppFramework Response that serves file content with HTTP Range
 * support, used by the ZGW DRC-compatible download endpoint for resumable
 * transfers of large documents. When the request carries a satisfiable
 * `Range: bytes=start-end` header the response status is 206 Partial Content
 * with a `Content-Range` header and only the requested byte slice in the body;
 * otherwise the full content is returned with `Accept-Ranges: bytes`.
 *
 * @category Http
 * @package  OCA\Dossiq\Http
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Http;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;

/**
 * Range-aware download response.
 *
 * @template-extends Response<200|206|404|416, array<string, mixed>>
 *
 * @psalm-suppress InvalidTemplateParam
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
 */
class RangeStreamResponse extends Response {

	/**
	 * The (possibly sliced) body to emit.
	 *
	 * @var string
	 */
	private string $body;

	/**
	 * Constructor.
	 *
	 * @param string $content The full file content.
	 * @param string $fileName The download filename.
	 * @param string $contentType The MIME type.
	 * @param string $rangeHeader The raw `Range` request header (may be empty).
	 */
	public function __construct(string $content, string $fileName, string $contentType, string $rangeHeader = '') {
		parent::__construct();

		$total = strlen($content);
		$this->addHeader(name: 'Accept-Ranges', value: 'bytes');
		$this->addHeader(name: 'Content-Type', value: $contentType);
		$this->addHeader(name: 'Content-Disposition', value: 'attachment; filename="' . rawurlencode($fileName) . '"');

		$range = $this->parseRange(rangeHeader: $rangeHeader, total: $total);

		if ($range === null) {
			$this->body = $content;
			$this->addHeader(name: 'Content-Length', value: (string)$total);
			$this->setStatus(status: Http::STATUS_OK);
			return;
		}

		[$start, $end] = $range;
		$length = (($end - $start) + 1);
		$this->body = substr($content, $start, $length);
		$this->addHeader(name: 'Content-Range', value: 'bytes ' . $start . '-' . $end . '/' . $total);
		$this->addHeader(name: 'Content-Length', value: (string)$length);
		$this->setStatus(status: Http::STATUS_PARTIAL_CONTENT);
	}//end __construct()

	/**
	 * Render the response body.
	 *
	 * @return string The (possibly sliced) content.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
	 */
	public function render(): string {
		return $this->body;
	}//end render()

	/**
	 * Parse a single-range `Range: bytes=start-end` header.
	 *
	 * Returns null when no range is requested or the range is unsatisfiable
	 * (caller then serves the full body with status 200).
	 *
	 * @param string $rangeHeader The raw Range header.
	 * @param int $total The total content length.
	 *
	 * @return array{0:int,1:int}|null The clamped [start, end] pair, or null.
	 */
	private function parseRange(string $rangeHeader, int $total): ?array {
		if ($total === 0) {
			return null;
		}

		$parts = $this->matchRangeHeader(rangeHeader: $rangeHeader);
		if ($parts === null) {
			return null;
		}

		[$startRaw, $endRaw] = $parts;

		$bounds = $this->resolveBounds(startRaw: $startRaw, endRaw: $endRaw, total: $total);
		if ($bounds === null) {
			return null;
		}

		[$start, $end] = $bounds;

		if ($start > $end || $start >= $total) {
			return null;
		}

		$end = min($end, ($total - 1));

		return [$start, $end];
	}//end parseRange()

	/**
	 * Match the raw `Range` header against the single-range byte syntax.
	 *
	 * Returns null when no range is requested, the header does not match the
	 * `bytes=start-end` grammar, or both bounds are absent (`bytes=-`).
	 *
	 * @param string $rangeHeader The raw Range header.
	 *
	 * @return array{0:string,1:string}|null The raw [start, end] capture pair, or null.
	 */
	private function matchRangeHeader(string $rangeHeader): ?array {
		if ($rangeHeader === '') {
			return null;
		}

		if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($rangeHeader), $matches) !== 1) {
			return null;
		}

		$startRaw = $matches[1];
		$endRaw = $matches[2];

		if ($startRaw === '' && $endRaw === '') {
			return null;
		}

		return [$startRaw, $endRaw];
	}//end matchRangeHeader()

	/**
	 * Resolve the raw capture pair into unclamped [start, end] byte offsets.
	 *
	 * An absent end means "until EOF"; an absent start makes it a suffix range
	 * ("the last N bytes"), which is unsatisfiable when N is not positive.
	 *
	 * @param string $startRaw The raw start capture (may be empty).
	 * @param string $endRaw The raw end capture (may be empty).
	 * @param int $total The total content length.
	 *
	 * @return array{0:int,1:int}|null The [start, end] pair, or null when unsatisfiable.
	 */
	private function resolveBounds(string $startRaw, string $endRaw, int $total): ?array {
		if ($startRaw === '') {
			// Suffix range: last N bytes.
			$suffix = (int)$endRaw;
			if ($suffix <= 0) {
				return null;
			}

			return [max(0, ($total - $suffix)), ($total - 1)];
		}

		// Explicit "bytes=start-[end]" range; an absent end means "until EOF".
		$end = ($total - 1);
		if ($endRaw !== '') {
			$end = (int)$endRaw;
		}

		return [(int)$startRaw, $end];
	}//end resolveBounds()
}//end class
