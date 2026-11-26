<script setup lang="ts">
import { type DateValue, endOfMonth, parseDate } from '@internationalized/date';
import { IconCalendar } from '@tabler/icons-vue';
import { type DateRange, RangeCalendarRoot } from 'reka-ui';
import { computed, ref, shallowRef, watch } from 'vue';

import { localizedDay } from '@/components/socialhq/localizedDay';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    RangeCalendarCell,
    RangeCalendarCellTrigger,
    RangeCalendarGrid,
    RangeCalendarGridBody,
    RangeCalendarGridHead,
    RangeCalendarGridRow,
    RangeCalendarHeadCell,
    RangeCalendarHeader,
    RangeCalendarHeading,
} from '@/components/ui/range-calendar';
import { useCalendarLocale } from '@/composables/useCalendarLocale';
import dayjs from '@/dayjs';
import { weekStartIndex } from '@/preferences';

type QuickChoice = {
    key: 'next-week' | 'first-week' | 'rest-of-month' | 'whole-month';
    label: string;
    from: string;
    to: string;
};

const props = defineProps<{
    /** The plan's month (YYYY-MM): the only days that can be picked. */
    month: string;
    /** Today in the person's time zone (YYYY-MM-DD). */
    today: string;
    /** The element that names the field (its label). */
    labelledby: string;
    /** The element that explains the field (its hint). */
    describedby?: string;
}>();

/** The first and the last day to plan (YYYY-MM-DD), as the dialog sends them. */
const from = defineModel<string>('from', { required: true });
const to = defineModel<string>('to', { required: true });

const open = ref(false);
const calendarLocale = useCalendarLocale();

/** How many days "Next 7 days" picks: the week the dialog opens with. */
const WEEK = 7;

const parse = (value: string): DateValue | undefined =>
    /^\d{4}-\d{2}-\d{2}$/.test(value) ? parseDate(value) : undefined;

const firstOfMonth = computed(() => parseDate(`${props.month}-01`));
const lastOfMonth = computed(() => endOfMonth(firstOfMonth.value));

/**
 * What the calendar shows: the dialog's days, or a first day alone while the
 * last is being picked. The dialog's days change only once both are picked.
 */
const picked = shallowRef<DateRange>({
    start: parse(from.value),
    end: parse(to.value),
});

const showDialogDays = (): void => {
    picked.value = { start: parse(from.value), end: parse(to.value) };
};

watch([from, to], showDialogDays);

/** Closed halfway through a pick: the dialog's days stay as they were. */
watch(open, (isOpen) => {
    if (!isOpen) {
        showDialogDays();
    }
});

const choose = (first: string, last: string): void => {
    from.value = first;
    to.value = last;
    open.value = false;
};

const onPicked = (range: DateRange | null): void => {
    picked.value = range ?? { start: undefined, end: undefined };

    if (range?.start && range.end) {
        choose(range.start.toString(), range.end.toString());
    }
};

const pickingLast = computed(
    () => picked.value.start !== undefined && picked.value.end === undefined,
);

const label = computed(() => {
    if (from.value === '' || to.value === '') {
        return '';
    }

    const first = localizedDay(from.value);

    if (from.value === to.value) {
        return first.format('ddd D MMM');
    }

    return `${first.format('ddd D')} – ${localizedDay(to.value).format('ddd D MMM')}`;
});

const dayCount = computed(() =>
    from.value === '' || to.value === ''
        ? 0
        : dayjs(to.value).diff(dayjs(from.value), 'day') + 1,
);

const addDays = (day: string, days: number): string =>
    dayjs(day).add(days, 'day').format('YYYY-MM-DD');

/**
 * One click for the usual ranges, inside the month. In this month the week
 * starts today ("Next 7 days"); in another it starts on the 1st ("First 7
 * days"). A choice that picks the same days as a wider one is left out.
 */
