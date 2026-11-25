<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { IconExternalLink, IconTag, IconX } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

import { index as brandKnowledge } from '@/routes/socialhq/brand';

/**
 * The light note under AI text when the brand has no SEO keywords. Whoever
 * may add them gets the link (Brand knowledge in a new tab, so unsaved work
 * stays here); the others are told who adds them. × hides it for 7 days for
 * this brand in this browser: a per-viewer convenience, so localStorage.
 */
defineProps<{
    /** The AI answer's can_add_keywords: the rule that guards the keywords card. */
    canAdd: boolean;
}>();

const HIDE_DAYS = 7;

const page = usePage();

const storageKey = computed(
    () =>
        `socialhq.keywordsNote.hiddenUntil.${page.props.auth?.currentWorkspace?.id ?? ''}`,
);

const readHidden = (): boolean => {
    try {
        return (
            Number(window.localStorage.getItem(storageKey.value) ?? 0) >
            Date.now()
        );
    } catch {
        return false;
    }
};

const hidden = ref(readHidden());

const href = brandKnowledge.url({
    query: { tab: 'strategy', focus: 'keywords' },
});

const hide = (): void => {
    hidden.value = true;

    try {
        window.localStorage.setItem(
            storageKey.value,
            String(Date.now() + HIDE_DAYS * 24 * 60 * 60 * 1000),
        );
    } catch {
        // Private windows may refuse storage: it stays hidden until the page reloads.
    }

    toast.info(trans('socialhq.keywords.note.hidden'));
};
</script>

<template>
    <div
        v-if="!hidden"
        class="flex items-start gap-2.5 rounded-lg bg-muted px-3 py-2.5 text-[13px] leading-snug text-muted-foreground"
        role="note"
        data-testid="socialhq-keywords-missing-note"
    >
        <IconTag
            class="mt-px size-4 shrink-0 text-subtle-foreground"
            aria-hidden="true"
        />
        <p v-if="canAdd" class="min-w-0 flex-1">
            {{ $t('socialhq.keywords.note.admin') }}
            <a
                :href="href"
                target="_blank"
                rel="noopener"
                class="ms-1 inline-flex items-center gap-0.5 font-medium whitespace-nowrap text-primary-text hover:underline"
                data-testid="socialhq-keywords-missing-note-link"
            >
                {{ $t('socialhq.keywords.note.action') }}
                <IconExternalLink class="size-3.5" aria-hidden="true" />
            </a>
        </p>
        <p v-else class="min-w-0 flex-1">
            {{ $t('socialhq.keywords.note.member') }}
        </p>
        <button
            type="button"
            class="-me-1 -mt-0.5 inline-flex size-6 shrink-0 items-center justify-center rounded-md text-subtle-foreground hover:bg-accent"
            :title="$t('socialhq.keywords.note.hide')"
            :aria-label="$t('socialhq.keywords.note.hide')"
            data-testid="socialhq-keywords-missing-note-hide"
            @click="hide"
        >
            <IconX class="size-3.5" />
        </button>
    </div>
</template>
