// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Cell-formatter registry for dossiq's manifest-driven index pages.
//
// Each entry is `(value, row, property) => string|number` — pure data
// shaping, referenced by id from `pages[].config.columns[].formatter`
// in src/manifest.json (resolved by CnDataTable / CnCellRenderer via the
// `formatters` prop passed to CnAppRoot, see @conduction/nextcloud-vue →
// docs/migrating-to-manifest.md "Column formatters"). Keep this file to
// pure functions — the Vue layer stays the library's abstract
// CnIndexPage / CnDataTable; only the app-specific per-row logic lives
// here. (`mapFormatters.js` is the separate registry for `type:"map"`
// marker formatting.)

import { useDeelzaakStore } from '../store/modules/deelzaak.js'
import { useObjectStore } from '../store/modules/object.js'
import { subCaseCountBadge } from '../utils/deelzaakHelpers.js'

// Guard so each lookup collection is fetched at most once per page load.
const lookupFetchStarted = {}

// Batch-fetch guard for sub-case counts: collect the UUIDs requested during a
// render frame and flush them in a single round-trip on the next microtask,
// so a 25-row case list fires ONE /api/deelzaken/counts request (REQ-DZS-005-C)
// rather than 25. Each batch resolves into the deelzaak store's reactive
// subCaseCounts map, re-rendering the badge cells once it lands.
const pendingCountIds = new Set()
let countFlushScheduled = false

/**
 * Queue a parent UUID for the next batch sub-case-count fetch and schedule
 * the flush. No-ops when the count is already cached in the store.
 *
 * @param {object} store The deelzaak pinia store.
 * @param {string} uuid The parent case UUID to count children for.
 * @return {void}
 */
function queueSubCaseCount(store, uuid) {
	if (uuid in store.subCaseCounts) {
		return
	}
	pendingCountIds.add(uuid)
	if (countFlushScheduled) {
		return
	}
	countFlushScheduled = true
	Promise.resolve().then(() => {
		countFlushScheduled = false
		const ids = [...pendingCountIds]
		pendingCountIds.clear()
		if (ids.length === 0) {
			return
		}
		store.fetchSubCaseCounts(ids).catch(() => {})
	})
}

/**
 * Resolve a related object's UUID to its human label by reading the
 * (reactive) objectStore collection for `type`. Fires a one-off
 * fetchCollection when the collection is not loaded yet — the pinia
 * state access is tracked by the rendering component, so the cell
 * re-renders with the label once the collection arrives.
 *
 * @param {string} type Registered object type ('caseType' / 'statusType').
 * @param {string} uuid The related object's UUID.
 * @return {string} The label, or the raw UUID while unresolved.
 */
function lookupRelatedName(type, uuid) {
	if (!uuid) return '-'
	let store
	try {
		store = useObjectStore()
	} catch {
		return uuid
	}
	const collection = store.collections[type]
	// `registerObjectType` seeds `collections[type] = []` (a truthy empty
	// array) before any fetch, so a plain `!collection` guard treats a
	// registered-but-unfetched type as already loaded and never fires the
	// lookup — leaving reference cells stuck on the raw UUID. Fetch whenever
	// the collection is empty; `lookupFetchStarted` still guards against
	// re-fetching a type that genuinely resolved to zero rows.
	if ((!collection || collection.length === 0) && !lookupFetchStarted[type]) {
		// Only fetch once the type is registered (initializeStores done).
		if (store.objectTypeRegistry && store.objectTypeRegistry[type]) {
			lookupFetchStarted[type] = true
			store.fetchCollection(type, { _limit: 500 }).catch(() => {
				lookupFetchStarted[type] = false
			})
		}
	}
	const hit = (collection || []).find(
		(o) => o.id === uuid || (o['@self'] && o['@self'].id === uuid),
	)
	return hit ? hit.title || hit.name || uuid : uuid
}

export default {
	/**
	 * Human label for a case's `caseType` UUID reference.
	 *
	 * @param {string} value The caseType UUID.
	 * @return {string}
	 */
	caseTypeName: (value) => lookupRelatedName('caseType', value),

	/**
	 * Human label for a case's `status` UUID reference (statusType).
	 *
	 * @param {string} value The statusType UUID.
	 * @return {string}
	 */
	statusTypeName: (value) => lookupRelatedName('statusType', value),

	/**
	 * Sub-case count badge for a case row in the case list. Returns "N
	 * deelzaken" for cases with one or more sub-cases and an empty string
	 * (no badge) otherwise. The count is read from the reactive deelzaak
	 * store; on the first render for an uncounted case it queues a batched
	 * /api/deelzaken/counts fetch and re-renders once the count lands.
	 *
	 * @param {unknown} value Unused (the column key is the case UUID via `row`).
	 * @param {object} row The case object.
	 * @return {string} Badge label, or '' when the case has no sub-cases.
	 * @spec openspec/changes/deelzaak-support/tasks.md#T10
	 */
	subCaseCount: (value, row) => {
		const uuid = (row && (row.id || (row['@self'] && row['@self'].id))) || value
		if (!uuid) {
			return ''
		}
		let store
		try {
			store = useDeelzaakStore()
		} catch {
			return ''
		}
		// Sub-cases themselves never carry sub-cases (zrc-013c) — skip the count.
		if (row && row.parentCase) {
			return ''
		}
		queueSubCaseCount(store, uuid)
		return subCaseCountBadge(store.subCaseCounts[uuid] || 0)
	},
}
