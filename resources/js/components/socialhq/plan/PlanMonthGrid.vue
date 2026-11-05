<script setup lang="ts">
import { IconPlus } from '@tabler/icons-vue';
import { computed } from 'vue';

import PlanEventChip from '@/components/socialhq/plan/PlanEventChip.vue';
import PlanItemCard from '@/components/socialhq/plan/PlanItemCard.vue';
import type {
    PlanEvent,
    PlanItem,
    PlanLookups,
} from '@/components/socialhq/plan/types';
import { useAtLeastBreakpoint } from '@/composables/useBreakpoint';
import dayjs from '@/dayjs';
import { activeLocale } from '@/language';

const props = defineProps<{
    month: string;
    today: string;
    items: PlanItem[];
    events: PlanEvent[];
    lookups: PlanLookups;
    canWork: boolean;
    busyItemId: number | null;
}>();

const emit = defineEmits<{
    open: [item: PlanItem];
    accept: [item: PlanItem];
    drop: [item: PlanItem];
    add: [day: string];
}>();

const isDesktop = useAtLeastBreakpoint('md');

const localized = (value: dayjs.ConfigType): dayjs.Dayjs =>
    dayjs(value).locale(activeLocale.value.toLowerCase());

const monthStart = computed(() => localized(`${props.month}-01`));

const dayKey = (day: dayjs.Dayjs): string => day.format('YYYY-MM-DD');

/** Week rows covering the month, starting on the user's first day of the week. */
const weeks = computed<dayjs.Dayjs[][]>(() => {
    const start = monthStart.value.startOf('week');
    const end = monthStart.value.endOf('month').endOf('week');
    const rows: dayjs.Dayjs[][] = [];
    let cursor = start;

    while (cursor.isBefore(end) || cursor.isSame(end, 'day')) {
        if (rows.length === 0 || rows[rows.length - 1].length === 7) {
            rows.push([]);
        }

        rows[rows.length - 1].push(cursor);
        cursor = cursor.add(1, 'day');
    }

    return rows;
});

const weekdayNames = computed(() =>
    (weeks.value[0] ?? []).map((day) => day.format('ddd')),
);

const itemsByDay = computed<Record<string, PlanItem[]>>(() => {
    const grouped: Record<string, PlanItem[]> = {};

    // Dropped items go last in their day, so the live plan reads first.
    [...props.items]
        .sort(
            (a, b) =>
                Number(a.status === 'dropped') - Number(b.status === 'dropped'),
        )
        .forEach((item) => {
            (grouped[item.scheduled_on] ??= []).push(item);
        });

    return grouped;
});

const eventsByDay = computed<Record<string, PlanEvent[]>>(() => {
    const grouped: Record<string, PlanEvent[]> = {};

    props.events.forEach((event) => {
        let cursor = dayjs(event.starts_on);
        const end = dayjs(event.ends_on);

        while (cursor.isBefore(end) || cursor.isSame(end, 'day')) {
            (grouped[cursor.format('YYYY-MM-DD')] ??= []).push(event);
            cursor = cursor.add(1, 'day');
        }
    });

    return grouped;
});

const inMonth = (day: dayjs.Dayjs): boolean =>
    day.format('YYYY-MM') === props.month;

const isToday = (day: dayjs.Dayjs): boolean => dayKey(day) === props.today;

const isPast = (day: dayjs.Dayjs): boolean => dayKey(day) < props.today;

const itemsOn = (day: dayjs.Dayjs): PlanItem[] =>
    inMonth(day) ? (itemsByDay.value[dayKey(day)] ?? []) : [];

const eventsOn = (day: dayjs.Dayjs): PlanEvent[] =>
    inMonth(day) ? (eventsByDay.value[dayKey(day)] ?? []) : [];

/** Phones get a list of the days that have something, plus today. */
const listDays = computed(() =>
    weeks.value
        .flat()
        .filter(
            (day) =>
                inMonth(day) &&
                (itemsOn(day).length > 0 ||
                    eventsOn(day).length > 0 ||
                    isToday(day)),
        ),
);

const addOn = (day: dayjs.Dayjs): void => emit('add', dayKey(day));
const openItem = (item: PlanItem): void => emit('open', item);
const acceptItem = (item: PlanItem): void => emit('accept', item);
const dropItem = (item: PlanItem): void => emit('drop', item);
</script>

