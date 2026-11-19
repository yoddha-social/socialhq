<script setup lang="ts">
import { IconPlus } from '@tabler/icons-vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

import CalendarPostChip from '@/components/publish/CalendarPostChip.vue';
import CalendarSlotChip from '@/components/publish/CalendarSlotChip.vue';
import CalendarEventMarkers from '@/components/socialhq/plan/CalendarEventMarkers.vue';
import {
    calendarTargetKey,
    useCalendarDragContext,
} from '@/composables/calendar/useCalendarDrag';
import { useBelowBreakpoint } from '@/composables/useBreakpoint';
import date from '@/date';
import dayjs from '@/dayjs';
import { wallClockIn, type CalendarDropTarget } from '@/lib/calendarDrop';
import { calendarItems, type CalendarItem } from '@/lib/calendarItems';
import type {
    CalendarPost,
    CalendarSlot,
    PublishSocialAccount,
} from '@/types/publish';

const props = defineProps<{
    days: dayjs.Dayjs[];
    posts: Record<string, CalendarPost[]>;
    slots: Record<string, CalendarSlot[]>;
    channels: Record<string, PublishSocialAccount>;
    timezone: string;
    now: dayjs.Dayjs;
    canCreatePost: boolean;
}>();

const emit = defineEmits<{
    compose: [at: string];
}>();

const ROW_HEIGHT = 106;

const compact = useBelowBreakpoint('md');
const HOURS = Array.from({ length: 24 }, (_, hour) => hour);

const scroller = ref<HTMLElement | null>(null);

const nowInZone = computed(() => props.now.tz(props.timezone));
const todayKey = computed(() => nowInZone.value.format('YYYY-MM-DD'));

const columns = computed(() =>
    props.days.map((day) => {
        const key = day.format('YYYY-MM-DD');
        const byHour = new Map<number, CalendarItem[]>();

        for (const item of calendarItems(
            props.posts[key] ?? [],
            props.slots[key] ?? [],
        )) {
            const hour = dayjs.utc(item.at).tz(props.timezone).hour();
            byHour.set(hour, [...(byHour.get(hour) ?? []), item]);
        }

        return { day, key, byHour, isToday: key === todayKey.value };
    }),
);

const MAX_CHIPS = 3;
const RICH_CHIPS = 2;

const expandedHours = ref<string[]>([]);

const isExpanded = (key: string, hour: number): boolean =>
    expandedHours.value.includes(`${key}-${hour}`);

const expandHour = (key: string, hour: number): void => {
    expandedHours.value = [...expandedHours.value, `${key}-${hour}`];
};

const isStacked = (
    column: { byHour: Map<number, CalendarItem[]> },
    hour: number,
): boolean => (column.byHour.get(hour)?.length ?? 0) > 1;

const iconOnly = computed(() => compact.value && props.days.length === 7);

const chipClass = (
    column: { byHour: Map<number, CalendarItem[]> },
    hour: number,
): string => {
    if (iconOnly.value) {
        return '';
    }

    return isStacked(column, hour) ? 'w-full shrink-0' : 'flex-1';
};

const visibleItems = (
    key: string,
    hour: number,
    items: CalendarItem[],
): CalendarItem[] =>
    items.length > MAX_CHIPS && !isExpanded(key, hour)
        ? items.slice(0, MAX_CHIPS - 1)
        : items;

const hourLabel = (hour: number): string => String(hour).padStart(2, '0');

const hourSlots = computed(() =>
    props.days.map((day) =>
        HOURS.map((hour) => {
            const start = dayjs.tz(
                `${day.format('YYYY-MM-DD')} ${hourLabel(hour)}:00`,
                props.timezone,
            );
            const target: CalendarDropTarget | null =
                wallClockIn(start, props.timezone).hour === hour
                    ? { kind: 'hour', at: start.utc().format() }
                    : null;

            return {
                start,
                target,
                key: target ? calendarTargetKey(target) : null,
            };
        }),
    ),
);

const pastHours = computed(() =>
    hourSlots.value.map((hours) =>
        hours.map(({ start }) => start.isBefore(props.now)),
    ),
);

const isPastSlot = (column: number, hour: number): boolean =>
    pastHours.value[column][hour];

/**
 * Social HQ (PATCHES.md rows 222 and 227): where "now" falls, in pixels from
 * midnight, the column it falls in, and the grid's columns. From `md` up the
 * grid has a gutter with the hours; on a phone the hours stay inside the first
 * column.
 */
const nowOffset = computed(
    () => (nowInZone.value.hour() + nowInZone.value.minute() / 60) * ROW_HEIGHT,
);

const todayIndex = computed(() =>
    columns.value.findIndex((column) => column.isToday),
);

const nowLabel = computed(() =>
    date.formatTimeInTimezone(props.now.utc().format(), props.timezone),
);

const GUTTER_WIDTH = 64;

