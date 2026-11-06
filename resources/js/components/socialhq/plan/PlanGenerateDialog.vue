<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { IconSparkles } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, watch } from 'vue';
import { toast } from 'vue-sonner';

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
import { activeLocale } from '@/language';
import { generate } from '@/routes/socialhq/plan';

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
    const start = dayjs(`${props.month}-01`).locale(
        activeLocale.value.toLowerCase(),
    );

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

watch(open, (isOpen) => {
    if (isOpen) {
        form.clearErrors();
        form.goals = props.goals ?? '';
        form.from = firstDay();
        form.to = props.defaults.to;
        form.old_calendars = props.defaults.old_calendars;
        form.past_posts = props.defaults.past_posts;
        form.audience_events =
            hasAudience.value && props.defaults.audience_events;
    }
});

const setSwitch = (key: SourceSwitch, value: boolean): void => {
    form[key] = value;
};

const onFromChange = (): void => {
    if (form.to < form.from) {
        form.to = form.from;
    }
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
        <DialogContent class="sm:max-w-lg" data-testid="plan-generate-dialog">
            <form
                class="flex max-h-[80vh] flex-col gap-5 overflow-y-auto"
                @submit.prevent="start"
            >
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
                                @change="onFromChange"
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
