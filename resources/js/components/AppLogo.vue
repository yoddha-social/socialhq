<script setup lang="ts">
import { useId } from 'vue';

import {
    LOGO_DOT,
    LOGO_GRADIENT,
    LOGO_PATH,
    LOGO_VIEW_BOX,
} from '@/components/socialhq/logo';

withDefaults(
    defineProps<{
        /** `full` is the mark and the "Social HQ" wordmark, `mark` the mark alone. */
        variant?: 'full' | 'mark';
    }>(),
    { variant: 'full' },
);

// Each logo on the page needs its own gradient id: a shared one breaks
// when the first logo using it is hidden.
const gradientId = `socialhq-mark-${useId()}`;
const viewBox = `${LOGO_VIEW_BOX.x} ${LOGO_VIEW_BOX.y} ${LOGO_VIEW_BOX.size} ${LOGO_VIEW_BOX.size}`;
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center gap-[0.32em] font-logo leading-none font-bold tracking-[-0.01em] text-foreground"
        role="img"
        aria-label="Social HQ"
        data-testid="app-logo"
    >
        <svg
            :viewBox="viewBox"
            :class="[
                'shrink-0',
                variant === 'full' ? 'size-[1.7em]' : 'size-[1.15em]',
            ]"
            aria-hidden="true"
        >
            <defs>
                <linearGradient
                    :id="gradientId"
                    :x1="LOGO_GRADIENT.from.x"
                    :y1="LOGO_GRADIENT.from.y"
                    :x2="LOGO_GRADIENT.to.x"
                    :y2="LOGO_GRADIENT.to.y"
                    gradientUnits="userSpaceOnUse"
                >
                    <stop
                        v-for="stop in LOGO_GRADIENT.stops"
                        :key="stop.offset"
                        :offset="stop.offset"
                        :stop-color="stop.color"
                    />
                </linearGradient>
            </defs>
            <path :d="LOGO_PATH" :fill="`url(#${gradientId})`" />
            <circle
                :cx="LOGO_DOT.cx"
                :cy="LOGO_DOT.cy"
                :r="LOGO_DOT.r"
                :fill="LOGO_DOT.color"
            />
        </svg>
        <span v-if="variant === 'full'" aria-hidden="true">Social HQ</span>
    </span>
</template>
