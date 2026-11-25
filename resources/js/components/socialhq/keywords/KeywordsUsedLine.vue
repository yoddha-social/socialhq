<script setup lang="ts">
import { IconCheck, IconExternalLink, IconTag } from '@tabler/icons-vue';

import { index as brandKnowledge } from '@/routes/socialhq/brand';

/**
 * Under AI text for a brand with SEO keywords: the keywords the text says
 * (hashtags included) and whether a main one is in the first line, found by
 * the server after the answer came back, never asked of the model. With
 * none, it says that skipping them is fine. The link opens the keywords in
 * a new tab, so unsaved work stays here.
 */
defineProps<{
    /** The answer's keywords_used: main ones first. */
    used: string[];
    mainInFirstLine: boolean;
}>();

const href = brandKnowledge.url({
    query: { tab: 'strategy', focus: 'keywords' },
});
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-1.5 text-[12.5px] text-muted-foreground"
        data-testid="socialhq-keywords-used"
    >
        <IconTag
            class="size-3.5 shrink-0 text-subtle-foreground"
            aria-hidden="true"
        />
        <template v-if="used.length > 0">
            <span>{{ $t('socialhq.keywords.used.label') }}</span>
            <span
                v-for="keyword in used"
                :key="keyword"
                class="rounded-full border border-border bg-card px-2 py-0.5 text-xs text-foreground"
                data-testid="socialhq-keywords-used-keyword"
            >
                {{ keyword }}
            </span>
            <span
                v-if="mainInFirstLine"
                class="inline-flex items-center gap-0.5 text-emerald-700 dark:text-emerald-400"
                data-testid="socialhq-keywords-used-first-line"
            >
                <IconCheck class="size-3.5" aria-hidden="true" />
                {{ $t('socialhq.keywords.used.main_first_line') }}
            </span>
        </template>
        <span v-else data-testid="socialhq-keywords-used-none">
            {{ $t('socialhq.keywords.used.none') }}
        </span>
        <a
            :href="href"
            target="_blank"
            rel="noopener"
            class="ms-auto inline-flex items-center gap-0.5 font-medium whitespace-nowrap text-primary-text hover:underline"
            data-testid="socialhq-keywords-used-link"
        >
            {{ $t('socialhq.keywords.used.link') }}
            <IconExternalLink class="size-3.5" aria-hidden="true" />
        </a>
    </div>
</template>
