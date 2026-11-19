<script setup lang="ts">
import { IconAlertTriangle } from '@tabler/icons-vue';

/**
 * Names, numbers and quotes the AI wrote that its sources don't contain,
 * for someone to check before the text goes further.
 */
defineProps<{
    details: string[];
    title: string;
    body: string;
}>();
</script>

<template>
    <div
        class="grid gap-1.5 rounded-lg border border-warning/30 bg-warning/10 px-3 py-2 text-sm text-foreground"
        role="status"
    >
        <p class="flex items-center gap-2 font-medium">
            <IconAlertTriangle
                class="size-4 shrink-0 text-warning"
                aria-hidden="true"
            />
            {{ title }}
        </p>
        <p>{{ body }}</p>
        <ul class="flex flex-wrap gap-1.5">
            <li
                v-for="detail in details"
                :key="detail"
                class="rounded-md border border-warning/30 bg-card px-1.5 py-0.5 text-xs"
            >
                {{ detail }}
            </li>
        </ul>
    </div>
</template>
