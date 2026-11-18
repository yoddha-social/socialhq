<script setup lang="ts">
import { InfiniteScroll, router } from '@inertiajs/vue3';
import { IconSparkles } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

import EmptyState from '@/components/EmptyState.vue';
import UsageBreakdown from '@/components/socialhq/ai/UsageBreakdown.vue';
import UsageDay from '@/components/socialhq/ai/UsageDay.vue';
import UsageStats from '@/components/socialhq/ai/UsageStats.vue';
import {
    PROBLEMS,
    type UsageDayQuery,
    type UsageFilters,
    type UsagePageProps,
} from '@/components/socialhq/ai/usageTypes';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatFullNumber } from '@/lib/utils';

/**
 * Settings › AI › Usage and the platform admins' page: period and filters,
 * the stat cards, requests by feature and provider, and the log as one card
 * per day. Today and days with failed or blocked requests start open.
 */
const props = defineProps<
    UsagePageProps & {
        url: string;
        dayUrl: (query: UsageDayQuery) => string;
    }
>();

/** Reka's Select refuses an empty value: "all" stands for no filter. */
const ALL = 'all';

const hasFilters = computed(
    () =>
        props.filters.status !== null ||
        props.filters.feature !== null ||
        props.filters.provider !== null ||
        props.filters.workspace !== null ||
        props.filters.company !== null ||
        props.filters.user !== null,
);

/** The page's filters as the day endpoint's query. */
const dayQuery = computed<UsageDayQuery>(() =>
    Object.fromEntries(
        Object.entries(props.filters)
            .filter(([, value]) => value !== null && value !== '')
            .map(([key, value]) => [key, String(value)]),
    ),
);

/** Changes whenever the filters do, so opened days load their rows again. */
const filterKey = computed(() => JSON.stringify(dayQuery.value));

const reload = (filters: Partial<UsageFilters>): void => {
    const next = { ...props.filters, ...filters };

    router.get(
        props.url,
        Object.fromEntries(
            Object.entries(next).filter(
                ([, value]) => value !== null && value !== '',
            ),
        ),
        { preserveState: true, preserveScroll: true, reset: ['days'] },
    );
};

const filterValue = (value: string | number | null): string =>
    value === null ? ALL : String(value);

const setFilter = (key: keyof UsageFilters, value: unknown): void => {
    const picked =
        value === ALL || value === null || value === undefined
            ? null
            : String(value);

    reload({
        [key]: key === 'company' && picked !== null ? Number(picked) : picked,
    });
};

const pickPeriod = (period: string): void => {
    setFilter('period', period);
};

const pickFeature = (feature: string | null): void => {
    setFilter('feature', feature);
};

const pickProvider = (provider: string | null): void => {
    setFilter('provider', provider);
};

const toggleProblems = (): void => {
    setFilter('status', props.filters.status === PROBLEMS ? null : PROBLEMS);
};

const clearFilters = (): void => {
    reload({
        status: null,
        feature: null,
        provider: null,
        workspace: null,
        company: null,
        user: null,
    });
};

/* Which days are open. */
const openDays = ref<Set<string>>(new Set());
let seenDays = new Set<string>();

watch(filterKey, () => {
    seenDays = new Set();
    openDays.value = new Set();
});

/** A day seen for the first time opens when it is today or had a failed or blocked request. */
watch(
    () => props.days.data,
    (days) => {
        const next = new Set(openDays.value);

        days.forEach((day) => {
            if (seenDays.has(day.date)) {
                return;
            }

            seenDays.add(day.date);

            if (day.date === props.today || day.problems > 0) {
                next.add(day.date);
            }
        });

        openDays.value = next;
    },
    { immediate: true },
);

const setDayOpen = (date: string, open: boolean): void => {
    const next = new Set(openDays.value);

    if (open) {
        next.add(date);
    } else {
        next.delete(date);
    }

    openDays.value = next;
};

const expandAll = (): void => {
    openDays.value = new Set(props.days.data.map((day) => day.date));
};

const collapseAll = (): void => {
    openDays.value = new Set();
};
</script>

