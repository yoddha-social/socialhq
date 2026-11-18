<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { IconChevronRight } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

import {
    USAGE_ROW_GRID,
    formatCost,
} from '@/components/socialhq/ai/usageFormat';
import UsageRow from '@/components/socialhq/ai/UsageRow.vue';
import type {
    UsageDayQuery,
    UsageDayRows,
    UsageDaySummary,
} from '@/components/socialhq/ai/usageTypes';
import { localizedDay } from '@/components/socialhq/localizedDay';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatFullNumber, formatNumberCompact } from '@/lib/utils';

/**
 * One day of the AI usage log. Closed, it is one line: the day, its
 * features and its totals. Opened, it loads that day's requests (under the
 * page's filters) and lists them; a busy day shows the first rows and a
 * "Show all" button.
 */
const props = defineProps<{
    day: UsageDaySummary;
    today: string;
    platform: boolean;
    query: UsageDayQuery;
    dayUrl: (query: UsageDayQuery) => string;
}>();

const open = defineModel<boolean>('open', { default: false });

/** A day with more requests than this shows only the first BUSY_DAY_ROWS until "Show all". */
const BUSY_DAY = 50;
const BUSY_DAY_ROWS = 20;
/** Features named in a closed day's line; the rest are "+N more". */
const SUMMARY_FEATURES = 3;

const http = useHttp<Record<string, never>, UsageDayRows>({});
const loaded = ref<UsageDayRows | null>(null);
const failed = ref(false);
const showAll = ref(false);
const openRows = ref<Set<number>>(new Set());

/** "today", "yesterday" or null: the word shown before the date. */
const relativeDay = computed((): 'today' | 'yesterday' | null => {
    if (props.day.date === props.today) {
        return 'today';
    }

    const yesterday = localizedDay(props.today)
        .subtract(1, 'day')
        .format('YYYY-MM-DD');

    return props.day.date === yesterday ? 'yesterday' : null;
});

const dateText = computed(() =>
    localizedDay(props.day.date).format(
        relativeDay.value ? 'D MMMM' : 'dddd D MMMM',
    ),
);

const summaryFeatures = computed(() =>
    props.day.features.slice(0, SUMMARY_FEATURES),
);
const moreFeatures = computed(() =>
    Math.max(0, props.day.features.length - SUMMARY_FEATURES),
);

const visibleRows = computed(() => {
    const rows = loaded.value?.rows ?? [];

    return rows.length > BUSY_DAY && !showAll.value
        ? rows.slice(0, BUSY_DAY_ROWS)
        : rows;
});

const hiddenRows = computed(
    () => (loaded.value?.rows.length ?? 0) - visibleRows.value.length,
);

const load = async (): Promise<void> => {
    failed.value = false;

    try {
        loaded.value = await http.get(
            props.dayUrl({ ...props.query, day: props.day.date }),
        );
    } catch {
        failed.value = true;
    }
};

watch(
    open,
    (isOpen) => {
        if (isOpen && loaded.value === null && !http.processing) {
            void load();
        }
    },
    { immediate: true },
);

const toggle = (): void => {
    open.value = !open.value;
};

const retry = (): void => {
    void load();
};

const showAllRows = (): void => {
    showAll.value = true;
};

const setRowOpen = (id: number, isOpen: boolean): void => {
    const next = new Set(openRows.value);

    if (isOpen) {
        next.add(id);
    } else {
        next.delete(id);
    }

    openRows.value = next;
};
</script>

