<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

import DatePicker from '@/components/DatePicker.vue';
import type {
    CalendarEventRow,
    EventCategory,
} from '@/components/socialhq/plan/eventStyles';
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
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { store, update } from '@/routes/socialhq/plan/events';

/**
 * Add a date, or edit one, on the events page (people who approve posts).
 * A new date belongs to this brand only unless switched off.
 */
const props = defineProps<{
    event: CalendarEventRow | null;
    categories: EventCategory[];
    /** The day a new date starts on (YYYY-MM-DD). */
    defaultDay: string;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    name: '',
    name_ne: '',
    category: 'brand' as EventCategory,
    starts_on: '',
    ends_on: '',
    bs_date: '',
    public_holiday: false,
    description: '',
    source_url: '',
    brand_only: true,
});

const fill = (): void => {
    const event = props.event;

    form.clearErrors();
    form.name = event?.name ?? '';
    form.name_ne = event?.name_ne ?? '';
    form.category = event?.category ?? 'brand';
    form.starts_on = event?.starts_on ?? props.defaultDay;
    form.ends_on = event?.ends_on ?? props.defaultDay;
    form.bs_date = event?.bs_date ?? '';
    form.public_holiday = event?.public_holiday ?? false;
    form.description = event?.description ?? '';
    form.source_url = event?.source_url ?? '';
    form.brand_only = event?.brand_only ?? true;
};

watch(open, (isOpen) => {
    if (isOpen) {
        fill();
    }
});

/**
 * A one-day event moves as a whole; a longer one keeps its end unless the new
 * start passes it.
 */
const setStartsOn = (value: string | null): void => {
    const wasOneDay = form.ends_on === form.starts_on;

    form.starts_on = value ?? '';

    if (form.starts_on !== '' && (wasOneDay || form.ends_on < form.starts_on)) {
        form.ends_on = form.starts_on;
    }
};

const setEndsOn = (value: string | null): void => {
    form.ends_on = value ?? '';
};

const setPublicHoliday = (value: boolean): void => {
    form.public_holiday = value;
};

/** The brand kind is only for one brand's dates (the server refuses it otherwise). */
const SHARED_FALLBACK_CATEGORY: EventCategory = 'observance';

const categoryOptions = computed(() =>
    form.brand_only
        ? props.categories
        : props.categories.filter((category) => category !== 'brand'),
);

const setBrandOnly = (value: boolean): void => {
    form.brand_only = value;

    if (!value && form.category === 'brand') {
        form.category = SHARED_FALLBACK_CATEGORY;
    }
};

const close = (): void => {
    open.value = false;
};

