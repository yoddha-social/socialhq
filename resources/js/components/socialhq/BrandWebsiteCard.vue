<script setup lang="ts">
import { IconWorld } from '@tabler/icons-vue';
import { computed } from 'vue';

import {
    openSuggestionCount,
    websiteHost,
} from '@/components/socialhq/brandKnowledge';
import {
    isRunningCrawl,
    type WebsiteProp,
} from '@/components/socialhq/brandSources';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import date from '@/date';
import { formatFullNumber } from '@/lib/utils';

/**
 * "Website source" beside Brand knowledge: the site the AI read, when, and
 * a way to the suggestions on the Sources tab. Admins only, like the
 * website reader itself.
 */
const props = defineProps<{
    website: WebsiteProp;
}>();

const emit = defineEmits<{
    open: [];
}>();

const crawl = computed(() => props.website.crawl);
const isRunning = computed(() => isRunningCrawl(crawl.value?.status));
const openCount = computed(() => openSuggestionCount(props.website));
const pagesRead = computed(() =>
    crawl.value ? crawl.value.pages_read + crawl.value.pages_unchanged : 0,
);

const openSource = (): void => {
    emit('open');
};
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-xl border border-border-strong bg-card p-4"
        aria-labelledby="socialhq-brand-website-title"
        data-testid="socialhq-brand-website-card"
    >
        <h2
            id="socialhq-brand-website-title"
            class="flex items-center gap-1.5 text-sm font-semibold text-foreground"
        >
            <IconWorld class="size-4 text-muted-foreground" />
            {{ $t('socialhq.brand.website_card.title') }}
        </h2>

        <template v-if="crawl">
            <p class="truncate text-base font-semibold text-foreground">
                {{ websiteHost(crawl.url) }}
            </p>
            <p
                v-if="isRunning"
                class="flex items-center gap-2 text-sm text-muted-foreground"
                aria-live="polite"
            >
                <Spinner />
                {{ $t(`socialhq_knowledge.website.status.${crawl.status}`) }}
            </p>
            <p v-else class="text-sm text-muted-foreground">
                {{
                    $tChoice('socialhq_knowledge.website.pages', pagesRead, {
                        count: formatFullNumber(pagesRead),
                    })
                }}
                <template v-if="crawl.finished_at">
                    <br />
                    {{
                        $t('socialhq.brand.website_card.read_when', {
                            time: date.diffForHumans(crawl.finished_at),
                        })
                    }}
                </template>
            </p>
        </template>
        <template v-else>
            <p class="text-base font-semibold text-foreground">
                {{ $t('socialhq.brand.website_card.none') }}
            </p>
            <p class="text-sm text-muted-foreground">
                {{ $t('socialhq.brand.website_card.none_hint') }}
            </p>
        </template>

        <Button
            variant="outline"
            class="w-full"
            data-testid="socialhq-brand-website-open"
            @click="openSource"
        >
            <template v-if="openCount > 0">
                {{ $t('socialhq.brand.website_card.review') }}
                <span
                    class="rounded-full bg-warning px-1.5 text-xs font-semibold text-black tabular-nums"
                >
                    {{ formatFullNumber(openCount) }}
                </span>
            </template>
            <template v-else-if="crawl">
                {{ $t('socialhq.brand.website_card.open') }}
            </template>
            <template v-else>
                {{ $t('socialhq.brand.website_card.add') }}
            </template>
        </Button>
    </section>
</template>
