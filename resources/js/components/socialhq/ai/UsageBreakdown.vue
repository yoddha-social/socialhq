<script setup lang="ts">
import { computed } from 'vue';

import { percentOf } from '@/components/socialhq/ai/usageFormat';
import type { UsageBreakdownRow } from '@/components/socialhq/ai/usageTypes';
import { formatFullNumber } from '@/lib/utils';

/**
 * A "By feature" or "By provider" panel: one bar per value, busiest first.
 * Clicking a bar filters the log to it; clicking the active one clears it.
 */
const props = defineProps<{
    title: string;
    rows: UsageBreakdownRow[];
    active: string | null;
    testid: string;
}>();

const emit = defineEmits<{
    pick: [value: string | null];
}>();

/** Rows shown; the rest are rare enough to reach through the filter. */
const VISIBLE_ROWS = 6;

const visibleRows = computed(() => props.rows.slice(0, VISIBLE_ROWS));
const busiest = computed(() =>
    Math.max(1, ...props.rows.map((row) => row.calls)),
);

const pick = (row: UsageBreakdownRow): void => {
    emit('pick', props.active === row.value ? null : row.value);
};
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-xl border border-border-strong bg-card p-4"
        :data-testid="testid"
    >
        <h3 class="text-sm font-semibold text-foreground">{{ title }}</h3>
        <p v-if="rows.length === 0" class="text-sm text-muted-foreground">
            {{ $t('socialhq_ai_usage.breakdown.none') }}
        </p>
        <ul v-else class="flex flex-col gap-1">
            <li v-for="row in visibleRows" :key="row.value">
                <button
                    type="button"
                    class="flex w-full flex-col gap-1 rounded-md px-2 py-1.5 text-left transition-control hover:bg-accent"
                    :class="{ 'bg-primary-selected': active === row.value }"
                    :aria-pressed="active === row.value"
                    :aria-label="
                        $t('socialhq_ai_usage.breakdown.filter_label', {
                            name: row.label,
                        })
                    "
                    :data-testid="`${testid}-${row.value}`"
                    @click="pick(row)"
                >
                    <span class="flex items-center justify-between gap-3">
                        <span class="truncate text-sm text-foreground">
                            {{ row.label }}
                        </span>
                        <span
                            class="shrink-0 text-xs text-muted-foreground tabular-nums"
                        >
                            {{ formatFullNumber(row.calls) }}
                        </span>
                    </span>
                    <span class="h-1.5 overflow-hidden rounded-full bg-muted">
                        <span
                            class="block h-full rounded-full bg-primary/70"
                            :style="{
                                width: `${percentOf(row.calls, busiest)}%`,
                            }"
                        />
                    </span>
                </button>
            </li>
        </ul>
    </section>
</template>
