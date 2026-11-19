<script setup lang="ts">
import { IconAlertCircle, IconCircleCheck } from '@tabler/icons-vue';
import { computed } from 'vue';

const props = defineProps<{
    used: number;
    limit: number;
    platform: string;
}>();

/** From this share of the limit the chip warns that little room is left. */
const NEAR_LIMIT = 0.9;

const exceeded = computed(() => props.used > props.limit);
const near = computed(
    () => !exceeded.value && props.used > props.limit * NEAR_LIMIT,
);

const tone = computed(() => {
    if (exceeded.value) {
        return 'border-destructive/30 bg-destructive/10 text-destructive-text';
    }

    return near.value
        ? 'border-warning/40 bg-warning/10 text-warning'
        : 'border-border text-muted-foreground';
});
</script>

<template>
    <span
        class="inline-flex h-7 shrink-0 items-center gap-1.5 rounded-md border px-2 text-xs whitespace-nowrap tabular-nums"
        :class="tone"
        :data-exceeded="exceeded ? '' : undefined"
        :data-near="near ? '' : undefined"
    >
        <IconAlertCircle v-if="exceeded || near" class="size-3.5" />
        <IconCircleCheck v-else class="size-3.5 text-success" />
        {{
            $t('socialhq.composer.shared_counter', {
                used: used.toLocaleString(),
                limit: limit.toLocaleString(),
            })
        }}
        <span class="text-subtle-foreground max-sm:hidden">{{
            $t('socialhq.composer.shared_counter_limit', { platform })
        }}</span>
    </span>
</template>
