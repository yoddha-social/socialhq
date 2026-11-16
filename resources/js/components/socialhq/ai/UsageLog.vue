<script setup lang="ts">
import { InfiniteScroll, router } from '@inertiajs/vue3';
import {
    IconArrowDownRight,
    IconChevronDown,
    IconChevronUp,
    IconSparkles,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import EmptyState from '@/components/EmptyState.vue';
import type {
    UsageFilters,
    UsageLogRow,
    UsagePageProps,
    UsageStatus,
} from '@/components/socialhq/ai/usageTypes';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import date from '@/date';

const props = defineProps<UsagePageProps & { url: string }>();

/** Reka's Select refuses an empty value: "all" stands for no filter. */
const ALL = 'all';

const expanded = ref<Set<number>>(new Set());

const hasFilters = computed(
    () =>
        props.filters.status !== null ||
        props.filters.feature !== null ||
        props.filters.provider !== null ||
        props.filters.workspace !== null ||
        props.filters.company !== null,
);

const free = computed(() => props.totals.calls - props.totals.paid);

const reload = (filters: Partial<UsageFilters>): void => {
    const next = { ...props.filters, ...filters };

    router.get(
        props.url,
        Object.fromEntries(
            Object.entries(next).filter(
                ([, value]) => value !== null && value !== '',
            ),
        ),
        { preserveState: true, preserveScroll: true, reset: ['logs'] },
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

const clearFilters = (): void => {
    reload({
        status: null,
        feature: null,
        provider: null,
        workspace: null,
        company: null,
    });
};

const toggleDetail = (id: number): void => {
    const next = new Set(expanded.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    expanded.value = next;
};

const formatCost = (value: number): string =>
    new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: value > 0 && value < 0.01 ? 6 : 2,
    }).format(value);

const formatDuration = (ms: number): string =>
    ms < 1000 ? `${ms} ms` : `${(ms / 1000).toFixed(1)} s`;

const statusVariant = (
    status: UsageStatus,
): 'success' | 'destructive-subtle' | 'warning' | 'secondary' => {
    if (status === 'ok') {
        return 'success';
    }

    if (status === 'failed') {
        return 'destructive-subtle';
    }

    return status === 'rate_limited' ? 'secondary' : 'warning';
};

const answeredBy = (row: UsageLogRow): string | null =>
    row.provider && row.model
        ? `${row.provider} · ${row.model}`
        : (row.model ?? row.provider);

const explanation = (row: UsageLogRow): string | null => {
    if (row.status !== 'ok') {
        return `socialhq_ai_usage.explain.${row.status}`;
    }

    return row.fallbacks > 0 ? 'socialhq_ai_usage.explain.fell_back' : null;
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
            <div class="flex flex-wrap items-center gap-2">
                <Select
                    :model-value="filters.period"
                    @update:model-value="setFilter('period', $event)"
                >
                    <SelectTrigger class="w-40" data-testid="ai-usage-period">
                        <SelectValue>
                            {{
                                $t(
                                    `socialhq_ai_usage.periods.${filters.period}`,
                                )
                            }}
                        </SelectValue>
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="period in options.periods"
                            :key="period"
                            :value="period"
                        >
                            {{ $t(`socialhq_ai_usage.periods.${period}`) }}
                        </SelectItem>
                    </SelectContent>
                </Select>

                <Select
                    v-if="platform"
                    :model-value="filterValue(filters.company)"
                    @update:model-value="setFilter('company', $event)"
                >
                    <SelectTrigger class="w-44" data-testid="ai-usage-company">
                        <SelectValue>
                            {{
                                options.companies.find(
                                    (company) =>
                                        company.value === filters.company,
                                )?.label ??
                                $t('socialhq_ai_usage.filters.all_companies')
                            }}
                        </SelectValue>
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL">
                            {{ $t('socialhq_ai_usage.filters.all_companies') }}
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
                                        workspace.value === filters.workspace,
                                )?.label ??
                                $t('socialhq_ai_usage.filters.all_workspaces')
                            }}
                        </SelectValue>
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL">
                            {{ $t('socialhq_ai_usage.filters.all_workspaces') }}
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
                    <SelectTrigger class="w-40" data-testid="ai-usage-status">
                        <SelectValue>
                            {{
                                filters.status
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
                            {{ $t('socialhq_ai_usage.filters.all_statuses') }}
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
                    <SelectTrigger class="w-44" data-testid="ai-usage-feature">
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
                            {{ $t('socialhq_ai_usage.filters.all_features') }}
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
                    <SelectTrigger class="w-40" data-testid="ai-usage-provider">
                        <SelectValue>
                            {{
                                filters.provider ??
                                $t('socialhq_ai_usage.filters.all_providers')
                            }}
                        </SelectValue>
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL">
                            {{ $t('socialhq_ai_usage.filters.all_providers') }}
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

                <Button
                    v-if="hasFilters"
                    variant="ghost"
                    size="sm"
                    data-testid="ai-usage-clear"
                    @click="clearFilters"
                >
                    {{ $t('socialhq_ai_usage.filters.clear') }}
                </Button>
            </div>

            <dl
                class="grid grid-cols-2 gap-3 sm:grid-cols-4"
                data-testid="ai-usage-totals"
            >
                <div class="rounded-xl border border-border p-4">
                    <dt class="text-xs text-muted-foreground">
                        {{ $t('socialhq_ai_usage.totals.calls') }}
                    </dt>
                    <dd
                        class="text-xl font-semibold"
                        data-testid="ai-usage-total-calls"
                    >
                        {{ totals.calls }}
                    </dd>
                </div>
                <div class="rounded-xl border border-border p-4">
                    <dt class="text-xs text-muted-foreground">
                        {{ $t('socialhq_ai_usage.totals.cost') }}
                    </dt>
                    <dd
                        class="text-xl font-semibold"
                        data-testid="ai-usage-total-cost"
                    >
                        {{ formatCost(totals.cost) }}
                    </dd>
                </div>
                <div class="rounded-xl border border-border p-4">
                    <dt class="text-xs text-muted-foreground">
                        {{ $t('socialhq_ai_usage.totals.free') }} /
                        {{ $t('socialhq_ai_usage.totals.paid') }}
                    </dt>
                    <dd class="text-xl font-semibold">
                        {{ free }} / {{ totals.paid }}
                    </dd>
                </div>
                <div class="rounded-xl border border-border p-4">
                    <dt class="text-xs text-muted-foreground">
                        {{ $t('socialhq_ai_usage.totals.problems') }}
                    </dt>
                    <dd
                        class="text-xl font-semibold"
                        data-testid="ai-usage-total-problems"
                    >
                        {{ totals.problems }}
                    </dd>
                </div>
            </dl>

            <EmptyState
                v-if="logs.data.length === 0"
                :title="$t('socialhq_ai_usage.no_results.title')"
                :description="$t('socialhq_ai_usage.no_results.description')"
                data-testid="ai-usage-no-results"
            />

            <InfiniteScroll
                v-else
                data="logs"
                items-element="#ai-usage-rows"
                preserve-url
            >
                <ul id="ai-usage-rows" class="flex flex-col gap-2">
                    <li
                        v-for="row in logs.data"
                        :key="row.id"
                        class="rounded-xl border border-border"
                        :data-testid="`ai-usage-row-${row.id}`"
                    >
                        <button
                            type="button"
                            class="flex w-full flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-left"
                            :aria-expanded="expanded.has(row.id)"
                            :data-testid="`ai-usage-toggle-${row.id}`"
                            @click="toggleDetail(row.id)"
                        >
                            <span
                                class="w-32 shrink-0 text-xs text-muted-foreground"
                            >
                                {{ date.formatDateTime(row.created_at) }}
                            </span>
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-sm font-medium"
                                >
                                    {{ row.feature_label }}
                                </span>
                                <span
                                    class="block truncate text-xs text-muted-foreground"
                                >
                                    <template v-if="row.company"
                                        >{{ row.company }} ·
                                    </template>
                                    {{
                                        row.workspace ??
                                        $t('socialhq_ai_usage.row.deleted')
                                    }}
                                    ·
                                    {{
                                        row.user ??
                                        $t('socialhq_ai_usage.row.nobody')
                                    }}
                                </span>
                            </span>
                            <span
                                class="flex min-w-0 items-center gap-1 text-sm"
                            >
                                <IconArrowDownRight
                                    v-if="row.fallbacks > 0"
                                    class="size-4 shrink-0 text-amber-600"
                                    :aria-label="
                                        $t('socialhq_ai_usage.row.fallback')
                                    "
                                />
                                <span class="truncate">
                                    {{
                                        answeredBy(row) ??
                                        $t(
                                            'socialhq_ai_usage.row.unknown_provider',
                                        )
                                    }}
                                </span>
                            </span>
                            <span
                                class="w-20 shrink-0 text-right text-sm tabular-nums"
                            >
                                <template v-if="row.cost === null">—</template>
                                <template v-else-if="row.cost === 0">{{
                                    $t('socialhq_ai_usage.row.free')
                                }}</template>
                                <template v-else>{{
                                    formatCost(row.cost)
                                }}</template>
                            </span>
                            <Badge
                                :variant="statusVariant(row.status)"
                                :data-testid="`ai-usage-status-${row.id}`"
                            >
                                {{
                                    $t(`socialhq_ai_usage.status.${row.status}`)
                                }}
                            </Badge>
                            <component
                                :is="
                                    expanded.has(row.id)
                                        ? IconChevronUp
                                        : IconChevronDown
                                "
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                        </button>

                        <div
                            v-if="expanded.has(row.id)"
                            class="flex flex-col gap-3 border-t border-border px-4 py-3 text-sm"
                            :data-testid="`ai-usage-detail-${row.id}`"
                        >
                            <p
                                v-if="explanation(row)"
                                class="text-muted-foreground"
                            >
                                {{ $t(explanation(row) as string) }}
                            </p>
                            <dl
                                class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2"
                            >
                                <div
                                    v-if="platform"
                                    class="flex justify-between gap-4"
                                >
                                    <dt class="text-muted-foreground">
                                        {{
                                            $t(
                                                'socialhq_ai_usage.detail.company',
                                            )
                                        }}
                                    </dt>
                                    <dd>
                                        {{
                                            row.company ??
                                            $t('socialhq_ai_usage.detail.none')
                                        }}
                                    </dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-muted-foreground">
                                        {{
                                            $t('socialhq_ai_usage.detail.route')
                                        }}
                                    </dt>
                                    <dd>
                                        {{
                                            row.route
                                                ? $t(
                                                      `socialhq_ai_usage.route.${row.route}`,
                                                  )
                                                : $t(
                                                      'socialhq_ai_usage.detail.none',
                                                  )
                                        }}
                                    </dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-muted-foreground">
                                        {{
                                            $t('socialhq_ai_usage.detail.task')
                                        }}
                                    </dt>
                                    <dd>
                                        {{
                                            row.task ??
                                            $t('socialhq_ai_usage.detail.none')
                                        }}
                                    </dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-muted-foreground">
                                        {{
                                            $t(
                                                'socialhq_ai_usage.detail.requested_group',
                                            )
                                        }}
                                    </dt>
                                    <dd class="font-mono text-xs">
                                        {{
                                            row.model_group_requested ??
                                            $t('socialhq_ai_usage.detail.none')
                                        }}
                                    </dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-muted-foreground">
                                        {{
                                            $t(
                                                'socialhq_ai_usage.detail.answered_group',
                                            )
                                        }}
                                    </dt>
                                    <dd class="font-mono text-xs">
                                        {{
                                            row.model_group ??
                                            $t('socialhq_ai_usage.detail.none')
                                        }}
                                    </dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-muted-foreground">
                                        {{
                                            $t('socialhq_ai_usage.detail.model')
                                        }}
                                    </dt>
                                    <dd class="truncate">
                                        {{
                                            answeredBy(row) ??
                                            $t('socialhq_ai_usage.detail.none')
                                        }}
                                    </dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-muted-foreground">
                                        {{
                                            $t(
                                                'socialhq_ai_usage.detail.fallbacks',
                                            )
                                        }}
                                    </dt>
                                    <dd>{{ row.fallbacks }}</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-muted-foreground">
                                        {{
                                            $t(
                                                'socialhq_ai_usage.detail.requests',
                                            )
                                        }}
                                    </dt>
                                    <dd>{{ row.requests }}</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-muted-foreground">
                                        {{
                                            $t(
                                                'socialhq_ai_usage.detail.tokens',
                                            )
                                        }}
                                    </dt>
                                    <dd class="tabular-nums">
                                        {{ row.prompt_tokens ?? '—' }} /
                                        {{ row.completion_tokens ?? '—' }}
                                    </dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-muted-foreground">
                                        {{
                                            $t(
                                                'socialhq_ai_usage.detail.duration',
                                            )
                                        }}
                                    </dt>
                                    <dd class="tabular-nums">
                                        {{
                                            row.duration_ms === null
                                                ? '—'
                                                : formatDuration(
                                                      row.duration_ms,
                                                  )
                                        }}
                                    </dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-muted-foreground">
                                        {{
                                            $t('socialhq_ai_usage.detail.cost')
                                        }}
                                    </dt>
                                    <dd class="tabular-nums">
                                        {{
                                            row.cost === null
                                                ? '—'
                                                : formatCost(row.cost)
                                        }}
                                    </dd>
                                </div>
                                <div
                                    v-if="row.error_code || row.error_type"
                                    class="flex justify-between gap-4"
                                >
                                    <dt class="text-muted-foreground">
                                        {{
                                            $t('socialhq_ai_usage.detail.error')
                                        }}
                                    </dt>
                                    <dd class="font-mono text-xs">
                                        {{
                                            [row.error_code, row.error_type]
                                                .filter(Boolean)
                                                .join(' · ')
                                        }}
                                    </dd>
                                </div>
                                <div
                                    v-if="row.call_id"
                                    class="flex justify-between gap-4 sm:col-span-2"
                                >
                                    <dt class="text-muted-foreground">
                                        {{
                                            $t(
                                                'socialhq_ai_usage.detail.call_id',
                                            )
                                        }}
                                    </dt>
                                    <dd
                                        class="truncate font-mono text-xs"
                                        :data-testid="`ai-usage-call-id-${row.id}`"
                                    >
                                        {{ row.call_id }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </li>
                </ul>
            </InfiniteScroll>

            <p class="text-xs text-muted-foreground">
                {{ $t('socialhq_ai_usage.kept') }}
            </p>
        </template>
    </div>
</template>
