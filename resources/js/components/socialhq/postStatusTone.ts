import type { BadgeVariants } from '@/components/ui/badge';
import type { PostStatusValue } from '@/types/post';

export interface PostStatusTone {
    /** The `Badge` variant: the plan, Publish's post cards and the calendar's chips and list. */
    badge: NonNullable<BadgeVariants['variant']>;
    /** A solid dot of the same colour: the calendar's chips and list, the channel page. */
    dot: string;
}

/**
 * The one colour table for a post's status (owner decision 2026-11-25: the
 * plan's palette). Grey draft, amber waiting for approval, blue scheduled and
 * publishing, green published, red failed. Labels stay with each screen.
 */
export const POST_STATUS_TONES: Record<PostStatusValue, PostStatusTone> = {
    draft: { badge: 'secondary', dot: 'bg-muted-foreground/50' },
    pending_approval: { badge: 'warning', dot: 'bg-warning' },
    scheduled: { badge: 'info', dot: 'bg-info' },
    publishing: { badge: 'info', dot: 'bg-info' },
    published: { badge: 'success', dot: 'bg-success' },
    failed: { badge: 'destructive-subtle', dot: 'bg-destructive' },
};

/** The tone of a status the table may not know (a network-side one): draft's. */
export const postStatusTone = (status: string): PostStatusTone =>
    POST_STATUS_TONES[status as PostStatusValue] ?? POST_STATUS_TONES.draft;
