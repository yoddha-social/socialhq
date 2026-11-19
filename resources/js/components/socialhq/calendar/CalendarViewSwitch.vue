<script setup lang="ts">
import type { CalendarView } from '@/types/publish';

defineProps<{
    view: CalendarView;
    views: readonly CalendarView[];
}>();

const emit = defineEmits<{
    change: [view: CalendarView];
}>();

const change = (view: CalendarView): void => {
    emit('change', view);
};
</script>

<template>
    <div
        class="flex shrink-0 rounded-lg bg-muted p-1"
        role="group"
        :aria-label="$t('calendar.title')"
        data-testid="calendar-view-switch"
    >
        <button
            v-for="option in views"
            :key="option"
            type="button"
            :aria-pressed="view === option"
            class="h-7 rounded-md px-3 text-[13px] whitespace-nowrap transition-control focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
            :class="
                view === option
                    ? 'bg-card font-emphasis text-foreground shadow-sm'
                    : 'text-muted-foreground hover:text-foreground'
            "
            :data-testid="`calendar-view-${option}`"
            @click="change(option)"
        >
            {{ $t(`calendar.${option}`) }}
        </button>
    </div>
</template>
