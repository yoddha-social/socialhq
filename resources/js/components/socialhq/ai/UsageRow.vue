<script setup lang="ts">
import {
    IconArrowDownRight,
    IconChevronDown,
    IconCopy,
} from '@tabler/icons-vue';
import { computed } from 'vue';

import {
    USAGE_ROW_GRID,
    answeredBy,
    explanation,
    formatCost,
    formatDuration,
    rowTokens,
    statusVariant,
} from '@/components/socialhq/ai/usageFormat';
import type { UsageLogRow } from '@/components/socialhq/ai/usageTypes';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import date from '@/date';
import { copyToClipboard, formatNumberCompact } from '@/lib/utils';

/**
 * One AI request in an opened day: a table row on md and up, a card on
 * phones. Clicking it shows who asked, how it was routed and what it used.
 */
const props = defineProps<{
    row: UsageLogRow;
    platform: boolean;
}>();

const open = defineModel<boolean>('open', { default: false });

const tokens = computed(() => rowTokens(props.row));
const isFree = computed(() => props.row.status === 'ok' && !props.row.cost);
const explained = computed(() => explanation(props.row));

/** "shq-write → shq-write-paid" when the request fell back, else the one group. */
const routing = computed(() => {
    const { model_group_requested: requested, model_group: answered } =
        props.row;

    if (requested && answered && requested !== answered) {
        return `${requested} → ${answered}`;
    }

    return answered ?? requested;
});

const toggle = (): void => {
    open.value = !open.value;
};

const copyCallId = (): void => {
    if (props.row.call_id) {
        void copyToClipboard(props.row.call_id);
    }
};
</script>

