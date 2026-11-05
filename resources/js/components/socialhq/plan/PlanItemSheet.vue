<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { IconArrowBackUp, IconExternalLink, IconX } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import type {
    PlanChannel,
    PlanEvent,
    PlanItem,
    PlanOwner,
} from '@/components/socialhq/plan/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import dayjs from '@/dayjs';
import { activeLocale } from '@/language';
import {
    destroy as destroyItem,
    drop as dropItem,
    restore as restoreItem,
    show as showItem,
    store as storeItem,
    update as updateItem,
} from '@/routes/socialhq/plan/items';

const props = defineProps<{
    item: PlanItem | null;
    defaultDay: string | null;
    month: string;
    channels: PlanChannel[];
    events: PlanEvent[];
    owners: PlanOwner[];
    pillars: string[];
    creatives: string[];
    canWork: boolean;
}>();

const open = defineModel<boolean>('open', { default: false });

const confirmDelete = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);
const statusProcessing = ref(false);

const form = useForm({
    scheduled_on: '',
    social_account_id: '',
    content_type: '',
    creative: 'image',
    pillar: '',
    title: '',
    objective: '',
    angle: '',
    event_id: '',
    owner_id: '',
});

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

/** The item's channel stays pickable even when it is no longer connected. */
const channelOptions = computed(() => {
    const options = props.channels.map((channel) => ({
        value: channel.id,
        label: channel.name,
    }));

    if (
        props.item?.social_account_id &&
        !options.some(
            (option) => option.value === props.item?.social_account_id,
        )
    ) {
        options.push({
            value: props.item.social_account_id,
            label: props.item.platform ?? props.item.social_account_id,
        });
    }

    return options;
});

const contentTypes = computed(
    () =>
        props.channels.find((channel) => channel.id === form.social_account_id)
            ?.content_types ?? [],
);

const pillarOptions = computed(() =>
    props.item?.pillar && !props.pillars.includes(props.item.pillar)
        ? [...props.pillars, props.item.pillar]
        : props.pillars,
);

const isNew = computed(() => props.item === null);

const fill = (): void => {
    form.clearErrors();

    const item = props.item;
    const channelId = item?.social_account_id ?? props.channels[0]?.id ?? '';

    form.scheduled_on =
        item?.scheduled_on ?? props.defaultDay ?? days.value[0]?.value ?? '';
    form.social_account_id = channelId;
    form.content_type =
        item?.content_type ??
        props.channels.find((channel) => channel.id === channelId)
            ?.content_types[0]?.value ??
        '';
    form.creative = item?.creative ?? 'image';
    form.pillar = item?.pillar ?? '';
    form.title = item?.title ?? '';
    form.objective = item?.objective ?? '';
    form.angle = item?.angle ?? '';
    form.event_id = item?.event_id ? String(item.event_id) : '';
    form.owner_id = item?.owner_id ?? '';
};

watch(open, (isOpen) => {
    if (isOpen) {
        fill();
    }
});

const onChannelChange = (): void => {
    if (!contentTypes.value.some((type) => type.value === form.content_type)) {
        form.content_type = contentTypes.value[0]?.value ?? '';
    }
};

const close = (): void => {
    open.value = false;
};

const save = (): void => {
    const options = { preserveScroll: true, onSuccess: close };

    form.transform((data) => ({
        ...data,
        pillar: data.pillar || null,
        objective: data.objective || null,
        angle: data.angle || null,
        event_id: data.event_id ? Number(data.event_id) : null,
        owner_id: data.owner_id || null,
    }));

    if (props.item) {
        form.put(updateItem.url(props.item.id), options);
    } else {
        form.post(storeItem.url(props.month), options);
    }
};

const toggleDropped = (): void => {
    if (!props.item) {
        return;
    }

    const route =
        props.item.status === 'dropped'
            ? restoreItem.url(props.item.id)
            : dropItem.url(props.item.id);

    router.post(
        route,
        {},
        {
            preserveScroll: true,
            onStart: () => {
                statusProcessing.value = true;
            },
            onFinish: () => {
                statusProcessing.value = false;
            },
            onSuccess: close,
        },
    );
};

const askDelete = (): void => {
    if (props.item) {
        confirmDelete.value?.open({ url: destroyItem.url(props.item.id) });
    }
};
</script>