const quickChoices = computed<QuickChoice[]>(() => {
    const monthStart = firstOfMonth.value.toString();
    const monthEnd = lastOfMonth.value.toString();
    const thisMonth = props.today >= monthStart && props.today <= monthEnd;
    const weekStart = thisMonth ? props.today : monthStart;
    const weekEnd = addDays(weekStart, WEEK - 1);

    const choices: QuickChoice[] = [
        {
            key: thisMonth ? 'next-week' : 'first-week',
            label: thisMonth
                ? 'socialhq_plan.generate.range_choices.next_week'
                : 'socialhq_plan.generate.range_choices.first_week',
            from: weekStart,
            to: weekEnd < monthEnd ? weekEnd : monthEnd,
        },
        ...(thisMonth
            ? [
                  {
                      key: 'rest-of-month' as const,
                      label: 'socialhq_plan.generate.range_choices.rest_of_month',
                      from: props.today,
                      to: monthEnd,
                  },
              ]
            : []),
        {
            key: 'whole-month',
            label: 'socialhq_plan.generate.range_choices.whole_month',
            from: monthStart,
            to: monthEnd,
        },
    ];

    return choices.filter(
        (choice, index) =>
            !choices
                .slice(index + 1)
                .some(
                    (wider) =>
                        wider.from === choice.from && wider.to === choice.to,
                ),
    );
});

const isChosen = (choice: QuickChoice): boolean =>
    choice.from === from.value && choice.to === to.value;

const chipClass = (active: boolean): string[] => [
    'inline-flex h-7 items-center rounded-full border px-3 text-xs font-medium transition-control outline-none focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring focus-visible:outline-solid',
    active
        ? 'border-primary bg-primary-selected text-primary-text'
        : 'border-border-strong text-muted-foreground hover:bg-accent hover:text-foreground',
];

const panel = ref<HTMLElement>();

/**
 * Opening puts the focus on the first picked day (the calendar's own focus),
 * not on the first button of the popover, which is a greyed-out day of the
 * month before.
 */
const focusPickedDay = (event: Event): void => {
    event.preventDefault();
    panel.value
        ?.querySelector<HTMLElement>(
            '[data-reka-calendar-cell-trigger][tabindex="0"]',
        )
        ?.focus();
};

/** Days of the months around the plan's: shown greyed out, never picked or tabbed to. */
const outsideMonth = (day: DateValue, month: DateValue): boolean =>
    day.month !== month.month;

/**
 * Shaded between the first day and the pointer while the last day is picked,
 * like the picked range itself.
 */
const CELL_CLASS =
    'w-9 [&:has([data-highlighted])]:bg-primary-subtle first:[&:has([data-highlighted])]:rounded-l-md last:[&:has([data-highlighted])]:rounded-r-md [&:has([data-highlighted-start])]:rounded-l-md [&:has([data-highlighted-end])]:rounded-r-md';

/**
 * The shared day button, with the range's ends in the dialog's strong
 * purple, today marked by a dot (in the person's time zone, not the
 * browser's) and the day under the pointer ringed while the last is picked.
 */
