<template>
	<div class="email-thread">
		<h4 class="email-thread__title">
			{{ t('dossiq', 'Email Communication') }}
			<span v-if="messages.length > 0" class="email-thread__count">
				({{ messages.length }})
			</span>
		</h4>

		<div v-if="messages.length === 0" class="email-thread__empty">
			{{ t('dossiq', 'No emails for this case.') }}
		</div>

		<div v-else class="email-thread__messages">
			<div
				v-for="msg in sortedMessages"
				:key="msg.messageId || msg.id"
				class="email-thread__message"
				:class="'email-thread__message--' + msg.direction">
				<div class="email-thread__message-header">
					<span class="email-thread__direction">
						{{
							msg.direction === 'outbound'
								? t('dossiq', 'Sent')
								: t('dossiq', 'Received')
						}}
					</span>
					<span class="email-thread__date">
						{{ formatDateTime(msg.sentAt || msg.receivedAt) }}
					</span>
				</div>

				<div class="email-thread__message-meta">
					<span v-if="msg.direction === 'outbound'">
						{{ t('dossiq', 'To: {email}', { email: msg.to }) }}
					</span>
					<span v-else>
						{{ t('dossiq', 'From: {email}', { email: msg.from }) }}
					</span>
				</div>

				<div class="email-thread__subject">
					{{ msg.subject }}
				</div>

				<div class="email-thread__body">
					{{ truncateBody(msg.body) }}
				</div>

				<NcButton
					v-if="msg.body && msg.body.length > 200"
					type="tertiary"
					@click="toggleExpand(msg.messageId || msg.id)">
					{{
						isExpanded(msg.messageId || msg.id)
							? t('dossiq', 'Show less')
							: t('dossiq', 'Show more')
					}}
				</NcButton>
			</div>
		</div>

		<!-- Compose button -->
		<NcButton v-if="!isReadOnly" @click="$emit('compose')">
			{{ t('dossiq', 'Compose Email') }}
		</NcButton>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'EmailThread',
	components: {
		NcButton,
	},

	props: {
		messages: {
			type: Array,
			default: () => [],
		},

		isReadOnly: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['compose'],

	data() {
		return {
			expandedMessages: {},
		}
	},

	computed: {
		/** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
		sortedMessages() {
			return [...this.messages].sort((a, b) => {
				const dateA = new Date(a.sentAt || a.receivedAt || 0)
				const dateB = new Date(b.sentAt || b.receivedAt || 0)
				return dateA - dateB
			})
		},
	},

	methods: {
		/**
		 * @param {string} dateStr The date str, as a string.
		 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
		 */
		formatDateTime(dateStr) {
			if (!dateStr) return '---'
			const d = new Date(dateStr)
			if (isNaN(d.getTime())) return dateStr
			return d.toLocaleString('nl-NL', {
				day: 'numeric',
				month: 'short',
				year: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			})
		},

		/**
		 * @param {object} body The body.
		 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
		 */
		truncateBody(body) {
			if (!body) return ''
			const id = 'temp'
			if (body.length <= 200 || this.expandedMessages[id]) {
				return body
			}
			return body.substring(0, 200) + '...'
		},

		isExpanded(id) {
			return this.expandedMessages[id] === true
		},

		/**
		 * @param {string} id Identifier of the id.
		 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
		 */
		toggleExpand(id) {
			this.expandedMessages[id] = !this.expandedMessages[id]
		},
	},
}
</script>

<style scoped>
.email-thread__title {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 12px;
}

.email-thread__count {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.email-thread__empty {
	color: var(--color-text-maxcontrast);
	padding: 8px 0;
}

.email-thread__message {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 12px;
	margin-bottom: 8px;
}

.email-thread__message--outbound {
	border-left: 3px solid var(--color-primary-element);
}

.email-thread__message--inbound {
	border-left: 3px solid var(--color-success, #2e7d32);
}

.email-thread__message-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 4px;
}

.email-thread__direction {
	font-size: 0.75rem;
	text-transform: uppercase;
	font-weight: 600;
}

.email-thread__message--outbound .email-thread__direction {
	color: var(--color-primary-element);
}

.email-thread__message--inbound .email-thread__direction {
	color: var(--color-success, #2e7d32);
}

.email-thread__date {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.email-thread__message-meta {
	font-size: 0.8125rem;
	color: var(--color-text-maxcontrast);
	margin-bottom: 4px;
}

.email-thread__subject {
	font-weight: 600;
	margin-bottom: 4px;
}

.email-thread__body {
	font-size: 0.875rem;
	white-space: pre-wrap;
	overflow-wrap: break-word;
}

.email-thread__messages {
	margin-bottom: 12px;
}
</style>
