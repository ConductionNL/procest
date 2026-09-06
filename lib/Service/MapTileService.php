<?php

/**
 * Dossiq MapTileService
 *
 * Computes the tile-list manifest the PWA service worker pre-downloads for
 * offline-map rendering during field inspections. Each call returns the
 * (z, x, y, format) coordinates for every tile within a bounding box across
 * the requested zoom range, plus the URL template the worker should fetch
 * each tile from (default: PDOK BRT achtergrondkaart WMTS).
 *
 * The service is stateless and deterministic — it does NOT make HTTP calls.
 * The Service Worker is responsible for actually downloading and caching the
 * tiles; this service just enumerates them so the client knows what to
 * fetch ahead of time and can estimate the data volume.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#Task-6
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use InvalidArgumentException;

/**
 * Stateless tile-list manifest builder for offline PWA pre-caching.
 *
 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#Task-6
 */
class MapTileService {
	/**
	 * Default PDOK BRT achtergrondkaart WMTS tile-URL template (WGS84-Web-Mercator
	 * grid). Variables: {z}/{x}/{y}.
	 */
	public const PDOK_BRT_TEMPLATE = 'https://service.pdok.nl/brt/achtergrondkaart/wmts/v2_0/standaard/EPSG:3857/{z}/{x}/{y}.png';

	/**
	 * Maximum zoom level allowed in a single manifest. Inspectors typically need
	 * 10 (city scale) to 18 (street scale); the spec asks for 10-18 by default.
	 */
	public const MAX_ZOOM = 18;

	/**
	 * Maximum number of tiles a single manifest may emit. Guard against
	 * accidental whole-Netherlands requests at z=18 (would be ~1.4 billion
	 * tiles); the limit forces callers to narrow their bbox or zoom range.
	 */
	public const MAX_TILES = 50000;

	/**
	 * Estimate-only average tile size in KiB (used for download-size warnings).
	 */
	public const AVG_TILE_SIZE_KIB = 24;

	/**
	 * Build a tile manifest covering a bounding box at the given zoom levels.
	 *
	 * @param array{minLat: float, minLon: float, maxLat: float, maxLon: float} $bbox Geographic bbox.
	 * @param array<int, int> $zoomLevels Zoom levels to cover.
	 * @param string|null $template Optional URL template (default: PDOK BRT).
	 *
	 * @return array{tiles: array<int, array{z: int, x: int, y: int, url: string}>,
	 *               total: int,
	 *               estimatedSizeKiB: int,
	 *               estimatedSizeBytes: int,
	 *               template: string}
	 *
	 * @throws \InvalidArgumentException When bbox or zoom is invalid.
	 *
	 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#Task-6
	 */
	public function buildManifest(array $bbox, array $zoomLevels, ?string $template = null): array {
		$this->assertBbox(bbox: $bbox);
		$this->assertZoomLevels(zoomLevels: $zoomLevels);

		$resolvedTemplate = $template ?? self::PDOK_BRT_TEMPLATE;

		$tiles = [];
		foreach ($zoomLevels as $z) {
			foreach ($this->tilesForZoom(bbox: $bbox, zoom: (int)$z) as $tile) {
				$tile['url'] = $this->urlFor(
					template: $resolvedTemplate,
					zoom: $tile['z'],
					tileX: $tile['x'],
					tileY: $tile['y']
				);
				$tiles[] = $tile;
				if (count($tiles) > self::MAX_TILES) {
					throw new InvalidArgumentException(
						sprintf(
							'Tile manifest would exceed %d tiles; narrow bbox or zoom range.',
							self::MAX_TILES
						)
					);
				}
			}
		}

		$total = count($tiles);
		$sizeKiB = $total * self::AVG_TILE_SIZE_KIB;

		return [
			'tiles' => $tiles,
			'total' => $total,
			'estimatedSizeKiB' => $sizeKiB,
			'estimatedSizeBytes' => ($sizeKiB * 1024),
			'template' => $resolvedTemplate,
		];
	}//end buildManifest()

	/**
	 * Convenience: compute total tile count without enumerating each one.
	 * Cheaper than buildManifest() when the caller just wants a size estimate
	 * for a "downloading 24MB on 3G will take ~3min" warning.
	 *
	 * @param array{minLat: float, minLon: float, maxLat: float, maxLon: float} $bbox Geographic bbox.
	 * @param array<int, int> $zoomLevels Zoom levels.
	 *
	 * @return array{total: int, estimatedSizeKiB: int}
	 *
	 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#Task-6
	 */
	public function estimate(array $bbox, array $zoomLevels): array {
		$this->assertBbox(bbox: $bbox);
		$this->assertZoomLevels(zoomLevels: $zoomLevels);

		$total = 0;
		foreach ($zoomLevels as $z) {
			[$minX, $maxX, $minY, $maxY] = $this->tileBoundsForZoom(bbox: $bbox, zoom: (int)$z);
			$total += (($maxX - $minX) + 1) * (($maxY - $minY) + 1);
		}

		return [
			'total' => $total,
			'estimatedSizeKiB' => $total * self::AVG_TILE_SIZE_KIB,
		];
	}//end estimate()

