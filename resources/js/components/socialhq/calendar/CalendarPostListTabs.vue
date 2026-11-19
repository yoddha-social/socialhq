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
        class="flex rounded-lg border border-border-strong bg-card p-[3px]"
        role="group"
        :aria-label="$t('socialhq.calendar.list_title')"
        data-testid="calendar-list-tabs"
    >
        <button
            v-for="option in CALENDAR_LIST_TABS"
            :key="option"
            type="button"
            :aria-pressed="tab === option"
            class="flex h-7 min-w-0 flex-1 items-center justify-center gap-1 rounded-md px-1 text-xs font-medium transition-control focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
            :class="
                tab === option
                    ? 'bg-primary-selected text-primary-text'
                    : 'text-foreground hover:bg-accent'
            "
            :data-testid="`calendar-list-tab-${option}`"
            @click="select(option)"
        >
            <span class="truncate">{{ $t(`calendar.status.${option}`) }}</span>
            <span
                v-if="counts[option] !== null"
                class="tabular-nums opacity-70"
                :data-testid="`calendar-list-count-${option}`"
                >{{ counts[option] }}</span
            >
        </button>
    </div>
</template>
