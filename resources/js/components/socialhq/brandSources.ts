import type {
    KeywordTag,
    KeywordVerdict,
} from '@/components/socialhq/keywords/keywords';

/** Brand look on Brand knowledge (LookPage::prop). */
export interface BrandColour {
    name: string;
    hex: string;
}

/**
 * A typed colour as the server stores it ("#rrggbb", lower case): the "#"
 * may be left out and the 3-digit form is expanded (BrandLook::normalizeHex).
 * Null when it isn't a hex colour.
 */
export const normalizeHex = (value: string): string | null => {
    const match = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(value.trim());

    if (match === null) {
        return null;
    }

    const digits = match[1].toLowerCase();

    return `#${digits.length === 3 ? [...digits].map((digit) => digit + digit).join('') : digits}`;
};

export interface BrandLookProp {
    logo: {
        url: string;
        mime: string;
        isVector: boolean;
        width: number | null;
        height: number | null;
    } | null;
    colours: BrandColour[];
    heading_font: string;
    body_font: string;
    style_notes: string;
    maxColours: number;
    maxLogoKilobytes: number;
}

/** "Read our website" (WebsitePanel::prop). */
export type CrawlStatus =
    | 'queued'
    | 'reading'
    | 'drafting'
    | 'ready'
    | 'unchanged'
    | 'failed';

export type SuggestionStatus = 'open' | 'accepted' | 'skipped';

export type WebsiteSuggestion =
    | {
          key: string;
          type: 'profile';
          field: 'about' | 'audience' | 'voice';
          value: string;
          status: SuggestionStatus;
      }
    | {
          key: string;
          type: 'font';
          field: 'heading_font' | 'body_font';
          value: string;
          status: SuggestionStatus;
      }
    | {
          key: string;
          type: 'style';
          field: 'style_notes';
          value: string;
          status: SuggestionStatus;
      }
    | {
          key: string;
          type: 'entry';
          kind: string;
          title: string;
          body: string;
          status: SuggestionStatus;
      }
    | {
          key: string;
          type: 'colour';
          name: string;
          hex: string;
          status: SuggestionStatus;
      }
    | {
          key: string;
          type: 'document';
          pages: number;
          status: SuggestionStatus;
      }
    | {
          key: string;
          type: 'keyword';
          text: string;
          main: boolean;
          /** Asked for as main while main was full: it goes to the library. */
          main_full: boolean;
          group: WebsiteKeywordGroup;
          tags: KeywordTag[];
          pages: number;
          page_titles: string[];
          sample: string;
          verdict: KeywordVerdict;
          reason: string;
          status: SuggestionStatus;
      };

/** How keyword suggestions are grouped for review (WebsiteKeywordReader::GROUPS). */
export const WEBSITE_KEYWORD_GROUPS = [
    'features',
    'problems',
    'audience',
    'place',
    'seasonal',
] as const;

export type WebsiteKeywordGroup = (typeof WEBSITE_KEYWORD_GROUPS)[number];

export interface WebsiteProp {
    aiAvailable: boolean;
    maxPages: number;
    crawl: {
        id: number;
        url: string;
        status: CrawlStatus;
        failure: string | null;
        pages_read: number;
        pages_unchanged: number;
        pages_skipped: number;
        finished_at: string | null;
        suggestions: WebsiteSuggestion[];
    } | null;
}

export const isRunningCrawl = (status: CrawlStatus | undefined): boolean =>
    status === 'queued' || status === 'reading' || status === 'drafting';

/** What the Preview dialog shows (DocumentPreview on the server). */
export interface SourcePreview {
    kind: 'pdf' | 'text' | 'markdown' | 'extracted' | 'pages' | 'none';
    text: string | null;
    /** Markdown rendered by the server without the file's HTML, pictures or scripts. */
    html: string | null;
    /** A "Website text" suggestion's pages, as Accept keeps them. */
    pages: { url: string; title: string; text: string }[];
    count: number;
    /** Only the first `kilobytes` of the text are shown. */
    truncated: boolean;
    kilobytes: number;
    /** A PDF, served inline from the app's own origin. */
    file_url: string | null;
}