const gridColumns = computed(() =>
    compact.value
        ? `repeat(${props.days.length}, minmax(0, 1fr))`
        : `${GUTTER_WIDTH}px repeat(${props.days.length}, minmax(0, 1fr))`,
);

/** A chip shows the post's first line while its hour holds at most two. */
const isRich = (
    column: { byHour: Map<number, CalendarItem[]> },
    hour: number,
): boolean =>
    !iconOnly.value && (column.byHour.get(hour)?.length ?? 0) <= RICH_CHIPS;

const { overTarget, draggingPostId, vCalendarTarget } =
    useCalendarDragContext();

const isHourOver = (column: number, hour: number): boolean =>
    hourSlots.value[column][hour].key !== null &&
    overTarget.value === hourSlots.value[column][hour].key;

const isDimmed = (column: number, hour: number, item: CalendarItem): boolean =>
    isHourOver(column, hour) && item.post?.id !== draggingPostId.value;

const itemClass = (
    column: { byHour: Map<number, CalendarItem[]> },
    index: number,
    hour: number,
    item: CalendarItem,
): string[] => [
    chipClass(column, hour),
    isDimmed(index, hour, item) ? 'opacity-50' : '',
];

const compose = (column: number, hour: number): void => {
    emit('compose', hourSlots.value[column][hour].start.utc().format());
};

const scrollToCurrentHour = (): void => {
    if (scroller.value) {
        scroller.value.scrollTop = nowInZone.value.hour() * ROW_HEIGHT;
    }
};

onMounted(scrollToCurrentHour);

watch(
    () => props.days[0]?.format('YYYY-MM-DD'),
    () => {
        expandedHours.value = [];
        nextTick(scrollToCurrentHour);
    },
);
</script>

