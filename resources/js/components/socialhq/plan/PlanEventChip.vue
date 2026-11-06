<script setup lang="ts">
import { IconAlertTriangle, IconConfetti } from '@tabler/icons-vue';

import type { PlanEvent } from '@/components/socialhq/plan/types';

defineProps<{
    event: PlanEvent;
}>();
</script>

<template>
    <div
        class="flex min-w-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-[11px] leading-4 font-medium"
        :class="
            event.confirmed
                ? 'bg-info-subtle text-info-text'
                : 'border border-dashed border-warning/70 text-foreground'
        "
        :title="
            event.confirmed
                ? event.name
                : `${event.name} · ${$t('socialhq_plan.event.needs_check')}`
        "
        :data-confirmed="event.confirmed"
        :data-testid="`plan-event-${event.id}`"
    >
        <IconConfetti
            v-if="event.confirmed"
            class="size-3 shrink-0"
            aria-hidden="true"
        />
        <IconAlertTriangle
            v-else
            class="size-3 shrink-0 text-warning"
            aria-hidden="true"
        />
        <span class="min-w-0 truncate">{{ event.name }}</span>
    </div>
</template>
