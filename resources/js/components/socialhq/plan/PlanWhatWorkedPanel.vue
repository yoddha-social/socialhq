<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    IconChevronDown,
    IconExternalLink,
    IconTrendingUp,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onMounted, ref, watch } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import type {
    WhatWorked,
    WhatWorkedPost,
} from '@/components/socialhq/plan/types';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import date from '@/date';
import { formatNumberCompact } from '@/lib/utils';

/**
 * "What worked lately": the brand's best and weakest posts of the last 90
 * days and its best format, for the team. Collapsed by default (the choice is
 * remembered per browser); the data is an optional prop asked for with a
 * partial reload the first time the panel opens, so the month page stays fast.
 */
const props = defineProps<{
    whatWorked?: WhatWorked;
}>();

const OPEN_STORAGE_KEY = 'socialhq.plan.whatWorked.open';

const readOpen = (): boolean => {
    try {
        return window.localStorage.getItem(OPEN_STORAGE_KEY) === '1';
    } catch {
        return false;
    }
};

const open = ref(readOpen());
const loading = ref(false);
const failed = ref(false);

/** The last answer, kept when later visits leave the optional prop out. */
const data = ref<WhatWorked | null>(props.whatWorked ?? null);

watch(
    () => props.whatWorked,
    (next) => {
        if (next) {
            data.value = next;
        }
    },
);

const load = (): void => {
    if (loading.value || data.value) {
        return;
    }

    failed.value = false;
    loading.value = true;
    router.reload({
        only: ['whatWorked'],
        onError: () => {
            failed.value = true;
        },
        onHttpException: () => {
            failed.value = true;
        },
        onNetworkError: () => {
            failed.value = true;
        },
        onFinish: () => {
            loading.value = false;
        },
    });
};

const toggle = (): void => {
    open.value = !open.value;

    try {
        window.localStorage.setItem(OPEN_STORAGE_KEY, open.value ? '1' : '0');
    } catch {
        // Storage blocked: the panel opens closed next time.
    }

    if (open.value) {
        load();
    }
};

const retry = (): void => load();

onMounted(() => {
    if (open.value) {
        load();
    }
});

const isEmpty = computed(
    () =>
        data.value !== null &&
        data.value.best.length === 0 &&
        data.value.weakest.length === 0,
);

const metricWord = computed(() =>
    data.value?.metric
        ? trans(`socialhq_plan.lately.metrics.${data.value.metric}`)
        : '',
);

const formatLabel = (contentType: string | null): string =>
    contentType ? trans(`analytics.detail.content_types.${contentType}`) : '';

const bestFormatLine = computed(() => {
    const best = data.value?.best_format;

    if (!best) {
        return null;
    }

    return trans('socialhq_plan.lately.best_format', {
        format: formatLabel(best.content_type),
        average: formatNumberCompact(best.average),
        metric: metricWord.value,
    });
});

const groups = computed<{ key: 'best' | 'weakest'; posts: WhatWorkedPost[] }[]>(
    () =>
        data.value
            ? [
                  { key: 'best' as const, posts: data.value.best },
                  { key: 'weakest' as const, posts: data.value.weakest },
              ].filter((group) => group.posts.length > 0)
            : [],
);
</script>

<template>
    <section
        class="rounded-xl border border-border-strong bg-card"
        data-testid="plan-what-worked"
    >
        <button
            type="button"
            class="flex w-full items-center gap-2 rounded-xl px-4 py-2.5 text-start transition-control hover:bg-accent/50 focus-visible:outline-2 focus-visible:outline-ring"
            :aria-expanded="open"
            aria-controls="plan-what-worked-body"
            data-testid="plan-what-worked-toggle"
            @click="toggle"
        >
            <IconTrendingUp
                class="size-4 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
            <span class="text-sm font-medium text-foreground">
                {{ $t('socialhq_plan.lately.title') }}
            </span>
            <span class="text-xs text-muted-foreground">
                {{ $t('socialhq_plan.lately.period') }}
            </span>
            <IconChevronDown
                class="ms-auto size-4 shrink-0 text-muted-foreground transition-transform"
                :class="open ? 'rotate-180' : ''"
                aria-hidden="true"
            />
        </button>

        <div
            v-if="open"
            id="plan-what-worked-body"
            class="border-t border-border px-4 py-3"
            :aria-busy="loading || undefined"
        >
            <div
                v-if="loading && !data"
                class="grid gap-2"
                data-testid="plan-what-worked-loading"
            >
                <Skeleton v-for="row in 4" :key="row" class="h-6 w-full" />
            </div>

            <div
                v-else-if="failed && !data"
                class="flex flex-wrap items-center gap-3 text-sm text-muted-foreground"
                role="alert"
            >
                {{ $t('socialhq_plan.lately.failed') }}
                <Button
                    size="sm"
                    variant="outline"
                    data-testid="plan-what-worked-retry"
                    @click="retry"
                >
                    {{ $t('socialhq_plan.lately.retry') }}
                </Button>
            </div>

            <p
                v-else-if="isEmpty"
                class="text-sm text-muted-foreground"
                data-testid="plan-what-worked-empty"
            >
                {{ $t('socialhq_plan.lately.empty') }}
            </p>

            <div v-else-if="data" class="grid gap-3">
                <p
                    v-if="bestFormatLine"
                    class="text-sm font-medium text-foreground"
                    data-testid="plan-what-worked-format"
                >
                    {{ bestFormatLine }}
                </p>
                <div
                    v-for="group in groups"
                    :key="group.key"
                    class="grid gap-1"
                    :data-testid="`plan-what-worked-${group.key}`"
                >
                    <h3
                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        {{ $t(`socialhq_plan.lately.${group.key}`) }}
                    </h3>
                    <ul class="grid gap-1">
                        <li
                            v-for="post in group.posts"
                            :key="post.id"
                            class="flex min-w-0 items-center gap-2 text-sm"
                        >
                            <span
                                class="w-16 shrink-0 text-xs text-muted-foreground tabular-nums"
                            >
                                {{ date.formatDateOnly(post.date) }}
                            </span>
                            <ChannelAvatar
                                :platform="post.platform"
                                :name="post.channel ?? post.platform"
                                :src="post.avatar_url"
                                :size="20"
                                reserve-space
                            />
                            <span
                                v-if="post.content_type"
                                class="shrink-0 text-xs text-muted-foreground max-sm:hidden"
                            >
                                {{ formatLabel(post.content_type) }}
                            </span>
                            <span
                                class="min-w-0 flex-1 truncate text-foreground"
                                :title="post.excerpt ?? undefined"
                            >
                                {{ post.excerpt }}
                            </span>
                            <span
                                class="shrink-0 text-xs font-medium text-foreground tabular-nums"
                            >
                                {{ formatNumberCompact(post.value) }}
                                <span class="font-normal text-muted-foreground">
                                    {{ metricWord }}
                                </span>
                            </span>
                            <a
                                v-if="post.url"
                                :href="post.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="shrink-0 rounded-md p-1 text-muted-foreground transition-control hover:bg-accent hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
                                :aria-label="
                                    $t('socialhq_plan.lately.open_post')
                                "
                                :title="$t('socialhq_plan.lately.open_post')"
                                :data-testid="`plan-what-worked-link-${post.id}`"
                            >
                                <IconExternalLink class="size-4" />
                            </a>
                            <span v-else class="size-6 shrink-0" />
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </section>
</template>
