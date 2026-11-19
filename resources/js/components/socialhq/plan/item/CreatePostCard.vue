<script setup lang="ts">
import { router, useHttp } from '@inertiajs/vue3';
import {
    IconCircleCheck,
    IconCircleDashed,
    IconPhotoOff,
    IconSend2,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { onBeforeUnmount, ref } from 'vue';
import { toast } from 'vue-sonner';

import type {
    CreativeWaiver,
    PlanAbilities,
    PlanItemDetail,
    PostBlocker,
} from '@/components/socialhq/plan/item/types';
import {
    firstErrorOf,
    serverMessageOf,
} from '@/components/socialhq/requestErrors';
import { Button } from '@/components/ui/button';
import { openPostComposer } from '@/composables/useGlobalPostComposer';
import date from '@/date';
import { store as preparePost } from '@/routes/socialhq/plan/items/post';
import {
    store as allowTextOnly,
    destroy as requireCreative,
} from '@/routes/socialhq/plan/items/post/text-only';
import type { MediaItem } from '@/types/media';

const props = defineProps<{
    item: PlanItemDetail;
    ready: boolean;
    /** Everything still in the way, all at once. */
    blockers: PostBlocker[];
    waiver: CreativeWaiver | null;
    /** Whether the item has something to make (a graphic or a video). */
    hasCreative: boolean;
    /** Whether that creative is approved (its files go with the post). */
    creativeApproved: boolean;
    abilities: PlanAbilities;
}>();

const http = useHttp<
    Record<string, never>,
    {
        content: string;
        media: MediaItem[];
        /** Approved files the post's format can't carry (a PDF on a Facebook post). */
        left_out: string[];
        scheduled_on: string;
        /** The item's posting time as a wall clock in the user's zone, or null. */
        scheduled_at: string | null;
        social_account_id: string | null;
    }
>({});

onBeforeUnmount(() => {
    http.cancel();
});

const waiving = ref(false);

/** An approver lets the post go out without its creative, or takes that back. */
const setTextOnly = (on: boolean): void => {
    const route = on
        ? allowTextOnly(props.item.id)
        : requireCreative(props.item.id);

    router.visit(route.url, {
        method: route.method,
        only: ['postReady', 'postBlockers', 'creativeWaiver'],
        preserveScroll: true,
        onStart: () => (waiving.value = true),
        onFinish: () => (waiving.value = false),
        onError: (errors) => {
            const message = firstErrorOf(
                errors as Record<string, string | undefined>,
            );

            if (message) {
                toast.error(message);
            }
        },
    });
};

const createPost = async (): Promise<void> => {
    if (http.processing || !props.ready) {
        return;
    }

    try {
        const draft = await http.post(preparePost.url(props.item.id));

        // A 422 resolves with nothing and fills http.errors.
        if (!draft) {
            toast.error(
                firstErrorOf(
                    http.errors as Record<string, string | undefined>,
                ) ?? trans('socialhq_production.post.failed'),
            );

            return;
        }

        openPostComposer({
            date: draft.scheduled_at ?? draft.scheduled_on,
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

        if (draft.left_out.length > 0) {
            toast.warning(
                trans('socialhq_production.post.left_out', {
                    names: draft.left_out.join(', '),
                }),
            );
        }
    } catch (exception) {
        toast.error(
            serverMessageOf(exception) ??
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
            v-if="ready"
            class="text-sm text-muted-foreground"
            data-testid="item-create-post-reason"
        >
            {{
                $t(
                    waiver && hasCreative && !creativeApproved
                        ? 'socialhq_production.post.ready_text_only'
                        : 'socialhq_production.post.ready',
                )
            }}
        </p>
        <div
            v-else
            id="socialhq-create-post-reason"
            class="grid gap-1.5 text-sm text-muted-foreground"
            data-testid="item-create-post-reason"
        >
            <p>{{ $t('socialhq_production.post.waiting') }}</p>
            <ul class="grid gap-1">
                <li
                    v-for="blocker in blockers"
                    :key="blocker"
                    class="flex items-start gap-1.5"
                    :data-testid="`item-create-post-blocker-${blocker}`"
                >
                    <IconCircleDashed
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    {{ $t(`socialhq_production.post.blockers.${blocker}`) }}
                </li>
            </ul>
        </div>
        <div
            v-if="waiver"
            class="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground"
            data-testid="item-text-only"
        >
            <span class="flex items-center gap-1.5">
                <IconPhotoOff class="size-4 shrink-0" aria-hidden="true" />
                {{
                    waiver.by
                        ? $t('socialhq_production.post.text_only_on', {
                              name: waiver.by.name,
                              date: date.formatDate(waiver.at),
                          })
                        : $t('socialhq_production.post.text_only_on_unknown', {
                              date: date.formatDate(waiver.at),
                          })
                }}
            </span>
            <Button
                v-if="abilities.canApprove && !item.post_created_at"
                variant="link"
                size="sm"
                class="h-auto p-0 text-xs"
                :disabled="waiving"
                data-testid="item-text-only-undo"
                @click="setTextOnly(false)"
            >
                {{ $t('socialhq_production.post.text_only_undo') }}
            </Button>
        </div>
        <div
            v-else-if="
                abilities.canApprove &&
                hasCreative &&
                blockers.includes('creative')
            "
            class="grid gap-1"
        >
            <Button
                variant="outline"
                class="w-full"
                :loading="waiving"
                data-testid="item-text-only-button"
                aria-describedby="socialhq-text-only-hint"
                @click="setTextOnly(true)"
            >
                <IconPhotoOff />
                {{ $t('socialhq_production.post.text_only') }}
            </Button>
            <p
                id="socialhq-text-only-hint"
                class="text-xs text-muted-foreground"
            >
                {{ $t('socialhq_production.post.text_only_hint') }}
            </p>
        </div>
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
            :variant="ready && !item.post_created_at ? 'default' : 'outline'"
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
            {{
                $t(
                    item.post_created_at
                        ? 'socialhq_production.post.create_another'
                        : 'socialhq_production.post.create',
                )
            }}
        </Button>
    </section>
</template>
