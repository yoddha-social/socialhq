import type { PostStatus } from '@/components/socialhq/plan/postStatus';
import type { CopyStatus } from '@/components/socialhq/plan/types';

export interface PlanPerson {
    id: string;
    name: string;
    photo_url: string | null;
}

// One definition each, in the plan's types; re-exported for the item's files.
export type {
    CopyStatus,
    PlanAbilities,
} from '@/components/socialhq/plan/types';

export type CreativeKind = 'none' | 'image' | 'carousel' | 'video' | 'document';

export interface PlanItemDetail {
    id: number;
    title: string;
    scheduled_on: string;
    /** The posting time as a UTC instant, and the zone it was written in (the main audience zone). */
    suggested_at: string | null;
    suggested_timezone: string | null;
    month: string;
    platform: string | null;
    platform_label: string | null;
    content_type: string | null;
    content_type_label: string | null;
    creative: CreativeKind;
    pillar: string | null;
    objective: string | null;
    angle: string | null;
    /** Names and numbers the AI planner wrote that its sources don't contain. */
    unsupported_details: string[];
    status: string;
    copy_status: CopyStatus;
    post_created_at: string | null;
    max_length: number | null;
    owner: PlanPerson | null;
    channel: {
        id: string;
        name: string;
        platform: string;
        avatar_url: string | null;
        /** The zone the composer shows the post's time in. */
        timezone: string;
    } | null;
    event: { name: string; starts_on: string; verified: boolean } | null;
}

export interface CopyVersion {
    id: number;
    number: number;
    caption: string;
    hashtags: string | null;
    script: string | null;
    notes: string | null;
    source: 'ai' | 'manual';
    /** Names, numbers and quotes in AI copy that its sources don't contain. */
    unsupported_details: string[];
    /** Gaps in square brackets ("[city]") still to fill. */
    placeholders: string[];
    author: PlanPerson | null;
    created_at: string | null;
}

/** What stands between the item and its post. */
export type PostBlocker = 'copy' | 'placeholders' | 'creative';

/** An approver let the post go out without its creative. */
export interface CreativeWaiver {
    at: string;
    by: PlanPerson | null;
}

export type ReviewEntryKind =
    | 'comment'
    | 'submitted'
    | 'changes_requested'
    | 'approved'
    | 'reopened';

export interface ReviewEntry {
    id: number;
    kind: ReviewEntryKind;
    body: string | null;
    about: 'copy' | 'creative' | null;
    version_number: number | null;
    round: number | null;
    user: PlanPerson | null;
    created_at: string | null;
}

export type BriefStage =
    | 'todo'
    | 'in_progress'
    | 'in_review'
    | 'changes_requested'
    | 'approved';

export interface BriefFileRow {
    id: number;
    name: string;
    mime_type: string;
    type: 'image' | 'video' | 'document' | null;
    size: number;
    note: string | null;
    url: string;
    uploader: PlanPerson | null;
    created_at: string | null;
}

export interface BriefRound {
    round: number;
    files: BriefFileRow[];
}

export interface BriefDetail {
    id: number;
    kind: 'graphic' | 'video';
    stage: BriefStage;
    assignee: PlanPerson | null;
    due_on: string | null;
    format_notes: string | null;
    on_screen_text: string | null;
    direction: string | null;
    references: string | null;
    approved_at: string | null;
    rounds?: BriefRound[];
    current_round?: number;
    accepts_uploads?: boolean;
    /** The round the next upload goes into: a new one once the current round is decided. */
    next_round?: number;
    /** Whether the current round may be sent for review. */
    can_submit?: boolean;
}

/** A post made from the item, and where it stands now. */
export interface ItemPost {
    id: string;
    status: PostStatus;
    status_label: string;
    scheduled_at: string | null;
    published_at: string | null;
    channel: string | null;
    platform: string | null;
    /** The post in the composer (TryPost's edit page). */
    url: string;
    /** The post on the network, once published. */
    network_url: string | null;
    /** A dated draft or scheduled post that isn't on the item's day. */
    off_day: boolean;
    /** Approved, and moving it to the item's day sends it back for approval. */
    back_to_approval: boolean;
    /** The item has another channel or format than the post was made for. */
    other_channel: boolean;
    other_format: boolean;
}
