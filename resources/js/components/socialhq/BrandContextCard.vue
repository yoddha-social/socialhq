<script setup lang="ts">
import { IconSparkles, IconTag } from '@tabler/icons-vue';
import { computed } from 'vue';

import type { BrandUsage } from '@/components/socialhq/brandKnowledge';
import { formatFullNumber } from '@/lib/utils';

/**
 * "AI context" beside Brand knowledge: how much of the space the AI reads
 * is used, split between the profile and the knowledge entries, and the
 * SEO keywords, which come on top of it.
 */
const props = defineProps<{
    usage: BrandUsage;
    /** How many SEO keywords the brand has; they go to the AI outside the limit. */
    keywordCount: number;
}>();

const emit = defineEmits<{
    check: [];
}>();

type ContextState = 'empty' | 'shortened' | 'ready';

const state = computed<ContextState>(() => {
    if (props.usage.used === 0) {
        return 'empty';
    }

    return props.usage.profileTrimmed ? 'shortened' : 'ready';
});

const knowledgeUsed = computed(() =>
    Math.max(0, props.usage.used - props.usage.profileUsed),
);

const percentOf = (value: number): string =>
    `${Math.min(100, (value / Math.max(1, props.usage.budget)) * 100)}%`;

const openCheck = (): void => {
    emit('check');
};
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-xl border border-border-strong bg-card p-4"
        aria-labelledby="socialhq-brand-context-title"
        data-testid="socialhq-brand-usage"
    >
        <div class="flex items-center justify-between gap-2">
            <h2
                id="socialhq-brand-context-title"
                class="flex items-center gap-1.5 text-sm font-semibold text-foreground"
            >
                <IconSparkles class="size-4 text-muted-foreground" />
                {{ $t('socialhq.brand.context.title') }}
            </h2>
            <span
                class="flex items-center gap-1.5 text-xs font-medium"
                :class="{
                    'text-success-text': state === 'ready',
                    'text-amber-700 dark:text-amber-400': state === 'shortened',
                    'text-muted-foreground': state === 'empty',
                }"
                data-testid="socialhq-brand-context-state"
            >
                <span class="size-1.5 rounded-full bg-current" />
                {{ $t(`socialhq.brand.context.state.${state}`) }}
            </span>
        </div>

        <p class="text-sm text-muted-foreground tabular-nums">
            <span class="text-xl font-semibold text-foreground">
                {{ formatFullNumber(usage.used) }}
            </span>
            {{
                $t('socialhq.brand.context.of_budget', {
                    budget: formatFullNumber(usage.budget),
                })
            }}
        </p>

        <div
            class="flex h-2 overflow-hidden rounded-full bg-accent"
            role="img"
            :aria-label="
                $t('socialhq.brand.usage.value', {
                    used: formatFullNumber(usage.used),
                    budget: formatFullNumber(usage.budget),
                })
            "
        >
            <span
                class="h-full bg-primary"
                :style="{ width: percentOf(usage.profileUsed) }"
            />
            <span
                class="h-full bg-primary/45"
                :style="{ width: percentOf(knowledgeUsed) }"
            />
        </div>

        <dl class="flex flex-col gap-1.5 text-sm">
            <div class="flex items-center justify-between gap-2">
                <dt class="flex items-center gap-2 text-muted-foreground">
                    <span class="size-2 rounded-sm bg-primary" />
                    {{ $t('socialhq.brand.context.profile') }}
                </dt>
                <dd class="font-medium text-foreground tabular-nums">
                    {{ formatFullNumber(usage.profileUsed) }}
                </dd>
            </div>
            <div class="flex items-center justify-between gap-2">
                <dt class="flex items-center gap-2 text-muted-foreground">
                    <span class="size-2 rounded-sm bg-primary/45" />
                    {{ $t('socialhq.brand.context.knowledge') }}
                </dt>
                <dd class="font-medium text-foreground tabular-nums">
                    {{ formatFullNumber(knowledgeUsed) }}
                </dd>
            </div>
            <div
                v-if="keywordCount > 0"
                class="flex items-center justify-between gap-2 border-t border-dashed border-border pt-1.5"
                data-testid="socialhq-brand-context-keywords"
            >
                <dt class="flex items-center gap-2 text-muted-foreground">
                    <IconTag class="size-3.5" aria-hidden="true" />
                    {{ $t('socialhq.brand.context.keywords') }}
                </dt>
                <dd class="font-medium text-foreground tabular-nums">
                    {{ formatFullNumber(keywordCount) }}
                    <span class="text-xs font-normal text-muted-foreground">
                        · {{ $t('socialhq.brand.context.keywords_outside') }}
                    </span>
                </dd>
            </div>
        </dl>

        <p
            v-if="usage.profileTrimmed"
            class="text-sm text-amber-700 dark:text-amber-400"
            data-testid="socialhq-brand-profile-trimmed"
        >
            {{ $t('socialhq.brand.usage.profile_trimmed') }}
        </p>
        <p v-else class="text-sm text-muted-foreground">
            {{
                state === 'empty'
                    ? $t('socialhq.brand.context.empty_hint')
                    : $t('socialhq.brand.context.hint')
            }}
        </p>
        <p class="text-xs text-muted-foreground">
            {{ $t('socialhq.brand.usage.hint') }}
        </p>

        <button
            type="button"
            class="self-start text-sm font-medium text-primary-text hover:text-primary-text-hover hover:underline"
            data-testid="socialhq-brand-context-check"
            @click="openCheck"
        >
            {{ $t('socialhq.brand.context.check') }} →
        </button>
    </section>
</template>
