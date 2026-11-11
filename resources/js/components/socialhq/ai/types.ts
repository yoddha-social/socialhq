export type AiTaskName = 'write' | 'structured' | 'vision' | 'image' | 'embed';

export type ProviderConfirmation = 'training_off' | 'billing_on';

/** A provider a company may bring a key for (the catalogue). */
export interface CatalogueProvider {
    id: string;
    logo: string;
    consoleUrl: string;
    steps: number;
    confirmation: ProviderConfirmation | null;
    needsAccountId: boolean;
    tasks: AiTaskName[];
}

/** A key the company added. The key itself never reaches the browser. */
export interface CompanyProvider {
    id: number;
    provider: string;
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
}

export interface CompanySpend {
    spend: number;
    maxBudget: number | null;
    resetsAt: string | null;
}