<template>
    <div class="flex flex-col gap-6" data-testid="ai-usage">
        <EmptyState
            v-if="!hasData"
            :title="$t('socialhq_ai_usage.empty.title')"
            :description="$t('socialhq_ai_usage.empty.description')"
            data-testid="ai-usage-empty"
        >
            <template #illustration>
                <IconSparkles class="size-10 text-muted-foreground" />
            </template>
        </EmptyState>

        <template v-else>
            <div class="flex flex-col gap-3">
                <div
                    class="inline-flex self-start rounded-lg border border-border-strong bg-card p-0.5"
                    role="group"
                    :aria-label="$t('socialhq_ai_usage.filters.period')"
                    data-testid="ai-usage-period"
                >
                    <button
                        v-for="period in options.periods"
                        :key="period"
                        type="button"
                        class="h-7 rounded-md px-3 text-sm transition-control"
                        :class="
                            filters.period === period
                                ? 'bg-primary-selected font-medium text-primary-text'
                                : 'text-muted-foreground hover:bg-accent hover:text-foreground'
                        "
                        :aria-pressed="filters.period === period"
                        :data-testid="`ai-usage-period-${period}`"
                        @click="pickPeriod(period)"
                    >
                        {{ $t(`socialhq_ai_usage.periods_short.${period}`) }}
                    </button>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Select
                        v-if="platform"
                        :model-value="filterValue(filters.company)"
                        @update:model-value="setFilter('company', $event)"
                    >
                        <SelectTrigger
                            class="w-44"
                            data-testid="ai-usage-company"
                        >
                            <SelectValue>
                                {{
                                    options.companies.find(
                                        (company) =>
                                            company.value === filters.company,
                                    )?.label ??
                                    $t(
                                        'socialhq_ai_usage.filters.all_companies',
                                    )
                                }}
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL">
                                {{
                                    $t(
                                        'socialhq_ai_usage.filters.all_companies',
                                    )
                                }}
                            </SelectItem>
                            <SelectItem
                                v-for="company in options.companies"
                                :key="company.value"
                                :value="String(company.value)"
                            >
                                {{ company.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        v-if="!platform && options.workspaces.length > 1"
                        :model-value="filterValue(filters.workspace)"
                        @update:model-value="setFilter('workspace', $event)"
                    >
                        <SelectTrigger
                            class="w-40"
                            data-testid="ai-usage-workspace"
                        >
                            <SelectValue>
                                {{
                                    options.workspaces.find(
                                        (workspace) =>
                                            workspace.value ===
                                            filters.workspace,
                                    )?.label ??
                                    $t(
                                        'socialhq_ai_usage.filters.all_workspaces',
                                    )
                                }}
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL">
                                {{
                                    $t(
                                        'socialhq_ai_usage.filters.all_workspaces',
                                    )
                                }}
                            </SelectItem>
                            <SelectItem
                                v-for="workspace in options.workspaces"
                                :key="workspace.value"
                                :value="workspace.value"
                            >
                                {{ workspace.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        :model-value="filterValue(filters.status)"
                        @update:model-value="setFilter('status', $event)"
                    >
                        <SelectTrigger
                            class="w-44"
                            data-testid="ai-usage-status"
                        >
                            <SelectValue>
                                {{
                                    filters.status === PROBLEMS
                                        ? $t(
                                              'socialhq_ai_usage.filters.problems',
                                          )
                                        : filters.status
                                          ? $t(
                                                `socialhq_ai_usage.status.${filters.status}`,
                                            )
                                          : $t(
                                                'socialhq_ai_usage.filters.all_statuses',
                                            )
                                }}
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL">
                                {{
                                    $t('socialhq_ai_usage.filters.all_statuses')
                                }}
                            </SelectItem>
                            <SelectItem :value="PROBLEMS">
                                {{ $t('socialhq_ai_usage.filters.problems') }}
                            </SelectItem>
                            <SelectItem
                                v-for="status in options.statuses"
                                :key="status"
                                :value="status"
                            >
                                {{ $t(`socialhq_ai_usage.status.${status}`) }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        :model-value="filterValue(filters.feature)"
                        @update:model-value="setFilter('feature', $event)"
                    >
                        <SelectTrigger
                            class="w-44"
                            data-testid="ai-usage-feature"
                        >
                            <SelectValue>
                                {{
                                    options.features.find(
                                        (feature) =>
                                            feature.value === filters.feature,
                                    )?.label ??
                                    $t('socialhq_ai_usage.filters.all_features')
                                }}
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL">
                                {{
                                    $t('socialhq_ai_usage.filters.all_features')
                                }}
                            </SelectItem>
                            <SelectItem
                                v-for="feature in options.features"
                                :key="feature.value"
                                :value="feature.value"
                            >
                                {{ feature.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        v-if="options.providers.length > 0"
                        :model-value="filterValue(filters.provider)"
                        @update:model-value="setFilter('provider', $event)"
                    >
                        <SelectTrigger
                            class="w-40"
                            data-testid="ai-usage-provider"
                        >
                            <SelectValue>
                                {{
                                    filters.provider ??
                                    $t(
                                        'socialhq_ai_usage.filters.all_providers',
                                    )
                                }}
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL">
                                {{
                                    $t(
                                        'socialhq_ai_usage.filters.all_providers',
                                    )
                                }}
                            </SelectItem>
                            <SelectItem
                                v-for="provider in options.providers"
                                :key="provider"
                                :value="provider"
                            >
                                {{ provider }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        v-if="options.people.length > 0"
                        :model-value="filterValue(filters.user)"
                        @update:model-value="setFilter('user', $event)"
                    >
                        <SelectTrigger
                            class="w-40"
                            data-testid="ai-usage-person"
                        >
                            <SelectValue>
                                {{
                                    options.people.find(
                                        (person) =>
                                            person.value === filters.user,
                                    )?.label ??
                                    $t('socialhq_ai_usage.filters.all_people')
                                }}
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL">
                                {{ $t('socialhq_ai_usage.filters.all_people') }}
                            </SelectItem>
                            <SelectItem
                                v-for="person in options.people"
                                :key="person.value"
                                :value="person.value"
                            >
                                {{ person.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <Button
                        v-if="hasFilters"
                        variant="ghost"
                        size="sm"
                        data-testid="ai-usage-clear"
                        @click="clearFilters"
                    >
                        {{ $t('socialhq_ai_usage.filters.clear') }}
                    </Button>

                    <p
                        v-if="hasFilters"
                        class="text-sm text-muted-foreground"
                        data-testid="ai-usage-count"
                    >
                        {{
                            $t('socialhq_ai_usage.filters.count', {
                                shown: formatFullNumber(totals.calls),
                                total: formatFullNumber(inScope),
                            })
                        }}
                    </p>
                </div>
            </div>

            <UsageStats
                :totals="totals"
                :series="series"
                :budget="budget"
                :period="filters.period"
                :showing-problems="filters.status === PROBLEMS"
                @toggle-problems="toggleProblems"
            />

            <div class="grid gap-3 md:grid-cols-2">
                <UsageBreakdown
                    :title="$t('socialhq_ai_usage.breakdown.features')"
                    :rows="breakdown.features"
                    :active="filters.feature"
                    testid="ai-usage-by-feature"
                    @pick="pickFeature"
                />
                <UsageBreakdown
                    :title="$t('socialhq_ai_usage.breakdown.providers')"
                    :rows="breakdown.providers"
                    :active="filters.provider"
                    testid="ai-usage-by-provider"
                    @pick="pickProvider"
                />
            </div>

            <EmptyState
                v-if="days.data.length === 0"
                :title="$t('socialhq_ai_usage.no_results.title')"
                :description="$t('socialhq_ai_usage.no_results.description')"
                data-testid="ai-usage-no-results"
            />

            <section v-else class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base font-semibold text-foreground">
                        {{ $t('socialhq_ai_usage.days.title') }}
                    </h2>
                    <div class="flex gap-1">
                        <Button
                            variant="ghost"
                            size="sm"
                            data-testid="ai-usage-expand-all"
                            @click="expandAll"
                        >
                            {{ $t('socialhq_ai_usage.days.expand_all') }}
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            data-testid="ai-usage-collapse-all"
                            @click="collapseAll"
                        >
                            {{ $t('socialhq_ai_usage.days.collapse_all') }}
                        </Button>
                    </div>
                </div>

                <InfiniteScroll
                    data="days"
                    items-element="#ai-usage-days"
                    preserve-url
                >
                    <div id="ai-usage-days" class="flex flex-col gap-3">
                        <UsageDay
                            v-for="day in days.data"
                            :key="`${day.date}:${filterKey}`"
                            :day="day"
                            :today="today"
                            :platform="platform"
                            :query="dayQuery"
                            :day-url="dayUrl"
                            :open="openDays.has(day.date)"
                            @update:open="setDayOpen(day.date, $event)"
                        />
                    </div>
                </InfiniteScroll>
            </section>

            <p class="text-xs text-muted-foreground">
                {{ $t('socialhq_ai_usage.kept') }}
            </p>
        </template>
    </div>
</template>
