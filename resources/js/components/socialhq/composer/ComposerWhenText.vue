<script setup lang="ts">
import { computed } from 'vue';

import dayjs from '@/dayjs';
import { activeLocale } from '@/language';

/**
 * Social HQ: the text of the composer's "when" button. A set time reads with
 * its weekday and the time zone it is in ("Thu, Nov 26, 6:00 PM
 * Asia/Kathmandu"); the queue and "Now" show their own label only.
 */
const props = defineProps<{
    /** "Nov 26, 6:00 PM", or the queue's or "Now"'s label. */
    label: string;
    /** The set time as a wall clock of `timezone`; empty in the queue and "Now". */
    wallClock: string;
    timezone: string;
}>();

const weekday = computed(() =>
    props.wallClock
        ? `${new Intl.DateTimeFormat(activeLocale.value, { weekday: 'short' }).format(dayjs(props.wallClock).toDate())},`
        : '',
);
const zone = computed(() => props.timezone.replaceAll('_', ' '));
/** The zone's place ("Kathmandu"), shown on narrow screens where the full name doesn't fit. */
const place = computed(() =>
    (props.timezone.split('/').pop() ?? props.timezone).replaceAll('_', ' '),
);
</script>

<template>
    <span class="flex min-w-0 items-center gap-1.5">
        <span
            v-if="weekday"
            class="max-sm:hidden"
            data-testid="composer-schedule-trigger-weekday"
            >{{ weekday }}</span
        >
        <span class="truncate" data-testid="composer-schedule-trigger-label">{{
            label
        }}</span>
        <span
            v-if="wallClock"
            class="font-normal text-subtle-foreground max-lg:hidden"
            data-testid="composer-schedule-trigger-zone"
            >{{ zone }}</span
        >
        <span
            v-if="wallClock"
            class="shrink-0 font-normal text-subtle-foreground lg:hidden"
            data-testid="composer-schedule-trigger-place"
            >{{ place }}</span
        >
    </span>
</template>
