<script setup lang="ts">
import { useForm, usePoll } from '@inertiajs/vue3';
import { IconChevronDown, IconWorld } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

import {
    isRunningCrawl,
    type WebsiteProp,
} from '@/components/socialhq/brandSources';
import WebsiteKeywordSuggestions from '@/components/socialhq/WebsiteKeywordSuggestions.vue';
import WebsiteSuggestionItem from '@/components/socialhq/WebsiteSuggestionItem.vue';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatFullNumber } from '@/lib/utils';
import { store as storeCrawl } from '@/routes/socialhq/brand/website';

/**
 * "Read our website" on Brand knowledge: an admin gives the site's
 * address; it is read in the background (the page reloads the `website`
 * prop while it runs) and the suggestions are listed to accept, edit or
 * skip one by one; the ones already decided fold under one closed row.
 * For workspace admins only.
 */
const props = defineProps<{
    website: WebsiteProp;
    canEdit: boolean;
}>();

const crawl = computed(() => props.website.crawl);
const isRunning = computed(() => isRunningCrawl(crawl.value?.status));
const suggestions = computed(() => crawl.value?.suggestions ?? []);
const openSuggestions = computed(() =>
    suggestions.value.filter((suggestion) => suggestion.status === 'open'),
);
const reviewedSuggestions = computed(() =>
    suggestions.value.filter((suggestion) => suggestion.status !== 'open'),
);
const hasOpen = computed(() => openSuggestions.value.length > 0);
/** Open SEO keywords are listed in their own groups, after the rest. */
const openOthers = computed(() =>
    openSuggestions.value.filter((suggestion) => suggestion.type !== 'keyword'),
);
/** The accepted and skipped suggestions start folded away. */
const isReviewedOpen = ref(false);
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
            <template v-else>
                <ul v-if="openOthers.length > 0" class="flex flex-col gap-2">
                    <WebsiteSuggestionItem
                        v-for="suggestion in openOthers"
                        :key="suggestion.key"
                        :crawl-id="crawl.id"
                        :suggestion="suggestion"
                    />
                </ul>
                <WebsiteKeywordSuggestions
                    :crawl-id="crawl.id"
                    :suggestions="openSuggestions"
                />

                <Collapsible
                    v-if="reviewedSuggestions.length > 0"
                    v-model:open="isReviewedOpen"
                    class="flex flex-col gap-2"
                >
                    <CollapsibleTrigger
                        class="flex w-full cursor-pointer items-center gap-2 rounded-lg border bg-card px-4 py-3 text-start text-sm font-medium text-foreground transition-control outline-none hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring focus-visible:outline-solid"
                        data-testid="socialhq-website-reviewed-toggle"
                    >
                        <span class="flex-1">
                            {{
                                $t(
                                    'socialhq_knowledge.website.review.reviewed',
                                    {
                                        count: formatFullNumber(
                                            reviewedSuggestions.length,
                                        ),
                                    },
                                )
                            }}
                        </span>
                        <IconChevronDown
                            class="size-4 shrink-0 text-muted-foreground transition-transform"
                            :class="{ 'rotate-180': isReviewedOpen }"
                            aria-hidden="true"
                        />
                    </CollapsibleTrigger>
                    <CollapsibleContent data-testid="socialhq-website-reviewed">
                        <ul class="flex flex-col gap-2">
                            <WebsiteSuggestionItem
                                v-for="suggestion in reviewedSuggestions"
                                :key="suggestion.key"
                                :crawl-id="crawl.id"
                                :suggestion="suggestion"
                            />
                        </ul>
                    </CollapsibleContent>
                </Collapsible>
            </template>
        </template>
    </section>
</template>
