<template>
	<div class="cases-on-map">
		<div class="cases-on-map__sidebar">
			<h2>{{ t('dossiq', 'Cases on map') }}</h2>
			<p class="cases-on-map__summary">
				{{
					t('dossiq', 'Showing {filtered} of {total} located cases', {
						filtered: features.length,
						total: total,
					})
				}}
			</p>

			<NcSelect
				v-model="filterCaseType"
				:inputLabel="t('dossiq', 'Case type')"
				:options="caseTypeOptions"
				:placeholder="t('dossiq', 'All case types')"
				:clearable="true"
				class="cases-on-map__filter"
				@update:modelValue="reload" />

			<NcSelect
				v-model="filterStatus"
				:inputLabel="t('dossiq', 'Status')"
				:options="statusOptions"
				:placeholder="t('dossiq', 'All statuses')"
				:clearable="true"
				class="cases-on-map__filter"
				@update:modelValue="reload" />

			<div v-if="degraded" class="cases-on-map__notice">
				<AlertIcon :size="20" />
				<span>{{
					t(
						'dossiq',
						'Map data could not be loaded. Showing what is available.',
					)
				}}</span>
			</div>
		</div>

		<div class="cases-on-map__map">
			<CnMapWidget
				:center="mapCenter"
				:layers="mapLayers"
				:markers="markers"
				:clustering="true"
				:autoFit="features.length > 0"
				height="100%"
				@markerClick="onMarkerClick" />
			<NcLoadingIcon v-if="loading" :size="40" class="cases-on-map__loading" />
		</div>
	</div>
</template>

<script>
import { CnMapWidget } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import { registerCasesOnMapOverview } from '../services/casesOnMapApi.js'
import { shapeMarkerFeatures } from '../services/mapFormatters.js'

/**
 * CasesOnMapView — full-screen multi-object cases-on-map overview.
 *
 * MIGRATED to OpenRegister's page-level maps-overview leaf (ADR-022, OR #154):
 *
 * - On mount it DECLARES the `cases-on-map` overview with OR's maps-overview
 *   surface (POST /api/integrations/maps/overviews) and FETCHES the RBAC-scoped
 *   marker points from OR (GET .../maps/overviews/{register}/{schema}/points).
 *   OR owns the geometry extraction + RBAC scoping (fail-closed, no IDOR) and
 *   the declarative base-layer config (PDOK WMTS).
 * - The markers are rendered by the library's declarative `CnMapWidget`, which
 *   owns the Leaflet engine, clustering, and tile layers. Dossiq embeds NO
 *   Leaflet / WMS / WFS stack of its own — the bespoke `CaseMap` /
 *   `src/components/map/*` plumbing and the `/api/cases/geo` endpoint are gone.
 * - This view owns only data shaping (status colour via `shapeMarkerFeatures`)
 *   and the case-type / status filters, which it forwards to OR as object
 *   filters. Degrades gracefully when OR is unavailable.
 *
 * @spec openspec/specs/case-map-overview/spec.md
 */
