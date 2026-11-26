<script setup lang="ts">
import {
    IconCalendarEvent,
    IconCalendarMonth,
    IconLayoutColumns,
    IconPackage,
} from '@tabler/icons-vue';
import type { Component } from 'vue';

import type { KeywordTagKind } from '@/components/socialhq/keywords/keywords';

/**
 * One tag of an SEO keyword: its kind as an icon, its label as text. The
 * kind's name is in the title, for those who don't read the icon.
 */
defineProps<{
    kind: KeywordTagKind;
    label: string;
}>();

const ICONS: Record<KeywordTagKind, Component> = {
    pillar: IconLayoutColumns,
    product: IconPackage,
    event: IconCalendarEvent,
    months: IconCalendarMonth,
};
</script>

<template>
    <span
        class="inline-flex h-[22px] max-w-full min-w-0 items-center gap-1 rounded-full border border-border bg-card px-2 text-xs text-muted-foreground"
        :title="`${$t(`socialhq.keywords.tags.kinds.${kind}`)}: ${label}`"
        :data-testid="`socialhq-keyword-tag-${kind}`"
    >
        <component
            :is="ICONS[kind]"
            class="size-3.5 shrink-0 text-subtle-foreground"
            aria-hidden="true"
        />
        <span class="sr-only">
            {{ $t(`socialhq.keywords.tags.kinds.${kind}`) }}:
        </span>
        <span class="truncate">{{ label }}</span>
    </span>
</template>