<template>
    <li
        class="border-t border-border first:border-t-0"
        :data-testid="`ai-usage-row-${row.id}`"
    >
        <button
            type="button"
            class="flex w-full flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5 text-left text-sm transition-control hover:bg-accent/60"
            :class="USAGE_ROW_GRID"
            :aria-expanded="open"
            :data-testid="`ai-usage-toggle-${row.id}`"
            @click="toggle"
        >
            <span class="text-xs text-muted-foreground tabular-nums">
                {{ date.formatTime(row.created_at) }}
            </span>
            <span class="min-w-0 flex-1 truncate font-medium">
                {{ row.feature_label }}
            </span>
            <span
                class="w-full truncate text-xs text-muted-foreground md:w-auto"
            >
                <template v-if="platform && row.company"
                    >{{ row.company }} ·
                </template>
                {{ row.user ?? $t('socialhq_ai_usage.row.nobody') }}
            </span>
            <span class="flex min-w-0 items-center gap-1.5">
                <IconArrowDownRight
                    v-if="row.fallbacks > 0"
                    class="size-4 shrink-0 text-amber-600"
                    :aria-label="$t('socialhq_ai_usage.row.fallback')"
                    role="img"
                />
                <span class="truncate">
                    {{
                        answeredBy(row) ??
                        $t('socialhq_ai_usage.row.unknown_provider')
                    }}
                </span>
                <Badge v-if="isFree" variant="success" class="shrink-0">
                    {{ $t('socialhq_ai_usage.row.free') }}
                </Badge>
            </span>
            <span
                class="text-xs text-muted-foreground tabular-nums md:text-right md:text-sm"
            >
                {{ tokens === null ? '—' : formatNumberCompact(tokens) }}
            </span>
            <span
                class="text-xs text-muted-foreground tabular-nums md:text-right md:text-sm"
            >
                {{
                    row.duration_ms === null
                        ? '—'
                        : formatDuration(row.duration_ms)
                }}
            </span>
            <span class="tabular-nums md:text-right">
                {{ row.cost === null ? '—' : formatCost(row.cost) }}
            </span>
            <span>
                <Badge
                    :variant="statusVariant(row.status)"
                    :data-testid="`ai-usage-status-${row.id}`"
                >
                    {{ $t(`socialhq_ai_usage.status.${row.status}`) }}
                </Badge>
            </span>
            <IconChevronDown
                class="size-4 shrink-0 text-muted-foreground transition-transform"
                :class="{ 'rotate-180': open }"
            />
        </button>

        <div
            v-if="open"
            class="flex flex-col gap-3 bg-muted/40 px-4 py-3 text-sm"
            :data-testid="`ai-usage-detail-${row.id}`"
        >
            <p v-if="explained" class="text-muted-foreground">
                {{ $t(explained) }}
            </p>
            <div class="grid gap-4 sm:grid-cols-3">
                <dl class="flex flex-col gap-1.5">
                    <p class="text-xs font-semibold text-muted-foreground">
                        {{ $t('socialhq_ai_usage.detail.who') }}
                    </p>
                    <div v-if="platform" class="flex justify-between gap-3">
                        <dt class="text-muted-foreground">
                            {{ $t('socialhq_ai_usage.detail.company') }}
                        </dt>
                        <dd class="truncate">
                            {{
                                row.company ??
                                $t('socialhq_ai_usage.detail.none')
                            }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted-foreground">
                            {{ $t('socialhq_ai_usage.detail.workspace') }}
                        </dt>
                        <dd class="truncate">
                            {{
                                row.workspace ??
                                $t('socialhq_ai_usage.row.deleted')
                            }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted-foreground">
                            {{ $t('socialhq_ai_usage.detail.user') }}
                        </dt>
                        <dd class="truncate">
                            {{ row.user ?? $t('socialhq_ai_usage.row.nobody') }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted-foreground">
                            {{ $t('socialhq_ai_usage.detail.task') }}
                        </dt>
                        <dd>
                            {{
                                row.task ?? $t('socialhq_ai_usage.detail.none')
                            }}
                        </dd>
                    </div>
                </dl>

                <dl class="flex flex-col gap-1.5">
                    <p class="text-xs font-semibold text-muted-foreground">
                        {{ $t('socialhq_ai_usage.detail.routing') }}
                    </p>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted-foreground">
                            {{ $t('socialhq_ai_usage.detail.route') }}
                        </dt>
                        <dd>
                            {{
                                row.route
                                    ? $t(`socialhq_ai_usage.route.${row.route}`)
                                    : $t('socialhq_ai_usage.detail.none')
                            }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted-foreground">
                            {{ $t('socialhq_ai_usage.detail.answered_group') }}
                        </dt>
                        <dd
                            class="truncate font-mono text-xs"
                            :data-testid="`ai-usage-routing-${row.id}`"
                        >
                            {{ routing ?? $t('socialhq_ai_usage.detail.none') }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted-foreground">
                            {{ $t('socialhq_ai_usage.detail.model') }}
                        </dt>
                        <dd class="truncate">
                            {{
                                answeredBy(row) ??
                                $t('socialhq_ai_usage.detail.none')
                            }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted-foreground">
                            {{ $t('socialhq_ai_usage.detail.fallbacks') }}
                        </dt>
                        <dd class="tabular-nums">{{ row.fallbacks }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted-foreground">
                            {{ $t('socialhq_ai_usage.detail.requests') }}
                        </dt>
                        <dd class="tabular-nums">{{ row.requests }}</dd>
                    </div>
                </dl>

                <dl class="flex flex-col gap-1.5">
                    <p class="text-xs font-semibold text-muted-foreground">
                        {{ $t('socialhq_ai_usage.detail.usage') }}
                    </p>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted-foreground">
                            {{ $t('socialhq_ai_usage.detail.tokens') }}
                        </dt>
                        <dd class="tabular-nums">
                            {{ row.prompt_tokens ?? '—' }} /
                            {{ row.completion_tokens ?? '—' }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted-foreground">
                            {{ $t('socialhq_ai_usage.detail.duration') }}
                        </dt>
                        <dd class="tabular-nums">
                            {{
                                row.duration_ms === null
                                    ? '—'
                                    : formatDuration(row.duration_ms)
                            }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted-foreground">
                            {{ $t('socialhq_ai_usage.detail.cost') }}
                        </dt>
                        <dd class="tabular-nums">
                            {{ row.cost === null ? '—' : formatCost(row.cost) }}
                        </dd>
                    </div>
                    <div
                        v-if="row.error_code || row.error_type"
                        class="flex justify-between gap-3"
                    >
                        <dt class="text-muted-foreground">
                            {{ $t('socialhq_ai_usage.detail.error') }}
                        </dt>
                        <dd class="truncate font-mono text-xs">
                            {{
                                [row.error_code, row.error_type]
                                    .filter(Boolean)
                                    .join(' · ')
                            }}
                        </dd>
                    </div>
                </dl>
            </div>

            <div
                v-if="row.call_id"
                class="flex flex-wrap items-center gap-2 border-t border-border pt-3"
            >
                <span class="text-muted-foreground">
                    {{ $t('socialhq_ai_usage.detail.call_id') }}
                </span>
                <code
                    class="min-w-0 truncate font-mono text-xs"
                    :data-testid="`ai-usage-call-id-${row.id}`"
                    >{{ row.call_id }}</code
                >
                <Button
                    variant="ghost"
                    size="sm"
                    :aria-label="$t('socialhq_ai_usage.detail.copy_label')"
                    @click="copyCallId"
                >
                    <IconCopy class="size-4" />
                    {{ $t('socialhq_ai_usage.detail.copy') }}
                </Button>
            </div>
        </div>
    </li>
</template>
