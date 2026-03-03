<template>
	<div class="my-work-preview">
		<h3 class="my-work-preview__title">
			{{ t('procest', 'My Work') }}
		</h3>

		<template v-if="loading">
			<div v-for="i in 5" :key="i" class="my-work-preview__skeleton">
				<div class="skeleton-row" />
			</div>
		</template>

		<template v-else-if="error">
			<div class="my-work-preview__error">
				<p>{{ error }}</p>
				<NcButton type="tertiary" @click="$emit('retry')">
					{{ t('procest', 'Retry') }}
				</NcButton>
			</div>
		</template>

		<template v-else-if="items.length === 0">
			<p class="my-work-preview__empty">
				{{ t('procest', 'No items assigned to you') }}
			</p>
		</template>

		<template v-else>
			<div
				v-for="item in items"
				:key="`${item.type}-${item.id}`"
				class="my-work-preview__row"
				@click="$emit('click-item', item.type, item.id)">
				<span class="my-work-preview__badge" :class="`my-work-preview__badge--${item.type}`">
					{{ item.type === 'case' ? t('procest', 'CASE') : t('procest', 'TASK') }}
				</span>
				<div class="my-work-preview__info">
					<span class="my-work-preview__item-title">{{ item.title }}</span>
					<span v-if="item.reference" class="my-work-preview__reference">{{ item.reference }}</span>
				</div>
				<div class="my-work-preview__deadline">
					<span :class="{ 'my-work-preview__overdue': item.isOverdue }">{{ item.daysText }}</span>
					<span v-if="item.priority === 'urgent' || item.priority === 'high'" class="my-work-preview__priority">
						{{ item.priority === 'urgent' ? '!!' : '!' }}
					</span>
				</div>
			</div>

			<div class="my-work-preview__footer">
				<a href="#" @click.prevent="$emit('view-all')">
					{{ t('procest', 'View all my work') }} &rarr;
				</a>
			</div>
		</template>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'MyWorkPreview',
	components: {
		NcButton,
	},
	props: {
		items: { type: Array, default: () => [] },
		loading: { type: Boolean, default: false },
		error: { type: String, default: null },
	},
	emits: ['click-item', 'view-all', 'retry'],
}
</script>

<style scoped>
.my-work-preview {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
}

.my-work-preview__title {
	font-size: 15px;
	margin: 0 0 12px;
}

.my-work-preview__row {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 8px;
	margin-bottom: 4px;
	border-radius: var(--border-radius);
	cursor: pointer;
	transition: background 0.15s ease;
}

.my-work-preview__row:hover {
	background: var(--color-background-hover);
}

.my-work-preview__badge {
	font-size: 10px;
	font-weight: bold;
	padding: 2px 6px;
	border-radius: 4px;
	white-space: nowrap;
	flex-shrink: 0;
}

.my-work-preview__badge--case {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.my-work-preview__badge--task {
	background: rgba(var(--color-success-rgb, 76, 175, 80), 0.15);
	color: var(--color-success);
}

.my-work-preview__info {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.my-work-preview__item-title {
	font-size: 13px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.my-work-preview__reference {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.my-work-preview__deadline {
	flex-shrink: 0;
	display: flex;
	align-items: center;
	gap: 4px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.my-work-preview__overdue {
	color: var(--color-error);
	font-weight: 600;
}

.my-work-preview__priority {
	color: var(--color-warning);
	font-weight: bold;
}

.my-work-preview__footer {
	margin-top: 12px;
	text-align: center;
}

.my-work-preview__footer a {
	font-size: 13px;
	color: var(--color-primary);
	text-decoration: none;
}

.my-work-preview__empty {
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 20px 0;
}

.my-work-preview__error {
	text-align: center;
	padding: 12px;
	color: var(--color-error);
}

.my-work-preview__skeleton {
	margin-bottom: 8px;
}

.skeleton-row {
	height: 36px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	animation: shimmer 1.5s infinite;
}

@keyframes shimmer {
	0% { opacity: 0.6; }
	50% { opacity: 1; }
	100% { opacity: 0.6; }
}
</style>
