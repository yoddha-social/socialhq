<script setup lang="ts">
import type { Component } from 'vue';

withDefaults(
    defineProps<{
        icon: Component;
        title: string;
        description: string;
        danger?: boolean;
        testid: string;
    }>(),
    { danger: false },
);
</script>

<template>
    <div
        class="flex items-center gap-3 border-b border-border px-4 py-3.5 last:border-b-0"
        :data-testid="testid"
    >
        <span
            class="inline-flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted"
            aria-hidden="true"
        >
            <component
                :is="icon"
                class="size-[18px]"
                :class="danger ? 'text-destructive' : 'text-subtle-foreground'"
            />
        </span>
        <div class="min-w-0 flex-1">
            <p
                class="text-sm leading-5 font-emphasis"
                :class="danger ? 'text-destructive-text' : 'text-foreground'"
            >
                {{ title }}
            </p>
            <p
                class="text-[13px] text-muted-foreground md:truncate"
                :title="description"
                :data-testid="`${testid}-value`"
            >
                {{ description }}
            </p>
        </div>
        <div class="shrink-0">
            <slot />
        </div>
    </div>
</template>