<template>
    <div
        v-if="isDesktop"
        class="flex min-h-0 flex-1 flex-col overflow-y-auto overscroll-contain"
        data-testid="plan-month-grid"
    >
        <div
            class="sticky top-0 z-10 grid grid-cols-7 border-b border-border-strong bg-card"
        >
            <div
                v-for="(name, index) in weekdayNames"
                :key="name"
                class="border-border-strong p-2 text-center text-xs font-medium text-muted-foreground capitalize"
                :class="{ 'border-l': index > 0 }"
            >
                {{ name }}
            </div>
        </div>

        <div class="flex flex-1 flex-col">
            <div
                v-for="(week, weekIndex) in weeks"
                :key="weekIndex"
                class="grid min-h-36 flex-1 grid-cols-7 border-border-strong"
                :class="{ 'border-t': weekIndex > 0 }"
            >
                <div
                    v-for="(day, index) in week"
                    :key="dayKey(day)"
                    class="group flex min-w-0 flex-col gap-1 border-border-strong p-1.5"
                    :class="{
                        'border-l': index > 0,
                        'bg-accent/60': !inMonth(day),
                        'bg-accent/30': inMonth(day) && isPast(day),
                    }"
                    :data-testid="`plan-day-${dayKey(day)}`"
                >
                    <div class="flex h-6 items-center justify-between">
                        <span
                            class="inline-flex size-6 items-center justify-center rounded-full text-xs font-medium"
                            :class="{
                                'bg-primary text-primary-foreground':
                                    isToday(day),
                                'text-subtle-foreground': !inMonth(day),
                                'text-muted-foreground':
                                    !isToday(day) && inMonth(day),
                            }"
                        >
                            {{ day.format('D') }}
                        </span>
                        <button
                            v-if="canWork && inMonth(day)"
                            type="button"
                            :aria-label="
                                $t('socialhq_plan.grid.add_on', {
                                    day: day.format('D MMMM'),
                                })
                            "
                            class="flex size-6 items-center justify-center rounded-md border border-border-strong bg-card text-muted-foreground opacity-0 transition-opacity duration-100 group-hover:opacity-100 hover:text-foreground focus-visible:opacity-100 [@media(hover:none)]:opacity-100"
                            :data-testid="`plan-add-${dayKey(day)}`"
                            @click="addOn(day)"
                        >
                            <IconPlus class="size-3.5" />
                        </button>
                    </div>

                    <PlanEventChip
                        v-for="event in eventsOn(day)"
                        :key="`event-${event.id}`"
                        :event="event"
                    />
                    <PlanItemCard
                        v-for="item in itemsOn(day)"
                        :key="item.id"
                        :item="item"
                        :lookups="lookups"
                        :can-work="canWork"
                        :busy="busyItemId === item.id"
                        @open="openItem"
                        @accept="acceptItem"
                        @drop="dropItem"
                    />
                </div>
            </div>
        </div>
    </div>

    <div
        v-else
        class="flex min-h-0 flex-1 flex-col divide-y divide-border overflow-y-auto overscroll-contain"
        data-testid="plan-day-list"
    >
        <section
            v-for="day in listDays"
            :key="dayKey(day)"
            class="flex flex-col gap-2 p-3"
            :data-testid="`plan-day-${dayKey(day)}`"
        >
            <header class="flex items-center justify-between gap-2">
                <h3
                    class="text-sm font-medium capitalize"
                    :class="
                        isToday(day) ? 'text-primary-text' : 'text-foreground'
                    "
                >
                    {{ day.format('dddd, D MMMM') }}
                </h3>
                <button
                    v-if="canWork"
                    type="button"
                    :aria-label="
                        $t('socialhq_plan.grid.add_on', {
                            day: day.format('D MMMM'),
                        })
                    "
                    class="flex size-7 items-center justify-center rounded-md border border-border-strong bg-card text-muted-foreground hover:text-foreground"
                    :data-testid="`plan-add-${dayKey(day)}`"
                    @click="addOn(day)"
                >
                    <IconPlus class="size-4" />
                </button>
            </header>
            <PlanEventChip
                v-for="event in eventsOn(day)"
                :key="`event-${event.id}`"
                :event="event"
            />
            <PlanItemCard
                v-for="item in itemsOn(day)"
                :key="item.id"
                :item="item"
                :lookups="lookups"
                :can-work="canWork"
                :busy="busyItemId === item.id"
                @open="openItem"
                @accept="acceptItem"
                @drop="dropItem"
            />
            <p
                v-if="itemsOn(day).length === 0 && eventsOn(day).length === 0"
                class="text-sm text-muted-foreground"
            >
                {{ $t('socialhq_plan.grid.nothing_today') }}
            </p>
        </section>
    </div>
</template>
