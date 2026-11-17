<script setup lang="ts">
import { IconClock } from '@tabler/icons-vue';
import { computed } from 'vue';

import { postingTimeOf } from '@/components/socialhq/plan/postingTime';
import type { PlanItem } from '@/components/socialhq/plan/types';

const props = defineProps<{
    item: Pick<PlanItem, 'id' | 'suggested_at' | 'suggested_timezone'>;
    /** Leave out the icon (dense rows). */
    plain?: boolean;
    /** The audience's time and the viewer's on two lines, so neither is cut (narrow columns). */
    stacked?: boolean;
}>();

const posting = computed(() => postingTimeOf(props.item));

/** The lines to show, as lang keys and values (resolved with $t in the template). */
const lines = computed(
    (): { key: string; values: Record<string, string> }[] => {
        const time = posting.value;

        if (!time) {
            return [];
        }

        const inZone = (clock: string, place: string) => ({
            key: 'socialhq_plan.time.in_zone',
            values: { time: clock, place },
        });

        if (time.sameZone) {
            return [inZone(time.time, time.place)];
        }

        if (props.stacked) {
            return [
                inZone(time.time, time.place),
                inZone(time.viewerTime, time.viewerPlace),
            ];
        }

        return [
            {
                key: 'socialhq_plan.time.both',
                values: {
                    time: time.time,
                    place: time.place,
                    viewer: time.viewerTime,
                    viewer_place: time.viewerPlace,
                },
            },
        ];
    },
);
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
        <span
            class="flex min-w-0 flex-col"
            :class="{ 'leading-tight': lines.length > 1 }"
        >
            <!-- The title shows the whole line where a narrow slot cuts it. -->
            <span
                v-for="(line, index) in lines"
                :key="index"
                class="truncate"
                :title="$t(line.key, line.values)"
                >{{ $t(line.key, line.values) }}</span
            >
        </span>
    </span>
</template>
