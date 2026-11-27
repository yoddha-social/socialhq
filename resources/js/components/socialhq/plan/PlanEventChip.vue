<script setup lang="ts">
import { IconAlertTriangle, IconConfetti } from '@tabler/icons-vue';
import { computed } from 'vue';

import { countryNames } from '@/components/socialhq/plan/eventStyles';
import type { PlanEvent } from '@/components/socialhq/plan/types';
import { activeLocale } from '@/language';

/**
 * An event on its days of the plan, once however many countries' holiday it
 * is: the chip adds their codes when there are several (the name keeps the
 * room first), its title names them.
 */
const props = defineProps<{
    event: PlanEvent;
}>();

/** Its countries, named, when it is the holiday of several. */
const names = computed((): string =>
    props.event.countries.length > 1
        ? countryNames(props.event.countries, activeLocale.value)
        : '',
);
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
            [
                event.name,
                names,
                event.confirmed ? '' : $t('socialhq_plan.event.needs_check'),
            ]
                .filter(Boolean)
                .join(' · ')
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
        <span
            v-if="event.countries.length > 1"
            class="min-w-0 shrink-[999] truncate font-mono text-[10px] opacity-70"
            :data-testid="`plan-event-countries-${event.id}`"
            >{{ event.countries.join(' ') }}</span
        >
    </div>
</template>
