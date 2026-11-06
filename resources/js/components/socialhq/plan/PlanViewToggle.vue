<script setup lang="ts">
import { IconLayoutGrid, IconList } from '@tabler/icons-vue';

export type PlanView = 'grid' | 'list';

/** The grid/list switch of the plan page. */
const view = defineModel<PlanView>({ required: true });

const showGrid = (): void => {
    view.value = 'grid';
};

const showList = (): void => {
    view.value = 'list';
};
</script>

<template>
    <div
        class="flex shrink-0 rounded-lg border border-border-strong bg-card p-[3px]"
        role="group"
        :aria-label="$t('socialhq_plan.view.label')"
    >
        <button
            type="button"
            class="flex size-7 items-center justify-center rounded-md transition-control focus-visible:outline-2 focus-visible:outline-ring"
            :class="
                view === 'grid'
                    ? 'bg-primary-selected text-primary-text'
                    : 'text-muted-foreground hover:bg-accent'
            "
            :aria-pressed="view === 'grid'"
            :aria-label="$t('socialhq_plan.view.grid')"
            data-testid="plan-view-grid"
            @click="showGrid"
        >
            <IconLayoutGrid class="size-4" />
        </button>
        <button
            type="button"
            class="flex size-7 items-center justify-center rounded-md transition-control focus-visible:outline-2 focus-visible:outline-ring"
            :class="
                view === 'list'
                    ? 'bg-primary-selected text-primary-text'
                    : 'text-muted-foreground hover:bg-accent'
            "
            :aria-pressed="view === 'list'"
            :aria-label="$t('socialhq_plan.view.list')"
            data-testid="plan-view-list"
            @click="showList"
        >
            <IconList class="size-4" />
        </button>
    </div>
</template>
