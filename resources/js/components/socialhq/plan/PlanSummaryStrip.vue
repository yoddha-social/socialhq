<script setup lang="ts">
import { computed } from 'vue';

import PlatformBrandIcon from '@/components/PlatformBrandIcon.vue';
import { pillarColor } from '@/components/socialhq/plan/pillarColors';
import type {
    PlanChannel,
    PlanEvent,
    PlanItem,
} from '@/components/socialhq/plan/types';
import dayjs from '@/dayjs';

const props = defineProps<{
    month: string;
    items: PlanItem[];
    events: PlanEvent[];
    channels: PlanChannel[];
    pillars: string[];
    cadence: Record<string, number | null>;
}>();

/** Planned and suggested items; dropped ones don't count. */
const live = computed(() =>
    props.items.filter((item) => item.status !== 'dropped'),
);

const weeksInMonth = computed(
    () => dayjs(`${props.month}-01`).daysInMonth() / 7,
);

const channelRows = computed(() =>
    props.channels.map((channel) => {
        const perWeek = props.cadence[channel.id] ?? null;

        return {
            channel,
            count: live.value.filter(
                (item) => item.social_account_id === channel.id,
            ).length,
            target:
                perWeek === null
                    ? null
                    : Math.round(perWeek * weeksInMonth.value),
        };
    }),
);

const pillarRows = computed(() =>
    props.pillars.map((pillar) => ({
        pillar,
        color: pillarColor(pillar, props.pillars),
        count: live.value.filter((item) => item.pillar === pillar).length,
    })),
);

const confirmedEvents = computed(() =>
    props.events.filter((event) => event.confirmed),
);

const coveredEvents = computed(
    () =>
        confirmedEvents.value.filter((event) =>
            live.value.some((item) => item.event_id === event.id),
        ).length,
);

const countTone = (count: number, target: number | null): string => {
    if (target === null) {
        return 'text-foreground';
    }

    return count >= target ? 'text-success-text' : 'text-foreground';
};
</script>

<template>
    <div
        class="flex flex-wrap items-stretch gap-x-6 gap-y-3 rounded-xl border border-border-strong bg-card px-4 py-3"
        data-testid="plan-summary"
    >
        <section
            v-if="channelRows.length"
            class="flex min-w-0 flex-col gap-1.5"
            :aria-label="$t('socialhq_plan.summary.channels')"
        >
            <h3 class="text-[11px] font-medium text-muted-foreground uppercase">
                {{ $t('socialhq_plan.summary.channels') }}
            </h3>
            <ul class="flex flex-wrap gap-x-4 gap-y-1">
                <li
                    v-for="row in channelRows"
                    :key="row.channel.id"
                    class="flex min-w-0 items-center gap-1.5 text-sm"
                    :data-testid="`plan-summary-channel-${row.channel.id}`"
                >
                    <PlatformBrandIcon
                        :platform="row.channel.platform"
                        colored
                        class="size-4 shrink-0"
                    />
                    <span class="max-w-36 truncate text-muted-foreground">{{
                        row.channel.name
                    }}</span>
                    <span
                        class="font-medium tabular-nums"
                        :class="countTone(row.count, row.target)"
                    >
                        <template v-if="row.target !== null">
                            {{
                                $t('socialhq_plan.summary.count_of_target', {
                                    count: String(row.count),
                                    target: String(row.target),
                                })
                            }}
                        </template>
                        <template v-else>{{ row.count }}</template>
                    </span>
                </li>
            </ul>
        </section>

        <section
            v-if="pillarRows.length"
            class="flex min-w-0 flex-col gap-1.5"
            :aria-label="$t('socialhq_plan.summary.pillars')"
        >
            <h3 class="text-[11px] font-medium text-muted-foreground uppercase">
                {{ $t('socialhq_plan.summary.pillars') }}
            </h3>
            <ul class="flex flex-wrap gap-1.5">
                <li
                    v-for="row in pillarRows"
                    :key="row.pillar"
                    class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="row.color?.soft"
                >
                    <span
                        class="size-1.5 rounded-full"
                        :class="row.color?.dot"
                    />
                    {{ row.pillar }}
                    <span class="tabular-nums opacity-80">{{ row.count }}</span>
                </li>
            </ul>
        </section>

        <section
            v-if="confirmedEvents.length"
            class="flex min-w-0 flex-col gap-1.5"
            :aria-label="$t('socialhq_plan.summary.events')"
        >
            <h3 class="text-[11px] font-medium text-muted-foreground uppercase">
                {{ $t('socialhq_plan.summary.events') }}
            </h3>
            <p class="text-sm" data-testid="plan-summary-events">
                {{
                    $t('socialhq_plan.summary.events_covered', {
                        covered: String(coveredEvents),
                        total: String(confirmedEvents.length),
                    })
                }}
            </p>
        </section>
    </div>
</template>
