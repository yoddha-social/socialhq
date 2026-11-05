<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import {
    categoryBadgeClass,
    categoryDotClass,
    NEEDS_CHECK_CLASS,
    type CalendarEventMarker,
} from '@/components/socialhq/plan/eventStyles';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { index as eventsIndex } from '@/routes/socialhq/plan/events';

/**
 * The festivals and holidays of one day of TryPost's publish calendar, read
 * from the page's `shqEvents` prop (BuildCalendarPageProps, PATCHES.md row 60).
 * One small chip with the first name and "+N" (only a dot on phones, so the
 * header keeps its height); the tooltip and the label list them all, and the
 * chip opens that month on the events page. Unconfirmed dates are dashed.
 */
const props = withDefaults(
    defineProps<{
        day: string;
        compact?: boolean;
    }>(),
    { compact: false },
);

const page = usePage();

const events = computed<CalendarEventMarker[]>(() => {
    const byDay = (page.props as Record<string, unknown>).shqEvents as
        | Record<string, CalendarEventMarker[]>
        | undefined;

    return byDay?.[props.day] ?? [];
});

const first = computed(() => events.value[0]);

const allConfirmed = computed(() =>
    events.value.every((event) => event.verified),
);

const href = computed(() =>
    eventsIndex.url({ query: { month: props.day.slice(0, 7) } }),
);
</script>

<template>
    <TooltipProvider v-if="first" :delay-duration="150">
        <Tooltip>
            <TooltipTrigger as-child>
                <Link
                    :href="href"
                    class="inline-flex max-w-full min-w-0 items-center gap-1 rounded-full text-[11px] leading-none font-medium transition-opacity hover:opacity-80 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                    :class="[
                        compact ? 'h-4 px-1' : 'h-5 px-1.5',
                        allConfirmed
                            ? categoryBadgeClass(first.category)
                            : NEEDS_CHECK_CLASS,
                    ]"
                    :aria-label="
                        $t('socialhq_events.markers.label', {
                            names: events
                                .map((event) =>
                                    event.verified
                                        ? event.name
                                        : $t(
                                              'socialhq_events.markers.needs_check',
                                              { name: event.name },
                                          ),
                                )
                                .join(', '),
                        })
                    "
                    :data-testid="`calendar-events-${day}`"
                >
                    <span
                        class="size-1.5 shrink-0 rounded-full"
                        :class="categoryDotClass(first.category)"
                        aria-hidden="true"
                    />
                    <span v-if="!compact" class="truncate">{{
                        first.name
                    }}</span>
                    <span v-if="!compact && events.length > 1" class="shrink-0"
                        >+{{ events.length - 1 }}</span
                    >
                </Link>
            </TooltipTrigger>
            <TooltipContent :data-testid="`calendar-events-tooltip-${day}`">
                <ul class="flex flex-col gap-0.5 text-left">
                    <li v-for="(event, index) in events" :key="index">
                        {{
                            event.verified
                                ? event.name
                                : $t('socialhq_events.markers.needs_check', {
                                      name: event.name,
                                  })
                        }}
                    </li>
                </ul>
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
