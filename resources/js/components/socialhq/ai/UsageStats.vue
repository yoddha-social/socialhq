<script setup lang="ts">
import { computed } from 'vue';

import { formatCost, percentOf } from '@/components/socialhq/ai/usageFormat';
import type {
    UsagePageProps,
    UsagePeriod,
} from '@/components/socialhq/ai/usageTypes';
import { formatFullNumber } from '@/lib/utils';

/**
 * The four stat cards of AI usage: requests (with a bar per hour or day),
 * spent (against the monthly budget when the company has one), the share
 * answered free, and failed or blocked, which filters the log to those.
 */
const props = defineProps<{
    totals: UsagePageProps['totals'];
    series: UsagePageProps['series'];
    budget: UsagePageProps['budget'];
    period: UsagePeriod;
    showingProblems: boolean;
}>();

const emit = defineEmits<{
    toggleProblems: [];
}>();

const busiest = computed(() =>
    Math.max(1, ...props.series.map((point) => point.calls)),
);

const freeShare = computed(() =>
    percentOf(props.totals.free, props.totals.answered),
);

const budgetShare = computed(() =>
    props.budget.monthlyUsd
        ? (props.budget.spentLastDays / props.budget.monthlyUsd) * 100
        : 0,
);

const barHeight = (calls: number): string =>
    `${calls === 0 ? 0 : Math.max(8, Math.round((calls / busiest.value) * 100))}%`;

const problemShare = (calls: number, problems: number): string =>
    `${percentOf(problems, calls)}%`;

const toggleProblems = (): void => {
    emit('toggleProblems');
};
</script>

<template>
    <div
        class="grid grid-cols-2 gap-3 lg:grid-cols-4"
        data-testid="ai-usage-totals"
    >
        <section
            class="flex flex-col gap-2 rounded-xl border border-border-strong bg-card p-4"
        >
            <h3 class="text-xs text-muted-foreground">
                {{ $t('socialhq_ai_usage.totals.calls') }}
            </h3>
            <p
                class="text-2xl font-semibold tabular-nums"
                data-testid="ai-usage-total-calls"
            >
                {{ formatFullNumber(totals.calls) }}
            </p>
            <div
                class="mt-auto flex h-8 items-end gap-px"
                role="img"
                :aria-label="$t('socialhq_ai_usage.totals.chart_label')"
            >
                <span
                    v-for="point in series"
                    :key="point.key"
                    class="relative min-w-px flex-1 overflow-hidden rounded-t-sm bg-primary/70"
                    :style="{ height: barHeight(point.calls) }"
                >
                    <span
                        v-if="point.problems > 0"
                        class="absolute inset-x-0 bottom-0 bg-destructive"
                        :style="{
                            height: problemShare(point.calls, point.problems),
                        }"
                    />
                </span>
            </div>
            <p class="text-xs text-muted-foreground">
                {{
                    period === '1d'
                        ? $t('socialhq_ai_usage.totals.per_hour')
                        : $t('socialhq_ai_usage.totals.per_day')
                }}
            </p>
        </section>

        <section
            class="flex flex-col gap-2 rounded-xl border border-border-strong bg-card p-4"
        >
            <h3 class="text-xs text-muted-foreground">
                {{ $t('socialhq_ai_usage.totals.cost') }}
            </h3>
            <p
                class="text-2xl font-semibold tabular-nums"
                data-testid="ai-usage-total-cost"
            >
                {{ formatCost(totals.cost) }}
            </p>
            <template v-if="budget.monthlyUsd">
                <div
                    class="mt-auto h-1.5 overflow-hidden rounded-full bg-muted"
                    data-testid="ai-usage-budget-bar"
                >
                    <div
                        class="h-full rounded-full"
                        :class="
                            budgetShare >= 90 ? 'bg-destructive' : 'bg-primary'
                        "
                        :style="{ width: `${Math.min(100, budgetShare)}%` }"
                    />
                </div>
                <p class="text-xs text-muted-foreground">
                    {{
                        $t('socialhq_ai_usage.totals.budget', {
                            spent: formatCost(budget.spentLastDays),
                            budget: formatCost(budget.monthlyUsd),
                            days: String(budget.days),
                        })
                    }}
                    ·
                    {{
                        budgetShare > 0 && budgetShare < 1
                            ? $t('socialhq_ai_usage.totals.budget_share_small')
                            : $t('socialhq_ai_usage.totals.budget_share', {
                                  percent: String(Math.round(budgetShare)),
                              })
                    }}
                </p>
            </template>
            <p v-else class="mt-auto text-xs text-muted-foreground">
                {{
                    $t('socialhq_ai_usage.totals.no_budget', {
                        spent: formatCost(budget.spentLastDays),
                        days: String(budget.days),
                    })
                }}
            </p>
        </section>

        <section
            class="flex flex-col gap-2 rounded-xl border border-border-strong bg-card p-4"
        >
            <h3 class="text-xs text-muted-foreground">
                {{ $t('socialhq_ai_usage.totals.free_share') }}
            </h3>
            <p
                class="text-2xl font-semibold tabular-nums"
                data-testid="ai-usage-free-share"
            >
                <template v-if="totals.answered > 0">{{ freeShare }}%</template>
                <template v-else>—</template>
            </p>
            <div
                class="mt-auto flex h-1.5 overflow-hidden rounded-full bg-muted"
            >
                <div
                    class="h-full bg-success"
                    :style="{ width: `${freeShare}%` }"
                />
                <div
                    class="h-full bg-violet-500"
                    :style="{
                        width: `${totals.answered > 0 ? 100 - freeShare : 0}%`,
                    }"
                />
            </div>
            <p class="text-xs text-muted-foreground">
                {{
                    totals.answered > 0
                        ? $t('socialhq_ai_usage.totals.free_paid', {
                              free: formatFullNumber(totals.free),
                              paid: formatFullNumber(totals.paid),
                          })
                        : $t('socialhq_ai_usage.totals.nothing_answered')
                }}
            </p>
        </section>

        <button
            type="button"
            class="flex flex-col gap-2 rounded-xl border bg-card p-4 text-left transition-control hover:bg-accent disabled:pointer-events-none"
            :class="
                totals.problems > 0 || showingProblems
                    ? 'border-destructive/60'
                    : 'border-border-strong'
            "
            :disabled="totals.problems === 0 && !showingProblems"
            :aria-pressed="showingProblems"
            data-testid="ai-usage-problems"
            @click="toggleProblems"
        >
            <span class="text-xs text-muted-foreground">
                {{ $t('socialhq_ai_usage.totals.problems') }}
            </span>
            <span
                class="text-2xl font-semibold tabular-nums"
                :class="{ 'text-destructive': totals.problems > 0 }"
                data-testid="ai-usage-total-problems"
            >
                {{ formatFullNumber(totals.problems) }}
            </span>
            <span class="mt-auto text-xs text-muted-foreground">
                <template v-if="showingProblems">
                    {{ $t('socialhq_ai_usage.totals.showing_problems') }}
                </template>
                <template v-else-if="totals.problems > 0">
                    {{ $t('socialhq_ai_usage.totals.show_problems') }} →
                </template>
                <template v-else>
                    {{ $t('socialhq_ai_usage.totals.all_answered') }}
                </template>
            </span>
        </button>
    </div>
</template>
