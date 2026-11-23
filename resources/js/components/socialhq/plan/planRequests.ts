import { router } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import type { Ref } from 'vue';
import { toast } from 'vue-sonner';

import type { PlanItem } from '@/components/socialhq/plan/types';
import { follow as followItemPosts } from '@/routes/socialhq/plan/items/post';

/**
 * Feedback for the plan's quick writes (accept, drop, restore, review):
 * the first validation message, or a generic failure for any other refusal.
 */
export const toastFirstError = (errors: Record<string, string>): void => {
    const message = Object.values(errors)[0];

    if (message) {
        toast.error(message);
    }
};

export const toastRequestFailed = (): false => {
    toast.error(trans('socialhq_plan.errors.request_failed'));

    return false;
};

/**
 * Moves the item's posts that aren't on its day to that day, at the same
 * time of day. A post that couldn't move comes back as the error toast.
 */
export const followPosts = (
    itemId: number,
    count: number,
    busy?: Ref<boolean>,
): void => {
    router.post(
        followItemPosts.url(itemId),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => {
                if (busy) {
                    busy.value = true;
                }
            },
            onFinish: () => {
                if (busy) {
                    busy.value = false;
                }
            },
            onSuccess: () => {
                toast.success(
                    transChoice('socialhq_plan.posts_follow.moved', count),
                );
            },
            onError: toastFirstError,
            onHttpException: toastRequestFailed,
        },
    );
};

/**
 * After an item moved to another day: when its posts stayed on the old one,
 * a toast offers to move them too.
 */
export const offerToMovePosts = (item: PlanItem | undefined): void => {
    const count = item?.posts_off_day ?? 0;

    if (!item || count === 0) {
        return;
    }

    toast(
        transChoice('socialhq_plan.posts_follow.offer', count, {
            count: String(count),
        }),
        {
            duration: 10000,
            action: {
                label: transChoice('socialhq_plan.posts_follow.action', count),
                onClick: () => followPosts(item.id, count),
            },
        },
    );
};
