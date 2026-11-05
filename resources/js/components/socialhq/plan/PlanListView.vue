<script setup lang="ts">
import { IconSparkles } from '@tabler/icons-vue';

import PlatformBrandIcon from '@/components/PlatformBrandIcon.vue';
import {
    COPY_STATUS_DOT,
    copyStatusLabelKey,
} from '@/components/socialhq/plan/copyStatus';
import { pillarColor } from '@/components/socialhq/plan/pillarColors';
import type { PlanItem, PlanLookups } from '@/components/socialhq/plan/types';
import { Avatar } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import dayjs from '@/dayjs';
import { activeLocale } from '@/language';

const props = defineProps<{
    items: PlanItem[];
    lookups: PlanLookups;
}>();

const emit = defineEmits<{
    open: [item: PlanItem];
}>();

const dayLabel = (value: string): string =>
    dayjs(value).locale(activeLocale.value.toLowerCase()).format('ddd D');

const channelName = (item: PlanItem): string =>
    (item.social_account_id
        ? props.lookups.channels[item.social_account_id]?.name
        : null) ??
    item.platform ??
    '';

const ownerOf = (item: PlanItem) =>
    item.owner_id ? (props.lookups.owners[item.owner_id] ?? null) : null;

const openItem = (item: PlanItem): void => emit('open', item);
</script>

<template>
    <div
        class="min-h-0 flex-1 overflow-auto overscroll-contain"
        data-testid="plan-list-view"
    >
        <Table class="min-w-[760px]">
            <TableHeader class="sticky top-0 z-10 bg-card">
                <TableRow>
                    <TableHead class="w-20 text-muted-foreground">{{
                        $t('socialhq_plan.list.date')
                    }}</TableHead>
                    <TableHead class="w-48 text-muted-foreground">{{
                        $t('socialhq_plan.list.channel')
                    }}</TableHead>
                    <TableHead class="w-28 text-muted-foreground">{{
                        $t('socialhq_plan.list.format')
                    }}</TableHead>
                    <TableHead class="text-muted-foreground">{{
                        $t('socialhq_plan.list.title')
                    }}</TableHead>
                    <TableHead class="w-32 text-muted-foreground">{{
                        $t('socialhq_plan.list.pillar')
                    }}</TableHead>
                    <TableHead class="w-36 text-muted-foreground">{{
                        $t('socialhq_plan.list.owner')
                    }}</TableHead>
                    <TableHead class="w-32 text-muted-foreground">{{
                        $t('socialhq_plan.list.copy')
                    }}</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow
                    v-for="item in items"
                    :key="item.id"
                    class="cursor-pointer focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                    :class="{
                        'bg-primary-subtle/30': item.status === 'suggested',
                        'opacity-60': item.status === 'dropped',
                    }"
                    tabindex="0"
                    :data-status="item.status"
                    :data-testid="`plan-row-${item.id}`"
                    @click="openItem(item)"
                    @keydown.enter="openItem(item)"
                >
                    <TableCell
                        class="text-xs font-medium whitespace-nowrap text-muted-foreground capitalize"
                    >
                        {{ dayLabel(item.scheduled_on) }}
                    </TableCell>
                    <TableCell>
                        <span class="flex min-w-0 items-center gap-1.5">
                            <PlatformBrandIcon
                                v-if="item.platform"
                                :platform="item.platform"
                                colored
                                class="size-4 shrink-0"
                            />
                            <span class="truncate text-sm">{{
                                channelName(item)
                            }}</span>
                        </span>
                    </TableCell>
                    <TableCell class="text-sm text-muted-foreground">
                        {{ item.content_type_label }}
                    </TableCell>
                    <TableCell>
                        <span class="flex min-w-0 items-center gap-2">
                            <span
                                class="truncate text-sm font-medium"
                                :class="{
                                    'line-through': item.status === 'dropped',
                                }"
                                >{{ item.title }}</span
                            >
                            <Badge
                                v-if="item.status === 'suggested'"
                                variant="info"
                                class="px-1.5"
                            >
                                <IconSparkles aria-hidden="true" />
                                {{ $t('socialhq_plan.item_status.suggested') }}
                            </Badge>
                            <Badge
                                v-else-if="item.status === 'dropped'"
                                variant="outline"
                                class="px-1.5"
                            >
                                {{ $t('socialhq_plan.item_status.dropped') }}
                            </Badge>
                        </span>
                    </TableCell>
                    <TableCell>
                        <span
                            v-if="item.pillar"
                            class="inline-flex max-w-full items-center gap-1.5 text-sm"
                        >
                            <span
                                class="size-2 shrink-0 rounded-full"
                                :class="
                                    pillarColor(item.pillar, lookups.pillars)
                                        ?.dot
                                "
                            />
                            <span class="truncate">{{ item.pillar }}</span>
                        </span>
                    </TableCell>
                    <TableCell>
                        <span
                            v-if="ownerOf(item)"
                            class="flex min-w-0 items-center gap-1.5"
                        >
                            <Avatar
                                :src="ownerOf(item)?.photo_url"
                                :name="ownerOf(item)?.name"
                                class="size-5 text-[9px]"
                            />
                            <span class="truncate text-sm">{{
                                ownerOf(item)?.name
                            }}</span>
                        </span>
                    </TableCell>
                    <TableCell>
                        <span class="inline-flex items-center gap-1.5 text-sm">
                            <span
                                class="size-2 shrink-0 rounded-full"
                                :class="COPY_STATUS_DOT[item.copy_status]"
                            />
                            {{ $t(copyStatusLabelKey(item.copy_status)) }}
                        </span>
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
    </div>
</template>