<template>
    <div
        ref="scroller"
        class="flex-1 overflow-y-auto overscroll-contain"
        data-calendar-scroller
        data-testid="calendar-time-grid"
    >
        <div
            class="sticky top-0 z-20 grid border-b border-border-strong bg-card"
            :style="{ gridTemplateColumns: gridColumns }"
        >
            <div v-if="!compact" aria-hidden="true" />
            <div
                v-for="(column, index) in columns"
                :key="column.key"
                class="-mb-px flex flex-col items-center justify-center border-b p-1.5 text-sm font-medium capitalize md:flex-row md:gap-3 md:p-2.5"
                :class="[
                    index > 0 || !compact
                        ? 'border-l border-l-border-strong'
                        : '',
                    column.isToday
                        ? 'border-b-primary-text text-primary-text'
                        : 'border-b-transparent text-muted-foreground',
                ]"
                :data-testid="`calendar-column-${column.key}`"
            >
                <span>{{ column.day.format(compact ? 'ddd' : 'dddd') }}</span>
                <span class="inline-flex max-w-full min-w-0 items-center gap-1">
                    {{ column.day.format('D') }}
                    <CalendarEventMarkers :day="column.key" :compact="compact" />
                </span>
            </div>
        </div>

        <div
            class="relative grid"
            :style="{ gridTemplateColumns: gridColumns }"
        >
            <div v-if="!compact" aria-hidden="true">
                <div
                    v-for="hour in HOURS"
                    :key="hour"
                    class="relative"
                    :style="{ height: `${ROW_HEIGHT}px` }"
                >
                    <span
                        v-if="hour > 0"
                        class="absolute end-2 top-0 -translate-y-1/2 text-[11px] leading-none whitespace-nowrap text-subtle-foreground"
                        >{{ date.formatHourOption(hourLabel(hour)) }}</span
                    >
                </div>
            </div>
            <div
                v-for="(column, index) in columns"
                :key="column.key"
                class="relative"
            >
                <div
                    v-if="column.isToday && compact"
                    class="pointer-events-none absolute inset-x-0 z-10 flex h-0 items-center"
                    :style="{ top: `${nowOffset}px` }"
                    aria-hidden="true"
                    data-testid="calendar-now-line"
                >
                    <span
                        class="-ms-1 size-2 shrink-0 rounded-full bg-primary-strong"
                    />
                    <span class="h-[2px] flex-1 bg-primary-strong" />
                </div>
                <div
                    v-if="column.isToday && !compact"
                    class="pointer-events-none absolute inset-x-0 z-10 h-0"
                    :style="{ top: `${nowOffset}px` }"
                    aria-hidden="true"
                    data-testid="calendar-now-line"
                >
                    <span
                        class="absolute inset-x-0 top-0 h-[2px] -translate-y-1/2 bg-primary-strong"
                    />
                </div>
                <div
                    v-for="hour in HOURS"
                    :key="hour"
                    v-calendar-target="hourSlots[index][hour].target"
                    class="group relative flex items-start justify-between gap-1 px-1 py-1.5 md:px-1.5"
                    :class="{
                        'shq-hatched bg-muted/40':
                            isPastSlot(index, hour) && !isHourOver(index, hour),
                        'bg-primary/15': isHourOver(index, hour),
                        'border-t border-border':
                            hour > 0 &&
                            (!compact || index > 0 || hour % 2 === 1),
                        'border-l border-border': index > 0 || !compact,
                    }"
                    :style="{ height: `${ROW_HEIGHT}px` }"
                    :data-drop-over="isHourOver(index, hour) || undefined"
                    :data-testid="`calendar-slot-${column.key}-${hourLabel(hour)}`"
                >
                    <div
                        v-if="
                            compact && index === 0 && hour > 0 && hour % 2 === 0
                        "
                        class="pointer-events-none absolute inset-x-0 top-0 z-10 flex h-px items-center"
                        aria-hidden="true"
                    >
                        <span
                            class="px-2 text-xs leading-[15px] font-medium whitespace-nowrap text-muted-foreground max-sm:px-1"
                            >{{ date.formatHourOption(hourLabel(hour)) }}</span
                        >
                        <span class="h-px flex-1 bg-border-strong" />
                    </div>

                    <div
                        class="flex min-w-0 flex-1"
                        :class="
                            isStacked(column, hour)
                                ? [
                                      'flex-col gap-0.5',
                                      isExpanded(column.key, hour)
                                          ? 'max-h-full overflow-y-auto overscroll-contain'
                                          : '',
                                  ]
                                : 'flex-nowrap'
                        "
                    >
                        <template
                            v-for="item in visibleItems(
                                column.key,
                                hour,
                                column.byHour.get(hour) ?? [],
                            )"
                            :key="item.key"
                        >
                            <CalendarPostChip
                                v-if="item.post"
                                :post="item.post"
                                :timezone="timezone"
                                :compact="iconOnly"
                                :rich="isRich(column, hour)"
                                :class="itemClass(column, index, hour, item)"
                            />
                            <CalendarSlotChip
                                v-else-if="item.slot"
                                :posting-slot="item.slot"
                                :channel="channels[item.slot.channel_id] ?? null"
                                :timezone="timezone"
                                :can-create-post="canCreatePost"
                                :compact="iconOnly"
                                :class="itemClass(column, index, hour, item)"
                            />
                        </template>
                        <button
                            v-if="
                                !isExpanded(column.key, hour) &&
                                (column.byHour.get(hour)?.length ?? 0) >
                                    MAX_CHIPS
                            "
                            type="button"
                            class="flex h-7 w-full shrink-0 items-center rounded-lg px-1.5 text-xs font-medium text-muted-foreground transition-control hover:bg-secondary hover:text-foreground"
                            :data-testid="`calendar-more-${column.key}-${hourLabel(hour)}`"
                            @click="expandHour(column.key, hour)"
                        >
                            +{{
                                (column.byHour.get(hour)?.length ?? 0) -
                                MAX_CHIPS +
                                1
                            }}
                        </button>
                    </div>

                    <button
                        v-if="
                            canCreatePost &&
                            hourSlots[index][hour].target &&
                            !isPastSlot(index, hour) &&
                            !draggingPostId
                        "
                        type="button"
                        :aria-label="$t('calendar.new_post')"
                        class="flex size-7 shrink-0 items-center justify-center rounded-lg border border-dashed border-border-strong text-muted-foreground opacity-0 transition-opacity duration-100 ease-in-out group-hover:opacity-100 hover:bg-accent hover:text-accent-foreground focus-visible:opacity-100 max-md:hidden [@media(hover:none)]:opacity-100"
                        :data-testid="`calendar-add-${column.key}-${hourLabel(hour)}`"
                        @click="compose(index, hour)"
                    >
                        <IconPlus class="size-3.5" />
                    </button>
                </div>
            </div>

            <!-- Social HQ: the time now, in the gutter, and a faint line across the days (PATCHES.md row 227) -->
            <div
                v-if="todayIndex >= 0 && !compact"
                class="pointer-events-none absolute inset-x-0 z-10 h-0"
                :style="{ top: `${nowOffset}px` }"
                aria-hidden="true"
            >
                <span
                    class="absolute inset-x-0 top-0 h-px bg-primary-strong/30"
                    :style="{ insetInlineStart: `${GUTTER_WIDTH}px` }"
                />
                <span
                    class="absolute start-1 top-0 -translate-y-1/2 rounded-md bg-primary-strong px-1.5 py-0.5 text-[10.5px] leading-none font-semibold whitespace-nowrap text-primary-strong-foreground"
                    data-testid="calendar-now-time"
                    >{{ nowLabel }}</span
                >
            </div>
        </div>
    </div>
</template>

<style scoped>
.shq-hatched {
    background-image: repeating-linear-gradient(
        135deg,
        transparent 0 6px,
        color-mix(in srgb, var(--foreground) 5%, transparent) 6px 7px
    );
}
</style>
