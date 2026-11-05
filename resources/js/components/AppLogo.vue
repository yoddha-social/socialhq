<script setup lang="ts">
withDefaults(
    defineProps<{
        /** `full` is the mark and the "Social HQ" wordmark, `mark` the round mark alone. */
        variant?: 'full' | 'mark';
    }>(),
    { variant: 'full' },
);
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center gap-[0.32em] font-logo leading-none font-bold tracking-[-0.01em] text-foreground"
        role="img"
        aria-label="Social HQ"
        data-testid="app-logo"
    >
        <svg
            viewBox="0 0 410 410"
            class="size-[1.15em] shrink-0 [clip-path:circle(50%)]"
            aria-hidden="true"
        >
            <rect width="410" height="410" fill="#4338CA" />
            <path
                d="M135 105H275A45 45 0 0 1 320 150V230A45 45 0 0 1 275 275H180L120 325V272.4A45 45 0 0 1 90 230V150A45 45 0 0 1 135 105Z"
                fill="#FFFFFF"
            />
            <path
                d="M173 190a18 18 0 1 1-36 0a18 18 0 1 1 36 0ZM223 190a18 18 0 1 1-36 0a18 18 0 1 1 36 0ZM273 190a18 18 0 1 1-36 0a18 18 0 1 1 36 0Z"
                fill="#4338CA"
            />
        </svg>
        <span v-if="variant === 'full'" aria-hidden="true">Social HQ</span>
    </span>
</template>
