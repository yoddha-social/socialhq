<script setup lang="ts">
import {
    IconArrowBackUp,
    IconArrowDown,
    IconArrowUp,
    IconArrowsSort,
    IconCheck,
    IconSparkles,
    IconX,
} from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

import PlatformBrandIcon from '@/components/PlatformBrandIcon.vue';
import {
    COPY_STATUS_DOT,
    copyStatusLabelKey,
} from '@/components/socialhq/plan/copyStatus';
import { pillarColor } from '@/components/socialhq/plan/pillarColors';
import PlanItemTime from '@/components/socialhq/plan/PlanItemTime.vue';
import {
    groupByWeek,
    type PlanListSort,
    type PlanListSortKey,
} from '@/components/socialhq/plan/planListSort';
import type { PlanItem, PlanLookups } from '@/components/socialhq/plan/types';
import { Avatar } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { useAtLeastBreakpoint } from '@/composables/useBreakpoint';
import dayjs from '@/dayjs';
import { activeLocale } from '@/language';

const props = defineProps<{
    items: PlanItem[];
    lookups: PlanLookups;
    canWork: boolean;
    busyItemId: number | null;
}>();

const emit = defineEmits<{
    open: [item: PlanItem];
    accept: [item: PlanItem];
    drop: [item: PlanItem];
    restore: [item: PlanItem];
}>();

const SORT_STORAGE_KEY = 'socialhq.plan.list.sort';
const SORT_KEYS: PlanListSortKey[] = [
    'date',
    'channel',
    'pillar',
    'owner',
    'status',
];

const readSort = (): PlanListSort => {
    try {
        const saved = JSON.parse(
            window.localStorage.getItem(SORT_STORAGE_KEY) ?? 'null',
        ) as PlanListSort | null;

        if (saved && SORT_KEYS.includes(saved.key)) {
            return {
                key: saved.key,
                direction: saved.direction === 'desc' ? 'desc' : 'asc',
            };
        }
    } catch {
        // Storage blocked or unreadable: the default order.
    }

    return { key: 'date', direction: 'asc' };
};

const sort = ref<PlanListSort>(readSort());

watch(sort, (next) => {
    try {
        window.localStorage.setItem(SORT_STORAGE_KEY, JSON.stringify(next));
    } catch {
        // Storage blocked: the order lasts until the page is left.
    }
});

const sortBy = (key: PlanListSortKey): void => {
    sort.value =
        sort.value.key === key
            ? {
                  key,
                  direction: sort.value.direction === 'asc' ? 'desc' : 'asc',
              }
            : { key, direction: 'asc' };
};

const ariaSort = (
    key: PlanListSortKey,
): 'ascending' | 'descending' | 'none' => {
    if (sort.value.key !== key) {
        return 'none';
    }

    return sort.value.direction === 'asc' ? 'ascending' : 'descending';
};

const weeks = computed(() =>
    groupByWeek(props.items, sort.value, props.lookups),
);

/** The table on md+, stacked cards below it: one is rendered, never both. */
const isWide = useAtLeastBreakpoint('md');

const localized = (value: string) =>
    dayjs(value).locale(activeLocale.value.toLowerCase());

const dayLabel = (value: string): string => localized(value).format('ddd D');

const weekLabel = (start: string): string => localized(start).format('D MMMM');

const channelName = (item: PlanItem): string =>
    (item.social_account_id
        ? props.lookups.channels[item.social_account_id]?.name
        : null) ??
    item.platform ??
    '';

const ownerOf = (item: PlanItem) =>
    item.owner_id ? (props.lookups.owners[item.owner_id] ?? null) : null;

const eventOf = (item: PlanItem) =>
    item.event_id ? (props.lookups.events[item.event_id] ?? null) : null;

const isBusy = (item: PlanItem): boolean => props.busyItemId === item.id;

const openItem = (item: PlanItem): void => emit('open', item);
const acceptItem = (item: PlanItem): void => emit('accept', item);
const dropItem = (item: PlanItem): void => emit('drop', item);
const restoreItem = (item: PlanItem): void => emit('restore', item);

