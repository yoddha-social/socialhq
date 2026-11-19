<script setup lang="ts">
import type { CalendarListTab } from '@/components/socialhq/calendar/postList';
import { CALENDAR_LIST_TABS } from '@/components/socialhq/calendar/postList';

defineProps<{
    counts: Record<CalendarListTab, number | null>;
}>();

const tab = defineModel<CalendarListTab>({ required: true });

const select = (value: CalendarListTab): void => {
    tab.value = value;
};
</script>

<template>
    <div
        class="grid grid-cols-3 rounded-lg bg-muted p-1"
        role="group"
        :aria-label="$t('socialhq.calendar.list_title')"
        data-testid="calendar-list-tabs"
    >
        <button
            v-for="option in CALENDAR_LIST_TABS"
            :key="option"
            type="button"
            :aria-pressed="tab === option"
            class="flex min-h-8 min-w-0 flex-wrap items-center justify-center gap-x-1 rounded-md px-1 py-1 text-[12.5px] leading-4 transition-control focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
            :class="
                tab === option
                    ? 'bg-card font-emphasis text-foreground shadow-sm'
                    : 'text-muted-foreground hover:text-foreground'
            "
            :data-testid="`calendar-list-tab-${option}`"
            @click="select(option)"
        >
            <span class="truncate">{{ $t(`calendar.status.${option}`) }}</span>
            <span
                v-if="counts[option] !== null"
                class="text-subtle-foreground tabular-nums"
                :data-testid="`calendar-list-count-${option}`"
                >{{ counts[option] }}</span
            >
        </button>
    </div>
</template>
