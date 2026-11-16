import type { BriefStage } from '@/components/socialhq/plan/item/types';
import type { CopyStatus } from '@/components/socialhq/plan/types';

/**
 * The dot that tells how far an item's copy is: nothing yet, a draft, in
 * review, changes asked for, approved. The label key is resolved with `$t`.
 */
export const COPY_STATUS_DOT: Record<CopyStatus, string> = {
    none: 'border border-muted-foreground/50 bg-transparent',
    draft: 'bg-muted-foreground/60',
    in_review: 'bg-info',
    changes_requested: 'bg-warning',
    approved: 'bg-success',
};

export const copyStatusLabelKey = (status: CopyStatus): string =>
    `socialhq_plan.copy_status.${status}`;

export type ReviewBadgeVariant =
    | 'secondary'
    | 'info'
    | 'warning'
    | 'success'
    | 'outline';

/**
 * The badge of a copy status or a brief stage: work under way, in review,
 * changes asked for, approved, or nothing yet.
 */
export const reviewBadgeVariant = (
    status: CopyStatus | BriefStage,
): ReviewBadgeVariant => {
    switch (status) {
        case 'draft':
        case 'in_progress':
            return 'secondary';
        case 'in_review':
            return 'info';
        case 'changes_requested':
            return 'warning';
        case 'approved':
            return 'success';
        default:
            return 'outline';
    }
};
