import type { BadgeVariants } from '@/components/ui/badge';

/** A post's lifecycle status, as TryPost stores it. */
export type PostStatus =
    | 'draft'
    | 'pending_approval'
    | 'scheduled'
    | 'publishing'
    | 'published'
    | 'failed';

/** How a plan item shows where its post stands: failed stands out, published reads as done. */
export const POST_STATUS_VARIANT: Record<
    PostStatus,
    NonNullable<BadgeVariants['variant']>
> = {
    draft: 'secondary',
    pending_approval: 'warning',
    scheduled: 'info',
    publishing: 'info',
    published: 'success',
    failed: 'destructive-subtle',
};

/** TryPost's own label for the status, so the plan and Publish use the same words. */
export const postStatusLabelKey = (status: PostStatus): string =>
    `posts.status.${status}`;
