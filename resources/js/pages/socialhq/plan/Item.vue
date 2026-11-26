<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import ComposerDiscardDialog from '@/components/posts/composer/ComposerDiscardDialog.vue';
import type { KeywordPanel } from '@/components/socialhq/keywords/keywords';
import BriefPanel from '@/components/socialhq/plan/item/BriefPanel.vue';
import CopyEditor from '@/components/socialhq/plan/item/CopyEditor.vue';
import CreatePostCard from '@/components/socialhq/plan/item/CreatePostCard.vue';
import CreativeRounds from '@/components/socialhq/plan/item/CreativeRounds.vue';
import ItemSummary from '@/components/socialhq/plan/item/ItemSummary.vue';
import ReviewThread from '@/components/socialhq/plan/item/ReviewThread.vue';
import type {
    BriefDetail,
    CopyVersion,
    CreativeWaiver,
    ItemPost,
    PlanAbilities,
    PlanItemDetail,
    PlanPerson,
    PostBlocker,
    ReviewEntry,
} from '@/components/socialhq/plan/item/types';
import { useItemPageGuard } from '@/components/socialhq/plan/item/unsavedWork';
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
    postBlockers: PostBlocker[];
    creativeWaiver: CreativeWaiver | null;
    /** Whether an approver may let the post go out without its creative (the format allows it). */
    textOnlyAllowed: boolean;
    itemPosts: ItemPost[];
    abilities: PlanAbilities;
    aiAvailable: boolean;
    /** "Keywords for this post"; null when the brand has no SEO keywords. */
    keywords: KeywordPanel | null;
}>();

const latest = computed(
    () => props.versions[props.versions.length - 1] ?? null,
);

const copyEditor = ref<InstanceType<typeof CopyEditor> | null>(null);

const unsavedWork = useItemPageGuard();

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
                        :keywords="keywords"
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
                        :blockers="postBlockers"
                        :waiver="creativeWaiver"
                        :text-only-allowed="textOnlyAllowed"
                        :posts="itemPosts"
                        :has-creative="briefKind !== null"
                        :creative-approved="brief?.stage === 'approved'"
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

    <ComposerDiscardDialog
        v-model:open="unsavedWork.confirming.value"
        :title="$t('common.unsaved_changes.title')"
        :description="$t('socialhq_production.item.unsaved_description')"
        :action="$t('common.unsaved_changes.discard')"
        test-id="item-unsaved-dialog"
        cancel-test-id="item-unsaved-keep"
        action-test-id="item-unsaved-discard"
        @confirm="unsavedWork.discard"
        @cancel="unsavedWork.keepEditing"
    />
</template>
