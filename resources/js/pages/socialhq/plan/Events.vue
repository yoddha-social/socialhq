<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    IconChevronLeft,
    IconChevronRight,
    IconDownload,
    IconLock,
    IconPlus,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import EmptyState from '@/components/EmptyState.vue';
import EventDialog from '@/components/socialhq/plan/EventDialog.vue';
import EventListItem from '@/components/socialhq/plan/EventListItem.vue';
import EventsEmptyIllustration from '@/components/socialhq/plan/EventsEmptyIllustration.vue';
import type {
    CalendarEventRow,
    EventCategory,
} from '@/components/socialhq/plan/eventStyles';
import PlanHeader from '@/components/socialhq/plan/PlanHeader.vue';
import PlanTabs from '@/components/socialhq/plan/PlanTabs.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import dayjs from '@/dayjs';
import { activeLocale } from '@/language';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    destroy as destroyEvent,
    index as eventsIndex,
    importMethod as importEvents,
    unverify as unverifyEvent,
    verify as verifyEvent,
} from '@/routes/socialhq/plan/events';

type EventsView = 'month' | 'upcoming';
type EventFilter = 'all' | 'nepal' | 'international' | 'brand' | 'unverified';

const props = defineProps<{
    view: EventsView;
    month: string;
    today: string;
    from: string;
    to: string;
    events: CalendarEventRow[];
    hasEvents: boolean;
    categories: EventCategory[];
    abilities: {
        canWork: boolean;
        canApprove: boolean;
        canManageBrand: boolean;
    };
}>();

const FILTERS: EventFilter[] = [
    'all',
    'nepal',
    'international',
    'brand',
    'unverified',
];

/**
 * Every date on this screen goes through here: `dayjs.locale()` is global and
 * not reactive, so the month and day names follow a language switch.
 */
const localized = (value?: dayjs.ConfigType) =>
    dayjs(value).locale(activeLocale.value.toLowerCase());

const filter = ref<EventFilter>('all');

const matches = (event: CalendarEventRow, by: EventFilter): boolean => {
    switch (by) {
        case 'nepal':
            return event.region === 'nepal';
        case 'international':
            return event.region === 'international';
        case 'brand':
            return event.brand_only;
        case 'unverified':
            return !event.verified;
        default:
            return true;
    }
};

const counts = computed<Record<EventFilter, number>>(
    () =>
        Object.fromEntries(
            FILTERS.map((by) => [
                by,
                props.events.filter((event) => matches(event, by)).length,
            ]),
        ) as Record<EventFilter, number>,
);

const setFilter = (by: EventFilter): void => {
    filter.value = by;
};

const showAll = (): void => {
    filter.value = 'all';
};

interface DayGroup {
    key: string;
    bsDate: string | null;
    events: CalendarEventRow[];
}

/**
 * Events grouped by day. One that started before the shown period sits on
 * its first shown day.
 */
const groups = computed<DayGroup[]>(() => {
    const byDay = new Map<string, CalendarEventRow[]>();

    for (const event of props.events) {
        if (!matches(event, filter.value)) {
            continue;
        }

        const key = event.starts_on < props.from ? props.from : event.starts_on;
        byDay.set(key, [...(byDay.get(key) ?? []), event]);
    }

    return [...byDay.entries()]
        .sort(([first], [second]) => first.localeCompare(second))
        .map(([key, events]) => ({
            key,
            events,
            bsDate:
                events.find((event) => event.bs_date && event.starts_on === key)
                    ?.bs_date ?? null,
        }));
});

const isThisMonth = computed(() => props.today.startsWith(props.month));

/** Where a new date starts: today when it is in the shown month, else its first day. */
const defaultDay = computed(() =>
    props.view === 'month' && !isThisMonth.value
        ? `${props.month}-01`
        : props.today,
);

const visit = (query: Record<string, string>): void => {
    router.get(
        eventsIndex.url({ query }),
        {},
        { preserveState: true, preserveScroll: true },
    );
};

const goToPreviousMonth = (): void => {
    visit({
        month: dayjs(`${props.month}-01`)
            .subtract(1, 'month')
            .format('YYYY-MM'),
    });
};

const goToNextMonth = (): void => {
    visit({
        month: dayjs(`${props.month}-01`).add(1, 'month').format('YYYY-MM'),
    });
};

const goToThisMonth = (): void => {
    visit({});
};

const showMonth = (): void => {
    visit({ month: props.month });
};

