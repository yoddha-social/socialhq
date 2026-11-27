import type { PostStatus } from '@/components/socialhq/plan/postStatus';

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
    /** Missing on drafts made before rejections named the network. */
    platform?: string | null;
    reason: string;
}

/** One week of the latest AI draft (one AI call each). */
export interface PlanGenerationWeek {
    from: string;
    to: string;
    status: 'pending' | 'done' | 'failed';
    /** Why the week wasn't drafted, when it failed. */
    error: string | null;
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
        weeks: PlanGenerationWeek[];
        /** Suggestions the draft added; null for drafts made before weekly calls. */
        added: number | null;
        generated_at: string | null;
    };
    submitted_at: string | null;
    approved_at: string | null;
    approver: string | null;
}

/** A post of an item that will still go out (scheduled, or waiting for approval). */
export interface UpcomingPost {
    id: string;
    status: PostStatus;
    scheduled_at: string | null;
    channel: string | null;
    platform: string | null;
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
    /** Names and numbers the AI planner wrote that its sources don't contain. */
    unsupported_details: string[];
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
    /** Where the item's posts stand, or null before any was saved. */
    post_status: PostStatus | null;
    /** Posts that will still go out: the drop and delete dialog names them. */
    upcoming_posts: UpcomingPost[];
    /** How many of the item's posts aren't on its day. */
    posts_off_day: number;
    /** The times of its approved posts that moving them to its day sends back for approval. */
    posts_back_to_approval: string[];
    position: number;
}

/**
 * One event of the month: the same holiday on the same day in several
 * countries is one event (EventsForMonth::group).
 */
export interface PlanEvent {
    /** The row a new item links to: the oldest confirmed one, else the oldest. */
    id: number;
    /** Every country's row of the event: an item may point at any of them. */
    ids: number[];
    key: string;
    name: string;
    category: string;
    starts_on: string;
    ends_on: string;
    /** Confirmed for at least one of its countries: the planner uses it. */
    confirmed: boolean;
    /** The countries whose holiday it is, by code; none for an international day. */
    countries: string[];
    confirmed_countries: string[];
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

/** PlanAccess::abilities(), as every plan page gets them. */
export interface PlanAbilities {
    canWork: boolean;
    canApprove: boolean;
    canManageBrand: boolean;
}

/** The month page adds whether "Generate with AI" shows (PlanPage). */
export interface PlanMonthAbilities extends PlanAbilities {
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
    /** By every row id of the event, so an item linked to any country's row finds it. */
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
