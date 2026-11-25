<script setup lang="ts">
import { Link, router, useHttp } from '@inertiajs/vue3';
import {
    IconAlertTriangle,
    IconCircleDashed,
    IconExternalLink,
    IconPhotoOff,
    IconSend2,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, ref } from 'vue';
import { toast } from 'vue-sonner';

import { channelLabel } from '@/components/socialhq/channelLabel';
import { localizedDay } from '@/components/socialhq/localizedDay';
import type {
    CreativeWaiver,
    ItemPost,
    PlanAbilities,
    PlanItemDetail,
    PostBlocker,
} from '@/components/socialhq/plan/item/types';
import {
    backToApprovalWarning,
    followPosts,
} from '@/components/socialhq/plan/planRequests';
import { postStatusLabelKey } from '@/components/socialhq/plan/postStatus';
import { POST_STATUS_TONES } from '@/components/socialhq/postStatusTone';
import {
    firstErrorOf,
    serverMessageOf,
} from '@/components/socialhq/requestErrors';
import { Badge } from '@/components/ui/badge';
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
    /** Whether the item's format can go out without an image or video. */
    textOnlyAllowed: boolean;
    /** The posts saved from this item, with where each stands now. */
    posts: ItemPost[];
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

/** Dated drafts and scheduled posts that aren't on the item's day. */
const offDayCount = computed(
    () => props.posts.filter((post) => post.off_day).length,
);

const itemDay = computed(() =>
    localizedDay(props.item.scheduled_on).format('ddd D MMM'),
);

/** Approved posts that moving to the item's day sends back for approval, named before the move. */
const followWarning = computed(() =>
    backToApprovalWarning(
        props.posts
            .filter((post) => post.back_to_approval && post.scheduled_at)
            .map((post) => post.scheduled_at as string),
    ),
);

const following = ref(false);

const movePostsToItemDay = (): void => followPosts(props.item.id, following);

/** When the post went out, or when it is due; nothing for a draft without a time. */
const postTime = (post: ItemPost): string | null => {
    const at = post.published_at ?? post.scheduled_at;

    return at ? date.formatDateTime(at) : null;
};

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
            // Saving links the post to this item; closing without saving leaves it as it was.
            planItemId: props.item.id,
        });

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
                v-if="abilities.canApprove && posts.length === 0"
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
        <p
            v-else-if="
                abilities.canApprove &&
                hasCreative &&
                blockers.includes('creative') &&
                !textOnlyAllowed
            "
            class="flex items-start gap-1.5 text-xs text-muted-foreground"
            data-testid="item-text-only-unavailable"
        >
            <IconPhotoOff class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            {{
                $t('socialhq_production.post.text_only_unavailable', {
                    format: item.content_type_label ?? '',
                })
            }}
        </p>
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
        <div
            v-if="posts.length > 0"
            class="grid gap-1.5"
            data-testid="item-posts"
        >
            <p class="text-xs font-medium text-muted-foreground">
                {{
                    $tChoice(
                        'socialhq_production.post.posts_title',
                        posts.length,
                    )
                }}
            </p>
            <ul class="grid gap-1.5">
                <li
                    v-for="post in posts"
                    :key="post.id"
                    class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm"
                    :data-testid="`item-post-${post.id}`"
                >
                    <Badge
                        :variant="POST_STATUS_TONES[post.status].badge"
                        :data-testid="`item-post-status-${post.id}`"
                        >{{ $t(postStatusLabelKey(post.status)) }}</Badge
                    >
                    <Badge
                        v-if="post.off_day"
                        variant="warning"
                        :data-testid="`item-post-off-day-${post.id}`"
                        >{{ $t('socialhq_production.post.off_day') }}</Badge
                    >
                    <Badge
                        v-if="post.other_channel"
                        variant="warning"
                        :data-testid="`item-post-other-channel-${post.id}`"
                        >{{
                            $t('socialhq_production.post.other_channel')
                        }}</Badge
                    >
                    <Badge
                        v-else-if="post.other_format"
                        variant="warning"
                        :data-testid="`item-post-other-format-${post.id}`"
                        >{{
                            $t('socialhq_production.post.other_format')
                        }}</Badge
                    >
                    <span class="min-w-0 truncate text-muted-foreground">
                        {{ channelLabel(post.channel, post.platform) }}
                        <template v-if="postTime(post)">
                            · {{ postTime(post) }}
                        </template>
                    </span>
                    <Link
                        :href="post.url"
                        class="ms-auto text-xs font-medium text-primary-text underline-offset-4 hover:underline"
                        :data-testid="`item-post-open-${post.id}`"
                        >{{ $t('socialhq_production.post.open') }}</Link
                    >
                    <a
                        v-if="post.network_url"
                        :href="post.network_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-muted-foreground hover:text-foreground"
                        :aria-label="
                            $t('socialhq_production.post.open_on_network')
                        "
                    >
                        <IconExternalLink class="size-4" aria-hidden="true" />
                    </a>
                </li>
            </ul>
            <p
                v-if="abilities.canWork && offDayCount > 0 && followWarning"
                class="flex items-start gap-2 rounded-lg border border-warning/30 bg-warning/10 px-3 py-2 text-xs text-foreground"
                role="status"
                data-testid="item-posts-follow-warning"
            >
                <IconAlertTriangle
                    class="size-4 shrink-0 text-warning"
                    aria-hidden="true"
                />
                {{ followWarning }}
            </p>
            <Button
                v-if="abilities.canWork && offDayCount > 0"
                variant="outline"
                size="sm"
                class="w-fit"
                :loading="following"
                data-testid="item-posts-follow"
                @click="movePostsToItemDay"
            >
                {{ $t('socialhq_production.post.follow', { date: itemDay }) }}
            </Button>
        </div>
        <Button
            v-if="abilities.canWork"
            class="w-full"
            :variant="ready && posts.length === 0 ? 'default' : 'outline'"
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
                    posts.length > 0
                        ? 'socialhq_production.post.create_another'
                        : 'socialhq_production.post.create',
                )
            }}
        </Button>
    </section>
</template>
