<script setup lang="ts">
import {
    IconAlertCircle,
    IconChevronDown,
    IconLoader2,
    IconSparkles,
    IconX,
} from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

import { channelLabel } from '@/components/socialhq/channelLabel';
import type { PlanData } from '@/components/socialhq/plan/types';
import { Button } from '@/components/ui/button';
import date from '@/date';

const props = defineProps<{
    plan: PlanData | null;
    monthLabel: string;
    canGenerate: boolean;
}>();

const emit = defineEmits<{
    retry: [];
}>();

const STORAGE_PREFIX = 'socialhq.plan.run-dismissed';

const storageKey = computed(() =>
    props.plan ? `${STORAGE_PREFIX}.${props.plan.id}` : null,
);

const readDismissed = (): string | null => {
    if (!storageKey.value) {
        return null;
    }

    try {
        return window.localStorage.getItem(storageKey.value);
    } catch {
        return null;
    }
};

const dismissedRun = ref<string | null>(readDismissed());

watch(storageKey, () => {
    dismissedRun.value = readDismissed();
});

const generation = computed(() => props.plan?.generation ?? null);

const showResult = computed(
    () =>
        generation.value?.status === 'idle' &&
        !!generation.value.generated_at &&
        dismissedRun.value !== generation.value.generated_at,
);

const rejectionsOpen = ref(false);

/** Weeks of the running draft already done, out of all its weeks (one AI call each). */
const progress = computed(() => {
    const weeks = generation.value?.weeks ?? [];

    return weeks.length > 1
        ? {
              done: weeks.filter((week) => week.status !== 'pending').length,
              total: weeks.length,
          }
        : null;
});

/** The reason most suggestions were not added for, with how many. */
const topReason = computed(() => {
    const counts = new Map<string, number>();

    for (const rejection of generation.value?.rejections ?? []) {
        counts.set(rejection.reason, (counts.get(rejection.reason) ?? 0) + 1);
    }

    const [reason, count] = [...counts.entries()].sort(
        (a, b) => b[1] - a[1],
    )[0] ?? [null, 0];

    return reason && count > 1 ? { reason, count: String(count) } : null;
});

/** Weeks the AI didn't draft, with the days and why. */
const failedWeeks = computed(() =>
    (generation.value?.weeks ?? [])
        .filter((week) => week.status === 'failed')
        .map((week) => ({
            key: week.from,
            days: date.formatDayMonthYearRange({
                start: week.from,
                end: week.to,
            }),
            error: week.error,
        })),
);

const dismiss = (): void => {
    const runAt = generation.value?.generated_at ?? null;
    dismissedRun.value = runAt;

    if (!storageKey.value || !runAt) {
        return;
    }

    try {
        window.localStorage.setItem(storageKey.value, runAt);
    } catch {
        // Private mode or blocked storage: the panel stays dismissed until reload.
    }
};

const toggleRejections = (): void => {
    rejectionsOpen.value = !rejectionsOpen.value;
};

const retry = (): void => emit('retry');
</script>

