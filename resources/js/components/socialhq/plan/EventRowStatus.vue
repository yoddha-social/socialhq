<script setup lang="ts">
import { IconCircleCheck } from '@tabler/icons-vue';

import {
    NEEDS_CHECK_CLASS,
    type CalendarEventRow,
} from '@/components/socialhq/plan/eventStyles';
import { Badge } from '@/components/ui/badge';

/**
 * Where one date stands: confirmed (and by whom), no longer listed by its
 * source, or still to check. Shared by the event card and the rows of an
 * event in several countries.
 */
defineProps<{
    event: CalendarEventRow;
}>();
</script>

<template>
    <span
        v-if="event.verified"
        class="inline-flex items-center gap-1 text-success-text"
        :data-testid="`socialhq-event-confirmed-${event.id}`"
    >
        <IconCircleCheck class="size-3.5 shrink-0" />
        {{
            event.verified_by
                ? $t('socialhq_events.badges.confirmed_by', {
                      name: event.verified_by,
                  })
                : $t('socialhq_events.badges.confirmed')
        }}
    </span>
    <Badge
        v-else-if="event.delisted"
        class="px-1.5"
        :class="NEEDS_CHECK_CLASS"
        :data-testid="`socialhq-event-delisted-${event.id}`"
    >
        {{ $t('socialhq_events.badges.delisted') }}
    </Badge>
    <Badge
        v-else
        class="px-1.5"
        :class="NEEDS_CHECK_CLASS"
        :data-testid="`socialhq-event-needs-check-${event.id}`"
    >
        {{ $t('socialhq_events.badges.needs_check') }}
    </Badge>
</template>