const showUpcoming = (): void => {
    visit({ view: 'upcoming' });
};

const busyEventId = ref<number | null>(null);

const trackBusy = (event: CalendarEventRow) => ({
    preserveScroll: true,
    preserveState: true,
    onStart: () => {
        busyEventId.value = event.id;
    },
    onFinish: () => {
        busyEventId.value = null;
    },
});

const confirmEvent = (event: CalendarEventRow): void => {
    router.post(verifyEvent.url(event.id), {}, trackBusy(event));
};

const unconfirmEvent = (event: CalendarEventRow): void => {
    router.delete(unverifyEvent.url(event.id), trackBusy(event));
};

const isImporting = ref(false);

const importOfficialDates = (): void => {
    router.post(
        importEvents.url(),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => {
                isImporting.value = true;
            },
            onFinish: () => {
                isImporting.value = false;
            },
        },
    );
};

const isDialogOpen = ref(false);
const editingEvent = ref<CalendarEventRow | null>(null);

const openAddEvent = (): void => {
    editingEvent.value = null;
    isDialogOpen.value = true;
};

const openEditEvent = (event: CalendarEventRow): void => {
    editingEvent.value = event;
    isDialogOpen.value = true;
};

const deletingEvent = ref<CalendarEventRow | null>(null);
const isDeleteOpen = ref(false);
const isDeleting = ref(false);

const openDeleteEvent = (event: CalendarEventRow): void => {
    deletingEvent.value = event;
    isDeleteOpen.value = true;
};

const closeDelete = (): void => {
    isDeleteOpen.value = false;
};

const confirmDelete = (): void => {
    if (deletingEvent.value === null) {
        return;
    }

    router.delete(destroyEvent.url(deletingEvent.value.id), {
        preserveScroll: true,
        preserveState: true,
        onStart: () => {
            isDeleting.value = true;
        },
        onFinish: () => {
            isDeleting.value = false;
        },
        onSuccess: closeDelete,
    });
};
</script>