	/**
	 * Resolve a (z, x, y) URL from a tile template.
	 *
	 * @param string $template The url template with {z}/{x}/{y}.
	 * @param int $zoom Zoom level.
	 * @param int $tileX Tile x.
	 * @param int $tileY Tile y.
	 *
	 * @return string The resolved URL.
	 *
	 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#Task-6
	 */
	public function urlFor(string $template, int $zoom, int $tileX, int $tileY): string {
		return strtr(
			$template,
			[
				'{z}' => (string)$zoom,
				'{x}' => (string)$tileX,
				'{y}' => (string)$tileY,
			]
		);
	}//end urlFor()

	/**
	 * Enumerate the tiles covering a bbox at a given zoom.
	 *
	 * @param array{minLat: float, minLon: float, maxLat: float, maxLon: float} $bbox The bbox.
	 * @param int $zoom Zoom level.
	 *
	 * @return iterable<int, array{z: int, x: int, y: int}>
	 */
	private function tilesForZoom(array $bbox, int $zoom): iterable {
		[$minX, $maxX, $minY, $maxY] = $this->tileBoundsForZoom(bbox: $bbox, zoom: $zoom);
		for ($x = $minX; $x <= $maxX; $x++) {
			for ($y = $minY; $y <= $maxY; $y++) {
				yield ['z' => $zoom, 'x' => $x, 'y' => $y];
			}
		}
	}//end tilesForZoom()

	/**
	 * Return [minX, maxX, minY, maxY] tile bounds for a bbox at a zoom.
	 *
	 * @param array{minLat: float, minLon: float, maxLat: float, maxLon: float} $bbox The bbox.
	 * @param int $zoom Zoom level.
	 *
	 * @return array{0: int, 1: int, 2: int, 3: int}
	 */
	private function tileBoundsForZoom(array $bbox, int $zoom): array {
		$minX = $this->lonToTileX(lon: $bbox['minLon'], zoom: $zoom);
		$maxX = $this->lonToTileX(lon: $bbox['maxLon'], zoom: $zoom);
		// Tile Y axis is inverted vs. latitude (north = 0). For our maxLat
		// we expect the lower Y, and for minLat the higher Y.
		$minY = $this->latToTileY(lat: $bbox['maxLat'], zoom: $zoom);
		$maxY = $this->latToTileY(lat: $bbox['minLat'], zoom: $zoom);
		if ($minX > $maxX) {
			[$minX, $maxX] = [$maxX, $minX];
		}

		if ($minY > $maxY) {
			[$minY, $maxY] = [$maxY, $minY];
		}

		return [$minX, $maxX, $minY, $maxY];
	}//end tileBoundsForZoom()

	/**
	 * Convert longitude to Web-Mercator tile X.
	 *
	 * @param float $lon Longitude in degrees.
	 * @param int $zoom Zoom level.
	 *
	 * @return int Tile x.
	 */
	private function lonToTileX(float $lon, int $zoom): int {
		$n = (1 << $zoom);
		return (int)floor((($lon + 180.0) / 360.0) * $n);
	}//end lonToTileX()

	/**
	 * Convert latitude to Web-Mercator tile Y.
	 *
	 * @param float $lat Latitude in degrees.
	 * @param int $zoom Zoom level.
	 *
	 * @return int Tile y.
	 */
	private function latToTileY(float $lat, int $zoom): int {
		$n = (1 << $zoom);
		$latRad = deg2rad($lat);
		return (int)floor((1.0 - log(tan($latRad) + (1.0 / cos($latRad))) / M_PI) / 2.0 * $n);
	}//end latToTileY()

	/**
	 * Validate a bounding box.
	 *
	 * @param array<string, mixed> $bbox The bbox.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When bbox is invalid.
	 */
	private function assertBbox(array $bbox): void {
		foreach (['minLat', 'minLon', 'maxLat', 'maxLon'] as $key) {
			if (isset($bbox[$key]) === false || is_numeric($bbox[$key]) === false) {
				throw new InvalidArgumentException('bbox.' . $key . ' is required and numeric');
			}
		}

		if ($bbox['minLat'] < -85.0511 || $bbox['maxLat'] > 85.0511) {
			throw new InvalidArgumentException('latitudes out of Web-Mercator range');
		}

		if ($bbox['minLat'] >= $bbox['maxLat']) {
			throw new InvalidArgumentException('minLat must be < maxLat');
		}

		if ($bbox['minLon'] >= $bbox['maxLon']) {
			throw new InvalidArgumentException('minLon must be < maxLon');
		}
	}//end assertBbox()

	/**
	 * Validate the requested zoom-levels array.
	 *
	 * @param array<int, int> $zoomLevels Zoom levels.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When zoom set is invalid.
	 */
	private function assertZoomLevels(array $zoomLevels): void {
		if (count($zoomLevels) === 0) {
			throw new InvalidArgumentException('zoomLevels must not be empty');
		}

		foreach ($zoomLevels as $z) {
			if ($z < 0 || $z > self::MAX_ZOOM) {
				throw new InvalidArgumentException(
					sprintf('zoom %s out of range [0, %d]', (string)$z, self::MAX_ZOOM)
				);
			}
		}
	}//end assertZoomLevels()
}//end class
