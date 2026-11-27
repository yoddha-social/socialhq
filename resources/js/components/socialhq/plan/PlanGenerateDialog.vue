<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import {
    IconCheck,
    IconPlus,
    IconSparkles,
    IconTrash,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import { channelLabel } from '@/components/socialhq/channelLabel';
import { localizedDay } from '@/components/socialhq/localizedDay';
import MustHaveBadge from '@/components/socialhq/plan/MustHaveBadge.vue';
import PlanRangePicker from '@/components/socialhq/plan/PlanRangePicker.vue';
import type {
    PlanAudience,
    PlanChannel,
    PlanGenerateOptions,
    PlanMustHave,
} from '@/components/socialhq/plan/types';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import dayjs from '@/dayjs';
import { orderedWeekdays } from '@/preferences';
import { generate } from '@/routes/socialhq/plan';
import { index as eventsIndex } from '@/routes/socialhq/plan/events';

type SourceOption = 'old_calendars' | 'past_posts' | 'audience_events';

/** A must-have as the form holds it: '' is "any" channel or format. */
type MustHaveRow = {
    date: string;
    channel: string;
    content_type: string;
    focus: string;
};

const props = defineProps<{
    month: string;
    monthLabel: string;
    today: string;
    hasSuggestions: boolean;
    hourlyLimit: number;
    defaults: PlanGenerateOptions;
    audience: PlanAudience;
    /** Dates of the month that still need a check: the AI leaves them out (QA 2026-11-18, item 6). */
    unconfirmedEvents: number;
    /** The brand's connected channels, and its posts per week (0: paused). */
    channels: PlanChannel[];
    cadence: Record<string, number | null>;
}>();

const open = defineModel<boolean>('open', { default: false });

/** Must-haves one run takes (MustHave::MAX). */
const MAX_MUST_HAVES = 10;

const form = useForm({
    goals: '',
    from: '',
    to: '',
    old_calendars: true,
    past_posts: true,
    audience_events: false,
    days_off: [] as number[],
    must_haves: [] as MustHaveRow[],
    keep_instructions: true,
});

/** Keys for the must-have rows, so a removed row takes its own fields with it. */
const rowKeys = ref<number[]>([]);
let nextRowKey = 0;

/** Refusals that are not about a field (AI not set up, no access to AI). */
const generateError = computed(
    () => (form.errors as Record<string, string | undefined>).generate,
);

const fieldError = (field: string): string | undefined =>
    (form.errors as Record<string, string | undefined>)[field];

const hasAudience = computed(() => props.audience.countries.length > 0);

const audienceCountries = computed(() =>
    props.audience.countries.map((country) => country.name).join(', '),
);

const sources = computed<
    { key: SourceOption; label: string; help: string; disabled: boolean }[]
>(() => [
    {
        key: 'old_calendars',
        label: 'socialhq_plan.generate.old_calendars',
        help: 'socialhq_plan.generate.old_calendars_help',
        disabled: false,
    },
    {
        key: 'past_posts',
        label: 'socialhq_plan.generate.past_posts',
        help: 'socialhq_plan.generate.past_posts_help',
        disabled: false,
    },
    {
        key: 'audience_events',
        label: 'socialhq_plan.generate.audience_events',
        help: hasAudience.value
            ? 'socialhq_plan.generate.audience_events_help'
            : 'socialhq_plan.generate.audience_events_none',
        disabled: !hasAudience.value,
    },
]);

/** Channels a must-have can name: those not set to 0 posts a week. */
const activeChannels = computed(() =>
    props.channels.filter((channel) => props.cadence[channel.id] !== 0),
);

/** Days already past in the current month are not worth planning: start today. */
const firstDay = (): string =>
    props.today > props.defaults.from && props.today <= props.defaults.to
        ? props.today
        : props.defaults.from;

/**
 * A week from the first day, or to the month's end: the AI keeps far more
 * of a week's suggestions than of a whole month's (audit 2026-11-24, UX-12).
 */
const DAYS_PER_RUN = 7;

const lastDay = (from: string): string => {
    const weekEnd = dayjs(from)
        .add(DAYS_PER_RUN - 1, 'day')
        .format('YYYY-MM-DD');

    return weekEnd < props.defaults.to ? weekEnd : props.defaults.to;
};

/** A saved must-have as a form row; a channel no longer active reads as any. */
const toRow = (mustHave: PlanMustHave): MustHaveRow => {
    const channel = activeChannels.value.some(
        (candidate) => candidate.id === mustHave.channel,
    )
        ? (mustHave.channel ?? '')
        : '';

    return {
        date: mustHave.date,
        channel,
        content_type:
            channel || !mustHave.channel ? (mustHave.content_type ?? '') : '',
        focus: mustHave.focus,
    };
};

watch(open, (isOpen) => {
    if (isOpen) {
        form.clearErrors();
        form.goals = props.defaults.strategy;
        form.from = firstDay();
        form.to = lastDay(form.from);
        form.old_calendars = props.defaults.old_calendars;
        form.past_posts = props.defaults.past_posts;
        form.audience_events =
            hasAudience.value && props.defaults.audience_events;
        form.days_off = [...props.defaults.days_off];
        form.must_haves = props.defaults.must_haves.map(toRow);
        rowKeys.value = form.must_haves.map(() => nextRowKey++);
        form.keep_instructions = true;
    }
});

const toggleSource = (key: SourceOption): void => {
    form[key] = !form[key];
};

/** An option card: accent tint, border and check when on, a plain outline when off. */
const sourceClass = (on: boolean): string[] => [
    'relative flex h-full w-full flex-col items-start gap-1 rounded-lg border p-3 pe-9 text-start transition-control outline-none focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring focus-visible:outline-solid disabled:cursor-not-allowed disabled:opacity-60',
    on
        ? 'border-primary-strong bg-primary-subtle'
        : 'border-border-strong bg-card hover:bg-accent disabled:hover:bg-card',
];

/** The days a must-have can go on: the days to plan, from today on. */
const rangeDays = computed<string[]>(() => {
    if (!form.from || !form.to) {
        return [];
    }

    const days: string[] = [];

    for (
        let day = dayjs(form.from);
        !day.isAfter(dayjs(form.to), 'day');
        day = day.add(1, 'day')
    ) {
        const value = day.format('YYYY-MM-DD');

        if (value >= props.today) {
            days.push(value);
        }
    }

    return days;
});

const dayLabel = (day: string): string => localizedDay(day).format('ddd D MMM');

/** The row's own day stays listed when it is outside the days to plan, so the select still shows it. */
const dayOptions = (row: MustHaveRow): string[] =>
    row.date && !rangeDays.value.includes(row.date)
        ? [row.date, ...rangeDays.value]
        : rangeDays.value;

/** Why a row's day can't be planned, as a lang key; null when it can. */
const dayProblem = (row: MustHaveRow): string | null => {
    if (!row.date) {
        return null;
    }

    if (row.date < props.today) {
        return 'socialhq_plan.generate.validation.must_have_past';
    }

    return rangeDays.value.includes(row.date)
        ? null
        : 'socialhq_plan.generate.validation.must_have_day';
};

/**
 * The server's refusals for a row, one per field. A day the dialog already
 * says is outside the days to plan isn't said twice.
 */
const rowErrors = (row: MustHaveRow, index: number): string[] =>
    (['date', 'channel', 'content_type', 'focus'] as const)
        .filter((field) => field !== 'date' || !dayProblem(row))
        .map((field) => fieldError(`must_haves.${index}.${field}`))
        .filter((message): message is string => !!message);

/**
 * The formats a row can pick: its channel's, or for any channel every
 * format an active channel takes, named with its network.
 */
const formatOptions = (
    row: MustHaveRow,
): { value: string; label: string; network: string | null }[] => {
    const channel = activeChannels.value.find(
        (candidate) => candidate.id === row.channel,
    );

    if (channel) {
        return channel.content_types.map((type) => ({
            ...type,
            network: null,
        }));
    }

    const seen = new Map<
        string,
        { value: string; label: string; network: string | null }
    >();

    for (const candidate of activeChannels.value) {
        for (const type of candidate.content_types) {
            if (!seen.has(type.value)) {
                seen.set(type.value, {
                    ...type,
                    network: channelLabel(null, candidate.platform),
                });
            }
        }
    }

    return [...seen.values()];
};

const addMustHave = (): void => {
    if (form.must_haves.length >= MAX_MUST_HAVES) {
        return;
    }

    form.must_haves.push({
        date: rangeDays.value[0] ?? form.from,
        channel: '',
        content_type: '',
        focus: '',
    });
    rowKeys.value.push(nextRowKey++);
};

const removeMustHave = (index: number): void => {
    form.must_haves.splice(index, 1);
    rowKeys.value.splice(index, 1);
};

/** A format the new channel doesn't take goes back to any. */
const changeChannel = (index: number, channel: unknown): void => {
    const row = form.must_haves[index];

    if (!row) {
        return;
    }

    row.channel = String(channel ?? '');

    if (
        row.content_type &&
        !formatOptions(row).some((type) => type.value === row.content_type)
    ) {
        row.content_type = '';
    }
};

const isDayOff = (day: number): boolean => form.days_off.includes(day);

const toggleDayOff = (day: number): void => {
    form.days_off = isDayOff(day)
        ? form.days_off.filter((off) => off !== day)
        : [...form.days_off, day].sort((a, b) => a - b);
};

/** The weekday's short name in the page's language. */
const weekdayName = (day: number): string =>
    localizedDay().day(day).format('ddd');

const dayOffClass = (off: boolean): string[] => [
    'inline-flex h-7 min-w-11 items-center justify-center rounded-full border px-3 text-xs font-medium capitalize transition-control outline-none focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring focus-visible:outline-solid',
    off
        ? 'border-foreground bg-foreground text-background'
        : 'border-border-strong bg-card text-muted-foreground hover:bg-accent hover:text-foreground',
];

const setKeepInstructions = (keep: boolean | 'indeterminate'): void => {
    form.keep_instructions = keep === true;
};

const close = (): void => {
    open.value = false;
};

const refused = (response: { status: number }): false => {
    if (response.status === 429) {
        toast.error(
            trans('socialhq_plan.generate.too_many', {
                limit: String(props.hourlyLimit),
            }),
        );
    } else {
        toast.error(
            trans(
                response.status === 409
                    ? 'socialhq_plan.generation.errors.already_running'
                    : 'socialhq_plan.errors.request_failed',
            ),
        );
    }

    close();

    return false;
};

const start = (): void => {
    form.post(generate.url(props.month), {
        preserveScroll: true,
        onSuccess: close,
        onHttpException: refused,
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-2xl" data-testid="plan-generate-dialog">
            <form class="flex flex-col gap-5" @submit.prevent="start">
                <DialogHeader>
                    <DialogTitle>{{
                        $t('socialhq_plan.generate.title', {
                            month: monthLabel,
                        })
                    }}</DialogTitle>
                    <DialogDescription>{{
                        $t('socialhq_plan.generate.description')
                    }}</DialogDescription>
                </DialogHeader>

                <p
                    v-if="unconfirmedEvents > 0"
                    class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg border border-dashed border-amber-500/70 px-3 py-2 text-sm text-foreground"
                    data-testid="plan-generate-unconfirmed"
                >
                    <span>{{
                        $tChoice(
                            'socialhq_plan.generate.unconfirmed',
                            unconfirmedEvents,
                            { count: String(unconfirmedEvents) },
                        )
                    }}</span>
                    <Link
                        :href="eventsIndex.url({ query: { month } })"
                        class="font-medium text-primary-text underline-offset-2 hover:underline"
                        data-testid="plan-generate-check-dates"
                        >{{ $t('socialhq_plan.generate.check_dates') }}</Link
                    >
                </p>

                <div class="flex flex-col gap-2">
                    <Label
                        id="plan-generate-range-label"
                        for="plan-generate-range-trigger"
                        >{{ $t('socialhq_plan.generate.range') }}</Label
                    >
                    <PlanRangePicker
                        v-model:from="form.from"
                        v-model:to="form.to"
                        :month="month"
                        :today="today"
                        labelledby="plan-generate-range-label"
                        describedby="plan-generate-range-help"
                    />
                    <p
                        id="plan-generate-range-help"
                        class="text-xs text-muted-foreground"
                    >
                        {{
                            $t('socialhq_plan.generate.range_help', {
                                month: monthLabel,
                            })
                        }}
                    </p>
                    <p
                        v-if="form.errors.from || form.errors.to"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.from ?? form.errors.to }}
                    </p>
                </div>

                <fieldset class="flex flex-col gap-2">
                    <legend class="mb-2 text-sm font-medium">
                        {{ $t('socialhq_plan.generate.sources') }}
                    </legend>
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                        <button
                            v-for="option in sources"
                            :key="option.key"
                            type="button"
                            :class="sourceClass(form[option.key])"
                            :disabled="option.disabled"
                            :aria-pressed="form[option.key]"
                            :aria-labelledby="`plan-generate-${option.key}-label`"
                            :aria-describedby="`plan-generate-${option.key}-help`"
                            :data-testid="`plan-generate-${option.key}`"
                            @click="toggleSource(option.key)"
                        >
                            <span
                                :id="`plan-generate-${option.key}-label`"
                                class="text-sm font-medium text-foreground"
                                >{{ $t(option.label) }}</span
                            >
                            <span
                                :id="`plan-generate-${option.key}-help`"
                                class="text-xs text-muted-foreground"
                            >
                                {{
                                    $t(option.help, {
                                        countries: audienceCountries,
                                    })
                                }}
                            </span>
                            <span
                                v-if="form[option.key]"
                                class="absolute end-2.5 top-2.5 flex size-5 items-center justify-center rounded-full bg-primary-strong text-primary-strong-foreground"
                                aria-hidden="true"
                            >
                                <IconCheck class="size-3.5" stroke-width="3" />
                            </span>
                        </button>
                    </div>
                </fieldset>

                <section
                    class="flex flex-col gap-5 rounded-xl border border-primary/30 bg-primary-subtle/30 p-4"
                    aria-labelledby="plan-generate-instructions"
                    data-testid="plan-generate-instructions"
                >
                    <h3
                        id="plan-generate-instructions"
                        class="text-sm font-semibold text-foreground"
                    >
                        {{ $t('socialhq_plan.generate.instructions') }}
                    </h3>

                    <div class="flex flex-col gap-2">
                        <Label for="plan-generate-goals">{{
                            $t('socialhq_plan.generate.strategy')
                        }}</Label>
                        <Textarea
                            id="plan-generate-goals"
                            v-model="form.goals"
                            rows="3"
                            class="bg-card"
                            :placeholder="
                                $t(
                                    'socialhq_plan.generate.strategy_placeholder',
                                )
                            "
                            aria-describedby="plan-generate-goals-help"
                            data-testid="plan-generate-goals"
                        />
                        <p
                            id="plan-generate-goals-help"
                            class="text-xs text-muted-foreground"
                        >
                            {{ $t('socialhq_plan.generate.strategy_help') }}
                        </p>
                        <p
                            v-if="form.errors.goals"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.goals }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-2">
                        <h4
                            id="plan-generate-must-haves-label"
                            class="text-sm font-medium text-foreground"
                        >
                            {{ $t('socialhq_plan.generate.must_haves') }}
                        </h4>
                        <p
                            id="plan-generate-must-haves-help"
                            class="text-xs text-muted-foreground"
                        >
                            {{ $t('socialhq_plan.generate.must_haves_help') }}
                        </p>
                        <ul
                            v-if="form.must_haves.length"
                            class="flex flex-col gap-2"
                            aria-labelledby="plan-generate-must-haves-label"
                            aria-describedby="plan-generate-must-haves-help"
                        >
                            <li
                                v-for="(row, index) in form.must_haves"
                                :key="rowKeys[index]"
                                class="flex flex-col gap-2 rounded-lg border border-border-strong bg-card p-3"
                                role="group"
                                :aria-label="
                                    $t(
                                        'socialhq_plan.generate.must_have.label',
                                        {
                                            number: String(index + 1),
                                        },
                                    )
                                "
                                :data-testid="`plan-generate-must-have-${index}`"
                            >
                                <div
                                    class="-me-1 -mt-1 flex items-center justify-between gap-2"
                                >
                                    <MustHaveBadge />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        class="size-8 text-muted-foreground hover:text-foreground"
                                        :aria-label="
                                            $t(
                                                'socialhq_plan.generate.must_have.remove',
                                            )
                                        "
                                        :title="
                                            $t(
                                                'socialhq_plan.generate.must_have.remove',
                                            )
                                        "
                                        :data-testid="`plan-generate-must-have-remove-${index}`"
                                        @click="removeMustHave(index)"
                                    >
                                        <IconTrash class="size-4" />
                                    </Button>
                                </div>
                                <div
                                    class="grid grid-cols-1 gap-2 sm:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)_minmax(0,1fr)] [&_[data-slot=native-select-wrapper]]:w-full"
                                >
                                    <NativeSelect
                                        v-model="row.date"
                                        class="capitalize"
                                        :aria-label="
                                            $t(
                                                'socialhq_plan.generate.must_have.day',
                                            )
                                        "
                                        :aria-invalid="
                                            !!(
                                                dayProblem(row) ||
                                                fieldError(
                                                    `must_haves.${index}.date`,
                                                )
                                            ) || undefined
                                        "
                                        :data-testid="`plan-generate-must-have-day-${index}`"
                                    >
                                        <NativeSelectOption
                                            v-for="day in dayOptions(row)"
                                            :key="day"
                                            :value="day"
                                        >
                                            {{ dayLabel(day) }}
                                        </NativeSelectOption>
                                    </NativeSelect>
                                    <NativeSelect
                                        :model-value="row.channel"
                                        :aria-label="
                                            $t(
                                                'socialhq_plan.generate.must_have.channel',
                                            )
                                        "
                                        :data-testid="`plan-generate-must-have-channel-${index}`"
                                        @update:model-value="
                                            changeChannel(index, $event)
                                        "
                                    >
                                        <NativeSelectOption value="">{{
                                            $t(
                                                'socialhq_plan.generate.must_have.any_channel',
                                            )
                                        }}</NativeSelectOption>
                                        <NativeSelectOption
                                            v-for="channel in activeChannels"
                                            :key="channel.id"
                                            :value="channel.id"
                                        >
                                            {{
                                                channelLabel(
                                                    channel.name,
                                                    channel.platform,
                                                )
                                            }}
                                        </NativeSelectOption>
                                    </NativeSelect>
                                    <NativeSelect
                                        v-model="row.content_type"
                                        :aria-label="
                                            $t(
                                                'socialhq_plan.generate.must_have.format',
                                            )
                                        "
                                        :data-testid="`plan-generate-must-have-format-${index}`"
                                    >
                                        <NativeSelectOption value="">{{
                                            $t(
                                                'socialhq_plan.generate.must_have.any_format',
                                            )
                                        }}</NativeSelectOption>
                                        <NativeSelectOption
                                            v-for="type in formatOptions(row)"
                                            :key="type.value"
                                            :value="type.value"
                                        >
                                            {{
                                                type.network
                                                    ? `${type.label} · ${type.network}`
                                                    : type.label
                                            }}
                                        </NativeSelectOption>
                                    </NativeSelect>
                                </div>
                                <Textarea
                                    v-model="row.focus"
                                    rows="2"
                                    :aria-label="
                                        $t(
                                            'socialhq_plan.generate.must_have.focus',
                                        )
                                    "
                                    :placeholder="
                                        $t(
                                            'socialhq_plan.generate.must_have.focus_placeholder',
                                        )
                                    "
                                    :aria-invalid="
                                        !!fieldError(
                                            `must_haves.${index}.focus`,
                                        ) || undefined
                                    "
                                    :data-testid="`plan-generate-must-have-focus-${index}`"
                                />
                                <p
                                    v-if="dayProblem(row)"
                                    class="text-sm text-destructive"
                                    :data-testid="`plan-generate-must-have-day-problem-${index}`"
                                >
                                    {{ $t(dayProblem(row) ?? '') }}
                                </p>
                                <p
                                    v-for="message in rowErrors(row, index)"
                                    :key="message"
                                    class="text-sm text-destructive"
                                >
                                    {{ message }}
                                </p>
                            </li>
                        </ul>
                        <p
                            v-if="fieldError('must_haves')"
                            class="text-sm text-destructive"
                        >
                            {{ fieldError('must_haves') }}
                        </p>
                        <Button
                            v-if="form.must_haves.length < MAX_MUST_HAVES"
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="self-start text-primary-text hover:text-primary-text-hover"
                            data-testid="plan-generate-must-have-add"
                            @click="addMustHave"
                        >
                            <IconPlus class="size-4" />
                            {{ $t('socialhq_plan.generate.must_have.add') }}
                        </Button>
                        <p v-else class="text-xs text-muted-foreground">
                            {{
                                $t('socialhq_plan.generate.must_have.limit', {
                                    max: String(MAX_MUST_HAVES),
                                })
                            }}
                        </p>
                    </div>

                    <fieldset
                        class="flex flex-col gap-2"
                        aria-describedby="plan-generate-days-off-help"
                    >
                        <legend
                            class="mb-2 text-sm font-medium text-foreground"
                        >
                            {{ $t('socialhq_plan.generate.days_off') }}
                        </legend>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="day in orderedWeekdays()"
                                :key="day"
                                type="button"
                                :class="dayOffClass(isDayOff(day))"
                                :aria-pressed="isDayOff(day)"
                                :data-testid="`plan-generate-day-off-${day}`"
                                @click="toggleDayOff(day)"
                            >
                                {{ weekdayName(day) }}
                            </button>
                        </div>
                        <p
                            id="plan-generate-days-off-help"
                            class="text-xs text-muted-foreground"
                        >
                            {{ $t('socialhq_plan.generate.days_off_help') }}
                        </p>
                        <p
                            v-if="
                                fieldError('days_off') ||
                                fieldError('days_off.0')
                            "
                            class="text-sm text-destructive"
                        >
                            {{
                                fieldError('days_off') ??
                                fieldError('days_off.0')
                            }}
                        </p>
                    </fieldset>

                    <div class="flex items-start gap-2">
                        <Checkbox
                            id="plan-generate-keep"
                            class="mt-0.5"
                            :model-value="form.keep_instructions"
                            aria-describedby="plan-generate-keep-help"
                            data-testid="plan-generate-keep"
                            @update:model-value="setKeepInstructions"
                        />
                        <div class="flex flex-col gap-0.5">
                            <Label for="plan-generate-keep" class="text-sm">{{
                                $t('socialhq_plan.generate.keep')
                            }}</Label>
                            <p
                                id="plan-generate-keep-help"
                                class="text-xs text-muted-foreground"
                            >
                                {{ $t('socialhq_plan.generate.keep_help') }}
                            </p>
                        </div>
                    </div>
                </section>

                <p
                    v-if="hasSuggestions"
                    class="rounded-lg bg-muted px-3 py-2 text-sm text-muted-foreground"
                >
                    {{ $t('socialhq_plan.generate.replaces') }}
                </p>

                <p
                    v-if="generateError"
                    class="text-sm text-destructive"
                    data-testid="plan-generate-error"
                >
                    {{ generateError }}
                </p>

                <DialogFooter
                    class="sticky -bottom-2 z-10 shadow-[0_0_0_0.5rem_var(--background)]"
                >
                    <Button type="button" variant="outline" @click="close">
                        {{ $t('socialhq_plan.sheet.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :loading="form.processing"
                        data-testid="plan-generate-start"
                    >
                        <IconSparkles class="size-4" />
                        {{ $t('socialhq_plan.generate.start') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
