<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { IconSparkles } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, watch } from 'vue';
import { toast } from 'vue-sonner';

import { localizedDay } from '@/components/socialhq/localizedDay';
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
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import dayjs from '@/dayjs';
import { generate } from '@/routes/socialhq/plan';
import { index as eventsIndex } from '@/routes/socialhq/plan/events';

type SourceSwitch = 'old_calendars' | 'past_posts' | 'audience_events';

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

const days = computed(() => {
    const start = localizedDay(`${props.month}-01`);

    return Array.from({ length: start.daysInMonth() }, (_, index) => {
        const day = start.add(index, 'day');

        return {
            value: day.format('YYYY-MM-DD'),
            label: day.format('ddd D MMMM'),
        };
    });
});

const hasAudience = computed(() => props.audience.countries.length > 0);

const audienceCountries = computed(() =>
    props.audience.countries.map((country) => country.name).join(', '),
);

const switches = computed<
    { key: SourceSwitch; label: string; help: string; disabled: boolean }[]
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

const setSwitch = (key: SourceSwitch, value: boolean): void => {
    form[key] = value;
};

/**
 * The last day never comes before the first: moving the first day past it
 * moves it along. A watcher, not the select's change event, which fires
 * before the select's v-model has updated the form.
 */
watch(
    () => form.from,
    (from) => {
        if (from !== '' && form.to < from) {
            form.to = from;
        }
    },
);

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

                <fieldset class="flex flex-col gap-2">
                    <legend class="mb-2 text-sm font-medium">
                        {{ $t('socialhq_plan.generate.range') }}
                    </legend>
                    <div
                        class="grid grid-cols-2 gap-3 [&_[data-slot=native-select-wrapper]]:w-full"
                    >
                        <div class="flex flex-col gap-1.5">
                            <Label
                                for="plan-generate-from"
                                class="text-xs text-muted-foreground"
                                >{{ $t('socialhq_plan.generate.from') }}</Label
                            >
                            <NativeSelect
                                id="plan-generate-from"
                                v-model="form.from"
                                class="capitalize"
                                data-testid="plan-generate-from"
                            >
                                <NativeSelectOption
                                    v-for="day in days"
                                    :key="day.value"
                                    :value="day.value"
                                >
                                    {{ day.label }}
                                </NativeSelectOption>
                            </NativeSelect>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label
                                for="plan-generate-to"
                                class="text-xs text-muted-foreground"
                                >{{ $t('socialhq_plan.generate.to') }}</Label
                            >
                            <NativeSelect
                                id="plan-generate-to"
                                v-model="form.to"
                                class="capitalize"
                                data-testid="plan-generate-to"
                            >
                                <NativeSelectOption
                                    v-for="day in days"
                                    :key="day.value"
                                    :value="day.value"
                                    :disabled="day.value < form.from"
                                >
                                    {{ day.label }}
                                </NativeSelectOption>
                            </NativeSelect>
                        </div>
                    </div>
                    <p class="text-xs text-muted-foreground">
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
                </fieldset>

                <fieldset class="flex flex-col gap-3">
                    <legend class="mb-1 text-sm font-medium">
                        {{ $t('socialhq_plan.generate.sources') }}
                    </legend>
                    <div
                        v-for="option in switches"
                        :key="option.key"
                        class="flex items-start justify-between gap-4"
                    >
                        <div class="flex min-w-0 flex-col gap-0.5">
                            <Label
                                :for="`plan-generate-${option.key}`"
                                :class="{
                                    'text-muted-foreground': option.disabled,
                                }"
                                >{{ $t(option.label) }}</Label
                            >
                            <p
                                :id="`plan-generate-${option.key}-help`"
                                class="text-xs text-muted-foreground"
                            >
                                {{
                                    $t(option.help, {
                                        countries: audienceCountries,
                                    })
                                }}
                            </p>
                        </div>
                        <Switch
                            :id="`plan-generate-${option.key}`"
                            class="mt-0.5 shrink-0"
                            :model-value="form[option.key]"
                            :disabled="option.disabled"
                            :aria-describedby="`plan-generate-${option.key}-help`"
                            :data-testid="`plan-generate-${option.key}`"
                            @update:model-value="
                                setSwitch(option.key, Boolean($event))
                            "
                        />
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
