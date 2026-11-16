export type UsageStatus =
    | 'ok'
    | 'failed'
    | 'rate_limited'
    | 'blocked_budget'
    | 'blocked_no_route';

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
    status: UsageStatus | null;
    feature: string | null;
    provider: string | null;
    workspace: string | null;
    company: number | null;
}

export interface UsagePageProps {
    platform: boolean;
    filters: UsageFilters;
    hasData: boolean;
    totals: { calls: number; paid: number; problems: number; cost: number };
    options: {
        periods: UsagePeriod[];
        statuses: UsageStatus[];
        features: UsageOption[];
        providers: string[];
        workspaces: UsageOption[];
        companies: UsageOption<number>[];
    };
    logs: { data: UsageLogRow[] };
}
