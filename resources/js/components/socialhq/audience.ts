import type { TimezoneOption } from '@/types/posting-schedule';

/** One place the brand's audience lives (Audience::locations). */
export interface AudienceLocation {
    country: string;
    city: string | null;
    timezone: string;
    share: number;
}

/** The `audience` prop of Brand knowledge (AudiencePage::prop). */
export interface AudienceProp {
    locations: AudienceLocation[];
    mainTimezone: string;
    countries: { code: string; name: string; timezone: string }[];
    timezones: TimezoneOption[];
    maxLocations: number;
}