const DAY_CLASS = [
    'relative size-9',
    '[&[data-today]:not([data-selected])]:bg-transparent [&[data-today]:not([data-selected])]:font-medium',
    'data-[plan-today]:font-semibold',
    'data-[selection-start]:bg-primary-strong data-[selection-start]:text-primary-strong-foreground data-[selection-start]:hover:bg-primary-strong data-[selection-start]:hover:text-primary-strong-foreground data-[selection-start]:focus:bg-primary-strong data-[selection-start]:focus:text-primary-strong-foreground',
    'data-[selection-end]:bg-primary-strong data-[selection-end]:text-primary-strong-foreground data-[selection-end]:hover:bg-primary-strong data-[selection-end]:hover:text-primary-strong-foreground data-[selection-end]:focus:bg-primary-strong data-[selection-end]:focus:text-primary-strong-foreground',
    '[&[data-highlighted-start]:not([data-selected])]:ring-2 [&[data-highlighted-end]:not([data-selected])]:ring-2 ring-inset ring-primary-strong',
    'data-[disabled]:cursor-not-allowed data-[disabled]:hover:bg-transparent',
].join(' ');
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <button
                id="plan-generate-range-trigger"
                type="button"
                class="flex h-9 w-full min-w-0 items-center gap-2 rounded-md border border-input bg-card px-3 text-start text-sm text-foreground shadow-xs transition-[color,box-shadow] outline-none focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring focus-visible:outline-solid data-[state=open]:border-ring dark:bg-input/30"
                :aria-labelledby="`${labelledby} plan-generate-range-value`"
                :aria-describedby="describedby"
                data-testid="plan-generate-range"
            >
                <IconCalendar
                    class="size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <span
                    id="plan-generate-range-value"
                    class="truncate font-medium capitalize"
                    data-testid="plan-generate-range-days"
                    >{{ label }}</span
                >
                <span
                    v-if="dayCount > 0"
                    class="ms-auto shrink-0 text-xs text-muted-foreground tabular-nums"
                    data-testid="plan-generate-range-count"
                    >{{
                        $tChoice(
                            'socialhq_plan.generate.range_days',
                            dayCount,
                            {
                                count: String(dayCount),
                            },
                        )
                    }}</span
                >
            </button>
        </PopoverTrigger>
        <PopoverContent
            align="start"
            class="w-auto p-0"
            data-testid="plan-generate-range-calendar"
            @open-auto-focus="focusPickedDay"
        >
            <div ref="panel">
                <RangeCalendarRoot
                    v-slot="{ grid, weekDays }"
                    :model-value="picked"
                    :default-placeholder="picked.start ?? firstOfMonth"
                    :min-value="firstOfMonth"
                    :max-value="lastOfMonth"
                    :locale="calendarLocale"
                    :week-starts-on="weekStartIndex()"
                    :calendar-label="$t('socialhq_plan.generate.range')"
                    class="p-3"
                    prevent-deselect
                    initial-focus
                    data-slot="range-calendar"
                    @update:model-value="onPicked"
                >
                    <RangeCalendarHeader>
                        <RangeCalendarHeading />
                    </RangeCalendarHeader>
                    <RangeCalendarGrid
                        v-for="shown in grid"
                        :key="shown.value.toString()"
                        class="mt-3 w-auto"
                    >
                        <RangeCalendarGridHead>
                            <RangeCalendarGridRow>
                                <RangeCalendarHeadCell
                                    v-for="weekDay in weekDays"
                                    :key="weekDay"
                                    class="w-9"
                                >
                                    {{ weekDay }}
                                </RangeCalendarHeadCell>
                            </RangeCalendarGridRow>
                        </RangeCalendarGridHead>
                        <RangeCalendarGridBody>
                            <RangeCalendarGridRow
                                v-for="(week, index) in shown.rows"
                                :key="`week-${index}`"
                                class="mt-1"
                            >
                                <RangeCalendarCell
                                    v-for="day in week"
                                    :key="day.toString()"
                                    :date="day"
                                    :class="CELL_CLASS"
                                >
                                    <RangeCalendarCellTrigger
                                        :day="day"
                                        :month="shown.value"
                                        :class="DAY_CLASS"
                                        v-bind="
                                            outsideMonth(day, shown.value)
                                                ? { tabindex: -1 }
                                                : {}
                                        "
                                        :data-plan-today="
                                            day.toString() === today
                                                ? ''
                                                : undefined
                                        "
                                    >
                                        {{
                                            day.day.toLocaleString(
                                                calendarLocale,
                                            )
                                        }}
                                        <span
                                            v-if="day.toString() === today"
                                            class="absolute bottom-1 left-1/2 size-1 -translate-x-1/2 rounded-full bg-current"
                                            aria-hidden="true"
                                        />
                                    </RangeCalendarCellTrigger>
                                </RangeCalendarCell>
                            </RangeCalendarGridRow>
                        </RangeCalendarGridBody>
                    </RangeCalendarGrid>
                </RangeCalendarRoot>
            </div>
            <div
                class="flex w-0 min-w-full flex-col gap-2 border-t border-border px-3 py-3"
            >
                <p
                    class="text-xs text-muted-foreground"
                    aria-live="polite"
                    data-testid="plan-generate-range-status"
                >
                    {{
                        pickingLast
                            ? $t('socialhq_plan.generate.range_pick_last')
                            : $t('socialhq_plan.generate.range_pick')
                    }}
                </p>
                <div
                    class="flex flex-wrap gap-1.5"
                    role="group"
                    :aria-label="
                        $t('socialhq_plan.generate.range_choices.label')
                    "
                >
                    <button
                        v-for="choice in quickChoices"
                        :key="choice.key"
                        type="button"
                        :class="chipClass(isChosen(choice))"
                        :aria-pressed="isChosen(choice)"
                        :data-testid="`plan-generate-range-${choice.key}`"
                        @click="choose(choice.from, choice.to)"
                    >
                        {{ $t(choice.label) }}
                    </button>
                </div>
            </div>
        </PopoverContent>
    </Popover>
</template>