<template>
    <div class="contents">
        <Sheet v-model:open="open">
            <SheetContent
                class="w-full gap-0 sm:max-w-md"
                data-testid="plan-item-sheet"
            >
                <SheetHeader class="border-b border-border px-5 py-4 pe-14">
                    <div class="flex items-center gap-2">
                        <SheetTitle>
                            {{
                                isNew
                                    ? $t('socialhq_plan.sheet.add_title')
                                    : $t('socialhq_plan.sheet.edit_title')
                            }}
                        </SheetTitle>
                        <Badge
                            v-if="item && item.status !== 'planned'"
                            :variant="
                                item.status === 'suggested' ? 'info' : 'outline'
                            "
                            class="px-1.5"
                        >
                            {{ $t(`socialhq_plan.item_status.${item.status}`) }}
                        </Badge>
                    </div>
                    <SheetDescription>
                        {{
                            canWork
                                ? $t('socialhq_plan.sheet.description')
                                : $t('socialhq_plan.sheet.read_only')
                        }}
                    </SheetDescription>
                    <Link
                        v-if="item"
                        :href="showItem.url(item.id)"
                        class="mt-1 inline-flex w-fit items-center gap-1 text-sm font-medium text-primary-text hover:text-primary-text-hover"
                        data-testid="plan-item-open-page"
                    >
                        {{ $t('socialhq_plan.sheet.open_item') }}
                        <IconExternalLink class="size-3.5" aria-hidden="true" />
                    </Link>
                </SheetHeader>

                <form
                    id="plan-item-form"
                    class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-5 py-4 [&_[data-slot=native-select-wrapper]]:w-full"
                    @submit.prevent="save"
                >
                    <fieldset :disabled="!canWork" class="contents">
                        <div class="flex flex-col gap-2">
                            <Label for="plan-item-title">{{
                                $t('socialhq_plan.sheet.title')
                            }}</Label>
                            <Input
                                id="plan-item-title"
                                v-model="form.title"
                                :placeholder="
                                    $t('socialhq_plan.sheet.title_placeholder')
                                "
                                :aria-invalid="!!form.errors.title || undefined"
                                data-testid="plan-item-title"
                            />
                            <p
                                v-if="form.errors.title"
                                class="text-sm text-destructive"
                            >
                                {{ form.errors.title }}
                            </p>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div class="col-span-2 flex flex-col gap-2">
                                <Label for="plan-item-day">{{
                                    $t('socialhq_plan.sheet.day')
                                }}</Label>
                                <NativeSelect
                                    id="plan-item-day"
                                    v-model="form.scheduled_on"
                                    class="capitalize"
                                    data-testid="plan-item-day"
                                >
                                    <NativeSelectOption
                                        v-for="day in days"
                                        :key="day.value"
                                        :value="day.value"
                                    >
                                        {{ day.label }}
                                    </NativeSelectOption>
                                </NativeSelect>
                                <p
                                    v-if="form.errors.scheduled_on"
                                    class="text-sm text-destructive"
                                >
                                    {{ form.errors.scheduled_on }}
                                </p>
                            </div>

                            <div class="col-span-2 flex flex-col gap-2">
                                <Label for="plan-item-channel">{{
                                    $t('socialhq_plan.sheet.channel')
                                }}</Label>
                                <NativeSelect
                                    id="plan-item-channel"
                                    v-model="form.social_account_id"
                                    data-testid="plan-item-channel"
                                    @change="onChannelChange"
                                >
                                    <NativeSelectOption
                                        v-for="option in channelOptions"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </NativeSelectOption>
                                </NativeSelect>
                                <p
                                    v-if="form.errors.social_account_id"
                                    class="text-sm text-destructive"
                                >
                                    {{ form.errors.social_account_id }}
                                </p>
                            </div>

                            <div class="flex flex-col gap-2">
                                <Label for="plan-item-format">{{
                                    $t('socialhq_plan.sheet.format')
                                }}</Label>
                                <NativeSelect
                                    id="plan-item-format"
                                    v-model="form.content_type"
                                    data-testid="plan-item-format"
                                >
                                    <NativeSelectOption
                                        v-for="type in contentTypes"
                                        :key="type.value"
                                        :value="type.value"
                                    >
                                        {{ type.label }}
                                    </NativeSelectOption>
                                </NativeSelect>
                                <p
                                    v-if="form.errors.content_type"
                                    class="text-sm text-destructive"
                                >
                                    {{ form.errors.content_type }}
                                </p>
                            </div>

                            <div class="flex flex-col gap-2">
                                <Label for="plan-item-creative">{{
                                    $t('socialhq_plan.sheet.creative')
                                }}</Label>
                                <NativeSelect
                                    id="plan-item-creative"
                                    v-model="form.creative"
                                    data-testid="plan-item-creative"
                                >
                                    <NativeSelectOption
                                        v-for="creative in creatives"
                                        :key="creative"
                                        :value="creative"
                                    >
                                        {{
                                            $t(
                                                `socialhq_plan.creative.${creative}`,
                                            )
                                        }}
                                    </NativeSelectOption>
                                </NativeSelect>
                            </div>

                            <div class="flex flex-col gap-2">
                                <Label for="plan-item-pillar">{{
                                    $t('socialhq_plan.sheet.pillar')
                                }}</Label>
                                <NativeSelect
                                    id="plan-item-pillar"
                                    v-model="form.pillar"
                                    data-testid="plan-item-pillar"
                                >
                                    <NativeSelectOption value="">{{
                                        $t('socialhq_plan.sheet.none')
                                    }}</NativeSelectOption>
                                    <NativeSelectOption
                                        v-for="pillar in pillarOptions"
                                        :key="pillar"
                                        :value="pillar"
                                    >
                                        {{ pillar }}
                                    </NativeSelectOption>
                                </NativeSelect>
                                <p
                                    v-if="form.errors.pillar"
                                    class="text-sm text-destructive"
                                >
                                    {{ form.errors.pillar }}
                                </p>
                            </div>

                            <div class="flex flex-col gap-2">
                                <Label for="plan-item-owner">{{
                                    $t('socialhq_plan.sheet.owner')
                                }}</Label>
                                <NativeSelect
                                    id="plan-item-owner"
                                    v-model="form.owner_id"
                                    data-testid="plan-item-owner"
                                >
                                    <NativeSelectOption value="">{{
                                        $t('socialhq_plan.sheet.nobody')
                                    }}</NativeSelectOption>
                                    <NativeSelectOption
                                        v-for="owner in owners"
                                        :key="owner.id"
                                        :value="owner.id"
                                    >
                                        {{ owner.name }}
                                    </NativeSelectOption>
                                </NativeSelect>
                                <p
                                    v-if="form.errors.owner_id"
                                    class="text-sm text-destructive"
                                >
                                    {{ form.errors.owner_id }}
                                </p>
                            </div>

                            <div class="col-span-2 flex flex-col gap-2">
                                <Label for="plan-item-event">{{
                                    $t('socialhq_plan.sheet.event')
                                }}</Label>
                                <NativeSelect
                                    id="plan-item-event"
                                    v-model="form.event_id"
                                    data-testid="plan-item-event"
                                >
                                    <NativeSelectOption value="">{{
                                        $t('socialhq_plan.sheet.no_event')
                                    }}</NativeSelectOption>
                                    <NativeSelectOption
                                        v-for="event in events"
                                        :key="event.id"
                                        :value="String(event.id)"
                                    >
                                        {{
                                            event.confirmed
                                                ? event.name
                                                : $t(
                                                      'socialhq_plan.sheet.event_needs_check',
                                                      { name: event.name },
                                                  )
                                        }}
                                    </NativeSelectOption>
                                </NativeSelect>
                                <p
                                    v-if="form.errors.event_id"
                                    class="text-sm text-destructive"
                                >
                                    {{ form.errors.event_id }}
                                </p>
                            </div>
                        </div>

                        <div class="flex flex-col gap-2">
                            <Label for="plan-item-objective">{{
                                $t('socialhq_plan.sheet.objective')
                            }}</Label>
                            <Textarea
                                id="plan-item-objective"
                                v-model="form.objective"
                                rows="2"
                                :placeholder="
                                    $t(
                                        'socialhq_plan.sheet.objective_placeholder',
                                    )
                                "
                                data-testid="plan-item-objective"
                            />
                            <p
                                v-if="form.errors.objective"
                                class="text-sm text-destructive"
                            >
                                {{ form.errors.objective }}
                            </p>
                        </div>

                        <div class="flex flex-col gap-2">
                            <Label for="plan-item-angle">{{
                                $t('socialhq_plan.sheet.angle')
                            }}</Label>
                            <Textarea
                                id="plan-item-angle"
                                v-model="form.angle"
                                rows="4"
                                :placeholder="
                                    $t('socialhq_plan.sheet.angle_placeholder')
                                "
                                data-testid="plan-item-angle"
                            />
                            <p
                                v-if="form.errors.angle"
                                class="text-sm text-destructive"
                            >
                                {{ form.errors.angle }}
                            </p>
                        </div>
                    </fieldset>
                </form>

                <SheetFooter
                    v-if="canWork"
                    class="flex-row flex-wrap items-center gap-2 border-t border-border px-5 py-3"
                >
                    <Button
                        type="button"
                        variant="outline"
                        data-testid="plan-item-cancel"
                        @click="close"
                    >
                        {{ $t('socialhq_plan.sheet.cancel') }}
                    </Button>
                    <template v-if="item">
                        <Button
                            type="button"
                            variant="ghost"
                            :loading="statusProcessing"
                            data-testid="plan-item-toggle-drop"
                            @click="toggleDropped"
                        >
                            <IconArrowBackUp
                                v-if="item.status === 'dropped'"
                                class="size-4"
                            />
                            <IconX v-else class="size-4" />
                            {{
                                item.status === 'dropped'
                                    ? $t('socialhq_plan.item.restore')
                                    : $t('socialhq_plan.item.drop')
                            }}
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            class="text-destructive-text hover:text-destructive-text"
                            data-testid="plan-item-delete"
                            @click="askDelete"
                        >
                            {{ $t('socialhq_plan.item.delete') }}
                        </Button>
                    </template>
                    <Button
                        type="submit"
                        form="plan-item-form"
                        class="ms-auto"
                        :loading="form.processing"
                        data-testid="plan-item-save"
                    >
                        {{
                            isNew
                                ? $t('socialhq_plan.sheet.add')
                                : $t('socialhq_plan.sheet.save')
                        }}
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>

        <ConfirmDeleteModal
            ref="confirmDelete"
            :title="$t('socialhq_plan.delete.title')"
            :description="$t('socialhq_plan.delete.description')"
            :action="$t('socialhq_plan.item.delete')"
            :cancel="$t('socialhq_plan.sheet.cancel')"
            @deleted="close"
        />
    </div>
</template>
