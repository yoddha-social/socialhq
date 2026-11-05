<script setup lang="ts">
import { IconCheck, IconSparkles, IconX } from '@tabler/icons-vue';
import { computed } from 'vue';

import PlatformBrandIcon from '@/components/PlatformBrandIcon.vue';
import {
    COPY_STATUS_DOT,
    copyStatusLabelKey,
} from '@/components/socialhq/plan/copyStatus';
import { pillarColor } from '@/components/socialhq/plan/pillarColors';
import type { PlanItem, PlanLookups } from '@/components/socialhq/plan/types';
import { Avatar } from '@/components/ui/avatar';

const props = defineProps<{
    item: PlanItem;
    lookups: PlanLookups;
    canWork: boolean;
    busy?: boolean;
}>();

const emit = defineEmits<{
    open: [item: PlanItem];
    accept: [item: PlanItem];
    drop: [item: PlanItem];
}>();

const channel = computed(() =>
    props.item.social_account_id
        ? (props.lookups.channels[props.item.social_account_id] ?? null)
        : null,
);

const owner = computed(() =>
    props.item.owner_id
        ? (props.lookups.owners[props.item.owner_id] ?? null)
        : null,
);

const pillar = computed(() =>
    pillarColor(props.item.pillar, props.lookups.pillars),
);

const isSuggestion = computed(() => props.item.status === 'suggested');
const isDropped = computed(() => props.item.status === 'dropped');

const openItem = (): void => emit('open', props.item);
const acceptItem = (): void => emit('accept', props.item);
const dropItem = (): void => emit('drop', props.item);
</script>

<template>
    <div
        class="group/card relative flex min-w-0 flex-col rounded-lg border text-start transition-control"
        :class="{
            'border-border-strong bg-card hover:bg-secondary':
                !isSuggestion && !isDropped,
            'border-dashed border-primary/50 bg-primary-subtle/40 hover:bg-primary-subtle/70':
                isSuggestion,
            'border-dashed border-border bg-transparent opacity-60 hover:opacity-90':
                isDropped,
        }"
        :data-status="item.status"
        :data-testid="`plan-item-${item.id}`"
    >
        <button
            type="button"
            class="flex min-w-0 flex-col gap-1 rounded-lg px-2 py-1.5 text-start focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
            :aria-label="
                [
                    channel?.name ?? item.platform,
                    item.content_type_label,
                    item.title,
                    $t(`socialhq_plan.item_status.${item.status}`),
                ]
                    .filter(Boolean)
                    .join(' · ')
            "
            :data-testid="`plan-item-open-${item.id}`"
            @click="openItem"
        >
            <span class="flex min-w-0 items-center gap-1.5">
                <PlatformBrandIcon
                    v-if="item.platform"
                    :platform="item.platform"
                    colored
                    class="size-3.5 shrink-0"
                />
                <span
                    class="min-w-0 truncate text-[11px] leading-4 font-medium text-muted-foreground"
                >
                    {{ item.content_type_label }}
                </span>
                <IconSparkles
                    v-if="item.source === 'ai'"
                    class="size-3 shrink-0 text-primary-text"
                    aria-hidden="true"
                />
                <span class="ms-auto flex shrink-0 items-center gap-1">
                    <span
                        class="size-2 rounded-full"
                        :class="COPY_STATUS_DOT[item.copy_status]"
                        :title="$t(copyStatusLabelKey(item.copy_status))"
                        :data-testid="`plan-item-copy-${item.id}`"
                    />
                    <Avatar
                        v-if="owner"
                        :src="owner.photo_url"
                        :name="owner.name"
                        class="size-4 text-[8px]"
                        :title="owner.name"
                    />
                </span>
            </span>
            <span class="flex min-w-0 items-start gap-1.5">
                <span
                    v-if="pillar"
                    class="mt-1 size-2 shrink-0 rounded-full"
                    :class="pillar.dot"
                    :title="item.pillar ?? undefined"
                />
                <span
                    class="line-clamp-2 min-w-0 text-xs leading-4 font-medium text-foreground"
                    :class="{ 'line-through': isDropped }"
                >
                    {{ item.title }}
                </span>
            </span>
        </button>

        <div
            v-if="isSuggestion && canWork"
            class="flex items-center gap-1 border-t border-dashed border-primary/30 px-1 py-1"
        >
            <button
                type="button"
                class="inline-flex h-6 flex-1 items-center justify-center gap-1 rounded-md text-[11px] font-medium text-primary-text transition-control hover:bg-primary-subtle focus-visible:outline-2 focus-visible:outline-ring disabled:opacity-50"
                :disabled="busy"
                :aria-label="
                    $t('socialhq_plan.item.accept_named', { title: item.title })
                "
                :data-testid="`plan-item-accept-${item.id}`"
                @click="acceptItem"
            >
                <IconCheck class="size-3.5" aria-hidden="true" />
                {{ $t('socialhq_plan.item.accept') }}
            </button>
            <button
                type="button"
                class="inline-flex h-6 flex-1 items-center justify-center gap-1 rounded-md text-[11px] font-medium text-muted-foreground transition-control hover:bg-accent hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring disabled:opacity-50"
                :disabled="busy"
                :aria-label="
                    $t('socialhq_plan.item.drop_named', { title: item.title })
                "
                :data-testid="`plan-item-drop-${item.id}`"
                @click="dropItem"
            >
                <IconX class="size-3.5" aria-hidden="true" />
                {{ $t('socialhq_plan.item.drop') }}
            </button>
        </div>
    </div>
</template>
