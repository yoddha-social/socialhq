<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

import { show as planShow, production } from '@/routes/socialhq/plan';
import { index as eventsIndex } from '@/routes/socialhq/plan/events';

export type PlanTab = 'plan' | 'production' | 'events';

defineProps<{
    active: PlanTab;
}>();

const tabs: { key: PlanTab; href: () => string }[] = [
    { key: 'plan', href: () => planShow.url() },
    { key: 'production', href: () => production.url() },
    { key: 'events', href: () => eventsIndex.url() },
];
</script>

<template>
    <!-- Below md the filters get their own row under the tabs, so they never cover them. -->
    <div
        class="mx-4 flex min-w-0 shrink-0 flex-col gap-x-4 md:mx-8 md:flex-row md:items-center md:justify-between md:shadow-[inset_0_-1px_0_var(--color-border-strong)]"
    >
        <nav
            class="flex min-w-0 gap-4 overflow-x-auto max-md:shadow-[inset_0_-1px_0_var(--color-border-strong)]"
            :aria-label="$t('socialhq_plan.tabs.label')"
            data-testid="plan-tabs"
        >
            <Link
                v-for="tab in tabs"
                :key="tab.key"
                :href="tab.href()"
                :aria-current="active === tab.key ? 'page' : undefined"
                :data-testid="`plan-tab-${tab.key}`"
                class="relative inline-flex h-[45px] shrink-0 items-center px-2 text-sm font-medium transition-control after:absolute after:inset-x-0.5 after:bottom-0 after:h-px after:bg-primary-text after:opacity-0 aria-[current=page]:after:opacity-100"
                :class="
                    active === tab.key
                        ? 'text-foreground'
                        : 'text-muted-foreground hover:text-foreground'
                "
            >
                {{ $t(`socialhq_plan.tabs.${tab.key}`) }}
            </Link>
        </nav>
        <div
            v-if="$slots.filters"
            class="flex min-w-0 flex-wrap items-center gap-2 py-1 max-md:pt-2 md:shrink-0"
        >
            <slot name="filters" />
        </div>
    </div>
</template>
