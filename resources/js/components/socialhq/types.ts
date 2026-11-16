import type { CalendarEventMarker } from '@/components/socialhq/plan/eventStyles';

/**
 * The `socialhq` prop every page gets: what `SocialHqServiceProvider` shares
 * with `Inertia::share('socialhq', ...)`. Keep the two in step.
 */
export interface SocialHqSharedProps {
    approvals: { membersAlwaysReviewed: boolean };
    aiGraphics: {
        available: boolean;
        maxReferences: number;
        maxReferenceKilobytes: number;
    };
    companyAi: { canManage: boolean };
    signup: {
        open: boolean;
        joinsByEmail: boolean;
        companyRequests: boolean;
        pendingRequests: number;
    };
    platform: { admin: boolean; pendingCompanies: number };
}

/** Event markers by day (`YYYY-MM-DD`), on the publish calendar page only. */
export type CalendarEventMarkersByDay = Record<string, CalendarEventMarker[]>;

// Added to the app's shared props, which Inertia's PageProps extends
// (types/globals.d.ts), so usePage() and usePage<SharedData>() both see them.
declare module '@/types' {
    interface SharedData {
        socialhq: SocialHqSharedProps;
        /** BuildCalendarPageProps (PATCHES.md row 60). */
        shqEvents?: CalendarEventMarkersByDay;
    }
}
