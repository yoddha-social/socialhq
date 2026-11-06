<script setup lang="ts">
import { IconClock } from '@tabler/icons-vue';
import { computed } from 'vue';

import { postingTimeOf } from '@/components/socialhq/plan/postingTime';
import type { PlanItem } from '@/components/socialhq/plan/types';

const props = defineProps<{
    item: Pick<PlanItem, 'id' | 'suggested_at' | 'suggested_timezone'>;
    /** Leave out the icon (dense rows). */
    plain?: boolean;
}>();

const posting = computed(() => postingTimeOf(props.item));
</script>

<template>
    <span
        v-if="posting"
        class="inline-flex min-w-0 items-center gap-1 tabular-nums"
        :data-testid="`plan-item-time-${item.id}`"
    >
        <IconClock
            v-if="!plain"
            class="size-3 shrink-0 opacity-70"
            aria-hidden="true"
        />
        <span class="truncate">{{
            posting.sameZone
                ? $t('socialhq_plan.time.in_zone', {
                      time: posting.time,
                      place: posting.place,
                  })
                : $t('socialhq_plan.time.both', {
                      time: posting.time,
                      place: posting.place,
                      viewer: posting.viewerTime,
                      viewer_place: posting.viewerPlace,
                  })
        }}</span>
    </span>
</template>