<template>
    <section
        class="overflow-clip rounded-xl border bg-card"
        :class="
            day.problems > 0 ? 'border-destructive/40' : 'border-border-strong'
        "
        :data-testid="`ai-usage-day-${day.date}`"
    >
        <button
            type="button"
            class="flex w-full flex-wrap items-center gap-x-3 gap-y-1 bg-card px-4 py-3 text-left transition-control hover:bg-accent/60"
            :class="{ 'sticky top-0 z-10 border-b border-border': open }"
            :aria-expanded="open"
            :data-testid="`ai-usage-day-toggle-${day.date}`"
            @click="toggle"
        >
            <IconChevronRight
                class="size-4 shrink-0 text-muted-foreground transition-transform"
                :class="{ 'rotate-90': open }"
            />
            <span class="text-sm font-semibold text-foreground">
                <template v-if="relativeDay"
                    >{{ $t(`socialhq_ai_usage.days.${relativeDay}`) }} ·
                </template>
                {{ dateText }}
            </span>
            <span
                v-if="!open"
                class="hidden min-w-0 flex-1 truncate text-sm text-muted-foreground md:block"
            >
                <template
                    v-for="(feature, index) in summaryFeatures"
                    :key="feature.label"
                    ><template v-if="index > 0"> · </template
                    >{{ feature.label }}
                    {{ formatFullNumber(feature.calls) }}</template
                ><template v-if="moreFeatures > 0">
                    ·
                    {{
                        $t('socialhq_ai_usage.days.more_features', {
                            count: String(moreFeatures),
                        })
                    }}</template
                >
            </span>
            <span
                class="ms-auto flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground tabular-nums"
            >
                <span>
                    {{
                        $tChoice('socialhq_ai_usage.days.requests', day.calls, {
                            count: formatFullNumber(day.calls),
                        })
                    }}
                </span>
                <span>
                    {{
                        $t('socialhq_ai_usage.days.tokens', {
                            count: formatNumberCompact(day.tokens),
                        })
                    }}
                </span>
                <span>{{ formatCost(day.cost) }}</span>
                <Badge
                    :variant="
                        day.problems > 0 ? 'destructive-subtle' : 'success'
                    "
                    :data-testid="`ai-usage-day-status-${day.date}`"
                >
                    {{
                        day.problems > 0
                            ? $t('socialhq_ai_usage.days.problems', {
                                  count: formatFullNumber(day.problems),
                              })
                            : $t('socialhq_ai_usage.days.all_answered')
                    }}
                </Badge>
            </span>
        </button>

        <div v-if="open" :data-testid="`ai-usage-day-rows-${day.date}`">
            <p
                v-if="loaded === null && !failed"
                class="flex items-center gap-2 px-4 py-4 text-sm text-muted-foreground"
                aria-live="polite"
            >
                <Spinner />
                {{ $t('socialhq_ai_usage.days.loading') }}
            </p>

            <div
                v-else-if="failed"
                class="flex flex-wrap items-center gap-3 px-4 py-4 text-sm"
                role="alert"
            >
                <span class="text-destructive">
                    {{ $t('socialhq_ai_usage.days.load_failed') }}
                </span>
                <Button
                    variant="outline"
                    size="sm"
                    :loading="http.processing"
                    @click="retry"
                >
                    {{ $t('socialhq_ai_usage.days.retry') }}
                </Button>
            </div>

            <template v-else-if="loaded">
                <div
                    class="hidden border-b border-border px-4 py-2 text-xs font-medium text-muted-foreground"
                    :class="USAGE_ROW_GRID"
                    aria-hidden="true"
                >
                    <span>{{ $t('socialhq_ai_usage.columns.time') }}</span>
                    <span>{{ $t('socialhq_ai_usage.columns.feature') }}</span>
                    <span>{{ $t('socialhq_ai_usage.columns.person') }}</span>
                    <span>{{ $t('socialhq_ai_usage.columns.model') }}</span>
                    <span class="text-right">
                        {{ $t('socialhq_ai_usage.columns.tokens') }}
                    </span>
                    <span class="text-right">
                        {{ $t('socialhq_ai_usage.columns.duration') }}
                    </span>
                    <span class="text-right">
                        {{ $t('socialhq_ai_usage.columns.cost') }}
                    </span>
                    <span>{{ $t('socialhq_ai_usage.columns.result') }}</span>
                    <span />
                </div>

                <ul>
                    <UsageRow
                        v-for="row in visibleRows"
                        :key="row.id"
                        :row="row"
                        :platform="platform"
                        :open="openRows.has(row.id)"
                        @update:open="setRowOpen(row.id, $event)"
                    />
                </ul>

                <div
                    v-if="hiddenRows > 0"
                    class="border-t border-border px-4 py-2"
                >
                    <Button
                        variant="ghost"
                        size="sm"
                        :data-testid="`ai-usage-day-show-all-${day.date}`"
                        @click="showAllRows"
                    >
                        {{
                            $t('socialhq_ai_usage.days.show_all', {
                                count: formatFullNumber(loaded.rows.length),
                            })
                        }}
                    </Button>
                </div>

                <p
                    v-if="loaded.total > loaded.rows.length"
                    class="border-t border-border px-4 py-2 text-xs text-muted-foreground"
                >
                    {{
                        $t('socialhq_ai_usage.days.limited', {
                            shown: formatFullNumber(loaded.rows.length),
                            total: formatFullNumber(loaded.total),
                        })
                    }}
                </p>
            </template>
        </div>
    </section>
</template>
