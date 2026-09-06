<template>
	<div
		class="location-picker-overlay"
		role="button"
		tabindex="0"
		@click.self="$emit('cancel')"
		@keydown.enter.self="$emit('cancel')"
		@keydown.space.self.prevent="$emit('cancel')">
		<div class="location-picker">
			<div class="location-picker__header">
				<h3>{{ t('dossiq', 'Select location') }}</h3>
				<div class="location-picker__tools">
					<NcButton
						:type="mode === 'point' ? 'primary' : 'secondary'"
						@click="setMode('point')">
						{{ t('dossiq', 'Point') }}
					</NcButton>
					<NcButton
						:type="mode === 'polygon' ? 'primary' : 'secondary'"
						@click="setMode('polygon')">
						{{ t('dossiq', 'Draw area') }}
					</NcButton>
					<NcButton @click="useCurrentLocation">
						{{ t('dossiq', 'My location') }}
					</NcButton>
				</div>
			</div>

			<AddressSearch
				class="location-picker__search"
				@select="onAddressSelect" />

			<div ref="mapElement" class="location-picker__map" />

			<div class="location-picker__info">
				<template v-if="selectedGeometry">
					<p v-if="selectedGeometry.type === 'Point'">
						{{ t('dossiq', 'Coordinates') }}:
						{{ selectedGeometry.coordinates[1].toFixed(6) }},
						{{ selectedGeometry.coordinates[0].toFixed(6) }}
					</p>
					<p v-if="selectedGeometry.type === 'Polygon' && area > 0">
						{{ t('dossiq', 'Area') }}: {{ formatArea(area) }}
					</p>
				</template>
				<p v-else class="location-picker__hint">
					{{
						mode === 'point'
							? t('dossiq', 'Click on the map to place a marker')
							: t(
									'dossiq',
									'Click points to draw a polygon, double-click to finish',
								)
					}}
				</p>
			</div>

			<div class="location-picker__actions">
				<NcButton @click="$emit('cancel')">
					{{ t('dossiq', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" :disabled="!selectedGeometry" @click="save">
					{{ t('dossiq', 'Save') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import L from 'leaflet'
import iconRetinaUrl from 'leaflet/dist/images/marker-icon-2x.png'
// Fix Leaflet default icon paths broken by webpack
import iconUrl from 'leaflet/dist/images/marker-icon.png'
import shadowUrl from 'leaflet/dist/images/marker-shadow.png'
import AddressSearch from './AddressSearch.vue'

import 'leaflet/dist/leaflet.css'
import 'leaflet-draw'
import 'leaflet-draw/dist/leaflet.draw.css'

delete L.Icon.Default.prototype._getIconUrl
L.Icon.Default.mergeOptions({ iconUrl, iconRetinaUrl, shadowUrl })

const PDOK_TILES =
	'https://service.pdok.nl/brt/achtergrondkaart/wmts/v2_0/standaard/EPSG:3857/{z}/{x}/{y}.png'
const NL_CENTER = [52.1326, 5.2913]

export default {
	name: 'LocationPicker',
	components: { NcButton, AddressSearch },
	props: {
		/** Existing geometry to pre-populate (GeoJSON object). */
		initialGeometry: {
			type: Object,
			default: null,
		},
	},

	emits: ['save', 'cancel'],
	data() {
		return {
			map: null,
			mode: 'point',
			selectedGeometry: null,
			marker: null,
			drawnLayer: null,
			drawControl: null,
			area: 0,
		}
	},

	mounted() {
		this.initMap()
	},

	/** @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md */
	beforeUnmount() {
		if (this.map) {
			this.map.remove()
		}
	},

	methods: {
		/** @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md */
		initMap() {
			let center = NL_CENTER
			let zoom = 7

			if (this.initialGeometry) {
				this.selectedGeometry = this.initialGeometry
				if (this.initialGeometry.type === 'Point') {
					center = [
						this.initialGeometry.coordinates[1],
						this.initialGeometry.coordinates[0],
					]
					zoom = 16
				}
			}

			this.map = L.map(this.$refs.mapElement, { center, zoom })

			L.tileLayer(PDOK_TILES, {
				attribution:
					'Kaartgegevens &copy; <a href="https://www.kadaster.nl">Kadaster</a>',
				maxZoom: 19,
			}).addTo(this.map)

			// Add existing geometry
			if (this.initialGeometry) {
				if (this.initialGeometry.type === 'Point') {
					this.marker = L.marker(center).addTo(this.map)
				} else {
					const geoLayer = L.geoJSON(this.initialGeometry).addTo(this.map)
					this.drawnLayer = geoLayer
					this.map.fitBounds(geoLayer.getBounds(), { padding: [50, 50] })
				}
			}

			// Point mode: click to place marker
			this.map.on('click', (e) => {
				if (this.mode !== 'point') return
				this.placeMarker(e.latlng)
			})

			this.$nextTick(() => this.map.invalidateSize())
		},

		/**
		 * @param {string} mode The drawing mode the picker is in.
		 * @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md
		 */
		setMode(mode) {
			this.mode = mode

			// Clean up previous state
			if (this.drawControl) {
				this.map.removeControl(this.drawControl)
				this.drawControl = null
			}

			if (mode === 'polygon') {
				// Initialize Leaflet.draw for polygon
				const drawnItems = new L.FeatureGroup()
				this.map.addLayer(drawnItems)

				this.drawControl = new L.Control.Draw({
					draw: {
						polygon: {
							allowIntersection: false,
							showArea: true,
						},

						polyline: false,
						rectangle: false,
						circle: false,
						circlemarker: false,
						marker: false,
					},

					edit: {
						featureGroup: drawnItems,
					},
				})
				this.map.addControl(this.drawControl)

				this.map.on(L.Draw.Event.CREATED, (e) => {
					if (this.drawnLayer) {
						this.map.removeLayer(this.drawnLayer)
					}
					this.drawnLayer = e.layer
					drawnItems.addLayer(e.layer)

					const geojson = e.layer.toGeoJSON()
					this.selectedGeometry = geojson.geometry
					this.area = L.GeometryUtil.geodesicArea(e.layer.getLatLngs()[0])
				})
			}
		},

		/**
		 * @param {object} latlng The latlng.
		 * @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md
		 */
		placeMarker(latlng) {
			if (this.marker) {
				this.marker.setLatLng(latlng)
			} else {
				this.marker = L.marker(latlng).addTo(this.map)
			}
			this.selectedGeometry = {
				type: 'Point',
				coordinates: [latlng.lng, latlng.lat],
			}
		},

		/**
		 * @param {object} root0 The destructured argument object.
		 * @param {Array<number>} root0.coordinates The [lon, lat] pair the picker selected.
		 * @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md
		 */
		onAddressSelect({ coordinates }) {
			if (!coordinates) return
			const latlng = L.latLng(coordinates.lat, coordinates.lng)
			this.map.setView(latlng, 16)
			if (this.mode === 'point') {
				this.placeMarker(latlng)
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md */
		useCurrentLocation() {
			if (!navigator.geolocation) return
			navigator.geolocation.getCurrentPosition(
				(pos) => {
					const latlng = L.latLng(
						pos.coords.latitude,
						pos.coords.longitude,
					)
					this.map.setView(latlng, 16)
					if (this.mode === 'point') {
						this.placeMarker(latlng)
					}
				},
				() => {
					// Permission denied or error — silently ignore
				},
			)
		},

		/** @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md */
		save() {
			if (this.selectedGeometry) {
				this.$emit('save', this.selectedGeometry)
			}
		},

		/**
		 * @param {number} sqm The sqm.
		 * @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md
		 */
		formatArea(sqm) {
			if (sqm > 10000) {
				return `${(sqm / 10000).toFixed(2)} ha`
			}
			return `${Math.round(sqm)} m\u00B2`
		},
	},
}
</script>

<style scoped>
.location-picker-overlay {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	z-index: 10000;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
}

.location-picker {
	width: 800px;
	max-width: 95vw;
	max-height: 90vh;
	background: var(--color-main-background);
	border-radius: 12px;
	overflow: hidden;
	display: flex;
	flex-direction: column;
}

.location-picker__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
}

.location-picker__header h3 {
	margin: 0;
	font-size: 16px;
}

.location-picker__tools {
	display: flex;
	gap: 8px;
}

.location-picker__search {
	padding: 8px 16px;
}

.location-picker__map {
	flex: 1;
	min-height: 400px;
}

.location-picker__info {
	padding: 8px 16px;
	font-size: 13px;
	border-top: 1px solid var(--color-border);
}

.location-picker__hint {
	color: var(--color-text-maxcontrast);
}

.location-picker__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	padding: 12px 16px;
	border-top: 1px solid var(--color-border);
}
</style>
