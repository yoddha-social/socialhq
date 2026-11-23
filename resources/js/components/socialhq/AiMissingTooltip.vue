<script setup lang="ts">
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

/**
 * Social HQ: an AI button the company's AI can't run yet stays in place and
 * says why on hover and keyboard focus. The button itself keeps focus
 * (aria-disabled, not disabled) and repeats the message when clicked, for
 * touch screens, which never show a tooltip.
 */
defineProps<{
    /** The message, or null when the button works (no tooltip). */
    message: string | null;
    side?: 'top' | 'right' | 'bottom' | 'left';
}>();
</script>

<template>
    <TooltipProvider v-if="message" :delay-duration="200">
        <Tooltip>
            <TooltipTrigger as-child>
                <slot />
            </TooltipTrigger>
            <TooltipContent
                :side="side ?? 'top'"
                class="max-w-72"
                data-testid="ai-missing-tooltip"
            >
                {{ message }}
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
    <slot v-else />
</template>
