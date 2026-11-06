export type PlanStatus = 'draft' | 'in_review' | 'approved';
export type GenerationStatus = 'idle' | 'running' | 'failed';
export type ItemStatus = 'suggested' | 'planned' | 'dropped';
export type CopyStatus =
    | 'none'
    | 'draft'
    | 'in_review'
    | 'changes_requested'
    | 'approved';

export interface PlanRejection {
    title: string;
    date: string;
    channel: string;
    reason: string;
}

export interface PlanData {
    id: number;
    status: PlanStatus;
    goals: string | null;
    generation: {
        status: GenerationStatus;
        error: string | null;
        summary: string | null;
        rejections: PlanRejection[];
        generated_at: string | null;
    };
    submitted_at: string | null;
    approved_at: string | null;
    approver: string | null;
}

export interface PlanItem {
    id: number;
    scheduled_on: string;
    social_account_id: string | null;
    platform: string | null;
    content_type: string | null;
    content_type_label: string | null;
    creative: string;
    pillar: string | null;
    title: string;
    objective: string | null;
    angle: string | null;
    event_id: number | null;
    owner_id: string | null;
    /** "HH:MM" on the item's day in suggested_timezone, or null. */
    suggested_time: string | null;
    suggested_timezone: string | null;
    /** That time as a UTC instant. */
    suggested_at: string | null;
    status: ItemStatus;
    source: 'ai' | 'manual';
    copy_status: CopyStatus;
    position: number;
}

export interface PlanEvent {
    id: number;
    key: string;
    name: string;
    category: string;
    starts_on: string;
    ends_on: string;
    confirmed: boolean;
}

export interface PlanChannel {
    id: string;
    platform: string;
    name: string;
    avatar_url: string | null;
    content_types: { value: string; label: string }[];
}

export interface PlanOwner {
    id: string;
    name: string;
    photo_url: string | null;
}

export interface PlanAbilities {
    canWork: boolean;
    canApprove: boolean;
    canManageBrand: boolean;
    canGenerate: boolean;
}

/** Where the brand's audience is, as the plan page needs it. */
export interface PlanAudience {
    mainTimezone: string;
    countries: { code: string; name: string }[];
}

/** What "Generate with AI" opens with (PlannerOptions). */
export interface PlanGenerateOptions {
    from: string;
    to: string;
    old_calendars: boolean;
    past_posts: boolean;
    audience_events: boolean;
}

/** Everything a card or row needs to draw an item, looked up once per page. */
export interface PlanLookups {
    channels: Record<string, PlanChannel>;
    owners: Record<string, PlanOwner>;
    events: Record<number, PlanEvent>;
    pillars: string[];
}

/** The metric "What worked lately" ranks posts by. */
export type WhatWorkedMetric = 'engagement' | 'reach' | 'views';

/** One past post in "What worked lately" (PastPerformance::lately). */
export interface WhatWorkedPost {
    id: string | number;
    date: string;
    platform: string;
    content_type: string | null;
    channel: string | null;
    avatar_url: string | null;
    excerpt: string | null;
    value: number | null;
    url: string | null;
}

/** How the brand's posts of the last 90 days did (the `whatWorked` prop). */
export interface WhatWorked {
    metric: WhatWorkedMetric | null;
    best: WhatWorkedPost[];
    weakest: WhatWorkedPost[];
    best_format: {
        content_type: string;
        average: number;
        posts: number;
    } | null;
}
