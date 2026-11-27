<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    IconChecks,
    IconChevronLeft,
    IconChevronRight,
    IconDownload,
    IconEyeOff,
    IconLoader,
    IconLock,
    IconPlus,
    IconWorld,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

import EmptyState from '@/components/EmptyState.vue';
import { localizedDay } from '@/components/socialhq/localizedDay';
import EventCountryDialog from '@/components/socialhq/plan/EventCountryDialog.vue';
import EventDialog from '@/components/socialhq/plan/EventDialog.vue';
import EventGroupCard from '@/components/socialhq/plan/EventGroupCard.vue';
import EventListItem from '@/components/socialhq/plan/EventListItem.vue';
import EventsEmptyIllustration from '@/components/socialhq/plan/EventsEmptyIllustration.vue';
import {
    countryName,
    type CalendarEventGroup,
    type CalendarEventRow,
    type EventCategory,
    type HolidayCountry,
} from '@/components/socialhq/plan/eventStyles';
import PlanHeader from '@/components/socialhq/plan/PlanHeader.vue';
import PlanTabs from '@/components/socialhq/plan/PlanTabs.vue';
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
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import dayjs from '@/dayjs';
import { activeLocale } from '@/language';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    destroy as destroyEvent,
    index as eventsIndex,
    hide as hideEvent,
    importMethod as importEvents,
    unhide as unhideEvent,
    unverify as unverifyEvent,
    verify as verifyEvent,
    verifyShown as verifyShownEvents,
} from '@/routes/socialhq/plan/events';
import { update as updateBrandCountries } from '@/routes/socialhq/plan/events/brand-countries';
import {
    destroy as resolveEventFlag,
    store as storeEventFlag,
} from '@/routes/socialhq/plan/events/flags';
import { undo as undoVerifyShown } from '@/routes/socialhq/plan/events/verify-shown';

type EventsView = 'month' | 'upcoming';
/** A chip: every date, a country the brand follows (`country:NP`), or a kind. */
type EventFilter = string;

const props = defineProps<{
    view: EventsView;
    month: string;
    today: string;
    from: string;
    to: string;
    /** The country filter (ISO code), or null for every country. */
    country: string | null;
    /** One per event: the same holiday on the same day in several countries has a row per country. */
    events: CalendarEventGroup[];
    hasEvents: boolean;
    /** The countries the account has dates for, plus Nepal: what the brand can follow. */
    countries: string[];
    /** The countries whose dates this brand plans around, and whether the brand chose them. */
    brandCountries: { codes: string[]; chosen: boolean };
    /** The audience's countries this brand doesn't follow yet. */
    audienceCountries: string[];
    /** Shared dates of the period this brand hid (approvers only). */
    hidden: CalendarEventRow[];
    /** "Add a country": loaded when the dialog opens; null when Nager.Date can't be reached. */
    holidayCountries?: HolidayCountry[] | null;
    holidayYears: number[];
    maxHolidayCountries: number;
    categories: EventCategory[];
    abilities: {
        canWork: boolean;
        canApprove: boolean;
        canManageBrand: boolean;
        canManageSharedEvents: boolean;
        canChooseCountries: boolean;
    };
}>();

const page = usePage();

/** The country of a `country:NP` chip, or null for another chip. */
const countryOf = (by: EventFilter): string | null =>
    by.startsWith('country:') ? by.slice('country:'.length) : null;

/** One chip per country the brand follows (QA 2026-11-18, item 6), then the kinds. */
const FILTERS = computed<EventFilter[]>(() => [
    'all',
    ...props.brandCountries.codes.map((code) => `country:${code}`),
    'international',
    'brand',
    'unverified',
    'flagged',
]);

const filter = ref<EventFilter>('all');

/** Every country's date on its own: the filters, counts and "Confirm all shown" work on these. */
const rows = computed<CalendarEventRow[]>(() =>
    props.events.flatMap((event) => event.rows),
);

/** Rows of an event in several countries: a dialog about one of them names its country. */
const sharedRowIds = computed(
    () =>
        new Set(
            props.events
                .filter((event) => event.rows.length > 1)
                .flatMap((event) => event.rows.map((row) => row.id)),
        ),
);

