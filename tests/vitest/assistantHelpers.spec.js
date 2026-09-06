/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the pure presentation helpers in src/utils/assistantHelpers.js
 * (case-assistant-via-hermiq): the composer send-guard, transcript entry
 * factory, and the errorCode/status → user-facing message mapping. The network
 * functions (src/services/assistantApi.js) are not exercised here (they need
 * axios + a live route); these tests pin the behaviour the chat panel relies on.
 *
 * The global `t()` (NC translation) is stubbed to return the English source
 * string so output is deterministically assertable.
 *
 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
 */

import { beforeAll, describe, expect, it } from 'vitest'

beforeAll(() => {
	globalThis.t = (app, text) => text
})

const importHelpers = async () => await import('../../src/utils/assistantHelpers.js')

describe('canSend', () => {
	it('allows a normal non-empty message when idle', async () => {
		const { canSend } = await importHelpers()
		expect(canSend('What is the status?', false)).toBe(true)
	})

	it('blocks while a request is in flight', async () => {
		const { canSend } = await importHelpers()
		expect(canSend('What is the status?', true)).toBe(false)
	})

	it('blocks an empty or whitespace-only draft', async () => {
		const { canSend } = await importHelpers()
		expect(canSend('', false)).toBe(false)
		expect(canSend('   ', false)).toBe(false)
		expect(canSend(null, false)).toBe(false)
	})

	it('blocks a draft over the backend length cap (mirrors the 4000-char 400)', async () => {
		const { canSend, MAX_MESSAGE_LENGTH } = await importHelpers()
		expect(MAX_MESSAGE_LENGTH).toBe(4000)
		expect(canSend('a'.repeat(4000), false)).toBe(true)
		expect(canSend('a'.repeat(4001), false)).toBe(false)
	})
})

describe('makeTranscriptEntry', () => {
	it('builds a role/content/at entry', async () => {
		const { makeTranscriptEntry } = await importHelpers()
		const entry = makeTranscriptEntry('user', 'hello')
		expect(entry.role).toBe('user')
		expect(entry.content).toBe('hello')
		expect(typeof entry.at).toBe('string')
		expect(Number.isNaN(Date.parse(entry.at))).toBe(false)
	})
})

describe('assistantErrorMessage', () => {
	const errorWith = (status, data = {}) => ({ response: { status, data } })

	it('keys guardrail blocks off the stable errorCode, not message text', async () => {
		const { assistantErrorMessage } = await importHelpers()
		const message = assistantErrorMessage(
			errorWith(422, {
				errorCode: 'guardrail_blocked',
				message: 'whatever backend text',
			}),
		)
		expect(message).toMatch(/guardrail/i)
	})

	it('maps 400 to a validation hint', async () => {
		const { assistantErrorMessage } = await importHelpers()
		expect(assistantErrorMessage(errorWith(400))).toMatch(/empty or too long/i)
	})

	it('maps 401/403 to a permission message', async () => {
		const { assistantErrorMessage } = await importHelpers()
		expect(assistantErrorMessage(errorWith(401))).toMatch(/not allowed/i)
		expect(assistantErrorMessage(errorWith(403))).toMatch(/not allowed/i)
	})

	it('maps 404 to a case-not-found message', async () => {
		const { assistantErrorMessage } = await importHelpers()
		expect(assistantErrorMessage(errorWith(404))).toMatch(/could not be found/i)
	})

	it('falls back to unavailable for 5xx and shapeless errors', async () => {
		const { assistantErrorMessage } = await importHelpers()
		expect(assistantErrorMessage(errorWith(503))).toMatch(/unavailable/i)
		expect(assistantErrorMessage(null)).toMatch(/unavailable/i)
		expect(assistantErrorMessage(new Error('network'))).toMatch(/unavailable/i)
	})
})
