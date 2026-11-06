<script setup lang="ts">
import { IconSparkles } from '@tabler/icons-vue';
import Anthropic from '@thesvg/vue/anthropic';
import Cloudflare from '@thesvg/vue/cloudflare';
import Gemini from '@thesvg/vue/gemini';
import Groq from '@thesvg/vue/groq';
import Mistral from '@thesvg/vue/mistral';
import OpenAi from '@thesvg/vue/openai';
import { computed, type Component } from 'vue';

const props = defineProps<{ logo: string }>();

/** One-color logos follow the theme; the others keep their own colors. */
const logos: Record<string, { component: Component; themed: boolean }> = {
    anthropic: { component: Anthropic, themed: true },
    cloudflare: { component: Cloudflare, themed: false },
    gemini: { component: Gemini, themed: false },
    groq: { component: Groq, themed: true },
    mistral: { component: Mistral, themed: false },
    openai: { component: OpenAi, themed: true },
};

const entry = computed(() => logos[props.logo] ?? null);
</script>

<template>
    <span
        class="inline-flex size-10 shrink-0 items-center justify-center rounded-lg bg-white p-2 ring-1 ring-border"
        aria-hidden="true"
    >
        <component
            :is="entry.component"
            v-if="entry"
            class="size-6"
            :class="{ 'text-neutral-900 [&_path]:fill-current': entry.themed }"
        />
        <IconSparkles v-else class="size-5 text-muted-foreground" />
    </span>
</template>