const COLUMNS: {
    key: PlanListSortKey | null;
    label: string;
    class: string;
}[] = [
    { key: 'date', label: 'socialhq_plan.list.date', class: 'w-24' },
    { key: null, label: 'socialhq_plan.list.time', class: 'w-44' },
    { key: 'channel', label: 'socialhq_plan.list.channel', class: 'w-48' },
    { key: null, label: 'socialhq_plan.list.title', class: '' },
    { key: 'pillar', label: 'socialhq_plan.list.pillar', class: 'w-32' },
    { key: 'owner', label: 'socialhq_plan.list.owner', class: 'w-36' },
    { key: null, label: 'socialhq_plan.list.event', class: 'w-36' },
    { key: null, label: 'socialhq_plan.list.copy', class: 'w-36' },
    { key: 'status', label: 'socialhq_plan.list.status', class: 'w-44' },
];

const actionClass =
    'inline-flex size-7 shrink-0 items-center justify-center rounded-md transition-control focus-visible:outline-2 focus-visible:outline-ring disabled:opacity-50';
</script>

<template>
    <div
        class="min-h-0 flex-1 overflow-auto overscroll-contain"
        data-testid="plan-list-view"
    >
        <table
            v-if="isWide"
            class="w-full min-w-[1080px] table-fixed border-separate border-spacing-0 text-sm"
        >
            <thead>
                <tr>
                    <th
                        v-for="column in COLUMNS"
                        :key="column.label"
                        scope="col"
                        class="sticky top-0 z-20 h-9 border-b bg-card px-2 text-start text-xs font-medium whitespace-nowrap text-muted-foreground"
                        :class="column.class"
                        :aria-sort="
                            column.key ? ariaSort(column.key) : undefined
                        "
                    >
                        <button
                            v-if="column.key"
                            type="button"
                            class="-mx-1 inline-flex items-center gap-1 rounded-sm px-1 hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
                            :aria-label="
                                $t('socialhq_plan.list.sort_by', {
                                    column: $t(column.label),
                                })
                            "
                            :data-testid="`plan-list-sort-${column.key}`"
                            @click="sortBy(column.key)"
                        >
                            {{ $t(column.label) }}
                            <IconArrowUp
                                v-if="
                                    sort.key === column.key &&
                                    sort.direction === 'asc'
                                "
                                class="size-3.5"
                                aria-hidden="true"
                            />
                            <IconArrowDown
                                v-else-if="sort.key === column.key"
                                class="size-3.5"
                                aria-hidden="true"
                            />
                            <IconArrowsSort
                                v-else
                                class="size-3.5 opacity-40"
                                aria-hidden="true"
                            />
                        </button>
                        <template v-else>{{ $t(column.label) }}</template>
                    </th>
                </tr>
            </thead>
            <tbody
                v-for="week in weeks"
                :key="week.start"
                :data-testid="`plan-list-week-${week.start}`"
            >
                <tr>
                    <th
                        :colspan="COLUMNS.length"
                        scope="rowgroup"
                        class="sticky top-9 z-10 h-8 border-b bg-muted px-2 text-start text-xs font-medium text-foreground"
                    >
                        {{
                            $t('socialhq_plan.list.week', {
                                date: weekLabel(week.start),
                            })
                        }}
                        <span class="ms-1 font-normal text-muted-foreground">
                            ·
                            {{
                                $tChoice(
                                    'socialhq_plan.list.week_count',
                                    week.items.length,
                                    { count: String(week.items.length) },
                                )
                            }}
                        </span>
                    </th>
                </tr>
                <tr
                    v-for="item in week.items"
                    :key="item.id"
                    class="group/row h-10 cursor-pointer transition-colors hover:bg-muted/50"
                    :class="{
                        'bg-primary-subtle/30': item.status === 'suggested',
                        'opacity-60': item.status === 'dropped',
                    }"
                    :data-status="item.status"
                    :data-testid="`plan-row-${item.id}`"
                    @click="openItem(item)"
                >
                    <td
                        class="border-b px-2 text-xs font-medium whitespace-nowrap text-muted-foreground capitalize"
                    >
                        {{ dayLabel(item.scheduled_on) }}
                    </td>
                    <td
                        class="border-b px-2 text-xs whitespace-nowrap text-muted-foreground"
                    >
                        <PlanItemTime
                            v-if="item.suggested_at"
                            :item="item"
                            plain
                            class="max-w-full"
                        />
                        <span v-else class="opacity-60">–</span>
                    </td>
                    <td class="border-b px-2">
                        <span class="flex min-w-0 items-center gap-1.5">
                            <PlatformBrandIcon
                                v-if="item.platform"
                                :platform="item.platform"
                                colored
                                class="size-4 shrink-0"
                            />
                            <span class="min-w-0 truncate">
                                {{ channelName(item) }}
                                <span
                                    v-if="item.content_type_label"
                                    class="text-xs text-muted-foreground"
                                    >· {{ item.content_type_label }}</span
                                >
                            </span>
                        </span>
                    </td>
                    <td class="border-b px-2">
                        <span class="flex min-w-0 items-center gap-1.5">
                            <IconSparkles
                                v-if="item.source === 'ai'"
                                class="size-3.5 shrink-0 text-primary-text"
                                aria-hidden="true"
                            />
                            <!-- The row opens on click; this button is how a keyboard or screen reader opens it. -->
                            <button
                                type="button"
                                class="min-w-0 truncate rounded-sm text-start font-medium hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                :class="{
                                    'line-through': item.status === 'dropped',
                                }"
                                :aria-label="
                                    $t('socialhq_plan.list.open_row', {
                                        title: item.title,
                                    })
                                "
                                :data-testid="`plan-row-open-${item.id}`"
                                @click.stop="openItem(item)"
                            >
                                {{ item.title }}
                            </button>
                        </span>
                    </td>
                    <td class="border-b px-2">
                        <span
                            v-if="item.pillar"
                            class="flex min-w-0 items-center gap-1.5"
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
                    </td>
                    <td class="border-b px-2">
                        <span
                            v-if="ownerOf(item)"
                            class="flex min-w-0 items-center gap-1.5"
                        >
                            <Avatar
                                :src="ownerOf(item)?.photo_url"
                                :name="ownerOf(item)?.name"
                                class="size-5 text-[9px]"
                            />
                            <span class="truncate">{{
                                ownerOf(item)?.name
                            }}</span>
                        </span>
                    </td>
                    <td class="border-b px-2">
                        <span
                            v-if="eventOf(item)"
                            class="block truncate text-xs text-muted-foreground"
                            :title="eventOf(item)?.name"
                            >{{ eventOf(item)?.name }}</span
                        >
                    </td>
                    <td class="border-b px-2">
                        <span class="flex min-w-0 items-center gap-1.5">
                            <span
                                class="size-2 shrink-0 rounded-full"
                                :class="COPY_STATUS_DOT[item.copy_status]"
                            />
                            <span class="truncate">{{
                                $t(copyStatusLabelKey(item.copy_status))
                            }}</span>
                        </span>
                    </td>
                    <td class="border-b px-2" @click.stop>
                        <span class="flex items-center gap-1">
                            <Badge
                                :variant="
                                    item.status === 'suggested'
                                        ? 'info'
                                        : item.status === 'dropped'
                                          ? 'outline'
                                          : 'secondary'
                                "
                                class="min-w-0 shrink truncate px-1.5"
                            >
                                {{
                                    $t(
                                        `socialhq_plan.item_status.${item.status}`,
                                    )
                                }}
                            </Badge>
                            <span
                                v-if="canWork"
                                class="ms-auto flex shrink-0 items-center gap-0.5"
                            >
                                <button
                                    v-if="item.status === 'suggested'"
                                    type="button"
                                    :class="actionClass"
                                    class="text-primary-text hover:bg-primary-subtle"
                                    :disabled="isBusy(item)"
                                    :title="$t('socialhq_plan.item.accept')"
                                    :aria-label="
                                        $t('socialhq_plan.item.accept_named', {
                                            title: item.title,
                                        })
                                    "
                                    :data-testid="`plan-row-accept-${item.id}`"
                                    @click="acceptItem(item)"
                                >
                                    <IconCheck
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                </button>
                                <button
                                    v-if="item.status !== 'dropped'"
                                    type="button"
                                    :class="actionClass"
                                    class="text-muted-foreground hover:bg-accent hover:text-foreground"
                                    :disabled="isBusy(item)"
                                    :title="$t('socialhq_plan.item.drop')"
                                    :aria-label="
                                        $t('socialhq_plan.item.drop_named', {
                                            title: item.title,
                                        })
                                    "
                                    :data-testid="`plan-row-drop-${item.id}`"
                                    @click="dropItem(item)"
                                >
                                    <IconX class="size-4" aria-hidden="true" />
                                </button>
                                <button
                                    v-else
                                    type="button"
                                    :class="actionClass"
                                    class="text-muted-foreground hover:bg-accent hover:text-foreground"
                                    :disabled="isBusy(item)"
                                    :title="$t('socialhq_plan.item.restore')"
                                    :aria-label="
                                        $t('socialhq_plan.item.restore_named', {
                                            title: item.title,
                                        })
                                    "
                                    :data-testid="`plan-row-restore-${item.id}`"
                                    @click="restoreItem(item)"
                                >
                                    <IconArrowBackUp
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                </button>
                            </span>
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>

        <div v-else class="flex flex-col" data-testid="plan-list-cards">
            <div
                class="sticky top-0 z-20 flex items-center gap-1 overflow-x-auto border-b bg-card px-3 py-2"
                role="group"
                :aria-label="$t('socialhq_plan.list.sort')"
            >
                <button
                    v-for="key in SORT_KEYS"
                    :key="key"
                    type="button"
                    class="inline-flex h-7 shrink-0 items-center gap-1 rounded-full border px-2.5 text-xs font-medium transition-control focus-visible:outline-2 focus-visible:outline-ring"
                    :class="
                        sort.key === key
                            ? 'border-primary/50 bg-primary-subtle text-primary-text'
                            : 'text-muted-foreground hover:bg-accent'
                    "
                    :aria-pressed="sort.key === key"
                    :aria-label="
                        $t('socialhq_plan.list.sort_by', {
                            column: $t(`socialhq_plan.list.${key}`),
                        })
                    "
                    :data-testid="`plan-list-sort-${key}`"
                    @click="sortBy(key)"
                >
                    {{ $t(`socialhq_plan.list.${key}`) }}
                    <IconArrowUp
                        v-if="sort.key === key && sort.direction === 'asc'"
                        class="size-3"
                        aria-hidden="true"
                    />
                    <IconArrowDown
                        v-else-if="sort.key === key"
                        class="size-3"
                        aria-hidden="true"
                    />
                </button>
            </div>
            <section
                v-for="week in weeks"
                :key="week.start"
                :aria-labelledby="`plan-list-week-${week.start}`"
                :data-testid="`plan-list-week-${week.start}`"
            >
                <h3
                    :id="`plan-list-week-${week.start}`"
                    class="sticky top-11 z-10 border-b bg-muted px-3 py-1.5 text-xs font-medium text-foreground"
                >
                    {{
                        $t('socialhq_plan.list.week', {
                            date: weekLabel(week.start),
                        })
                    }}
                    <span class="font-normal text-muted-foreground">
                        ·
                        {{
                            $tChoice(
                                'socialhq_plan.list.week_count',
                                week.items.length,
                                { count: String(week.items.length) },
                            )
                        }}
                    </span>
                </h3>
                <ul class="flex flex-col divide-y">
                    <li
                        v-for="item in week.items"
                        :key="item.id"
                        class="flex flex-col gap-1.5 px-3 py-2.5"
                        :class="{
                            'bg-primary-subtle/30': item.status === 'suggested',
                            'opacity-60': item.status === 'dropped',
                        }"
                        :data-status="item.status"
                        :data-testid="`plan-row-${item.id}`"
                    >
                        <div
                            class="flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground"
                        >
                            <span class="font-medium capitalize">{{
                                dayLabel(item.scheduled_on)
                            }}</span>
                            <PlanItemTime
                                v-if="item.suggested_at"
                                :item="item"
                                class="min-w-0"
                            />
                        </div>
                        <button
                            type="button"
                            class="flex min-w-0 items-start gap-1.5 rounded-sm text-start text-sm font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                            :class="{
                                'line-through': item.status === 'dropped',
                            }"
                            :aria-label="
                                $t('socialhq_plan.list.open_row', {
                                    title: item.title,
                                })
                            "
                            :data-testid="`plan-row-open-${item.id}`"
                            @click="openItem(item)"
                        >
                            <IconSparkles
                                v-if="item.source === 'ai'"
                                class="mt-0.5 size-3.5 shrink-0 text-primary-text"
                                aria-hidden="true"
                            />
                            <span class="line-clamp-2">{{ item.title }}</span>
                        </button>
                        <div
                            class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground"
                        >
                            <span class="flex min-w-0 items-center gap-1">
                                <PlatformBrandIcon
                                    v-if="item.platform"
                                    :platform="item.platform"
                                    colored
                                    class="size-3.5 shrink-0"
                                />
                                <span class="truncate">{{
                                    channelName(item)
                                }}</span>
                            </span>
                            <span
                                v-if="item.pillar"
                                class="flex items-center gap-1"
                            >
                                <span
                                    class="size-2 rounded-full"
                                    :class="
                                        pillarColor(
                                            item.pillar,
                                            lookups.pillars,
                                        )?.dot
                                    "
                                />
                                {{ item.pillar }}
                            </span>
                            <span v-if="ownerOf(item)">{{
                                ownerOf(item)?.name
                            }}</span>
                            <span v-if="eventOf(item)" class="truncate">{{
                                eventOf(item)?.name
                            }}</span>
                            <span class="flex items-center gap-1">
                                <span
                                    class="size-2 rounded-full"
                                    :class="COPY_STATUS_DOT[item.copy_status]"
                                />
                                {{ $t(copyStatusLabelKey(item.copy_status)) }}
                            </span>
                        </div>
                        <div
                            v-if="canWork && item.status !== 'planned'"
                            class="flex items-center gap-2"
                        >
                            <button
                                v-if="item.status === 'suggested'"
                                type="button"
                                class="inline-flex h-8 flex-1 items-center justify-center gap-1 rounded-md border border-primary/40 text-xs font-medium text-primary-text transition-control hover:bg-primary-subtle focus-visible:outline-2 focus-visible:outline-ring disabled:opacity-50"
                                :disabled="isBusy(item)"
                                :aria-label="
                                    $t('socialhq_plan.item.accept_named', {
                                        title: item.title,
                                    })
                                "
                                :data-testid="`plan-row-accept-${item.id}`"
                                @click="acceptItem(item)"
                            >
                                <IconCheck
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                {{ $t('socialhq_plan.item.accept') }}
                            </button>
                            <button
                                v-if="item.status === 'suggested'"
                                type="button"
                                class="inline-flex h-8 flex-1 items-center justify-center gap-1 rounded-md border text-xs font-medium text-muted-foreground transition-control hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring disabled:opacity-50"
                                :disabled="isBusy(item)"
                                :aria-label="
                                    $t('socialhq_plan.item.drop_named', {
                                        title: item.title,
                                    })
                                "
                                :data-testid="`plan-row-drop-${item.id}`"
                                @click="dropItem(item)"
                            >
                                <IconX class="size-3.5" aria-hidden="true" />
                                {{ $t('socialhq_plan.item.drop') }}
                            </button>
                            <button
                                v-if="item.status === 'dropped'"
                                type="button"
                                class="inline-flex h-8 flex-1 items-center justify-center gap-1 rounded-md border text-xs font-medium text-muted-foreground transition-control hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring disabled:opacity-50"
                                :disabled="isBusy(item)"
                                :aria-label="
                                    $t('socialhq_plan.item.restore_named', {
                                        title: item.title,
                                    })
                                "
                                :data-testid="`plan-row-restore-${item.id}`"
                                @click="restoreItem(item)"
                            >
                                <IconArrowBackUp
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                {{ $t('socialhq_plan.item.restore') }}
                            </button>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
