<script setup lang="ts">
import { IconWorld } from '@tabler/icons-vue';
import { computed } from 'vue';

import { localizedDay } from '@/components/socialhq/localizedDay';
import EventRowActions from '@/components/socialhq/plan/EventRowActions.vue';
import EventRowFlags from '@/components/socialhq/plan/EventRowFlags.vue';
import EventRowSource from '@/components/socialhq/plan/EventRowSource.vue';
import EventRowStatus from '@/components/socialhq/plan/EventRowStatus.vue';
import {
    CATEGORY_ORDER,
    categoryBadgeClass,
    countryName,
    type CalendarEventRow,
    type EventCategory,
} from '@/components/socialhq/plan/eventStyles';
import { Badge } from '@/components/ui/badge';
import { activeLocale } from '@/language';

/**
 * One event in several countries (owner decision 2026-11-27, layout A of the
 * mockup): the name and kinds once, how many countries are confirmed, and a
 * row per country with its status, its source and its own actions, since a
 * date confirmed for Australia is no proof for Nepal. An event with one row
 * shown is EventListItem instead, so a one-country brand sees no change.
 */
const props = defineProps<{
    /** The rows shown (two or more), in the order the server sends them. */
    rows: CalendarEventRow[];
    /** The person approves this brand's posts. */
    canApprove: boolean;
    /** They may also change the dates every brand shares. */
    canManageSharedEvents: boolean;
    /** They work on the plan: they flag a date they can't change. */
    canWork: boolean;
    busyEventId: number | null;
}>();

const emit = defineEmits<{
    confirm: [event: CalendarEventRow];
    unconfirm: [event: CalendarEventRow];
    edit: [event: CalendarEventRow];
    delete: [event: CalendarEventRow];
    hide: [event: CalendarEventRow];
    flag: [event: CalendarEventRow];
    resolveFlag: [flagId: number];
}>();

/** Named by its oldest date, which no confirmation or filter changes. */
const cardId = computed(() => Math.min(...props.rows.map((row) => row.id)));

const title = computed(() => props.rows[0]?.name ?? '');

/** The local name of the first row that has one ("क्रिसमस डे"). */
const local = computed(() => props.rows.find((row) => row.name_ne) ?? null);

/** Every row's kind once, festival before public holiday (Christmas is both). */
const kinds = computed((): EventCategory[] =>
    [...new Set(props.rows.map((row) => row.category))].sort(
        (first, second) =>
            CATEGORY_ORDER.indexOf(first) - CATEGORY_ORDER.indexOf(second),
    ),
);

const isPublicHoliday = computed(
    () =>
        props.rows.some((row) => row.public_holiday) &&
        !kinds.value.includes('public_holiday'),
);

const confirmedCount = computed(
    () => props.rows.filter((row) => row.verified).length,
);

/** "1 of 2 countries confirmed", or "of 2" when a row is international or the brand's own. */
const summaryKey = computed(() =>
    props.rows.every((row) => row.country_code && !row.brand_only)
        ? 'socialhq_events.group.summary_countries'
        : 'socialhq_events.group.summary',
);

/** The last day, when every row ends on the same one after it starts. */
const sharedEnd = computed((): string | null => {
    const ends = new Set(props.rows.map((row) => row.ends_on));
    const [end] = [...ends];

    return ends.size === 1 && end && end > (props.rows[0]?.starts_on ?? end)
        ? end
        : null;
});

const untilText = (end: string): string => localizedDay(end).format('D MMM');

/** A row's country, named; empty for one without (placeKey() names it). */
const placeOf = (row: CalendarEventRow): string =>
    row.country_code ? countryName(row.country_code, activeLocale.value) : '';

/** The words for a row without a country (International, This brand only), resolved in the template. */
const placeKey = (row: CalendarEventRow): string =>
    row.brand_only
        ? 'socialhq_events.badges.brand_only'
        : `socialhq_events.regions.${row.region}`;

/** May change this row's date: a shared one needs who manages shared dates. */
const canChange = (row: CalendarEventRow): boolean =>
    props.canApprove && (row.brand_only || props.canManageSharedEvents);

const confirm = (event: CalendarEventRow): void => emit('confirm', event);
const unconfirm = (event: CalendarEventRow): void => emit('unconfirm', event);
const edit = (event: CalendarEventRow): void => emit('edit', event);
const remove = (event: CalendarEventRow): void => emit('delete', event);
const hide = (event: CalendarEventRow): void => emit('hide', event);
const flag = (event: CalendarEventRow): void => emit('flag', event);
const resolveFlag = (flagId: number): void => emit('resolveFlag', flagId);
</script>

