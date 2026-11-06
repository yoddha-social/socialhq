<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { IconSearch } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

import type { HolidayCountry } from '@/components/socialhq/plan/eventStyles';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { filterBySearch } from '@/lib/search';
import { store as importCountries } from '@/routes/socialhq/plan/events/countries';

/**
 * "Add a country" on the events page: imports countries' public holidays
 * from Nager.Date, unconfirmed. The country list loads when the dialog
 * opens (`holidayCountries`, an optional prop; null when Nager.Date can't be
 * reached).
 */
const props = defineProps<{
    /** Undefined until loaded, null when it couldn't be loaded. */
    countries: HolidayCountry[] | null | undefined;
    /** This year and the next. */
    years: number[];
    max: number;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    countries: [] as string[],
    years: [] as number[],
});

const search = ref('');
const isLoading = ref(false);

const loadCountries = (): void => {
    router.reload({
        only: ['holidayCountries'],
        onStart: () => {
            isLoading.value = true;
        },
        onFinish: () => {
            isLoading.value = false;
        },
    });
};

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    form.reset();
    form.clearErrors();
    form.years = [...props.years];
    search.value = '';

    if (!props.countries) {
        loadCountries();
    }
});

const shown = computed<HolidayCountry[]>(() =>
    filterBySearch(props.countries ?? [], search.value, (country) => [
        country.name,
        country.code,
    ]),
);

const isPicked = (code: string): boolean => form.countries.includes(code);

const isFull = computed(() => form.countries.length >= props.max);

const toggleCountry = (
    code: string,
    picked: boolean | 'indeterminate',
): void => {
    form.countries =
        picked === true
            ? [...new Set([...form.countries, code])]
            : form.countries.filter((value) => value !== code);
};

const toggleYear = (year: number, picked: boolean | 'indeterminate'): void => {
    form.years =
        picked === true
            ? [...new Set([...form.years, year])].sort()
            : form.years.filter((value) => value !== year);
};

/** The first message about the countries, wherever the server put it. */
const countriesError = computed(
    () =>
        Object.entries(form.errors).find(([key]) =>
            key.startsWith('countries'),
        )?.[1] ?? null,
);

const yearsError = computed(
    () =>
        Object.entries(form.errors).find(([key]) =>
            key.startsWith('years'),
        )?.[1] ?? null,
);

const close = (): void => {
    open.value = false;
};

const submit = (): void => {
    form.post(importCountries.url(), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: close,
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="max-h-[90vh] overflow-y-auto sm:max-w-lg"
            data-testid="socialhq-event-country-dialog"
        >
            <form class="flex flex-col gap-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>
                        {{ $t('socialhq_events.countries.title') }}
                    </DialogTitle>
                    <DialogDescription>
                        {{ $t('socialhq_events.countries.description') }}
                    </DialogDescription>
                </DialogHeader>

                <div class="flex flex-col gap-2">
                    <div class="flex items-baseline justify-between gap-2">
                        <Label for="socialhq-event-country-search">
                            {{ $t('socialhq_events.countries.countries') }}
                        </Label>
                        <span
                            class="text-xs text-muted-foreground tabular-nums"
                        >
                            {{
                                $t('socialhq_events.countries.selected', {
                                    count: String(form.countries.length),
                                    max: String(max),
                                })
                            }}
                        </span>
                    </div>

                    <p
                        v-if="countries === null && !isLoading"
                        class="flex flex-wrap items-center gap-2 text-sm text-destructive"
                        data-testid="socialhq-event-country-load-failed"
                    >
                        {{ $t('socialhq_events.countries.load_failed') }}
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            @click="loadCountries"
                        >
                            {{ $t('socialhq_events.countries.retry') }}
                        </Button>
                    </p>
                    <p
                        v-else-if="!countries"
                        class="text-sm text-muted-foreground"
                        data-testid="socialhq-event-country-loading"
                    >
                        {{ $t('socialhq_events.countries.loading') }}
                    </p>
                    <template v-else>
                        <div class="relative">
                            <IconSearch
                                class="pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                            />
                            <Input
                                id="socialhq-event-country-search"
                                v-model="search"
                                type="search"
                                class="ps-8"
                                :placeholder="
                                    $t('socialhq_events.countries.search')
                                "
                                data-testid="socialhq-event-country-search"
                            />
                        </div>
                        <ul
                            class="flex max-h-60 flex-col overflow-y-auto rounded-md border border-border"
                            data-testid="socialhq-event-country-list"
                        >
                            <li
                                v-for="country in shown"
                                :key="country.code"
                                class="border-b border-border last:border-b-0"
                            >
                                <label
                                    class="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm hover:bg-accent"
                                    :class="
                                        isFull && !isPicked(country.code)
                                            ? 'cursor-not-allowed opacity-60'
                                            : ''
                                    "
                                >
                                    <Checkbox
                                        :model-value="isPicked(country.code)"
                                        :disabled="
                                            isFull && !isPicked(country.code)
                                        "
                                        :data-testid="`socialhq-event-country-${country.code}`"
                                        @update:model-value="
                                            toggleCountry(country.code, $event)
                                        "
                                    />
                                    <span class="flex-1">{{
                                        country.name
                                    }}</span>
                                    <span
                                        class="font-mono text-xs text-muted-foreground"
                                        >{{ country.code }}</span
                                    >
                                </label>
                            </li>
                            <li
                                v-if="shown.length === 0"
                                class="px-3 py-2 text-sm text-muted-foreground"
                            >
                                {{ $t('socialhq_events.countries.no_match') }}
                            </li>
                        </ul>
                        <p class="text-xs text-muted-foreground">
                            {{ $t('socialhq_events.countries.nepal_note') }}
                        </p>
                    </template>
                    <p v-if="countriesError" class="text-sm text-destructive">
                        {{ countriesError }}
                    </p>
                </div>

                <fieldset class="flex flex-col gap-2">
                    <legend class="mb-2 text-sm font-medium">
                        {{ $t('socialhq_events.countries.years') }}
                    </legend>
                    <div class="flex flex-wrap gap-4">
                        <label
                            v-for="year in years"
                            :key="year"
                            class="flex cursor-pointer items-center gap-2 text-sm tabular-nums"
                        >
                            <Checkbox
                                :model-value="form.years.includes(year)"
                                :data-testid="`socialhq-event-country-year-${year}`"
                                @update:model-value="toggleYear(year, $event)"
                            />
                            {{ year }}
                        </label>
                    </div>
                    <p v-if="yearsError" class="text-sm text-destructive">
                        {{ yearsError }}
                    </p>
                </fieldset>

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="close">
                        {{ $t('socialhq_events.countries.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :loading="form.processing"
                        :disabled="!countries"
                        data-testid="socialhq-event-country-import"
                    >
                        {{ $t('socialhq_events.countries.import') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
