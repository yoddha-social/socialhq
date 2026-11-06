<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { IconPlus, IconX } from '@tabler/icons-vue';
import { computed, watch } from 'vue';

import type {
    AudienceLocation,
    AudienceProp,
} from '@/components/socialhq/audience';
import TimezoneSelect from '@/components/TimezoneSelect.vue';
import { Button } from '@/components/ui/button';
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
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { update as updateAudience } from '@/routes/socialhq/plan/audience';

interface Row {
    country: string;
    city: string;
    timezone: string;
    share: string;
}

const props = defineProps<{
    audience: AudienceProp;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm<{ locations: Row[] }>({ locations: [] });

const zoneOf = computed(
    () =>
        new Map(
            props.audience.countries.map((country) => [
                country.code,
                country.timezone,
            ]),
        ),
);

const total = computed(() =>
    form.locations.reduce((sum, row) => sum + (Number(row.share) || 0), 0),
);

const canAdd = computed(
    () => form.locations.length < props.audience.maxLocations,
);

/** Errors on the list itself (the total, too many places). */
const listError = computed(
    () => (form.errors as Record<string, string | undefined>).locations,
);

const rowError = (index: number, field: keyof Row): string | undefined =>
    (form.errors as Record<string, string | undefined>)[
        `locations.${index}.${field}`
    ];

const toRow = (location: AudienceLocation): Row => ({
    country: location.country,
    city: location.city ?? '',
    timezone: location.timezone,
    share: String(location.share),
});

const addRow = (): void => {
    if (canAdd.value) {
        form.locations.push({
            country: '',
            city: '',
            timezone: '',
            share: '',
        });
    }
};

const removeRow = (index: number): void => {
    form.locations.splice(index, 1);
};

watch(open, (isOpen) => {
    if (isOpen) {
        form.clearErrors();
        form.locations = props.audience.locations.map(toRow);

        if (form.locations.length === 0) {
            addRow();
        }
    }
});

/** A new country brings its main time zone; the zone stays editable. */
const onCountryChange = (row: Row): void => {
    row.timezone = zoneOf.value.get(row.country) ?? row.timezone;
};

const close = (): void => {
    open.value = false;
};

const save = (): void => {
    form.transform((data) => ({
        locations: data.locations.map((row) => ({
            country: row.country,
            city: row.city.trim() || null,
            timezone: row.timezone,
            share: row.share === '' ? null : Number(row.share),
        })),
    })).put(updateAudience.url(), {
        preserveScroll: true,
        onSuccess: close,
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="sm:max-w-2xl"
            data-testid="socialhq-audience-dialog"
        >
            <form
                class="flex max-h-[80vh] flex-col gap-5 overflow-y-auto"
                @submit.prevent="save"
            >
                <DialogHeader>
                    <DialogTitle>{{
                        $t('socialhq_audience.dialog.title')
                    }}</DialogTitle>
                    <DialogDescription>{{
                        $t('socialhq_audience.dialog.description')
                    }}</DialogDescription>
                </DialogHeader>

                <ul class="flex flex-col gap-4">
                    <li
                        v-for="(row, index) in form.locations"
                        :key="index"
                        class="grid grid-cols-2 gap-2 rounded-lg border p-3 sm:grid-cols-[1.3fr_1fr_1.3fr_5rem_auto] sm:items-end sm:border-0 sm:p-0 [&_[data-slot=native-select-wrapper]]:w-full"
                        :aria-label="
                            $t('socialhq_audience.dialog.place', {
                                number: String(index + 1),
                            })
                        "
                        :data-testid="`socialhq-audience-row-${index}`"
                    >
                        <div
                            class="col-span-2 flex flex-col gap-1.5 sm:col-span-1"
                        >
                            <Label
                                :for="`socialhq-audience-country-${index}`"
                                class="text-xs text-muted-foreground"
                                >{{
                                    $t('socialhq_audience.dialog.country')
                                }}</Label
                            >
                            <NativeSelect
                                :id="`socialhq-audience-country-${index}`"
                                v-model="row.country"
                                :aria-invalid="
                                    !!rowError(index, 'country') || undefined
                                "
                                :data-testid="`socialhq-audience-country-${index}`"
                                @change="onCountryChange(row)"
                            >
                                <NativeSelectOption value="" disabled>{{
                                    $t(
                                        'socialhq_audience.dialog.country_placeholder',
                                    )
                                }}</NativeSelectOption>
                                <NativeSelectOption
                                    v-for="country in audience.countries"
                                    :key="country.code"
                                    :value="country.code"
                                >
                                    {{ country.name }}
                                </NativeSelectOption>
                            </NativeSelect>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label
                                :for="`socialhq-audience-city-${index}`"
                                class="text-xs text-muted-foreground"
                                >{{
                                    $t('socialhq_audience.dialog.city')
                                }}</Label
                            >
                            <Input
                                :id="`socialhq-audience-city-${index}`"
                                v-model="row.city"
                                :placeholder="
                                    $t(
                                        'socialhq_audience.dialog.city_placeholder',
                                    )
                                "
                                :data-testid="`socialhq-audience-city-${index}`"
                            />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <span class="text-xs text-muted-foreground">{{
                                $t('socialhq_audience.dialog.timezone')
                            }}</span>
                            <TimezoneSelect
                                v-model="row.timezone"
                                :options="audience.timezones"
                                :testid="`socialhq-audience-timezone-${index}`"
                                compact
                            />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label
                                :for="`socialhq-audience-share-${index}`"
                                class="text-xs text-muted-foreground"
                                >{{
                                    $t('socialhq_audience.dialog.share')
                                }}</Label
                            >
                            <Input
                                :id="`socialhq-audience-share-${index}`"
                                v-model="row.share"
                                inputmode="numeric"
                                class="tabular-nums"
                                :aria-invalid="
                                    !!rowError(index, 'share') || undefined
                                "
                                :data-testid="`socialhq-audience-share-${index}`"
                            />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="justify-self-end"
                            :aria-label="$t('socialhq_audience.dialog.remove')"
                            :data-testid="`socialhq-audience-remove-${index}`"
                            @click="removeRow(index)"
                        >
                            <IconX class="size-4" />
                        </Button>
                        <p
                            v-for="field in [
                                'country',
                                'city',
                                'timezone',
                                'share',
                            ] as const"
                            v-show="rowError(index, field)"
                            :key="field"
                            class="col-span-full text-sm text-destructive"
                        >
                            {{ rowError(index, field) }}
                        </p>
                    </li>
                </ul>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <Button
                        v-if="canAdd"
                        type="button"
                        variant="outline"
                        data-testid="socialhq-audience-add"
                        @click="addRow"
                    >
                        <IconPlus class="size-4" />
                        {{ $t('socialhq_audience.dialog.add') }}
                    </Button>
                    <p
                        class="ms-auto text-sm tabular-nums"
                        :class="
                            total > 100
                                ? 'text-destructive'
                                : 'text-muted-foreground'
                        "
                        data-testid="socialhq-audience-total"
                    >
                        {{
                            $t('socialhq_audience.dialog.total', {
                                total: String(total),
                            })
                        }}
                    </p>
                </div>

                <p v-if="listError" class="text-sm text-destructive">
                    {{ listError }}
                </p>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="close">
                        {{ $t('socialhq_audience.dialog.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :loading="form.processing"
                        data-testid="socialhq-audience-save"
                    >
                        {{ $t('socialhq_audience.dialog.save') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