const submit = (): void => {
    const options = { preserveScroll: true, onSuccess: close };
    const payload = form.transform((data) => ({
        ...data,
        name_ne: data.name_ne || null,
        bs_date: data.bs_date || null,
        description: data.description || null,
        source_url: data.source_url || null,
    }));

    if (props.event) {
        payload.put(update.url(props.event.id), options);
    } else {
        payload.post(store.url(), options);
    }
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="max-h-[90vh] overflow-y-auto sm:max-w-lg"
            data-testid="socialhq-event-dialog"
        >
            <form class="flex flex-col gap-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>
                        {{
                            event
                                ? $t('socialhq_events.dialog.edit_title')
                                : $t('socialhq_events.dialog.add_title')
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            event
                                ? $t('socialhq_events.dialog.edit_description')
                                : $t('socialhq_events.dialog.add_description')
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="flex flex-col gap-2 sm:col-span-2">
                        <Label for="socialhq-event-name">
                            {{ $t('socialhq_events.dialog.name') }}
                        </Label>
                        <Input
                            id="socialhq-event-name"
                            v-model="form.name"
                            :placeholder="
                                $t('socialhq_events.dialog.name_placeholder')
                            "
                            data-testid="socialhq-event-name"
                        />
                        <p
                            v-if="form.errors.name"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.name }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-2">
                        <Label for="socialhq-event-name-ne">
                            {{ $t('socialhq_events.dialog.name_ne') }}
                        </Label>
                        <Input
                            id="socialhq-event-name-ne"
                            v-model="form.name_ne"
                            lang="ne"
                            data-testid="socialhq-event-name-ne"
                        />
                        <p
                            v-if="form.errors.name_ne"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.name_ne }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-2">
                        <Label for="socialhq-event-category">
                            {{ $t('socialhq_events.dialog.category') }}
                        </Label>
                        <NativeSelect
                            id="socialhq-event-category"
                            v-model="form.category"
                            data-testid="socialhq-event-category"
                        >
                            <NativeSelectOption
                                v-for="category in categoryOptions"
                                :key="category"
                                :value="category"
                            >
                                {{
                                    $t(`socialhq_events.categories.${category}`)
                                }}
                            </NativeSelectOption>
                        </NativeSelect>
                        <p
                            v-if="form.errors.category"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.category }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-2">
                        <Label for="socialhq-event-starts-on">
                            {{ $t('socialhq_events.dialog.starts_on') }}
                        </Label>
                        <DatePicker
                            name="socialhq-event-starts-on"
                            :model-value="form.starts_on"
                            :show-time="false"
                            align="start"
                            data-testid="socialhq-event-starts-on"
                            @update:model-value="setStartsOn"
                        />
                        <p
                            v-if="form.errors.starts_on"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.starts_on }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-2">
                        <Label for="socialhq-event-ends-on">
                            {{ $t('socialhq_events.dialog.ends_on') }}
                        </Label>
                        <DatePicker
                            name="socialhq-event-ends-on"
                            :model-value="form.ends_on"
                            :show-time="false"
                            align="start"
                            data-testid="socialhq-event-ends-on"
                            @update:model-value="setEndsOn"
                        />
                        <p
                            v-if="form.errors.ends_on"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.ends_on }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-2">
                        <Label for="socialhq-event-bs-date">
                            {{ $t('socialhq_events.dialog.bs_date') }}
                        </Label>
                        <Input
                            id="socialhq-event-bs-date"
                            v-model="form.bs_date"
                            :placeholder="
                                $t('socialhq_events.dialog.bs_date_placeholder')
                            "
                            data-testid="socialhq-event-bs-date"
                        />
                        <p
                            v-if="form.errors.bs_date"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.bs_date }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-2">
                        <Label for="socialhq-event-source">
                            {{ $t('socialhq_events.dialog.source_url') }}
                        </Label>
                        <Input
                            id="socialhq-event-source"
                            v-model="form.source_url"
                            inputmode="url"
                            :placeholder="
                                $t(
                                    'socialhq_events.dialog.source_url_placeholder',
                                )
                            "
                            data-testid="socialhq-event-source"
                        />
                        <p
                            v-if="form.errors.source_url"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.source_url }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-2 sm:col-span-2">
                        <Label for="socialhq-event-description">
                            {{ $t('socialhq_events.dialog.description') }}
                        </Label>
                        <Textarea
                            id="socialhq-event-description"
                            v-model="form.description"
                            rows="3"
                            data-testid="socialhq-event-description"
                        />
                        <p
                            v-if="form.errors.description"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.description }}
                        </p>
                    </div>
                </div>

                <div class="flex items-start justify-between gap-4">
                    <div class="flex flex-col gap-1">
                        <Label for="socialhq-event-holiday">
                            {{ $t('socialhq_events.dialog.public_holiday') }}
                        </Label>
                        <p class="text-sm text-muted-foreground">
                            {{
                                $t('socialhq_events.dialog.public_holiday_hint')
                            }}
                        </p>
                    </div>
                    <Switch
                        id="socialhq-event-holiday"
                        class="shrink-0"
                        :model-value="form.public_holiday"
                        data-testid="socialhq-event-holiday"
                        @update:model-value="setPublicHoliday(Boolean($event))"
                    />
                </div>

                <div class="flex items-start justify-between gap-4">
                    <div class="flex flex-col gap-1">
                        <Label for="socialhq-event-brand-only">
                            {{ $t('socialhq_events.dialog.brand_only') }}
                        </Label>
                        <p class="text-sm text-muted-foreground">
                            {{ $t('socialhq_events.dialog.brand_only_hint') }}
                        </p>
                    </div>
                    <Switch
                        id="socialhq-event-brand-only"
                        class="shrink-0"
                        :model-value="form.brand_only"
                        data-testid="socialhq-event-brand-only"
                        @update:model-value="setBrandOnly(Boolean($event))"
                    />
                </div>

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="close">
                        {{ $t('socialhq_events.dialog.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :loading="form.processing"
                        data-testid="socialhq-event-save"
                    >
                        {{
                            event
                                ? $t('socialhq_events.dialog.save')
                                : $t('socialhq_events.actions.add')
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