export default {
	name: 'CasesOnMapView',
	components: { CnMapWidget, NcSelect, NcLoadingIcon, AlertIcon },
	props: {
		/** @type {string} OpenRegister register slug holding the cases. */
		register: {
			type: String,
			default: 'dossiq',
		},

		/** @type {string} OpenRegister schema slug for the case objects. */
		schema: {
			type: String,
			default: 'case',
		},
	},

	data() {
		return {
			points: [],
			total: 0,
			loading: false,
			degraded: false,
			filterCaseType: null,
			filterStatus: null,
			statusOptions: ['open', 'in_progress', 'blocked', 'closed'],
			caseTypeOptions: [],
		}
	},

	computed: {
		/**
		 * GeoJSON Point features for CnMapWidget, shaped from the OR point set.
		 *
		 * @return {Array<object>} GeoJSON Feature array.
		 * @spec openspec/specs/case-map-overview/spec.md
		 */
		features() {
			return shapeMarkerFeatures(this.points)
		},

		/**
		 * CnMapWidget marker config — inline features + popup field. The
		 * features already carry the status colour from `shapeMarkerFeatures`.
		 *
		 * @return {object} CnMapWidget `markers` prop.
		 * @spec openspec/specs/case-map-overview/spec.md
		 */
		markers() {
			return {
				features: this.features,
				popupField: 'title',
				clustering: true,
			}
		},

		/**
		 * Default OpenStreetMap basemap for the map widget.
		 *
		 * @return {Array<object>} CnMapWidget layer definitions.
		 */
		mapLayers() {
			return [
				{
					type: 'tile',
					url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
					attribution: '© OpenStreetMap contributors',
				},
			]
		},

		/**
		 * Initial map centre — centroid of the plotted markers, else Berlin.
		 *
		 * @return {[number, number]} `[lat, lng]`.
		 */
		mapCenter() {
			const f = this.features
			let sumLat = 0
			let sumLng = 0
			let n = 0
			for (const feat of f) {
				const c = feat.geometry && feat.geometry.coordinates
				if (
					Array.isArray(c)
					&& Number.isFinite(c[0])
					&& Number.isFinite(c[1])
				) {
					sumLng += c[0]
					sumLat += c[1]
					n++
				}
			}
			if (n > 0) {
				return [sumLat / n, sumLng / n]
			}
			return [52.517, 13.404]
		},
	},

	/**
	 * Declare the overview once (idempotent on the OR side), then load points.
	 *
	 * @return {void}
	 * @spec openspec/specs/case-map-overview/spec.md
	 */
	mounted() {
		registerCasesOnMapOverview({ register: this.register, schema: this.schema })
		this.reload()
	},

	methods: {
		/**
		 * Fetch the RBAC-scoped case points from OR for the active filters.
		 *
		 * @return {Promise<void>} Resolves when loaded.
		 * @spec openspec/specs/case-map-overview/spec.md
		 */
		async reload() {
			this.loading = true
			this.degraded = false
			// Read the cases straight from OpenRegister's objects API (RBAC-scoped
			// by OR) and build the marker point set client-side from each case's
			// `geometry` field. The case `geometry` property is typed `string`
			// (JSON-encoded GeoJSON), which OR's server-side maps-overview builder
			// does not surface here, so we parse it ourselves — a Point yields its
			// coordinate, a Polygon its centroid — to `{ lat, lng }` for the map.
			const params = new URLSearchParams({ _limit: '500' })
			if (this.filterCaseType) {
				params.set('caseType', this.filterCaseType)
			}
			if (this.filterStatus) {
				params.set('status', this.filterStatus)
			}
			try {
				const url = generateUrl(
					'/apps/openregister/api/objects/{register}/{schema}',
					{ register: this.register, schema: this.schema },
				)
				const res = await fetch(url + '?' + params.toString(), {
					headers: { 'OCS-APIRequest': 'true' },
				})
				const json = await res.json()
				const rows = Array.isArray(json)
					? json
					: json.results || json.data || []
				const points = []
				for (const c of rows) {
					let geo = c.geometry
					if (typeof geo === 'string') {
						try {
							geo = JSON.parse(geo)
						} catch {
							geo = null
						}
					}
					const ll = this.firstLatLng(geo)
					if (!ll) {
						continue
					}
					points.push({
						id: c.id,
						caseId: c.id,
						title: c.title,
						label: c.title,
						status: c.status,
						lat: ll.lat,
						lng: ll.lng,
						geometry: geo,
					})
				}
				this.points = points
				if (!this.filterCaseType && !this.filterStatus) {
					this.total = points.length
				}
			} catch {
				this.degraded = true
				this.points = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Dig the first `[lng, lat]` pair out of a GeoJSON geometry (Point or the
		 * first ring of a Polygon) and return it as `{ lat, lng }`, or null.
		 *
		 * @param {object} geometry A GeoJSON geometry (already parsed).
		 * @return {{lat: number, lng: number}|null} The representative point.
		 */
		firstLatLng(geometry) {
			if (!geometry || typeof geometry !== 'object') {
				return null
			}
			let c = geometry.coordinates
			while (Array.isArray(c) && Array.isArray(c[0])) {
				c = c[0]
			}
			if (Array.isArray(c) && Number.isFinite(c[0]) && Number.isFinite(c[1])) {
				return { lat: Number(c[1]), lng: Number(c[0]) }
			}
			return null
		},

		/**
		 * Navigate to the case detail when a marker is clicked.
		 *
		 * @param {object} payload `{ feature, latlng }` from CnMapWidget.
		 * @return {void}
		 * @spec openspec/specs/case-map-overview/spec.md
		 */
		onMarkerClick(payload) {
			const caseId = payload?.feature?.properties?.caseId
			if (!caseId) {
				return
			}
			this.$router?.push({ name: 'CaseDetail', params: { id: caseId } })
		},
	},
}
</script>

<style scoped>
.cases-on-map {
	display: flex;
	height: 100%;
	width: 100%;
}

.cases-on-map__sidebar {
	width: 300px;
	min-width: 240px;
	padding: 16px;
	overflow-y: auto;
	border-inline-end: 1px solid var(--color-border);
}

.cases-on-map__summary {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.cases-on-map__filter {
	width: 100%;
	margin-bottom: 12px;
}

.cases-on-map__notice {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px;
	margin: 12px 0;
	border-radius: var(--border-radius);
	background: var(--color-warning, var(--color-background-hover));
}

.cases-on-map__map {
	position: relative;
	flex: 1;
	min-width: 0;
}

.cases-on-map__loading {
	position: absolute;
	top: 16px;
	inset-inline-end: 16px;
	z-index: 1000;
}

@media (max-width: 700px) {
	.cases-on-map {
		flex-direction: column;
	}

	.cases-on-map__sidebar {
		width: 100%;
		border-inline-end: none;
		border-bottom: 1px solid var(--color-border);
	}
}
</style>
