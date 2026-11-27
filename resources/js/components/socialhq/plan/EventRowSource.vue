<script setup lang="ts">
import { IconExternalLink } from '@tabler/icons-vue';
import { computed } from 'vue';

import type { CalendarEventRow } from '@/components/socialhq/plan/eventStyles';

/**
 * Where a date was copied from: a link to its source, named by the source or
 * its host. Each country's date has its own.
 */
const props = defineProps<{
    event: CalendarEventRow;
}>();

const sourceLabel = computed((): string | null => {
    if (props.event.source_name) {
        return props.event.source_name;
    }

    if (!props.event.source_url) {
        return null;
    }

    try {
        return new URL(props.event.source_url).hostname.replace(/^www\./, '');
    } catch {
        return props.event.source_url;
    }
});
</script>

<template>
    <a
        v-if="event.source_url"
        :href="event.source_url"
        target="_blank"
        rel="noopener noreferrer"
        class="inline-flex max-w-full min-w-0 items-center gap-1 text-muted-foreground underline-offset-2 hover:text-foreground hover:underline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
        :title="event.source_name ?? event.source_url"
        :data-testid="`socialhq-event-source-${event.id}`"
    >
        <IconExternalLink class="size-3.5 shrink-0" />
        <span class="truncate sm:max-w-80">{{ sourceLabel }}</span>
    </a>
    <span
        v-else-if="sourceLabel"
        class="truncate text-muted-foreground sm:max-w-80"
        :title="sourceLabel"
    >
        {{ sourceLabel }}
    </span>
</template>
