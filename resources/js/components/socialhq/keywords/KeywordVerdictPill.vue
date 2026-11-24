<script setup lang="ts">
import {
    IconAlertCircle,
    IconBan,
    IconCircleCheck,
    IconCircleDashed,
} from '@tabler/icons-vue';
import type { Component } from 'vue';

import type { KeywordVerdict } from '@/components/socialhq/keywords/keywords';

defineProps<{
    verdict: KeywordVerdict;
}>();

const STYLES: Record<KeywordVerdict, { icon: Component; classes: string }> = {
    good: {
        icon: IconCircleCheck,
        classes:
            'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400',
    },
    check: {
        icon: IconAlertCircle,
        classes:
            'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-400',
    },
    weak: {
        icon: IconCircleDashed,
        classes:
            'bg-stone-100 text-stone-600 dark:bg-stone-800 dark:text-stone-300',
    },
    conflict: {
        icon: IconBan,
        classes: 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-400',
    },
};
</script>

<template>
    <span
        class="inline-flex h-[22px] shrink-0 items-center gap-1 rounded-full px-2 text-xs font-medium"
        :class="STYLES[verdict].classes"
        :data-testid="`socialhq-keyword-verdict-${verdict}`"
    >
        <component
            :is="STYLES[verdict].icon"
            class="size-3.5"
            aria-hidden="true"
        />
        {{ $t(`socialhq.keywords.verdicts.${verdict}`) }}
    </span>
</template>
