<script setup lang="ts">
import { IconX } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';

import {
    tagKey,
    type Keyword,
    type KeywordOccasion,
    type KeywordTag,
} from '@/components/socialhq/keywords/keywords';
import KeywordTagChip from '@/components/socialhq/keywords/KeywordTagChip.vue';
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
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import date from '@/date';
import dayjs from '@/dayjs';

/**
 * A keyword's tags: the brand's content pillars and products or services
 * to tick, and occasions (an event of the Events page, which counts every
 * year, or a range of months) to add. Nothing is saved until "Save tags".
 */
const props = defineProps<{
    keyword: Keyword | null;
    pillars: string[];
    products: { id: number; title: string }[];
    occasions: KeywordOccasion[];
    occasionDays: { before: number; after: number };
    maxTags: number;
    saving: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    save: [tags: KeywordTag[]];
}>();

const tags = ref<KeywordTag[]>([]);
const eventKey = ref('');
const monthFrom = ref('1');
const monthTo = ref('1');

watch(
    () => [open.value, props.keyword] as const,
    ([isOpen, keyword]) => {
        if (isOpen && keyword) {
            tags.value = keyword.tags.map((tag) => ({ ...tag }));
            eventKey.value = '';
            monthFrom.value = String(dayjs().month() + 1);
            monthTo.value = String(dayjs().month() + 1);
        }
    },
    { immediate: true },
);

const keys = computed(() => new Set(tags.value.map(tagKey)));
const isFull = computed(() => tags.value.length >= props.maxTags);

/** Pillars to tick: the brand's, and any a tag still names after a rename. */
const pillarChoices = computed(() => {
    const names = [...props.pillars];

    for (const tag of tags.value) {
        if (
            tag.kind === 'pillar' &&
            tag.value &&
            !names.some(
                (name) => name.toLowerCase() === tag.value?.toLowerCase(),
            )
        ) {
            names.push(tag.value);
        }
    }

    return names;
});

const pillarTag = (name: string): KeywordTag => ({
    kind: 'pillar',
    value: name,
    entry_id: null,
    from: null,
    to: null,
    label: name,
});

const productTag = (product: { id: number; title: string }): KeywordTag => ({
    kind: 'product',
    value: null,
    entry_id: product.id,
    from: null,
    to: null,
    label: product.title,
});

const has = (tag: KeywordTag): boolean => keys.value.has(tagKey(tag));

const toggle = (tag: KeywordTag, on: boolean | 'indeterminate'): void => {
    if (on === true && !has(tag) && !isFull.value) {
        tags.value = [...tags.value, tag];
    } else if (on !== true) {
        tags.value = tags.value.filter(
            (other) => tagKey(other) !== tagKey(tag),
        );
    }
};

const togglePillar = (name: string, on: boolean | 'indeterminate'): void => {
    toggle(pillarTag(name), on);
};

const toggleProduct = (
    product: { id: number; title: string },
    on: boolean | 'indeterminate',
): void => {
    toggle(productTag(product), on);
};

const MONTHS = Array.from({ length: 12 }, (_, index) => index + 1);
const monthName = (month: number): string =>
    dayjs()
        .month(month - 1)
        .format('MMMM');

const occasionTags = computed(() =>
    tags.value.filter((tag) => tag.kind === 'event' || tag.kind === 'months'),
);

const addEvent = (): void => {
    const occasion = props.occasions.find(
        (choice) => choice.key === eventKey.value,
    );

    if (!occasion) {
        return;
    }

    toggle(
        {
            kind: 'event',
            value: occasion.key,
            entry_id: null,
            from: null,
            to: null,
            label: occasion.name,
        },
        true,
    );
    eventKey.value = '';
};

const addMonths = (): void => {
    const from = Number(monthFrom.value);
    const to = Number(monthTo.value);

    toggle(
        {
            kind: 'months',
            value: null,
            entry_id: null,
            from,
            to,
            label:
                from === to
                    ? monthName(from)
                    : trans('socialhq.keywords.tags.months_range', {
                          from: monthName(from),
                          to: monthName(to),
                      }),
        },
        true,
    );
};

const removeTag = (tag: KeywordTag): void => {
    toggle(tag, false);
};

const close = (): void => {
    open.value = false;
};

