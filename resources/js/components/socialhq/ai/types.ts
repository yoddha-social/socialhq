export type AiTaskName = 'write' | 'structured' | 'vision' | 'image' | 'embed';

export type ProviderConfirmation =
    | 'training_off'
    | 'billing_on'
    | 'may_train'
    | 'openrouter_privacy';

export type ProviderTier = 'free' | 'paid';

/** One plan of a provider: its checkbox and the tasks it serves, with the default model of each. */
export interface CatalogueTier {
    tier: ProviderTier;
    confirmation: ProviderConfirmation | null;
    tasks: { task: AiTaskName; model: string }[];
}

/** A provider a company may bring keys for (the catalogue). */
export interface CatalogueProvider {
    id: string;
    logo: string;
    consoleUrl: string;
    steps: number;
    needsAccountId: boolean;
    tiers: CatalogueTier[];
}

/** A key the company added. The key itself never reaches the browser. */
export interface CompanyProvider {
    id: number;
    provider: string;
    /** The owner's name for it; null on keys added before names. */
    label: string | null;
    tier: ProviderTier;
    /** The tasks ticked; null: every task the plan offers. */
    tasks: AiTaskName[] | null;
    /** What it serves now (the plan's tasks less the ones switched off). */
    servedTasks: AiTaskName[];
    /** Models chosen instead of the plan's, by task. */
    models: Partial<Record<AiTaskName, string>>;
    last4: string;
    status: 'ok' | 'failed' | 'untested';
    lastError: string | null;
    lastTestedAt: string | null;
    options: { accountId: string | null };
    retired: boolean;
}

export interface TaskRoute {
    task: AiTaskName;
    provider: string | null;
    label: string | null;
    /** How many of the company's keys serve the task. */
    count: number;
}

export interface CompanySpend {
    spend: number;
    maxBudget: number | null;
    resetsAt: string | null;
}