/** A date's name for a dialog: "Christmas Day · Nepal" when the event has other countries' rows. */
const dialogName = (event: CalendarEventRow | null): string => {
    if (event === null) {
        return '';
    }

    return sharedRowIds.value.has(event.id) && event.country_code
        ? `${event.name} · ${countryName(event.country_code, activeLocale.value)}`
        : event.name;
};

const matches = (event: CalendarEventRow, by: EventFilter): boolean => {
    const country = countryOf(by);

    if (country !== null) {
        return event.country_code === country;
    }

    switch (by) {
        case 'international':
            return event.region === 'international';
        case 'brand':
            return event.brand_only;
        case 'unverified':
            return !event.verified;
        case 'flagged':
            return event.flags.length > 0;
        default:
            return true;
    }
};

const counts = computed<Record<EventFilter, number>>(
    () =>
        Object.fromEntries(
            FILTERS.value.map((by) => [
                by,
                rows.value.filter((event) => matches(event, by)).length,
            ]),
        ) as Record<EventFilter, number>,
);

/** The brand's countries, named: "Nepal, Australia". */
const brandCountryNames = computed((): string =>
    props.brandCountries.codes
        .map((code) => countryName(code, activeLocale.value))
        .join(', '),
);

const audienceCountryNames = computed((): string =>
    props.audienceCountries
        .map((code) => countryName(code, activeLocale.value))
        .join(', '),
);

const setFilter = (by: EventFilter): void => {
    filter.value = by;
};

const showAll = (): void => {
    filter.value = 'all';
};

/** An event as the filter leaves it: the rows of its countries that match. */
interface ShownEvent {
    id: number;
    rows: CalendarEventRow[];
    /** The one row left, drawn as an event of one country. */
    only: CalendarEventRow | null;
}

interface DayGroup {
    key: string;
    bsDate: string | null;
    events: ShownEvent[];
}

/**
 * Events grouped by day, each with the rows the filter keeps. One that
 * started before the shown period sits on its first shown day.
 */