const save = (): void => {
    emit('save', tags.value);
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="max-h-[90vh] overflow-y-auto sm:max-w-lg"
            data-testid="socialhq-keyword-tags-dialog"
        >
            <form class="flex flex-col gap-5" @submit.prevent="save">
                <DialogHeader>
                    <DialogTitle>
                        {{
                            $t('socialhq.keywords.tags.dialog.title', {
                                keyword: keyword?.text ?? '',
                            })
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{ $t('socialhq.keywords.tags.dialog.description') }}
                    </DialogDescription>
                </DialogHeader>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium text-foreground">
                        {{ $t('socialhq.keywords.tags.dialog.pillars') }}
                    </legend>
                    <div
                        v-if="pillarChoices.length > 0"
                        class="flex flex-wrap gap-x-4 gap-y-2"
                    >
                        <label
                            v-for="(name, index) in pillarChoices"
                            :key="name"
                            class="flex items-center gap-2 text-sm"
                        >
                            <Checkbox
                                :model-value="has(pillarTag(name))"
                                :disabled="isFull && !has(pillarTag(name))"
                                :data-testid="`socialhq-keyword-tags-pillar-${index}`"
                                @update:model-value="togglePillar(name, $event)"
                            />
                            {{ name }}
                        </label>
                    </div>
                    <p v-else class="text-[13px] text-muted-foreground">
                        {{ $t('socialhq.keywords.tags.dialog.pillars_none') }}
                    </p>
                </fieldset>

                <fieldset class="grid gap-2">
                    <legend class="text-sm font-medium text-foreground">
                        {{ $t('socialhq.keywords.tags.dialog.products') }}
                    </legend>
                    <p class="text-xs text-muted-foreground">
                        {{ $t('socialhq.keywords.tags.dialog.products_hint') }}
                    </p>
                    <div
                        v-if="products.length > 0"
                        class="grid max-h-40 gap-2 overflow-y-auto pe-1"
                    >
                        <label
                            v-for="product in products"
                            :key="product.id"
                            class="flex items-center gap-2 text-sm"
                        >
                            <Checkbox
                                :model-value="has(productTag(product))"
                                :disabled="isFull && !has(productTag(product))"
                                :data-testid="`socialhq-keyword-tags-product-${product.id}`"
                                @update:model-value="
                                    toggleProduct(product, $event)
                                "
                            />
                            <span class="min-w-0 truncate">{{
                                product.title
                            }}</span>
                        </label>
                    </div>
                    <p v-else class="text-[13px] text-muted-foreground">
                        {{ $t('socialhq.keywords.tags.dialog.products_none') }}
                    </p>
                </fieldset>

                <fieldset class="grid gap-2">
                    <legend class="text-sm font-medium text-foreground">
                        {{ $t('socialhq.keywords.tags.dialog.occasions') }}
                    </legend>
                    <p class="text-xs text-muted-foreground">
                        {{
                            $t('socialhq.keywords.tags.dialog.occasions_hint', {
                                before: String(occasionDays.before),
                                after: String(occasionDays.after),
                            })
                        }}
                    </p>
                    <div
                        v-if="occasionTags.length > 0"
                        class="flex flex-wrap gap-1.5"
                    >
                        <span
                            v-for="(tag, index) in occasionTags"
                            :key="tagKey(tag)"
                            class="inline-flex max-w-full items-center gap-0.5"
                        >
                            <KeywordTagChip
                                :kind="tag.kind"
                                :label="tag.label"
                            />
                            <button
                                type="button"
                                class="inline-flex size-6 shrink-0 items-center justify-center rounded-md text-subtle-foreground hover:bg-accent"
                                :aria-label="
                                    $t('socialhq.keywords.tags.remove', {
                                        tag: tag.label,
                                    })
                                "
                                :data-testid="`socialhq-keyword-tags-remove-${index}`"
                                @click="removeTag(tag)"
                            >
                                <IconX class="size-3.5" />
                            </button>
                        </span>
                    </div>
                    <p v-else class="text-[13px] text-muted-foreground">
                        {{ $t('socialhq.keywords.tags.dialog.chosen_none') }}
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <NativeSelect
                            v-model="eventKey"
                            class="w-full max-w-72 font-normal"
                            :aria-label="
                                $t('socialhq.keywords.tags.dialog.event_label')
                            "
                            :disabled="occasions.length === 0"
                            data-testid="socialhq-keyword-tags-event"
                        >
                            <NativeSelectOption value="">
                                {{
                                    occasions.length > 0
                                        ? $t(
                                              'socialhq.keywords.tags.dialog.event_placeholder',
                                          )
                                        : $t(
                                              'socialhq.keywords.tags.dialog.events_none',
                                          )
                                }}
                            </NativeSelectOption>
                            <NativeSelectOption
                                v-for="occasion in occasions"
                                :key="occasion.key"
                                :value="occasion.key"
                            >
                                {{ occasion.name }} ·
                                {{ date.formatDateOnly(occasion.starts_on) }}
                            </NativeSelectOption>
                        </NativeSelect>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="eventKey === '' || isFull"
                            data-testid="socialhq-keyword-tags-add-event"
                            @click="addEvent"
                        >
                            {{ $t('socialhq.keywords.tags.dialog.add_event') }}
                        </Button>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <NativeSelect
                            v-model="monthFrom"
                            class="font-normal"
                            :aria-label="
                                $t('socialhq.keywords.tags.dialog.months_from')
                            "
                            data-testid="socialhq-keyword-tags-from"
                        >
                            <NativeSelectOption
                                v-for="month in MONTHS"
                                :key="month"
                                :value="String(month)"
                            >
                                {{ monthName(month) }}
                            </NativeSelectOption>
                        </NativeSelect>
                        <span class="text-sm text-muted-foreground">–</span>
                        <NativeSelect
                            v-model="monthTo"
                            class="font-normal"
                            :aria-label="
                                $t('socialhq.keywords.tags.dialog.months_to')
                            "
                            data-testid="socialhq-keyword-tags-to"
                        >
                            <NativeSelectOption
                                v-for="month in MONTHS"
                                :key="month"
                                :value="String(month)"
                            >
                                {{ monthName(month) }}
                            </NativeSelectOption>
                        </NativeSelect>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="isFull"
                            data-testid="socialhq-keyword-tags-add-months"
                            @click="addMonths"
                        >
                            {{ $t('socialhq.keywords.tags.dialog.add_months') }}
                        </Button>
                    </div>
                </fieldset>

                <p v-if="isFull" class="text-xs text-muted-foreground">
                    {{
                        $t('socialhq.keywords.tags.dialog.max', {
                            max: String(maxTags),
                        })
                    }}
                </p>

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="close">
                        {{ $t('socialhq.keywords.tags.dialog.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :loading="saving"
                        data-testid="socialhq-keyword-tags-save"
                    >
                        {{ $t('socialhq.keywords.tags.dialog.save') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