<template>
    <div
        v-if="generation?.status === 'running'"
        class="flex items-center gap-3 rounded-xl border border-primary/30 bg-primary-subtle/50 px-4 py-3"
        role="status"
        data-testid="plan-generation-running"
    >
        <IconLoader2
            class="size-5 shrink-0 animate-spin text-primary-text"
            aria-hidden="true"
        />
        <div class="min-w-0">
            <p class="text-sm font-medium text-foreground">
                {{
                    $t('socialhq_plan.generation.running_title', {
                        month: monthLabel,
                    })
                }}
            </p>
            <p class="text-sm text-muted-foreground">
                {{ $t('socialhq_plan.generation.running_body') }}
            </p>
            <p
                v-if="progress"
                class="text-sm text-muted-foreground"
                data-testid="plan-generation-progress"
            >
                {{
                    $t('socialhq_plan.generation.progress', {
                        done: String(progress.done),
                        total: String(progress.total),
                    })
                }}
            </p>
        </div>
    </div>

    <div
        v-else-if="generation?.status === 'failed'"
        class="flex items-start gap-3 rounded-xl border border-destructive/30 bg-destructive/5 px-4 py-3"
        role="alert"
        data-testid="plan-generation-failed"
    >
        <IconAlertCircle
            class="mt-0.5 size-5 shrink-0 text-destructive-text"
            aria-hidden="true"
        />
        <div class="min-w-0 flex-1">
            <p class="text-sm font-medium text-foreground">
                {{ $t('socialhq_plan.generation.failed_title') }}
            </p>
            <p class="text-sm text-muted-foreground">
                {{ generation.error }}
            </p>
        </div>
        <Button
            v-if="canGenerate"
            variant="outline"
            size="sm"
            data-testid="plan-generation-retry"
            @click="retry"
        >
            {{ $t('socialhq_plan.generation.retry') }}
        </Button>
    </div>

    <div
        v-else-if="showResult && generation"
        class="flex flex-col gap-2 rounded-xl border border-border-strong bg-card px-4 py-3"
        data-testid="plan-generation-result"
    >
        <div class="flex items-start gap-3">
            <IconSparkles
                class="mt-0.5 size-5 shrink-0 text-primary-text"
                aria-hidden="true"
            />
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-foreground">
                    {{ $t('socialhq_plan.generation.result_title') }}
                </p>
                <p
                    v-if="generation.summary"
                    class="text-sm whitespace-pre-line text-muted-foreground"
                    data-testid="plan-generation-summary"
                >
                    {{ generation.summary }}
                </p>
            </div>
            <Button
                variant="ghost"
                size="icon"
                class="-me-2 -mt-1 shrink-0"
                :aria-label="$t('socialhq_plan.generation.dismiss')"
                data-testid="plan-generation-dismiss"
                @click="dismiss"
            >
                <IconX class="size-4" />
            </Button>
        </div>

        <p
            v-if="generation.added !== null"
            class="ps-8 text-sm text-foreground"
            data-testid="plan-generation-added"
        >
            {{
                $t('socialhq_plan.generation.added', {
                    count: String(generation.added),
                })
            }}
        </p>

        <div v-if="generation.rejections.length" class="ps-8">
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded-md text-sm font-medium text-foreground hover:text-primary-text focus-visible:outline-2 focus-visible:outline-ring"
                :aria-expanded="rejectionsOpen"
                data-testid="plan-generation-rejections-toggle"
                @click="toggleRejections"
            >
                {{
                    $t('socialhq_plan.generation.rejected', {
                        count: String(generation.rejections.length),
                    })
                }}
                <IconChevronDown
                    class="size-4 text-muted-foreground transition-transform"
                    :class="{ 'rotate-180': rejectionsOpen }"
                    aria-hidden="true"
                />
            </button>
            <p
                v-if="topReason"
                class="text-xs text-muted-foreground"
                data-testid="plan-generation-top-reason"
            >
                {{
                    $t('socialhq_plan.generation.top_reason', {
                        reason: $t(
                            `socialhq_plan.generation.reasons.${topReason.reason}`,
                        ),
                        count: topReason.count,
                    })
                }}
            </p>
            <ul
                v-if="rejectionsOpen"
                class="mt-2 flex flex-col divide-y divide-border rounded-lg border border-border"
                data-testid="plan-generation-rejections"
            >
                <li
                    v-for="(rejection, index) in generation.rejections"
                    :key="index"
                    class="flex flex-col gap-0.5 px-3 py-2 sm:flex-row sm:items-center sm:gap-3"
                >
                    <span
                        class="min-w-0 flex-1 truncate text-sm text-foreground"
                        >{{ rejection.title || '—' }}</span
                    >
                    <span class="shrink-0 text-xs text-muted-foreground">
                        {{
                            [
                                rejection.date,
                                channelLabel(
                                    rejection.channel,
                                    rejection.platform,
                                ),
                            ]
                                .filter(Boolean)
                                .join(' · ')
                        }}
                    </span>
                    <span class="shrink-0 text-xs font-medium text-warning">
                        {{
                            $t(
                                `socialhq_plan.generation.reasons.${rejection.reason}`,
                            )
                        }}
                    </span>
                </li>
            </ul>
        </div>

        <div
            v-if="failedWeeks.length"
            class="flex flex-col gap-2 ps-8 sm:flex-row sm:items-start sm:justify-between"
            data-testid="plan-generation-not-drafted"
        >
            <ul class="flex flex-col gap-1">
                <li
                    v-for="week in failedWeeks"
                    :key="week.key"
                    class="text-sm text-muted-foreground"
                >
                    <span class="font-medium text-foreground">
                        {{
                            $t('socialhq_plan.generation.not_drafted', {
                                days: week.days,
                            })
                        }}
                    </span>
                    {{ week.error }}
                </li>
            </ul>
            <Button
                v-if="canGenerate"
                variant="outline"
                size="sm"
                class="shrink-0 self-start"
                data-testid="plan-generation-retry-weeks"
                @click="retry"
            >
                {{ $t('socialhq_plan.generation.retry') }}
            </Button>
        </div>
    </div>
</template>
