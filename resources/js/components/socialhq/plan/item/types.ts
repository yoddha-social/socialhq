export interface PlanPerson {
    id: string;
    name: string;
    photo_url: string | null;
}

export type CopyStatus =
    | 'none'
    | 'draft'
    | 'in_review'
    | 'changes_requested'
    | 'approved';

export type CreativeKind = 'none' | 'image' | 'carousel' | 'video' | 'document';

export interface PlanItemDetail {
    id: number;
    title: string;
    scheduled_on: string;
    month: string;
    platform: string | null;
    platform_label: string | null;
    content_type: string | null;
    content_type_label: string | null;
    creative: CreativeKind;
    pillar: string | null;
    objective: string | null;
    angle: string | null;
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
    author: PlanPerson | null;
    created_at: string | null;
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

export interface PlanAbilities {
    canWork: boolean;
    canApprove: boolean;
    canManageBrand: boolean;
}
