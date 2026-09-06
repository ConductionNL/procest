/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Consistency tests for the OR unified-search opt-in: the register definition
 * flags exactly the intended schemas `searchable: true`, and every flagged
 * schema has a matching deepLink entry whose urlTemplate points at a real
 * manifest page route. Read both JSON files from disk (not imported modules)
 * so the assertions cover the actual on-disk config the OR repair step reads.
 *
 * @spec openspec/changes/case-search-via-or-unified-search/specs/case-search-via-or-unified-search/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const REGISTER_PATH = path.resolve(
	__dirname,
	'../../lib/Settings/dossiq_register.json',
)
const MANIFEST_PATH = path.resolve(__dirname, '../../src/manifest.json')

const EXPECTED_SEARCHABLE_SLUGS = [
	'case',
	'caseTask',
	'objectionProceeding',
	'beroep',
]

const loadJson = (filePath) => JSON.parse(fs.readFileSync(filePath, 'utf8'))

describe('searchable schema opt-in (register JSON)', () => {
	it('flags exactly case, task, bezwaar, beroep as searchable', () => {
		const register = loadJson(REGISTER_PATH)
		const schemas = register.components.schemas

		const searchableSlugs = Object.keys(schemas).filter(
			(slug) => schemas[slug].searchable === true,
		)

		expect(searchableSlugs.sort()).toEqual([...EXPECTED_SEARCHABLE_SLUGS].sort())
	})
})

describe('deep links cover all searchable schemas', () => {
	it('has a deepLinks entry for every searchable schema slug', () => {
		const register = loadJson(REGISTER_PATH)
		const manifest = loadJson(MANIFEST_PATH)
		const schemas = register.components.schemas

		const searchableSlugs = Object.keys(schemas).filter(
			(slug) => schemas[slug].searchable === true,
		)
		const deepLinkSlugs = manifest.deepLinks.map((entry) => entry.schemaSlug)

		searchableSlugs.forEach((slug) => {
			expect(deepLinkSlugs).toContain(slug)
		})
	})

	it('maps each deepLink urlTemplate to a route that exists as a manifest page', () => {
		const manifest = loadJson(MANIFEST_PATH)

		const expectedTemplates = {
			case: '/apps/dossiq/cases/{uuid}',
			caseTask: '/apps/dossiq/tasks/{uuid}',
			// The KEY is the schema slug and moves with it; the URL is a published
			// ROUTE and deliberately does not — a route resolves at request time,
			// so breaking one fails silently. That holds for `caseTask` above as
			// much as for `objectionProceeding`: #1845 renamed the slug and left
			// both of these maps, and the manifest's own `deepLinks` entry,
			// keyed on `task`.
			objectionProceeding: '/apps/dossiq/bezwaren/{uuid}',
			beroep: '/apps/dossiq/beroepen/{uuid}',
		}

		const expectedRoutes = {
			case: '/cases/:id',
			caseTask: '/tasks/:id',
			objectionProceeding: '/bezwaren/:id',
			beroep: '/beroepen/:id',
		}

		const pageRoutes = manifest.pages.map((page) => page.route)
		const deepLinksBySlug = Object.fromEntries(
			manifest.deepLinks.map((entry) => [entry.schemaSlug, entry]),
		)

		Object.keys(expectedTemplates).forEach((slug) => {
			const deepLink = deepLinksBySlug[slug]
			expect(deepLink, `missing deepLink for schema "${slug}"`).toBeDefined()
			expect(deepLink.urlTemplate).toBe(expectedTemplates[slug])
			expect(
				pageRoutes,
				`manifest has no page route "${expectedRoutes[slug]}" for schema "${slug}"`,
			).toContain(expectedRoutes[slug])
		})
	})
})

describe('version-gated re-import', () => {
	it('advances the register info.version past 0.11.0', () => {
		const register = loadJson(REGISTER_PATH)

		expect(register.info.version).not.toBe('0.11.0')
	})
})