const groups = computed<DayGroup[]>(() => {
    const byDay = new Map<string, ShownEvent[]>();

    for (const event of props.events) {
        const shown = event.rows.filter((row) => matches(row, filter.value));

        if (shown.length === 0) {
            continue;
        }

        const key = event.starts_on < props.from ? props.from : event.starts_on;
        byDay.set(key, [
            ...(byDay.get(key) ?? []),
            {
                id: event.id,
                rows: shown,
                only: shown.length === 1 ? (shown[0] ?? null) : null,
            },
        ]);
    }

    return [...byDay.entries()]
        .sort(([first], [second]) => first.localeCompare(second))
        .map(([key, events]) => ({
            key,
            events,
            bsDate:
                events
                    .flatMap((event) => event.rows)
                    .find((row) => row.bs_date && row.starts_on === key)
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

/** Every visit keeps the country filter unless it sets its own. */
const visit = (query: Record<string, string>): void => {
    const withCountry =
        props.country && !('country' in query)
            ? { ...query, country: props.country }
            : query;

    router.get(
        eventsIndex.url({
            query: Object.fromEntries(
                Object.entries(withCountry).filter(([, value]) => value !== ''),
            ),
        }),
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

const isCountryDialogOpen = ref(false);
/** Countries "Add a country" starts with ticked: the audience's, from its hint. */
const countryDialogPicks = ref<string[]>([]);

const openAddCountry = (): void => {
    countryDialogPicks.value = [];
    isCountryDialogOpen.value = true;
};

/** Audience countries the account has dates for (Nepal's come from the official list): the brand only needs to follow them. */
const followableAudience = computed(() =>
    props.audienceCountries.filter((code) => props.countries.includes(code)),
);

/** Audience countries whose holidays have to be added first. */
const importableAudience = computed(() =>
    props.audienceCountries.filter((code) => !props.countries.includes(code)),
);

const openAddAudienceCountries = (): void => {
    countryDialogPicks.value = [...importableAudience.value];
    isCountryDialogOpen.value = true;
};

const followAudienceCountries = (): void => {
    router.put(
        updateBrandCountries.url(),
        {
            countries: [
                ...props.brandCountries.codes,
                ...followableAudience.value,
            ],
        },
        { preserveScroll: true },
    );
};

/* Countries this brand follows (owner decision 2026-11-24). */
const isBrandCountriesOpen = ref(false);
const brandCountriesForm = useForm({ countries: [] as string[] });

const countryChoices = computed(() =>
    props.countries
        .map((code) => ({ code, name: countryName(code, activeLocale.value) }))
        .sort((first, second) => first.name.localeCompare(second.name)),
);

const openBrandCountries = (): void => {
    brandCountriesForm.countries = [...props.brandCountries.codes];
    brandCountriesForm.clearErrors();
    isBrandCountriesOpen.value = true;
};

const closeBrandCountries = (): void => {
    isBrandCountriesOpen.value = false;
};

const toggleBrandCountry = (
    code: string,
    on: boolean | 'indeterminate',
): void => {
    const others = brandCountriesForm.countries.filter(
        (picked) => picked !== code,
    );
    brandCountriesForm.countries = on === true ? [...others, code] : others;
};

const saveBrandCountries = (): void => {
    brandCountriesForm.put(updateBrandCountries.url(), {
        preserveScroll: true,
        onSuccess: closeBrandCountries,
    });
};

/* Hide a shared date for this brand, or show it again. */
const hideForBrand = (event: CalendarEventRow): void => {
    router.post(hideEvent.url(event.id), {}, trackBusy(event));
};

const showForBrand = (event: CalendarEventRow): void => {
    router.delete(unhideEvent.url(event.id), trackBusy(event));
};

/* Members flag a date for the brand's approvers. */
const flaggingEvent = ref<CalendarEventRow | null>(null);
const isFlagOpen = ref(false);
const flagForm = useForm({ note: '' });

const openFlag = (event: CalendarEventRow): void => {
    flaggingEvent.value = event;
    flagForm.reset();
    flagForm.clearErrors();
    isFlagOpen.value = true;
};

const closeFlag = (): void => {
    isFlagOpen.value = false;
};

const sendFlag = (): void => {
    if (flaggingEvent.value === null) {
        return;
    }

    flagForm.post(storeEventFlag.url(flaggingEvent.value.id), {
        preserveScroll: true,
        onSuccess: closeFlag,
    });
};

const resolveFlag = (flagId: number): void => {
    router.delete(resolveEventFlag.url(flagId), {
        preserveScroll: true,
        preserveState: true,
    });
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

/** Confirms the date as shown: the server refuses it if it changed since. */
const confirmEvent = (event: CalendarEventRow): void => {
    router.post(
        verifyEvent.url(event.id),
        {
            name: event.name,
            starts_on: event.starts_on,
            ends_on: event.ends_on,
        },
        {
            ...trackBusy(event),
            onError: (errors) => {
                if (errors.event) {
                    toast.error(errors.event);
                }
            },
        },
    );
};

const unconfirmEvent = (event: CalendarEventRow): void => {
    router.delete(unverifyEvent.url(event.id), trackBusy(event));
};

/** The shown dates that need checking and that this person may confirm. */
const confirmableShown = computed(() =>
    props.abilities.canApprove
        ? rows.value.filter(
              (event) =>
                  matches(event, filter.value) &&
                  !event.verified &&
                  (event.brand_only || props.abilities.canManageSharedEvents),
          ).length
        : 0,
);

const isConfirmShownOpen = ref(false);
const isConfirmingShown = ref(false);

const openConfirmShown = (): void => {
    isConfirmShownOpen.value = true;
};

const closeConfirmShown = (): void => {
    isConfirmShownOpen.value = false;
};

/** Confirms what the list shows: the server applies the same period, country and filter. */
const confirmShown = (): void => {
    router.post(
        verifyShownEvents.url(),
        {
            view: props.view,
            month: props.view === 'month' ? props.month : null,
            country: countryOf(filter.value),
            filter: countryOf(filter.value) === null ? filter.value : 'country',
        },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => {
                isConfirmingShown.value = true;
            },
            onFinish: () => {
                isConfirmingShown.value = false;
            },
            onSuccess: () => {
                closeConfirmShown();
                offerUndo();
            },
        },
    );
};

interface ConfirmedBatch {
    message: string;
    ids: number[];
    at: string;
}

/** "Confirm all shown" says what it did and offers Undo (QA 2026-11-18, item 6). */
const offerUndo = (): void => {
    const batch = (
        page.props.flash as { eventsConfirmed?: ConfirmedBatch } | undefined
    )?.eventsConfirmed;

    if (!batch) {
        return;
    }

    toast.success(batch.message, {
        id: 'events-confirmed',
        testId: 'socialhq-events-confirmed-toast',
        duration: 10000,
        action:
            batch.ids.length > 0
                ? {
                      label: trans('socialhq_events.confirm_shown.undo'),
                      onClick: () => undoConfirmShown(batch),
                  }
                : undefined,
    });
};

const undoConfirmShown = (batch: ConfirmedBatch): void => {
    router.post(
        undoVerifyShown.url(),
        { ids: batch.ids, at: batch.at },
        { preserveScroll: true, preserveState: true },
    );
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
                    v-if="abilities.canManageSharedEvents"
                    variant="outline"
                    class="max-sm:w-8 max-sm:px-0"
                    :aria-label="$t('socialhq_events.actions.add_country')"
                    data-testid="socialhq-events-add-country"
                    @click="openAddCountry"
                >
                    <IconWorld class="size-4" />
                    <span class="max-sm:sr-only">{{
                        $t('socialhq_events.actions.add_country')
                    }}</span>
                </Button>
                <Button
                    v-if="abilities.canManageSharedEvents"
                    variant="outline"
                    class="max-sm:w-8 max-sm:px-0"
                    :disabled="isImporting"
                    :aria-busy="isImporting"
                    :aria-label="$t('socialhq_events.actions.import')"
                    data-testid="socialhq-events-import"
                    @click="importOfficialDates"
                >
                    <IconLoader
                        v-if="isImporting"
                        class="size-4 animate-spin"
                    />
                    <IconDownload v-else class="size-4" />
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
                            class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-foreground"
                            data-testid="socialhq-events-brand-countries"
                        >
                            <IconWorld
                                class="size-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <span class="text-muted-foreground">{{
                                $t('socialhq_events.brand_countries.label')
                            }}</span>
                            <span class="font-medium">{{
                                [
                                    brandCountryNames,
                                    $t(
                                        'socialhq_events.brand_countries.international',
                                    ),
                                ]
                                    .filter(Boolean)
                                    .join(' · ')
                            }}</span>
                            <Button
                                v-if="abilities.canChooseCountries"
                                variant="link"
                                size="sm"
                                class="h-auto p-0"
                                data-testid="socialhq-events-brand-countries-change"
                                @click="openBrandCountries"
                            >
                                {{
                                    $t('socialhq_events.brand_countries.change')
                                }}
                            </Button>
                        </p>
                        <div
                            v-if="audienceCountries.length > 0"
                            class="flex flex-wrap items-center gap-2 rounded-lg border border-dashed border-border-strong px-3 py-2 text-sm"
                            data-testid="socialhq-events-audience-hint"
                        >
                            <span
                                class="min-w-0 flex-1 text-muted-foreground"
                                >{{
                                    abilities.canChooseCountries ||
                                    abilities.canManageSharedEvents
                                        ? $t(
                                              'socialhq_events.brand_countries.audience_hint',
                                              {
                                                  countries:
                                                      audienceCountryNames,
                                              },
                                          )
                                        : $t(
                                              'socialhq_events.brand_countries.audience_hint_member',
                                              {
                                                  countries:
                                                      audienceCountryNames,
                                              },
                                          )
                                }}</span
                            >
                            <Button
                                v-if="
                                    abilities.canChooseCountries &&
                                    followableAudience.length > 0
                                "
                                size="sm"
                                variant="outline"
                                data-testid="socialhq-events-audience-follow"
                                @click="followAudienceCountries"
                            >
                                {{
                                    $t(
                                        'socialhq_events.brand_countries.audience_follow',
                                    )
                                }}
                            </Button>
                            <Button
                                v-if="
                                    abilities.canManageSharedEvents &&
                                    importableAudience.length > 0
                                "
                                size="sm"
                                variant="outline"
                                data-testid="socialhq-events-audience-add"
                                @click="openAddAudienceCountries"
                            >
                                {{
                                    $t(
                                        'socialhq_events.brand_countries.audience_add',
                                    )
                                }}
                            </Button>
                        </div>
                        <p
                            v-if="!abilities.canApprove"
                            class="flex items-start gap-2 text-sm text-muted-foreground"
                            data-testid="socialhq-events-read-only"
                        >
                            <IconLock class="mt-0.5 size-4 shrink-0" />
                            <span>{{ $t('socialhq_events.read_only') }}</span>
                        </p>
                        <p
                            v-else-if="!abilities.canManageSharedEvents"
                            class="flex items-start gap-2 text-sm text-muted-foreground"
                            data-testid="socialhq-events-shared-read-only"
                        >
                            <IconLock class="mt-0.5 size-4 shrink-0" />
                            <span>{{
                                $t('socialhq_events.shared_read_only')
                            }}</span>
                        </p>
                    </div>

                    <EmptyState
                        v-if="!hasEvents"
                        :title="$t('socialhq_events.empty.title')"
                        :description="
                            abilities.canManageSharedEvents
                                ? $t('socialhq_events.empty.description')
                                : abilities.canApprove
                                  ? $t(
                                        'socialhq_events.empty.description_brand',
                                    )
                                  : $t(
                                        'socialhq_events.empty.description_member',
                                    )
                        "
                        data-testid="socialhq-events-first-use"
                    >
                        <template #illustration>
                            <EventsEmptyIllustration />
                        </template>
                        <template v-if="abilities.canApprove" #action>
                            <div
                                class="flex flex-wrap items-center justify-center gap-2"
                            >
                                <Button
                                    variant="outline"
                                    data-testid="socialhq-events-add-first"
                                    @click="openAddEvent"
                                >
                                    <IconPlus class="size-4" />
                                    {{ $t('socialhq_events.actions.add') }}
                                </Button>
                                <Button
                                    v-if="abilities.canManageSharedEvents"
                                    :disabled="isImporting"
                                    :aria-busy="isImporting"
                                    data-testid="socialhq-events-import-first"
                                    @click="importOfficialDates"
                                >
                                    <IconLoader
                                        v-if="isImporting"
                                        class="size-4 animate-spin"
                                    />
                                    <IconDownload v-else class="size-4" />
                                    {{ $t('socialhq_events.actions.import') }}
                                </Button>
                                <Button
                                    v-if="abilities.canManageSharedEvents"
                                    variant="outline"
                                    data-testid="socialhq-events-add-country-first"
                                    @click="openAddCountry"
                                >
                                    <IconWorld class="size-4" />
                                    {{
                                        $t(
                                            'socialhq_events.actions.add_country',
                                        )
                                    }}
                                </Button>
                            </div>
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
                                        localizedDay(`${month}-01`).format(
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
                                {{ localizedDay(from).format('D MMM') }} –
                                {{ localizedDay(to).format('D MMM YYYY') }}
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
                                        : (by === 'unverified' ||
                                                by === 'flagged') &&
                                            counts[by] > 0
                                          ? 'border-dashed border-amber-500/70 bg-card text-foreground hover:bg-accent'
                                          : 'border-border-strong bg-card text-foreground hover:bg-accent'
                                "
                                :aria-pressed="filter === by"
                                :data-testid="`socialhq-events-filter-${by.replace(':', '-')}`"
                                @click="setFilter(by)"
                            >
                                <template v-if="countryOf(by)">{{
                                    countryName(
                                        countryOf(by) ?? '',
                                        activeLocale,
                                    )
                                }}</template>
                                <template v-else>{{
                                    $t(`socialhq_events.filters.${by}`)
                                }}</template>
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

                            <Button
                                v-if="confirmableShown > 0"
                                variant="outline"
                                size="sm"
                                class="ms-auto"
                                data-testid="socialhq-events-confirm-shown"
                                @click="openConfirmShown"
                            >
                                <IconChecks class="size-4" />
                                {{ $t('socialhq_events.confirm_shown.button') }}
                            </Button>
                        </div>

                        <EmptyState
                            v-if="groups.length === 0"
                            :title="
                                rows.length === 0
                                    ? $t('socialhq_events.empty.none')
                                    : $t('socialhq_events.empty.no_match')
                            "
                            :description="
                                rows.length > 0
                                    ? $t('socialhq_events.empty.no_match_hint')
                                    : view === 'upcoming'
                                      ? $t(
                                            'socialhq_events.empty.none_hint_upcoming',
                                        )
                                      : $t('socialhq_events.empty.none_hint')
                            "
                            data-testid="socialhq-events-no-results"
                        >
                            <template v-if="rows.length > 0" #action>
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
                                                localizedDay(group.key).format(
                                                    'D',
                                                )
                                            }}</span
                                        >
                                        <span
                                            class="text-sm font-medium text-muted-foreground capitalize"
                                            >{{
                                                localizedDay(group.key).format(
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
                                    <template
                                        v-for="shown in group.events"
                                        :key="shown.id"
                                    >
                                        <EventListItem
                                            v-if="shown.only"
                                            :event="shown.only"
                                            :can-approve="
                                                abilities.canApprove &&
                                                (shown.only.brand_only ||
                                                    abilities.canManageSharedEvents)
                                            "
                                            :busy="
                                                busyEventId === shown.only.id
                                            "
                                            :until="
                                                shown.only.ends_on >
                                                shown.only.starts_on
                                                    ? $t(
                                                          'socialhq_events.until',
                                                          {
                                                              date: localizedDay(
                                                                  shown.only
                                                                      .ends_on,
                                                              ).format('D MMM'),
                                                          },
                                                      )
                                                    : null
                                            "
                                            :can-hide="
                                                abilities.canApprove &&
                                                !shown.only.brand_only
                                            "
                                            :can-flag="abilities.canWork"
                                            :can-resolve-flags="
                                                abilities.canApprove
                                            "
                                            @confirm="confirmEvent"
                                            @unconfirm="unconfirmEvent"
                                            @edit="openEditEvent"
                                            @delete="openDeleteEvent"
                                            @hide="hideForBrand"
                                            @flag="openFlag"
                                            @resolve-flag="resolveFlag"
                                        />
                                        <EventGroupCard
                                            v-else
                                            :rows="shown.rows"
                                            :can-approve="abilities.canApprove"
                                            :can-manage-shared-events="
                                                abilities.canManageSharedEvents
                                            "
                                            :can-work="abilities.canWork"
                                            :busy-event-id="busyEventId"
                                            @confirm="confirmEvent"
                                            @unconfirm="unconfirmEvent"
                                            @edit="openEditEvent"
                                            @delete="openDeleteEvent"
                                            @hide="hideForBrand"
                                            @flag="openFlag"
                                            @resolve-flag="resolveFlag"
                                        />
                                    </template>
                                </ul>
                            </li>
                        </ol>

                        <section
                            v-if="hidden.length > 0"
                            class="flex flex-col gap-2 border-t border-border pt-4"
                            data-testid="socialhq-events-hidden"
                        >
                            <h3
                                class="flex items-center gap-2 text-sm font-semibold text-foreground"
                            >
                                <IconEyeOff
                                    class="size-4 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                {{ $t('socialhq_events.hide.hidden_title') }}
                            </h3>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    $t(
                                        'socialhq_events.hide.hidden_description',
                                    )
                                }}
                            </p>
                            <ul class="flex flex-col gap-2">
                                <EventListItem
                                    v-for="event in hidden"
                                    :key="event.id"
                                    :event="event"
                                    :can-approve="false"
                                    :busy="busyEventId === event.id"
                                    :until="null"
                                    hidden
                                    @unhide="showForBrand"
                                />
                            </ul>
                        </section>
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
        :can-share="abilities.canManageSharedEvents"
    />

    <EventCountryDialog
        v-if="abilities.canManageSharedEvents"
        v-model:open="isCountryDialogOpen"
        :countries="holidayCountries"
        :years="holidayYears"
        :max="maxHolidayCountries"
        :preselected="countryDialogPicks"
        @add-date="openAddEvent"
    />

    <Dialog
        v-if="abilities.canChooseCountries"
        v-model:open="isBrandCountriesOpen"
    >
        <DialogContent
            class="sm:max-w-md"
            data-testid="socialhq-events-brand-countries-dialog"
        >
            <DialogHeader>
                <DialogTitle>
                    {{ $t('socialhq_events.brand_countries.title') }}
                </DialogTitle>
                <DialogDescription>
                    {{ $t('socialhq_events.brand_countries.description') }}
                </DialogDescription>
            </DialogHeader>
            <ul class="flex max-h-72 flex-col gap-1 overflow-y-auto">
                <li v-for="choice in countryChoices" :key="choice.code">
                    <Label
                        class="flex items-center gap-2 rounded-md px-2 py-1.5 font-normal hover:bg-accent"
                    >
                        <Checkbox
                            :model-value="
                                brandCountriesForm.countries.includes(
                                    choice.code,
                                )
                            "
                            :data-testid="`socialhq-events-brand-country-${choice.code}`"
                            @update:model-value="
                                (on) => toggleBrandCountry(choice.code, on)
                            "
                        />
                        {{ choice.name }}
                    </Label>
                </li>
            </ul>
            <p
                v-if="brandCountriesForm.countries.length === 0"
                class="text-xs text-muted-foreground"
            >
                {{ $t('socialhq_events.brand_countries.none') }}
            </p>
            <p class="text-xs text-muted-foreground">
                {{ $t('socialhq_events.brand_countries.add_hint') }}
            </p>
            <p
                v-if="brandCountriesForm.errors.countries"
                class="text-sm text-destructive-text"
            >
                {{ brandCountriesForm.errors.countries }}
            </p>
            <DialogFooter>
                <Button
                    type="button"
                    variant="ghost"
                    @click="closeBrandCountries"
                >
                    {{ $t('socialhq_events.brand_countries.cancel') }}
                </Button>
                <Button
                    :loading="brandCountriesForm.processing"
                    data-testid="socialhq-events-brand-countries-save"
                    @click="saveBrandCountries"
                >
                    {{ $t('socialhq_events.brand_countries.save') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog v-if="abilities.canWork" v-model:open="isFlagOpen">
        <DialogContent
            class="sm:max-w-md"
            data-testid="socialhq-event-flag-dialog"
        >
            <DialogHeader>
                <DialogTitle>
                    {{ $t('socialhq_events.flag.title') }}
                    <template v-if="flaggingEvent">
                        · {{ dialogName(flaggingEvent) }}
                    </template>
                </DialogTitle>
                <DialogDescription>
                    {{ $t('socialhq_events.flag.description') }}
                </DialogDescription>
            </DialogHeader>
            <div class="grid gap-2">
                <Label for="socialhq-event-flag-note">
                    {{ $t('socialhq_events.flag.note') }}
                </Label>
                <Textarea
                    id="socialhq-event-flag-note"
                    v-model="flagForm.note"
                    :placeholder="$t('socialhq_events.flag.note_placeholder')"
                    rows="3"
                    data-testid="socialhq-event-flag-note"
                />
                <p
                    v-if="flagForm.errors.note"
                    class="text-sm text-destructive-text"
                >
                    {{ flagForm.errors.note }}
                </p>
            </div>
            <DialogFooter>
                <Button type="button" variant="ghost" @click="closeFlag">
                    {{ $t('socialhq_events.flag.cancel') }}
                </Button>
                <Button
                    :loading="flagForm.processing"
                    data-testid="socialhq-event-flag-send"
                    @click="sendFlag"
                >
                    {{ $t('socialhq_events.flag.send') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog v-if="abilities.canApprove" v-model:open="isConfirmShownOpen">
        <DialogContent
            class="sm:max-w-md"
            data-testid="socialhq-events-confirm-shown-dialog"
        >
            <DialogHeader>
                <DialogTitle>
                    {{
                        $tChoice(
                            'socialhq_events.confirm_shown.title',
                            confirmableShown,
                            { count: String(confirmableShown) },
                        )
                    }}
                </DialogTitle>
                <DialogDescription>
                    {{ $t('socialhq_events.confirm_shown.description') }}
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button
                    type="button"
                    variant="ghost"
                    @click="closeConfirmShown"
                >
                    {{ $t('socialhq_events.confirm_shown.cancel') }}
                </Button>
                <Button
                    :loading="isConfirmingShown"
                    data-testid="socialhq-events-confirm-shown-confirm"
                    @click="confirmShown"
                >
                    {{ $t('socialhq_events.confirm_shown.confirm') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog v-if="abilities.canApprove" v-model:open="isDeleteOpen">
        <DialogContent
            class="sm:max-w-md"
            data-testid="socialhq-event-delete-dialog"
        >
            <DialogHeader>
                <DialogTitle>
                    {{
                        $t('socialhq_events.delete.title', {
                            name: dialogName(deletingEvent),
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
