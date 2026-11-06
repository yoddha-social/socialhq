<script setup lang="ts">
import { router, useHttp } from '@inertiajs/vue3';
import { IconCircleCheck, IconSend2 } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount } from 'vue';
import { toast } from 'vue-sonner';

import type {
    PlanAbilities,
    PlanItemDetail,
} from '@/components/socialhq/plan/item/types';
import { Button } from '@/components/ui/button';
import { openPostComposer } from '@/composables/useGlobalPostComposer';
import date from '@/date';
import { extractErrorMessage } from '@/lib/httpError';
import { store as preparePost } from '@/routes/socialhq/plan/items/post';
import type { MediaItem } from '@/types/media';

const props = defineProps<{
    item: PlanItemDetail;
    ready: boolean;
    abilities: PlanAbilities;
}>();

const http = useHttp<
    Record<string, never>,
    {
        content: string;
        media: MediaItem[];
        scheduled_on: string;
        social_account_id: string | null;
    }
>({});

onBeforeUnmount(() => {
    http.cancel();
});

/** What still stands between the item and its post. */
const waitingKey = computed(() => {
    if (props.item.copy_status !== 'approved') {
        return 'socialhq_production.post.waiting_copy';
    }

    return 'socialhq_production.post.waiting_creative';
});

const createPost = async (): Promise<void> => {
    if (http.processing || !props.ready) {
        return;
    }

    try {
        const draft = await http.post(preparePost.url(props.item.id));

        if (!draft) {
            toast.error(trans('socialhq_production.post.failed'));

            return;
        }

        openPostComposer({
            date: draft.scheduled_on,
            socialAccountIds: draft.social_account_id
                ? [draft.social_account_id]
                : [],
            draft: {
                content: draft.content,
                media: draft.media,
                scheduled_at: null,
                label_ids: [],
            },
        });
        router.reload({ only: ['item'] });
    } catch (exception) {
        toast.error(
            extractErrorMessage(exception) ??
                trans('socialhq_production.post.failed'),
        );
    }
};
</script>

<template>
    <section
        class="grid gap-3 rounded-xl border bg-card px-5 py-4"
        data-testid="item-create-post"
    >
        <p
            id="socialhq-create-post-reason"
            class="text-sm text-muted-foreground"
            data-testid="item-create-post-reason"
        >
            {{ ready ? $t('socialhq_production.post.ready') : $t(waitingKey) }}
        </p>
        <p
            v-if="item.post_created_at"
            class="flex items-center gap-1.5 text-xs text-success-text"
            data-testid="item-post-created"
        >
            <IconCircleCheck class="size-4" />
            {{
                $t('socialhq_production.post.created', {
                    time: date.formatDateTime(item.post_created_at),
                })
            }}
        </p>
        <Button
            v-if="abilities.canWork"
            class="w-full"
            :variant="ready ? 'default' : 'outline'"
            :class="ready ? '' : 'text-muted-foreground'"
            :disabled="!ready"
            :aria-describedby="
                ready ? undefined : 'socialhq-create-post-reason'
            "
            :loading="http.processing"
            data-testid="item-create-post-button"
            @click="createPost"
        >
            <IconSend2 />
            {{ $t('socialhq_production.post.create') }}
        </Button>
    </section>
</template>
