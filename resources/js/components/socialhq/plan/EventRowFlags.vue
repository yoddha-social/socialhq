<script setup lang="ts">
import { IconFlag } from '@tabler/icons-vue';

import type { CalendarEventRow } from '@/components/socialhq/plan/eventStyles';
import { Button } from '@/components/ui/button';

/**
 * The brand's open notes that a date looks wrong, each with "Mark handled"
 * for the brand's approvers.
 */
defineProps<{
    event: CalendarEventRow;
    canResolve: boolean;
}>();

const emit = defineEmits<{
    resolve: [flagId: number];
}>();

const resolve = (flagId: number): void => emit('resolve', flagId);
</script>

<template>
    <ul
        class="flex flex-col gap-1"
        :data-testid="`socialhq-event-flags-${event.id}`"
    >
        <li
            v-for="item in event.flags"
            :key="item.id"
            class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-md bg-amber-500/10 px-2 py-1 text-xs text-foreground"
            :data-testid="`socialhq-event-flag-${item.id}`"
        >
            <IconFlag
                class="size-3.5 shrink-0 text-amber-600"
                aria-hidden="true"
            />
            <span class="min-w-0 flex-1">{{
                item.by
                    ? $t('socialhq_events.flag.by', {
                          name: item.by,
                          note: item.note,
                      })
                    : item.note
            }}</span>
            <Button
                v-if="canResolve"
                variant="link"
                size="sm"
                class="h-auto p-0 text-xs"
                :data-testid="`socialhq-event-flag-resolve-${item.id}`"
                @click="resolve(item.id)"
            >
                {{ $t('socialhq_events.flag.resolve') }}
            </Button>
        </li>
    </ul>
</template>
