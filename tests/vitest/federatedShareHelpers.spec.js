/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for src/utils/federatedShareHelpers.js — the pure logic behind
 * CaseSharingTab.vue, CreateFederatedShareDialog.vue and
 * PublicFederatedTransferPage.vue.
 *
 * Dossiq's Vitest project runs in the `node` environment with no Vue mount
 * harness installed (see vitest.config.js — no @vue/test-utils / jsdom /
 * vue-loader plugin registered), so the `.vue` single-file components
 * cannot be full-mounted here (same constraint documented in
 * tests/vitest/caseListExportAction.spec.js). This spec instead exercises
 * the extracted endpoint builders, payload shaping and form validation the
 * components call directly.
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	createFederatedShareEndpoint,
	federatedActivityEndpoint,
	federatedSharesListEndpoint,
	FEDERATION_ALLOWED_FIELDS,
	isFederatedShareFormValid,
	publicFederatedTransferEndpoint,
	revokeFederatedShareEndpoint,
	shapeFederatedSharePayload,
} from '../../src/utils/federatedShareHelpers.js'

describe('FEDERATION_ALLOWED_FIELDS', () => {
	it('mirrors the PHP allow-list and never includes @self or relations', () => {
		expect(FEDERATION_ALLOWED_FIELDS).toEqual([
			'title',
			'description',
			'status',
			'caseType',
			'priority',
			'dueDate',
			'requestedDate',
		])
		expect(FEDERATION_ALLOWED_FIELDS).not.toContain('@self')
		expect(FEDERATION_ALLOWED_FIELDS).not.toContain('relations')
	})
})

describe('endpoint builders', () => {
	it('builds the local federated-shares list endpoint', () => {
		expect(federatedSharesListEndpoint()).toBe(
			'/apps/openregister/api/objects/dossiq/caseFederatedShare',
		)
	})

	it('builds the create-federated-share endpoint', () => {
		expect(createFederatedShareEndpoint()).toBe(
			'/apps/dossiq/api/federation/shares',
		)
	})

	it('builds the revoke-federated-share endpoint with URL-encoding', () => {
		expect(revokeFederatedShareEndpoint('share/1')).toBe(
			'/apps/dossiq/api/federation/shares/share%2F1',
		)
	})

	it('builds the local activity endpoint', () => {
		expect(federatedActivityEndpoint('share-1')).toBe(
			'/apps/dossiq/api/federation/activity/share-1',
		)
	})

	it('builds the public transfer accept/reject endpoint from token + transferId', () => {
		expect(publicFederatedTransferEndpoint('tok-abc', 'transfer-1')).toBe(
			'/apps/dossiq/api/public/federation/transfers/tok-abc/transfer-1',
		)
	})
})

describe('shapeFederatedSharePayload', () => {
	it('trims the cloud id and de-duplicates fields/documents', () => {
		const payload = shapeFederatedSharePayload(
			{
				remoteCloudId: '  partner@remote.example  ',
				sharedFields: ['title', 'status', 'title'],
				sharedDocuments: ['doc-1', 'doc-1', 'doc-2'],
			},
			'case-1',
		)

		expect(payload).toEqual({
			caseId: 'case-1',
			remoteCloudId: 'partner@remote.example',
			sharedFields: ['title', 'status'],
			sharedDocuments: ['doc-1', 'doc-2'],
		})
	})

	it('defaults to empty arrays when fields/documents are omitted', () => {
		const payload = shapeFederatedSharePayload(
			{ remoteCloudId: 'x@y.example' },
			'case-2',
		)
		expect(payload.sharedFields).toEqual([])
		expect(payload.sharedDocuments).toEqual([])
	})

	it('never mutates or aliases the input form arrays', () => {
		const form = {
			remoteCloudId: 'x@y.example',
			sharedFields: ['title'],
			sharedDocuments: [],
		}
		const payload = shapeFederatedSharePayload(form, 'case-3')
		payload.sharedFields.push('description')
		expect(form.sharedFields).toEqual(['title'])
	})
})

describe('isFederatedShareFormValid', () => {
	it('requires a non-empty cloud id', () => {
		expect(
			isFederatedShareFormValid({
				remoteCloudId: '',
				sharedFields: ['title'],
			}),
		).toBe(false)
		expect(
			isFederatedShareFormValid({
				remoteCloudId: '   ',
				sharedFields: ['title'],
			}),
		).toBe(false)
	})

	it('requires at least one selected field', () => {
		expect(
			isFederatedShareFormValid({
				remoteCloudId: 'x@y.example',
				sharedFields: [],
			}),
		).toBe(false)
	})

	it('is valid with a cloud id and at least one field', () => {
		expect(
			isFederatedShareFormValid({
				remoteCloudId: 'x@y.example',
				sharedFields: ['title'],
			}),
		).toBe(true)
	})
})
