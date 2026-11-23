import { reactive } from 'vue';

import type { UpcomingPost } from '@/components/socialhq/plan/types';

/** What a drop or delete does with the item's posts that will still go out. */
export type PostsChoice = 'drafts' | 'keep';

export type PostsChoiceMode = 'drop' | 'delete';

/**
 * Dropping or deleting an item whose post will still go out asks first
 * (ItemPostsChoiceDialog, mounted once on the month page): the post goes
 * back to drafts or keeps its schedule. Null means the person cancelled.
 */
export const postsChoice = reactive<{
    open: boolean;
    mode: PostsChoiceMode;
    posts: UpcomingPost[];
    resolve: ((choice: PostsChoice | null) => void) | null;
}>({
    open: false,
    mode: 'drop',
    posts: [],
    resolve: null,
});

export const askPostsChoice = (
    mode: PostsChoiceMode,
    posts: UpcomingPost[],
): Promise<PostsChoice | null> => {
    answerPostsChoice(null);

    return new Promise((resolve) => {
        postsChoice.mode = mode;
        postsChoice.posts = posts;
        postsChoice.resolve = resolve;
        postsChoice.open = true;
    });
};

export const answerPostsChoice = (choice: PostsChoice | null): void => {
    const resolve = postsChoice.resolve;

    postsChoice.resolve = null;
    postsChoice.open = false;
    resolve?.(choice);
};
