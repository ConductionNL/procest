<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<button
		type="button"
		class="mywork-card"
		:class="{ 'mywork-card--selected': selected }"
		@click="$emit('open', object)">
		<span class="mywork-card__title">{{ title }}</span>
		<span v-if="description" class="mywork-card__description">{{
			description
		}}</span>
		<span class="mywork-card__meta">
			<span v-if="identifier" class="mywork-card__ref">{{ identifier }}</span>
			<span v-if="caseTypeLabel" class="mywork-card__chip">{{
				caseTypeLabel
			}}</span>
			<span
				v-if="statusLabel"
				class="mywork-card__chip mywork-card__chip--status"
				>{{ statusLabel }}</span
			>
			<span
				v-if="urgencyChipLabel"
				class="mywork-card__chip mywork-card__urgency-chip"
				:class="urgencyChipClassName">
				{{ urgencyChipLabel }}
			</span>
		</span>
		<span
			v-if="deadlineLabel"
			class="mywork-card__deadline"
			:class="{ 'mywork-card__deadline--overdue': overdue }">
			{{ t('dossiq', 'Deadline') }}: {{ deadlineLabel }}
		</span>
	</button>
</template>

<script>
import { urgencyChipClass } from '../utils/workQueueHelpers.js'

/**
 * Card for a single case on the My Work index. Renders the case with its
 * case-type and status resolved to human names via the parent-supplied
 * UUID→name maps (the default CnObjectCard shows raw relation UUIDs because
 * card view does not apply column formatters — see
 * reference_ncvue-manifest-feature-version-gating).
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */
export default {
	name: 'MyWorkCaseCard',

	props: {
		/** The case object for this card. */
		object: {
			type: Object,
			required: true,
		},

		/** Selection state forwarded by CnCardGrid. */
		selected: {
			type: Boolean,
			default: false,
		},

		/** { caseTypeUuid: name } supplied by the parent index. */
		caseTypeMap: {
			type: Object,
			default: () => ({}),
		},

		/** { statusTypeUuid: name } supplied by the parent index. */
		statusMap: {
			type: Object,
			default: () => ({}),
		},

		/**
		 * { caseId: { tier, score, daysUntilDeadline } } supplied by the parent
		 * index, sourced from GET /api/work-queue.
		 */
		urgencyMap: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['open'],

	computed: {
		/**
		 * Card heading for one case on the personal work index.
		 *
		 * @return {string} The case title.
		 *
		 * @spec openspec/specs/my-work/spec.md#requirement-card-display-mvp
		 */
		title() {
			return (
				this.object.title
				|| this.object.name
				|| this.identifier
				|| t('dossiq', 'Untitled case')
			)
		},

		description() {
			const d = this.object.description || ''
			return d.length > 140 ? `${d.slice(0, 140)}…` : d
		},

		identifier() {
			return this.object.identifier || ''
		},

		/** Case-type name resolved from the parent-supplied map. */
		caseTypeLabel() {
			const v = this.object.caseType
			return v ? this.caseTypeMap[v] || '' : ''
		},

		/** Status name resolved from the parent-supplied map. */
		statusLabel() {
			const v = this.object.status
			return v ? this.statusMap[v] || '' : ''
		},

		deadlineLabel() {
			const raw = this.object.deadline
			if (!raw) return ''
			const d = new Date(raw)
			if (isNaN(d.getTime())) return ''
			return d.toLocaleDateString(undefined, {
				day: 'numeric',
				month: 'short',
				year: 'numeric',
			})
		},

		overdue() {
			const raw = this.object.deadline
			if (!raw) return false
			const d = new Date(raw)
			return !isNaN(d.getTime()) && d.getTime() < Date.now()
		},

		/** This card's urgency entry from the parent-supplied work-queue map. */
		urgencyEntry() {
			const id =
				this.object.id || (this.object['@self'] && this.object['@self'].id)
			return id ? this.urgencyMap[id] || null : null
		},

		/** CSS modifier class for the urgency chip; '' when no chip should render. */
		urgencyChipClassName() {
			return urgencyChipClass(this.urgencyEntry && this.urgencyEntry.tier)
		},

		/**
		 * Human label for the urgency chip; '' hides the chip (normal tier).
		 *
		 * @return {string} The translated urgency label, or '' to hide the chip.
		 *
		 * @spec openspec/specs/my-work/spec.md#requirement-card-display-mvp
		 */
		urgencyChipLabel() {
			const tier = this.urgencyEntry && this.urgencyEntry.tier
			switch (tier) {
				case 'overdue':
					return t('dossiq', 'Overdue')
				case 'critical':
					return t('dossiq', 'Critical')
				case 'warning':
					return t('dossiq', 'Due soon')
				default:
					return ''
			}
		},
	},
}
</script>

<style scoped lang="scss">
.mywork-card {
	display: flex;
	flex-direction: column;
	gap: 6px;
	width: 100%;
	height: 100%;
	padding: 16px;
	text-align: start;
	background: var(--color-main-background);
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	cursor: pointer;

	&:hover,
	&:focus-visible {
		border-color: var(--color-primary-element);
		background: var(--color-background-hover);
	}

	&--selected {
		border-color: var(--color-primary-element);
	}
}

.mywork-card__title {
	font-weight: 600;
	font-size: 1rem;
	color: var(--color-main-text);
}

.mywork-card__description {
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
	line-height: 1.3;
}

.mywork-card__meta {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px;
	margin-top: 4px;
}

.mywork-card__ref {
	color: var(--color-text-maxcontrast);
	font-size: 0.8rem;
	font-family: var(--font-face-monospace, monospace);
}

.mywork-card__chip {
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 16px);
	background: var(--color-background-dark);
	color: var(--color-main-text);
	font-size: 0.8rem;
	white-space: nowrap;

	&--status {
		background: var(--color-primary-element-light);
		color: var(--color-primary-element-text-dark, var(--color-main-text));
	}
}

.mywork-card__deadline {
	color: var(--color-text-maxcontrast);
	font-size: 0.8rem;

	&--overdue {
		color: var(--color-error);
		font-weight: 600;
	}
}

// Urgency chip — overdue uses the error colour, critical/warning use the
// warning colour (critical solid, warning a softer outline), all via NC CSS
// variables per the werkvoorraad-intelligent-queue spec's colour rule.
.mywork-card__urgency-chip {
	font-weight: 600;

	&--overdue {
		background: var(--color-error);
		color: var(--color-primary-element-text, #fff);
	}

	&--critical {
		background: var(--color-warning);
		color: var(--color-primary-element-text, #000);
	}

	&--warning {
		color: var(--color-warning);
		background: transparent;
		border: 1px solid var(--color-warning);
	}
}
</style>