<template>
    <Head :title="$t('socialhq_events.title')" />

    <AppLayout full-width>
        <template #header>
            <PlanHeader />
        </template>

        <template v-if="abilities.canApprove && hasEvents" #header-actions>
            <div class="flex items-center gap-2">
                <Button
                    variant="outline"
                    class="max-sm:w-8 max-sm:px-0"
                    :loading="isImporting"
                    :aria-label="$t('socialhq_events.actions.import')"
                    data-testid="socialhq-events-import"
                    @click="importOfficialDates"
                >
                    <IconDownload class="size-4" />
                    <span class="max-sm:sr-only">{{
                        $t('socialhq_events.actions.import')
                    }}</span>
                </Button>
                <Button
                    class="max-sm:w-8 max-sm:px-0"
                    :aria-label="$t('socialhq_events.actions.add')"
                    data-testid="socialhq-events-add"
                    @click="openAddEvent"
                >
                    <IconPlus class="size-4" />
                    <span class="max-sm:sr-only">{{
                        $t('socialhq_events.actions.add')
                    }}</span>
                </Button>
            </div>
        </template>

        <div
            class="flex min-h-0 flex-1 flex-col overflow-hidden"
            data-testid="socialhq-events-page"
        >
            <PlanTabs active="events" />

            <div
                class="flex min-h-0 min-w-0 flex-1 flex-col overflow-auto overscroll-contain"
            >
                <div
                    class="mx-auto flex w-full max-w-4xl flex-col gap-5 px-4 pt-6 pb-12 md:px-8"
                >
                    <div class="flex flex-col gap-2">
                        <p class="max-w-3xl text-sm text-muted-foreground">
                            {{ $t('socialhq_events.description') }}
                        </p>
                        <p
                            v-if="!abilities.canApprove"
                            class="flex items-start gap-2 text-sm text-muted-foreground"
                            data-testid="socialhq-events-read-only"
                        >
                            <IconLock class="mt-0.5 size-4 shrink-0" />
                            <span>{{ $t('socialhq_events.read_only') }}</span>
                        </p>
                    </div>

                    <EmptyState
                        v-if="!hasEvents"
                        :title="$t('socialhq_events.empty.title')"
                        :description="
                            abilities.canApprove
                                ? $t('socialhq_events.empty.description')
                                : $t('socialhq_events.empty.description_member')
                        "
                        data-testid="socialhq-events-first-use"
                    >
                        <template #illustration>
                            <EventsEmptyIllustration />
                        </template>
                        <template v-if="abilities.canApprove" #action>
                            <Button
                                :loading="isImporting"
                                data-testid="socialhq-events-import-first"
                                @click="importOfficialDates"
                            >
                                <IconDownload class="size-4" />
                                {{ $t('socialhq_events.actions.import') }}
                            </Button>
                        </template>
                    </EmptyState>

                    <template v-else>
                        <div
                            class="flex flex-wrap items-center justify-between gap-3"
                        >
                            <div
                                v-if="view === 'month'"
                                class="flex items-center gap-1"
                            >
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    :aria-label="
                                        $t('socialhq_events.nav.previous')
                                    "
                                    data-testid="socialhq-events-previous"
                                    @click="goToPreviousMonth"
                                >
                                    <IconChevronLeft
                                        class="size-4 rtl:rotate-180"
                                    />
                                </Button>
                                <h2
                                    class="min-w-36 px-1 text-center text-base font-semibold text-foreground capitalize"
                                    data-testid="socialhq-events-period"
                                >
                                    {{
                                        localized(`${month}-01`).format(
                                            'MMMM YYYY',
                                        )
                                    }}
                                </h2>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    :aria-label="$t('socialhq_events.nav.next')"
                                    data-testid="socialhq-events-next"
                                    @click="goToNextMonth"
                                >
                                    <IconChevronRight
                                        class="size-4 rtl:rotate-180"
                                    />
                                </Button>
                                <Button
                                    v-if="!isThisMonth"
                                    variant="outline"
                                    size="sm"
                                    class="ms-1"
                                    data-testid="socialhq-events-this-month"
                                    @click="goToThisMonth"
                                >
                                    {{ $t('socialhq_events.nav.this_month') }}
                                </Button>
                            </div>
                            <h2
                                v-else
                                class="text-base font-semibold text-foreground"
                                data-testid="socialhq-events-period"
                            >
                                {{ localized(from).format('D MMM') }} –
                                {{ localized(to).format('D MMM YYYY') }}
                            </h2>

                            <div
                                class="inline-flex rounded-lg border border-border-strong p-0.5"
                                role="group"
                                :aria-label="$t('socialhq_events.view.label')"
                            >
                                <button
                                    type="button"
                                    class="h-7 rounded-md px-3 text-sm font-medium transition-control focus-visible:outline-2 focus-visible:outline-ring"
                                    :class="
                                        view === 'month'
                                            ? 'bg-primary-selected text-primary-text'
                                            : 'text-muted-foreground hover:text-foreground'
                                    "
                                    :aria-pressed="view === 'month'"
                                    data-testid="socialhq-events-view-month"
                                    @click="showMonth"
                                >
                                    {{ $t('socialhq_events.view.month') }}
                                </button>
                                <button
                                    type="button"
                                    class="h-7 rounded-md px-3 text-sm font-medium transition-control focus-visible:outline-2 focus-visible:outline-ring"
                                    :class="
                                        view === 'upcoming'
                                            ? 'bg-primary-selected text-primary-text'
                                            : 'text-muted-foreground hover:text-foreground'
                                    "
                                    :aria-pressed="view === 'upcoming'"
                                    data-testid="socialhq-events-view-upcoming"
                                    @click="showUpcoming"
                                >
                                    {{ $t('socialhq_events.view.upcoming') }}
                                </button>
                            </div>
                        </div>

                        <div
                            class="flex flex-wrap items-center gap-2"
                            role="group"
                            :aria-label="$t('socialhq_events.filters.label')"
                            data-testid="socialhq-events-filters"
                        >
                            <button
                                v-for="by in FILTERS"
                                :key="by"
                                type="button"
                                class="inline-flex h-8 items-center gap-2 rounded-full border px-3 text-sm font-medium transition-control focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                                :class="
                                    filter === by
                                        ? 'border-transparent bg-primary-selected text-primary-text'
                                        : by === 'unverified' && counts[by] > 0
                                          ? 'border-dashed border-amber-500/70 bg-card text-foreground hover:bg-accent'
                                          : 'border-border-strong bg-card text-foreground hover:bg-accent'
                                "
                                :aria-pressed="filter === by"
                                :data-testid="`socialhq-events-filter-${by}`"
                                @click="setFilter(by)"
                            >
                                {{ $t(`socialhq_events.filters.${by}`) }}
                                <span
                                    class="text-xs tabular-nums"
                                    :class="
                                        filter === by
                                            ? ''
                                            : 'text-muted-foreground'
                                    "
                                    >{{ counts[by] }}</span
                                >
                            </button>
                        </div>

                        <EmptyState
                            v-if="groups.length === 0"
                            :title="
                                events.length === 0
                                    ? $t('socialhq_events.empty.none')
                                    : $t('socialhq_events.empty.no_match')
                            "
                            :description="
                                events.length === 0
                                    ? $t('socialhq_events.empty.none_hint')
                                    : $t('socialhq_events.empty.no_match_hint')
                            "
                            data-testid="socialhq-events-no-results"
                        >
                            <template v-if="events.length > 0" #action>
                                <Button
                                    variant="outline"
                                    data-testid="socialhq-events-show-all"
                                    @click="showAll"
                                >
                                    {{ $t('socialhq_events.empty.show_all') }}
                                </Button>
                            </template>
                        </EmptyState>

                        <ol
                            v-else
                            class="flex flex-col"
                            data-testid="socialhq-events-list"
                        >
                            <li
                                v-for="group in groups"
                                :key="group.key"
                                class="flex flex-col gap-2 border-t border-border py-4 first:border-t-0 first:pt-0 sm:flex-row sm:gap-6"
                                :data-testid="`socialhq-events-day-${group.key}`"
                            >
                                <div
                                    class="flex shrink-0 items-baseline gap-2 sm:w-28 sm:flex-col sm:items-start sm:gap-0.5"
                                >
                                    <div class="flex items-baseline gap-1.5">
                                        <span
                                            class="text-2xl leading-none font-semibold text-foreground tabular-nums"
                                            >{{
                                                localized(group.key).format('D')
                                            }}</span
                                        >
                                        <span
                                            class="text-sm font-medium text-muted-foreground capitalize"
                                            >{{
                                                localized(group.key).format(
                                                    view === 'month'
                                                        ? 'ddd'
                                                        : 'MMM, ddd',
                                                )
                                            }}</span
                                        >
                                    </div>
                                    <span
                                        v-if="group.bsDate"
                                        class="text-xs text-muted-foreground"
                                        :data-testid="`socialhq-events-bs-${group.key}`"
                                        >{{ group.bsDate }}</span
                                    >
                                    <span
                                        v-if="group.key === today"
                                        class="inline-flex h-4.5 w-fit items-center rounded-full bg-primary-selected px-1.5 text-xs font-medium text-primary-text"
                                        >{{ $t('socialhq_events.today') }}</span
                                    >
                                </div>

                                <ul class="flex min-w-0 flex-1 flex-col gap-2">
                                    <EventListItem
                                        v-for="event in group.events"
                                        :key="event.id"
                                        :event="event"
                                        :can-approve="abilities.canApprove"
                                        :busy="busyEventId === event.id"
                                        :until="
                                            event.ends_on > event.starts_on
                                                ? $t('socialhq_events.until', {
                                                      date: localized(
                                                          event.ends_on,
                                                      ).format('D MMM'),
                                                  })
                                                : null
                                        "
                                        @confirm="confirmEvent"
                                        @unconfirm="unconfirmEvent"
                                        @edit="openEditEvent"
                                        @delete="openDeleteEvent"
                                    />
                                </ul>
                            </li>
                        </ol>
                    </template>
                </div>
            </div>
        </div>
    </AppLayout>

    <EventDialog
        v-if="abilities.canApprove"
        v-model:open="isDialogOpen"
        :event="editingEvent"
        :categories="categories"
        :default-day="defaultDay"
    />

    <Dialog v-if="abilities.canApprove" v-model:open="isDeleteOpen">
        <DialogContent
            class="sm:max-w-md"
            data-testid="socialhq-event-delete-dialog"
        >
            <DialogHeader>
                <DialogTitle>
                    {{
                        $t('socialhq_events.delete.title', {
                            name: deletingEvent?.name ?? '',
                        })
                    }}
                </DialogTitle>
                <DialogDescription>
                    {{ $t('socialhq_events.delete.description') }}
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button type="button" variant="ghost" @click="closeDelete">
                    {{ $t('socialhq_events.dialog.cancel') }}
                </Button>
                <Button
                    variant="destructive"
                    :loading="isDeleting"
                    data-testid="socialhq-event-delete-confirm"
                    @click="confirmDelete"
                >
                    {{ $t('socialhq_events.delete.confirm') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
