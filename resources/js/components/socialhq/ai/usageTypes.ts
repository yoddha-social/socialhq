export type UsageStatus =
    | 'ok'
    | 'failed'
    | 'cut_off'
    | 'rate_limited'
    | 'blocked_budget'
    | 'blocked_no_route';

/** The "Failed or blocked" filter: every status except answered. */
export const PROBLEMS = 'problems';

export type UsageStatusFilter = UsageStatus | typeof PROBLEMS;

export type UsagePeriod = '1d' | '7d' | '30d' | '90d';

export interface UsageLogRow {
    id: number;
    created_at: string;
    feature: string;
    feature_label: string;
    task: string | null;
    route: 'own' | 'platform' | 'none' | null;
    status: UsageStatus;
    company: string | null;
    workspace: string | null;
    user: string | null;
    provider: string | null;
    model: string | null;
    model_group_requested: string | null;
    model_group: string | null;
    fallbacks: number;
    requests: number;
    cost: number | null;
    prompt_tokens: number | null;
    completion_tokens: number | null;
    duration_ms: number | null;
    error_code: number | null;
    error_type: string | null;
    call_id: string | null;
}

export interface UsageOption<T = string> {
    value: T;
    label: string;
}

export interface UsageFilters {
    period: UsagePeriod;
    status: UsageStatusFilter | null;
    feature: string | null;
    provider: string | null;
    workspace: string | null;
    company: number | null;
    user: string | null;
}

export interface UsageTotals {
    calls: number;
    answered: number;
    free: number;
    paid: number;
    problems: number;
    cost: number;
    tokens: number;
}

/** One bar of the Requests chart: an hour (last 24 hours) or a day. */
export interface UsageSeriesPoint {
    key: string;
    calls: number;
    problems: number;
}

export interface UsageBreakdownRow {
    value: string;
    label: string;
    calls: number;
}

/** One day of the log, in the viewer's time zone. */
export interface UsageDaySummary {
    date: string;
    calls: number;
    answered: number;
    free: number;
    paid: number;
    problems: number;
    tokens: number;
    cost: number;
    features: { label: string; calls: number }[];
}

export interface UsageDayRows {
    rows: UsageLogRow[];
    total: number;
    limit: number;
}

/** Query of the day rows endpoint: the page's filters plus the day. */
export type UsageDayQuery = Record<string, string>;

export interface UsagePageProps {
    platform: boolean;
    filters: UsageFilters;
    hasData: boolean;
    today: string;
    totals: UsageTotals;
    inScope: number;
    series: UsageSeriesPoint[];
    breakdown: {
        features: UsageBreakdownRow[];
        providers: UsageBreakdownRow[];
    };
    budget: { monthlyUsd: number | null; spentLastDays: number; days: number };
    options: {
        periods: UsagePeriod[];
        statuses: UsageStatus[];
        features: UsageOption[];
        providers: string[];
        workspaces: UsageOption[];
        companies: UsageOption<number>[];
        people: UsageOption[];
    };
    days: { data: UsageDaySummary[] };
}
