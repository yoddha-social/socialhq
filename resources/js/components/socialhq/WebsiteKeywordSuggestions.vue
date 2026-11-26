<script setup lang="ts">
import { computed } from 'vue';

import {
    WEBSITE_KEYWORD_GROUPS,
    type WebsiteSuggestion,
} from '@/components/socialhq/brandSources';
import WebsiteSuggestionItem from '@/components/socialhq/WebsiteSuggestionItem.vue';

/**
 * The open SEO keyword suggestions of a website read, under one heading and
 * grouped for review (features, problems and questions, audience, place,
 * seasonal). The groups are only for reading: tags decide where a keyword
 * goes.
 */
const props = defineProps<{
    crawlId: number;
    suggestions: WebsiteSuggestion[];
}>();

const groups = computed(() =>
    WEBSITE_KEYWORD_GROUPS.map((group) => ({
        group,
        suggestions: props.suggestions.filter(
            (suggestion) =>
                suggestion.type === 'keyword' && suggestion.group === group,
        ),
    })).filter((entry) => entry.suggestions.length > 0),
);
</script>

<template>
    <section
        v-if="groups.length > 0"
        class="flex flex-col gap-3"
        data-testid="socialhq-website-keywords"
    >
        <div class="flex flex-col gap-1">
            <h3 class="text-sm font-semibold text-foreground">
                {{ $t('socialhq_knowledge.website.review.keywords.title') }}
            </h3>
            <p class="text-sm text-muted-foreground">
                {{
                    $t('socialhq_knowledge.website.review.keywords.description')
                }}
            </p>
        </div>
        <div
            v-for="entry in groups"
            :key="entry.group"
            class="flex flex-col gap-2"
            :data-testid="`socialhq-website-keywords-${entry.group}`"
        >
            <h4
                class="text-xs font-medium tracking-wide text-subtle-foreground uppercase"
            >
                {{
                    $t(
                        `socialhq_knowledge.website.review.keywords.groups.${entry.group}`,
                    )
                }}
            </h4>
            <ul class="flex flex-col gap-2">
                <WebsiteSuggestionItem
                    v-for="suggestion in entry.suggestions"
                    :key="suggestion.key"
                    :crawl-id="crawlId"
                    :suggestion="suggestion"
                />
            </ul>
        </div>
    </section>
</template>
