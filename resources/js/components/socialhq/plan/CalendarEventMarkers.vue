<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { useResizeObserver } from '@vueuse/core';
import { trans } from 'laravel-vue-i18n';
import { computed, nextTick, ref, watch } from 'vue';

import {
    categoryBadgeClass,
    categoryDotClass,
    countryNames,
    NEEDS_CHECK_CLASS,
    type CalendarEventMarker,
} from '@/components/socialhq/plan/eventStyles';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { activeLocale } from '@/language';
import { index as eventsIndex } from '@/routes/socialhq/plan/events';

/**
 * The festivals and holidays of one day of TryPost's publish calendar, read
 * from the page's `shqEvents` prop (BuildCalendarPageProps, PATCHES.md row 60).
 * One small chip with the first name and "+N"; the tooltip and the label list
 * them all, and the chip opens that month on the events page. Unconfirmed
 * dates are dashed. The same holiday in several countries is one event,
 * its countries named in the tooltip and the label.
 *
 * When the cell is too narrow to show a readable name (or on phones, through
 * `compact`), the chip becomes a coloured dot with the number of events, its
 * ring dashed when any is unconfirmed. The width is measured here, against the
 * day cell, so TryPost's calendar files don't change for it.
 *
 * `wrapsUnder` is for the week's column heads, whose items wrap from `md` up:
 * the chip stays beside the date while it gets at least 2rem there, and moves
 * to its own line under the date otherwise, so it never runs into the next
 * column (UX-03).
 */
const props = withDefaults(
    defineProps<{
        day: string;
        compact?: boolean;
        wrapsUnder?: boolean;
    }>(),
    { compact: false, wrapsUnder: false },
);

/** Below this many pixels of visible name, the chip turns into a dot. */
const MIN_NAME_WIDTH = 44;

/** How much wider the cell must get before the name is tried again. */
const REGROW_MARGIN = 24;

const page = usePage();

const events = computed<CalendarEventMarker[]>(() => {
    return page.props.shqEvents?.[props.day] ?? [];
});

const first = computed(() => events.value[0]);

const allConfirmed = computed(() =>
    events.value.every((event) => event.verified),
);

const href = computed(() =>
    eventsIndex.url({ query: { month: props.day.slice(0, 7) } }),
);

const dotEl = ref<HTMLElement | null>(null);
const nameEl = ref<HTMLElement | null>(null);

/** The cell width at which the name stopped fitting, or null while it fits. */
const narrowAt = ref<number | null>(null);

const asDot = computed(() => props.compact || narrowAt.value !== null);

const cell = computed<HTMLElement | null>(() => {
    const link = dotEl.value?.parentElement;

    return (
        (link?.parentElement?.closest(
            '[data-testid^="calendar-day-"], [data-testid^="calendar-column-"]',
        ) as HTMLElement | null) ??
        link?.parentElement ??
        null
    );
});

const measure = (): void => {
    const width = cell.value?.clientWidth ?? 0;

    if (narrowAt.value !== null) {
        if (width >= narrowAt.value + REGROW_MARGIN) {
            narrowAt.value = null;
            void nextTick(measure);
        }

        return;
    }

    const name = nameEl.value;

    if (
        name &&
        name.scrollWidth > name.clientWidth &&
        name.clientWidth < MIN_NAME_WIDTH
    ) {
        narrowAt.value = width;
    }
};

useResizeObserver(cell, measure);

watch(events, () => {
    narrowAt.value = null;
    void nextTick(measure);
});

/** An event's name, with its countries when it is the holiday of several. */
const nameOf = (event: CalendarEventMarker): string =>
    event.countries.length > 1
        ? `${event.name} (${countryNames(event.countries, activeLocale.value)})`
        : event.name;

const label = computed(() =>
    events.value
        .map((event) =>
            event.verified
                ? nameOf(event)
                : trans('socialhq_events.markers.needs_check', {
                      name: nameOf(event),
                  }),
        )
        .join(', '),
);
</script>

<template>
    <TooltipProvider v-if="first" :delay-duration="150">
        <Tooltip>
            <TooltipTrigger as-child>
                <Link
                    :href="href"
                    class="inline-flex items-center gap-1 rounded-full text-[11px] leading-none font-medium transition-opacity hover:opacity-80 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                    :class="[
                        compact ? 'h-4 px-1' : 'h-5 px-1.5',
                        wrapsUnder && !compact
                            ? 'max-w-max min-w-[min(100%,2rem)] flex-1'
                            : 'max-w-full min-w-0',
                        asDot
                            ? 'shrink-0 text-muted-foreground tabular-nums'
                            : allConfirmed
                              ? categoryBadgeClass(first.category)
                              : NEEDS_CHECK_CLASS,
                    ]"
                    :aria-label="
                        $t('socialhq_events.markers.label', { names: label })
                    "
                    :data-narrow="asDot || undefined"
                    :data-testid="`calendar-events-${day}`"
                >
                    <span
                        ref="dotEl"
                        class="shrink-0 rounded-full"
                        :class="[
                            categoryDotClass(first.category),
                            asDot ? 'size-2' : 'size-1.5',
                            asDot && !allConfirmed
                                ? 'outline-1 outline-offset-1 outline-amber-500 outline-dashed'
                                : '',
                        ]"
                        aria-hidden="true"
                    />
                    <span v-if="asDot" class="shrink-0">{{
                        events.length
                    }}</span>
                    <template v-else>
                        <span ref="nameEl" class="truncate">{{
                            first.name
                        }}</span>
                        <span v-if="events.length > 1" class="shrink-0"
                            >+{{ events.length - 1 }}</span
                        >
                    </template>
                </Link>
            </TooltipTrigger>
            <TooltipContent :data-testid="`calendar-events-tooltip-${day}`">
                <ul class="flex flex-col gap-0.5 text-left">
                    <li v-for="(event, index) in events" :key="index">
                        {{
                            event.verified
                                ? nameOf(event)
                                : $t('socialhq_events.markers.needs_check', {
                                      name: nameOf(event),
                                  })
                        }}
                    </li>
                </ul>
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
