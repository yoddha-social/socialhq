<script setup lang="ts">
import type { KeywordAnswer } from '@/components/socialhq/keywords/keywords';
import KeywordsMissingNote from '@/components/socialhq/keywords/KeywordsMissingNote.vue';
import KeywordsUsedLine from '@/components/socialhq/keywords/KeywordsUsedLine.vue';

/**
 * What goes under text the AI just wrote, from its answer's keyword fields
 * (BrandKeywords::forAnswer()): the note when the brand has no SEO keywords,
 * else the keywords the text says. Nothing when the answer has neither (a
 * rewrite that says none).
 */
defineProps<{
    answer: KeywordAnswer | null;
}>();
</script>

<template>
    <KeywordsMissingNote
        v-if="answer?.keywords_missing"
        :can-add="answer.can_add_keywords === true"
    />
    <KeywordsUsedLine
        v-else-if="answer?.keywords_used"
        :used="answer.keywords_used"
        :main-in-first-line="answer.main_in_first_line === true"
    />
</template>
