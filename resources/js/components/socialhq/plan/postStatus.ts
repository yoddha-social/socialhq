/**
 * A post's lifecycle status, as TryPost stores it. Its colours are in
 * `socialhq/postStatusTone.ts`, shared with Publish and the calendar.
 */
export type PostStatus =
    | 'draft'
    | 'pending_approval'
    | 'scheduled'
    | 'publishing'
    | 'published'
    | 'failed';

/** TryPost's own label for the status, so the plan and Publish use the same words. */
export const postStatusLabelKey = (status: PostStatus): string =>
    `posts.status.${status}`;
