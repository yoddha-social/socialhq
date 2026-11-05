<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import BriefPanel from '@/components/socialhq/plan/item/BriefPanel.vue';
import CopyEditor from '@/components/socialhq/plan/item/CopyEditor.vue';
import CreatePostCard from '@/components/socialhq/plan/item/CreatePostCard.vue';
import CreativeRounds from '@/components/socialhq/plan/item/CreativeRounds.vue';
import ItemSummary from '@/components/socialhq/plan/item/ItemSummary.vue';
import ReviewThread from '@/components/socialhq/plan/item/ReviewThread.vue';
import type {
    BriefDetail,
    CopyVersion,
    PlanAbilities,
    PlanItemDetail,
    PlanPerson,
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
    brief: BriefDetail | null;
    briefKind: 'graphic' | 'video' | null;
    team: PlanPerson[];
    postReady: boolean;
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
                    <BriefPanel
                        v-if="brief || briefKind"
                        :item-id="item.id"
                        :brief="brief"
                        :brief-kind="briefKind"
                        :team="team"
                        :abilities="abilities"
                        :ai-available="aiAvailable"
                    />
                    <CreativeRounds
                        v-if="brief"
                        :item-id="item.id"
                        :brief="brief"
                        :abilities="abilities"
                    />
                </div>
                <div class="grid min-w-0 gap-6">
                    <CreatePostCard
                        :item="item"
                        :ready="postReady"
                        :abilities="abilities"
                    />
                    <ReviewThread
                        :item-id="item.id"
                        :entries="thread"
                        :can-comment="abilities.canWork"
                        :has-copy="versions.length > 0"
                        :has-brief="brief !== null"
                    />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