<template>
    <li
        class="flex flex-col gap-2 rounded-lg border bg-card px-3 py-2.5 md:px-4 md:py-3"
        :class="
            confirmedCount > 0
                ? 'border-border'
                : 'border-dashed border-border-strong'
        "
        :data-testid="`socialhq-event-group-${cardId}`"
        :data-confirmed="confirmedCount > 0"
    >
        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
            <p class="text-sm font-medium text-foreground">{{ title }}</p>
            <p
                v-if="local"
                :lang="
                    local.country_code && local.country_code !== 'NP'
                        ? undefined
                        : 'ne'
                "
                class="text-sm text-muted-foreground"
            >
                {{ local.name_ne }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-1.5">
            <Badge
                v-for="kind in kinds"
                :key="kind"
                class="px-1.5"
                :class="categoryBadgeClass(kind)"
            >
                {{ $t(`socialhq_events.categories.${kind}`) }}
            </Badge>
            <Badge v-if="isPublicHoliday" variant="outline" class="px-1.5">
                {{ $t('socialhq_events.badges.public_holiday') }}
            </Badge>
            <span v-if="sharedEnd" class="text-xs text-muted-foreground">
                {{
                    $t('socialhq_events.until', { date: untilText(sharedEnd) })
                }}
            </span>
            <span
                class="text-xs text-muted-foreground"
                :data-testid="`socialhq-event-group-summary-${cardId}`"
            >
                {{
                    $t(summaryKey, {
                        confirmed: String(confirmedCount),
                        total: String(rows.length),
                    })
                }}
            </span>
        </div>

        <ul
            class="flex flex-col divide-y divide-border rounded-md border border-border text-xs"
        >
            <li
                v-for="row in rows"
                :key="row.id"
                class="flex flex-col gap-1.5 px-2.5 py-2"
                :data-testid="`socialhq-event-${row.id}`"
                :data-verified="row.verified"
            >
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                    <span
                        class="inline-flex min-w-0 items-center gap-1.5 text-foreground sm:w-36"
                        :data-testid="`socialhq-event-country-${row.id}`"
                    >
                        <Badge
                            v-if="row.country_code"
                            variant="outline"
                            class="px-1 font-mono text-[0.625rem] tracking-wide"
                            aria-hidden="true"
                        >
                            {{ row.country_code }}
                        </Badge>
                        <IconWorld
                            v-else
                            class="size-3.5 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span class="truncate">{{
                            placeOf(row) || $t(placeKey(row))
                        }}</span>
                        <Badge
                            v-if="row.brand_only && row.country_code"
                            variant="outline"
                            class="px-1.5"
                        >
                            {{ $t('socialhq_events.badges.brand_only') }}
                        </Badge>
                    </span>

                    <EventRowStatus :event="row" />

                    <div
                        class="ms-auto flex min-w-0 flex-wrap items-center justify-end gap-x-3 gap-y-1"
                    >
                        <EventRowSource :event="row" />
                        <EventRowActions
                            :event="row"
                            :can-approve="canChange(row)"
                            :busy="busyEventId === row.id"
                            :can-hide="canApprove && !row.brand_only"
                            :can-flag="canWork"
                            :place="placeOf(row) || $t(placeKey(row))"
                            @confirm="confirm"
                            @unconfirm="unconfirm"
                            @edit="edit"
                            @delete="remove"
                            @hide="hide"
                            @flag="flag"
                        />
                    </div>
                </div>

                <p
                    v-if="row.name !== title"
                    class="text-muted-foreground"
                    :data-testid="`socialhq-event-row-name-${row.id}`"
                >
                    {{ row.name }}
                </p>
                <p
                    v-if="row.name_ne && row.name_ne !== local?.name_ne"
                    :lang="
                        row.country_code && row.country_code !== 'NP'
                            ? undefined
                            : 'ne'
                    "
                    class="text-muted-foreground"
                >
                    {{ row.name_ne }}
                </p>
                <p
                    v-if="!sharedEnd && row.ends_on > row.starts_on"
                    class="text-muted-foreground"
                >
                    {{
                        $t('socialhq_events.until', {
                            date: untilText(row.ends_on),
                        })
                    }}
                </p>
                <p
                    v-if="row.description"
                    class="line-clamp-2 text-muted-foreground"
                    :title="row.description"
                >
                    {{ row.description }}
                </p>

                <EventRowFlags
                    v-if="row.flags.length > 0"
                    :event="row"
                    :can-resolve="canApprove"
                    @resolve="resolveFlag"
                />
            </li>
        </ul>
    </li>
</template>
