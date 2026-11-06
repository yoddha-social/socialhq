/** Brand look on Brand knowledge (LookPage::prop). */
export interface BrandColour {
    name: string;
    hex: string;
}

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
      };

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
