<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconChevronRight } from '@tabler/icons-vue';

withDefaults(
    defineProps<{
        title: string;
        description?: string | null;
        /** members: 760px, list: 860px, wide: 1160px (the mockup's columns). */
        width?: 'members' | 'list' | 'wide';
        /** Breadcrumb above the title: earlier pages link, the last is this page. */
        crumbs?: { label: string; href?: string }[];
    }>(),
    { description: null, width: 'list', crumbs: () => [] },
);

const widths = {
    members: 'max-w-[760px]',
    list: 'max-w-[860px]',
    wide: 'max-w-[1160px]',
} as const;
</script>

<template>
    <div
        class="mx-auto flex w-full flex-col gap-6 px-4 pt-2 pb-16 md:px-8 md:pt-10"
        :class="widths[width]"
        data-testid="settings-page"
    >
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <nav
                    v-if="crumbs.length"
                    class="mb-2 flex items-center gap-1.5 text-[13px] text-muted-foreground"
                    data-testid="roles-crumbs"
                >
                    <template v-for="(crumb, index) in crumbs" :key="index">
                        <IconChevronRight v-if="index > 0" class="size-3" />
                        <Link
                            v-if="crumb.href"
                            :href="crumb.href"
                            class="hover:text-foreground"
                            >{{ crumb.label }}</Link
                        >
                        <span v-else class="truncate text-foreground">{{
                            crumb.label
                        }}</span>
                    </template>
                </nav>
                <h1
                    class="font-heading text-xl leading-tight font-medium text-foreground"
                    data-testid="header-title"
                >
                    {{ title }}
                </h1>
                <p
                    v-if="description"
                    class="mt-1 text-sm text-muted-foreground"
                    data-testid="settings-page-description"
                >
                    {{ description }}
                </p>
            </div>
            <div
                v-if="$slots['actions']"
                class="flex flex-wrap items-center gap-2"
            >
                <slot name="actions" />
            </div>
        </header>

        <slot />
    </div>
</template>
