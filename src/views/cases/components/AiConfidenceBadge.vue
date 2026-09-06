<template>
	<span
		class="ai-confidence-badge"
		:class="`ai-confidence-badge--${level}`"
		:aria-label="ariaLabel">
		{{ label }}
	</span>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'AiConfidenceBadge',
	props: {
		confidence: {
			type: Number,
			required: true,
			validator: (v) => v >= 0 && v <= 1,
		},

		// AiClassifyDialog passes `size="medium"`. The badge sizes itself in CSS,
		// so the value is not read in script; the declaration keeps it a prop
		// rather than a stray HTML attribute on the root element.
		// eslint-disable-next-line vue/no-unused-properties
		size: {
			type: String,
			default: 'small',
			validator: (v) => ['small', 'medium'].includes(v),
		},
	},

	computed: {
		/** @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md */
		level() {
			if (this.confidence > 0.85) return 'high'
			if (this.confidence >= 0.6) return 'medium'
			return 'low'
		},

		/** @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md */
		label() {
			return `${Math.round(this.confidence * 100)}%`
		},

		/** @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md */
		ariaLabel() {
			const levelLabel =
				this.level === 'high'
					? t('dossiq', 'high')
					: this.level === 'medium'
						? t('dossiq', 'medium')
						: t('dossiq', 'low')
			return t('dossiq', 'Confidence: {percentage} ({level})', {
				percentage: this.label,
				level: levelLabel,
			})
		},
	},
}
</script>

<style scoped>
.ai-confidence-badge {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	border-radius: var(--border-radius-pill);
	font-weight: 600;
	font-size: 12px;
	padding: 2px 8px;
}

.ai-confidence-badge--high {
	background-color: var(--color-success);
	color: white;
}

.ai-confidence-badge--medium {
	background-color: var(--color-warning);
	color: var(--color-main-text);
}

.ai-confidence-badge--low {
	background-color: var(--color-error);
	color: white;
}
</style>
