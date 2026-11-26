<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { IconCheck, IconSparkles } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, watch } from 'vue';
import { toast } from 'vue-sonner';

import PlanRangePicker from '@/components/socialhq/plan/PlanRangePicker.vue';
import type {
    PlanAudience,
    PlanGenerateOptions,
} from '@/components/socialhq/plan/types';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import dayjs from '@/dayjs';
import { generate } from '@/routes/socialhq/plan';
import { index as eventsIndex } from '@/routes/socialhq/plan/events';

type SourceOption = 'old_calendars' | 'past_posts' | 'audience_events';

const props = defineProps<{
    month: string;
    monthLabel: string;
    today: string;
    goals: string | null;
    hasSuggestions: boolean;
    hourlyLimit: number;
    defaults: PlanGenerateOptions;
    audience: PlanAudience;
    /** Dates of the month that still need a check: the AI leaves them out (QA 2026-11-18, item 6). */
    unconfirmedEvents: number;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    goals: '',
    from: '',
    to: '',
    old_calendars: true,
    past_posts: true,
    audience_events: false,
});

/** Refusals that are not about a field (AI not set up, no access to AI). */
const generateError = computed(
    () => (form.errors as Record<string, string | undefined>).generate,
);

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

watch(open, (isOpen) => {
    if (isOpen) {
        form.clearErrors();
        form.goals = props.goals ?? '';
        form.from = firstDay();
        form.to = lastDay(form.from);
        form.old_calendars = props.defaults.old_calendars;
        form.past_posts = props.defaults.past_posts;
        form.audience_events =
            hasAudience.value && props.defaults.audience_events;
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
        <DialogContent class="sm:max-w-lg" data-testid="plan-generate-dialog">
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

                <div class="flex flex-col gap-2">
                    <Label for="plan-generate-goals">{{
                        $t('socialhq_plan.generate.goals')
                    }}</Label>
                    <Textarea
                        id="plan-generate-goals"
                        v-model="form.goals"
                        rows="3"
                        :placeholder="
                            $t('socialhq_plan.generate.goals_placeholder')
                        "
                        data-testid="plan-generate-goals"
                    />
                    <p
                        v-if="form.errors.goals"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.goals }}
                    </p>
                </div>

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

                <DialogFooter>
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
