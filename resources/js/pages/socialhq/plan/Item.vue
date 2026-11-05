<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import CopyEditor from '@/components/socialhq/plan/item/CopyEditor.vue';
import ItemSummary from '@/components/socialhq/plan/item/ItemSummary.vue';
import ReviewThread from '@/components/socialhq/plan/item/ReviewThread.vue';
import type {
    CopyVersion,
    PlanAbilities,
    PlanItemDetail,
    ReviewEntry,
} from '@/components/socialhq/plan/item/types';
import VersionHistory from '@/components/socialhq/plan/item/VersionHistory.vue';
import PlanHeader from '@/components/socialhq/plan/PlanHeader.vue';
import PlanTabs from '@/components/socialhq/plan/PlanTabs.vue';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps<{
    item: PlanItemDetail;
    versions: CopyVersion[];
    thread: ReviewEntry[];
    abilities: PlanAbilities;
    aiAvailable: boolean;
}>();

const latest = computed(
    () => props.versions[props.versions.length - 1] ?? null,
);

const copyEditor = ref<InstanceType<typeof CopyEditor> | null>(null);

const useVersion = (version: CopyVersion): void => {
    copyEditor.value?.loadVersion(version);
    document.getElementById('socialhq-copy-caption')?.focus();
};
</script>

<template>
    <Head :title="item.title" />
    <AppLayout full-width>
        <template #header>
            <PlanHeader />
        </template>
        <PlanTabs active="plan" />

        <div
            class="mx-auto w-full max-w-7xl px-4 py-6 md:px-8"
            data-testid="plan-item"
        >
            <ItemSummary :item="item" />

            <div
                class="mt-6 grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(320px,400px)]"
            >
                <div class="grid min-w-0 gap-6">
                    <CopyEditor
                        ref="copyEditor"
                        :item="item"
                        :latest="latest"
                        :abilities="abilities"
                        :ai-available="aiAvailable"
                    />
                    <VersionHistory
                        :versions="versions"
                        :can-edit="abilities.canWork"
                        @use="useVersion"
                    />
                </div>
                <div class="min-w-0 lg:sticky lg:top-4">
                    <ReviewThread
                        :item-id="item.id"
                        :entries="thread"
                        :can-comment="abilities.canWork"
                        :has-copy="versions.length > 0"
                        :has-brief="false"
                    />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
