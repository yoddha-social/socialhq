import type { WebsiteProp } from '@/components/socialhq/brandSources';
import type { KnowledgeEntryRow } from '@/components/socialhq/KnowledgeEntryDialog.vue';

/** The tabs of Brand knowledge, in order. */
export const BRAND_TABS = [
    'overview',
    'knowledge',
    'identity',
    'strategy',
    'sources',
] as const;

export type BrandTab = (typeof BRAND_TABS)[number];

/** The profile fields, in the order the form shows them. */
export const PROFILE_FIELDS = [
    'about',
    'audience',
    'voice',
    'words_to_use',
    'words_to_avoid',
] as const;

export type ProfileField = (typeof PROFILE_FIELDS)[number];

/** The fields that say who the brand is: the profile counts as complete with these. */
export const CORE_PROFILE_FIELDS: readonly ProfileField[] = [
    'about',
    'audience',
    'voice',
];

/** Space the AI reads (BrandKnowledgeController's `usage`). */
export interface BrandUsage {
    used: number;
    budget: number;
    profileUsed: number;
    profileTrimmed: boolean;
}

/** An entry marked for the AI that it can't read: no room left, or too long. */
export const needsAttention = (entry: KnowledgeEntryRow): boolean =>
    entry.fit === 'no_room' || entry.fit === 'too_long';

/** Website suggestions still waiting for Accept, Edit or Skip. */
export const openSuggestionCount = (website: WebsiteProp): number =>
    website.crawl?.status === 'ready' || website.crawl?.status === 'unchanged'
        ? website.crawl.suggestions.filter(
              (suggestion) => suggestion.status === 'open',
          ).length
        : 0;

/** The site's address without its scheme, for display. */
export const websiteHost = (url: string): string =>
    url.replace(/^https?:\/\//i, '').replace(/\/$/, '');
