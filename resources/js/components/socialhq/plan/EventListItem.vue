<script setup lang="ts">
import { computed } from 'vue';

import EventRowActions from '@/components/socialhq/plan/EventRowActions.vue';
import EventRowFlags from '@/components/socialhq/plan/EventRowFlags.vue';
import EventRowSource from '@/components/socialhq/plan/EventRowSource.vue';
import EventRowStatus from '@/components/socialhq/plan/EventRowStatus.vue';
import {
    categoryBadgeClass,
    countryName,
    type CalendarEventRow,
} from '@/components/socialhq/plan/eventStyles';
import { Badge } from '@/components/ui/badge';
import { activeLocale } from '@/language';

/**
 * One event on the events page: names, kind, holiday, source and who
 * confirmed it. People who approve posts get the actions. An event in
 * several countries is EventGroupCard instead, a row per country.
 */
const props = withDefaults(
    defineProps<{
        event: CalendarEventRow;
        canApprove: boolean;
        busy: boolean;
        /** "Until 12 Oct" text, already formatted, for a multi-day event. */
        until: string | null;
        /** A brand approver may hide a shared date for this brand. */
        canHide?: boolean;
        /** Someone who works on the plan but can't change the date flags it. */
        canFlag?: boolean;
        /** The brand's approvers mark a flag as handled. */
        canResolveFlags?: boolean;
        /** Shown in "Hidden for this brand": the only action is to show it again. */
        hidden?: boolean;
    }>(),
    { canHide: false, canFlag: false, canResolveFlags: false, hidden: false },
);

const emit = defineEmits<{
    confirm: [event: CalendarEventRow];
    unconfirm: [event: CalendarEventRow];
    edit: [event: CalendarEventRow];
    delete: [event: CalendarEventRow];
    hide: [event: CalendarEventRow];
    unhide: [event: CalendarEventRow];
    flag: [event: CalendarEventRow];
    resolveFlag: [flagId: number];
}>();

/** The event's country, named in the interface language. */
const country = computed((): string | null =>
    props.event.country_code
        ? countryName(props.event.country_code, activeLocale.value)
        : null,
);

const confirm = (event: CalendarEventRow): void => emit('confirm', event);
const unconfirm = (event: CalendarEventRow): void => emit('unconfirm', event);
const edit = (event: CalendarEventRow): void => emit('edit', event);
const remove = (event: CalendarEventRow): void => emit('delete', event);
const hide = (event: CalendarEventRow): void => emit('hide', event);
const unhide = (event: CalendarEventRow): void => emit('unhide', event);
const flag = (event: CalendarEventRow): void => emit('flag', event);
const resolveFlag = (flagId: number): void => emit('resolveFlag', flagId);
</script>

<template>
    <li
        class="flex flex-col gap-3 rounded-lg border bg-card px-3 py-2.5 sm:flex-row sm:items-start sm:justify-between md:px-4 md:py-3"
        :class="
            event.verified
                ? 'border-border'
                : 'border-dashed border-border-strong'
        "
        :data-testid="`socialhq-event-${event.id}`"
        :data-verified="event.verified"
    >
        <div class="flex min-w-0 flex-col gap-1.5">
            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                <p class="text-sm font-medium text-foreground">
                    {{ event.name }}
                </p>
                <p
                    v-if="event.name_ne"
                    :lang="
                        event.country_code && event.country_code !== 'NP'
                            ? undefined
                            : 'ne'
                    "
                    class="text-sm text-muted-foreground"
                >
                    {{ event.name_ne }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-1.5">
                <Badge
                    class="px-1.5"
                    :class="categoryBadgeClass(event.category)"
                    :data-testid="`socialhq-event-category-${event.id}`"
                >
                    {{ $t(`socialhq_events.categories.${event.category}`) }}
                </Badge>
                <Badge
                    v-if="
                        event.public_holiday &&
                        event.category !== 'public_holiday'
                    "
                    variant="outline"
                    class="px-1.5"
                >
                    {{ $t('socialhq_events.badges.public_holiday') }}
                </Badge>
                <Badge v-if="event.brand_only" variant="outline" class="px-1.5">
                    {{ $t('socialhq_events.badges.brand_only') }}
                </Badge>
                <span
                    v-if="event.country_code"
                    class="inline-flex items-center gap-1 text-xs text-muted-foreground"
                    :data-testid="`socialhq-event-country-${event.id}`"
                >
                    <Badge
                        variant="outline"
                        class="px-1 font-mono text-[0.625rem] tracking-wide"
                        :title="country"
                        aria-hidden="true"
                    >
                        {{ event.country_code }}
                    </Badge>
                    {{ country }}
                </span>
                <span
                    v-else-if="!event.brand_only"
                    class="text-xs text-muted-foreground"
                >
                    {{ $t(`socialhq_events.regions.${event.region}`) }}
                </span>
                <span v-if="until" class="text-xs text-muted-foreground">
                    · {{ until }}
                </span>
            </div>

            <p
                v-if="event.description"
                class="line-clamp-2 text-xs text-muted-foreground"
                :title="event.description"
            >
                {{ event.description }}
            </p>

            <EventRowFlags
                v-if="event.flags.length > 0"
                :event="event"
                :can-resolve="canResolveFlags"
                @resolve="resolveFlag"
            />

            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                <EventRowStatus :event="event" />
                <EventRowSource :event="event" />
            </div>
        </div>

        <EventRowActions
            :event="event"
            :can-approve="canApprove"
            :busy="busy"
            :can-hide="canHide"
            :can-flag="canFlag"
            :hidden="hidden"
            @confirm="confirm"
            @unconfirm="unconfirm"
            @edit="edit"
            @delete="remove"
            @hide="hide"
            @unhide="unhide"
            @flag="flag"
        />
    </li>
</template>
