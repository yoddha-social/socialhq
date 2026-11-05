<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    IconArrowLeft,
    IconCalendarEvent,
    IconCircleCheck,
} from '@tabler/icons-vue';
import { computed } from 'vue';

import PlatformLogo from '@/components/PlatformLogo.vue';
import type {
    CopyStatus,
    PlanItemDetail,
} from '@/components/socialhq/plan/item/types';
import { Avatar } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import dayjs from '@/dayjs';
import { show as planShow } from '@/routes/socialhq/plan';

const props = defineProps<{
    item: PlanItemDetail;
}>();

const monthLabel = computed(() =>
    dayjs(`${props.item.month}-01`).format('MMMM YYYY'),
);
const dayLabel = computed(() =>
    dayjs(props.item.scheduled_on).format('dddd, LL'),
);

const copyVariant = (
    status: CopyStatus,
): 'secondary' | 'info' | 'warning' | 'success' | 'outline' => {
    switch (status) {
        case 'in_review':
            return 'info';
        case 'changes_requested':
            return 'warning';
        case 'approved':
            return 'success';
        case 'draft':
            return 'secondary';
        default:
            return 'outline';
    }
};
</script>

<template>
    <div class="grid gap-3" data-testid="item-summary">
        <Link
            :href="planShow.url({ month: item.month })"
            class="inline-flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
            data-testid="item-back"
        >
            <IconArrowLeft class="size-4" />
            {{ $t('socialhq_production.item.back', { month: monthLabel }) }}
        </Link>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1
                    class="text-xl font-semibold text-balance"
                    data-testid="item-title"
                >
                    {{ item.title }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    <time :datetime="item.scheduled_on">{{ dayLabel }}</time>
                </p>
            </div>
            <div
                v-if="item.owner"
                class="flex items-center gap-2 text-sm"
                data-testid="item-owner"
            >
                <Avatar
                    :src="item.owner.photo_url"
                    :name="item.owner.name"
                    class="size-6"
                    fallback-class="text-[10px] bg-primary-subtle text-primary-text font-bold"
                />
                <span class="text-muted-foreground">
                    {{ $t('socialhq_production.item.owner') }}:
                </span>
                <span class="font-medium">{{ item.owner.name }}</span>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2" data-testid="item-chips">
            <span
                class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs"
            >
                <PlatformLogo
                    v-if="item.channel || item.platform"
                    :platform="item.channel?.platform ?? item.platform ?? ''"
                    size="xs"
                />
                {{
                    item.channel?.name ??
                    item.platform_label ??
                    $t('socialhq_production.item.no_channel')
                }}
                <template v-if="item.content_type_label">
                    · {{ item.content_type_label }}
                </template>
            </span>
            <Badge variant="outline">
                {{ $t(`socialhq_production.item.creative.${item.creative}`) }}
            </Badge>
            <Badge v-if="item.pillar" variant="secondary">
                {{ item.pillar }}
            </Badge>
            <Badge
                v-if="item.event"
                variant="outline"
                :class="item.event.verified ? '' : 'border-dashed'"
            >
                <IconCalendarEvent />
                {{
                    $t('socialhq_production.item.event', {
                        name: item.event.name,
                    })
                }}
                <template v-if="!item.event.verified">
                    · {{ $t('socialhq_production.item.event_unverified') }}
                </template>
            </Badge>
            <Badge
                :variant="copyVariant(item.copy_status)"
                data-testid="item-copy-status"
            >
                <IconCircleCheck v-if="item.copy_status === 'approved'" />
                {{
                    $t(
                        `socialhq_production.item.copy_status.${item.copy_status}`,
                    )
                }}
            </Badge>
        </div>
        <dl
            v-if="item.objective || item.angle"
            class="grid gap-2 text-sm sm:grid-cols-2"
        >
            <div v-if="item.objective">
                <dt class="text-xs font-medium text-muted-foreground">
                    {{ $t('socialhq_production.item.objective') }}
                </dt>
                <dd>{{ item.objective }}</dd>
            </div>
            <div v-if="item.angle">
                <dt class="text-xs font-medium text-muted-foreground">
                    {{ $t('socialhq_production.item.angle') }}
                </dt>
                <dd>{{ item.angle }}</dd>
            </div>
        </dl>
    </div>
</template>
