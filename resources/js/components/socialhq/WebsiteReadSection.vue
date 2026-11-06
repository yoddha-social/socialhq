<script setup lang="ts">
import { useForm, usePoll } from '@inertiajs/vue3';
import { IconWorld } from '@tabler/icons-vue';
import { computed, watch } from 'vue';

import {
    isRunningCrawl,
    type WebsiteProp,
} from '@/components/socialhq/brandSources';
import WebsiteSuggestionItem from '@/components/socialhq/WebsiteSuggestionItem.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatFullNumber } from '@/lib/utils';
import { store as storeCrawl } from '@/routes/socialhq/brand/website';

/**
 * "Read our website" on Brand knowledge: an admin gives the site's
 * address; it is read in the background (the page reloads the `website`
 * prop while it runs) and the suggestions are listed to accept, edit or
 * skip one by one. For workspace admins only.
 */
const props = defineProps<{
    website: WebsiteProp;
    canEdit: boolean;
}>();

const crawl = computed(() => props.website.crawl);
const isRunning = computed(() => isRunningCrawl(crawl.value?.status));
const suggestions = computed(() => crawl.value?.suggestions ?? []);
const hasOpen = computed(() =>
    suggestions.value.some((suggestion) => suggestion.status === 'open'),
);
const showsSuggestions = computed(
    () =>
        crawl.value?.status === 'ready' || crawl.value?.status === 'unchanged',
);

const form = useForm<{ url: string }>({ url: crawl.value?.url ?? '' });

const { start: startPolling, stop: stopPolling } = usePoll(
    3000,
    { only: ['website'] },
    { autoStart: false },
);

watch(
    isRunning,
    (running) => {
        if (running) {
            startPolling();
        } else {
            stopPolling();
        }
    },
    { immediate: true },
);

const read = (): void => {
    form.post(storeCrawl.url(), { preserveScroll: true });
};
</script>

<template>
    <section
        v-if="canEdit"
        class="flex flex-col gap-4"
        data-testid="socialhq-website"
    >
        <div class="flex flex-col gap-1">
            <h2 class="text-base font-semibold text-foreground">
                {{ $t('socialhq_knowledge.website.title') }}
            </h2>
            <p class="text-sm text-muted-foreground">
                {{
                    $t('socialhq_knowledge.website.description', {
                        count: String(website.maxPages),
                    })
                }}
            </p>
        </div>

        <p
            v-if="!website.aiAvailable"
            class="text-sm text-muted-foreground"
            data-testid="socialhq-website-ai-off"
        >
            {{ $t('socialhq_knowledge.website.ai_off') }}
        </p>
        <form v-else class="flex flex-col gap-2" @submit.prevent="read">
            <Label for="socialhq-website-url">
                {{ $t('socialhq_knowledge.website.url') }}
            </Label>
            <div class="flex flex-col gap-2 sm:flex-row">
                <Input
                    id="socialhq-website-url"
                    v-model="form.url"
                    inputmode="url"
                    class="min-w-0 flex-1"
                    :placeholder="
                        $t('socialhq_knowledge.website.url_placeholder')
                    "
                    :disabled="isRunning"
                    :aria-invalid="form.errors.url ? true : undefined"
                    :aria-describedby="
                        form.errors.url
                            ? 'socialhq-website-url-error'
                            : undefined
                    "
                    data-testid="socialhq-website-url"
                />
                <Button
                    type="submit"
                    class="shrink-0"
                    :loading="form.processing"
                    :disabled="isRunning"
                    data-testid="socialhq-website-read"
                >
                    <IconWorld class="size-4" />
                    {{
                        crawl
                            ? $t('socialhq_knowledge.website.read_again')
                            : $t('socialhq_knowledge.website.start')
                    }}
                </Button>
            </div>
            <p
                v-if="form.errors.url"
                id="socialhq-website-url-error"
                role="alert"
                class="text-sm text-destructive-text"
                data-testid="socialhq-website-url-error"
            >
                {{ form.errors.url }}
            </p>
        </form>

        <p
            v-if="crawl && isRunning"
            class="flex items-center gap-2 text-sm text-muted-foreground"
            aria-live="polite"
            data-testid="socialhq-website-status"
        >
            <Spinner />
            {{ $t(`socialhq_knowledge.website.status.${crawl.status}`) }}
        </p>

        <p
            v-else-if="crawl?.status === 'failed' && crawl.failure"
            role="alert"
            class="text-sm text-destructive-text"
            data-testid="socialhq-website-failure"
        >
            {{ $t(`socialhq_knowledge.website.failures.${crawl.failure}`) }}
        </p>

        <template v-else-if="crawl && showsSuggestions">
            <div class="flex flex-col gap-1">
                <h3 class="text-sm font-semibold text-foreground">
                    {{ $t('socialhq_knowledge.website.review.title') }}
                </h3>
                <p class="text-sm text-muted-foreground">
                    {{
                        $tChoice(
                            'socialhq_knowledge.website.pages',
                            crawl.pages_read + crawl.pages_unchanged,
                            {
                                count: formatFullNumber(
                                    crawl.pages_read + crawl.pages_unchanged,
                                ),
                            },
                        )
                    }}
                    <template v-if="crawl.pages_unchanged > 0">
                        ·
                        {{
                            $t('socialhq_knowledge.website.pages_unchanged', {
                                count: formatFullNumber(crawl.pages_unchanged),
                            })
                        }}
                    </template>
                </p>
                <p
                    v-if="crawl.status === 'unchanged'"
                    class="text-sm text-muted-foreground"
                    data-testid="socialhq-website-unchanged"
                >
                    {{ $t('socialhq_knowledge.website.unchanged') }}
                </p>
                <p v-if="hasOpen" class="text-sm text-muted-foreground">
                    {{ $t('socialhq_knowledge.website.review.description') }}
                </p>
                <p
                    v-else-if="suggestions.length > 0"
                    class="text-sm text-muted-foreground"
                >
                    {{ $t('socialhq_knowledge.website.review.done') }}
                </p>
            </div>

            <p
                v-if="suggestions.length === 0"
                class="text-sm text-muted-foreground"
                data-testid="socialhq-website-none"
            >
                {{ $t('socialhq_knowledge.website.review.none') }}
            </p>
            <ul v-else class="flex flex-col gap-2">
                <WebsiteSuggestionItem
                    v-for="suggestion in suggestions"
                    :key="suggestion.key"
                    :crawl-id="crawl.id"
                    :suggestion="suggestion"
                />
            </ul>
        </template>
    </section>
</template>
