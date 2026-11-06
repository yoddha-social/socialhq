import { usePage } from '@inertiajs/vue3';
import { computed, type ComputedRef } from 'vue';

interface SocialHqProps {
    signup?: {
        open?: boolean;
        joinsByEmail?: boolean;
        companyRequests?: boolean;
        pendingRequests?: number;
    };
    platform?: { admin?: boolean; pendingCompanies?: number };
}

const socialHqProps = (): ComputedRef<SocialHqProps | undefined> => {
    const page = usePage();

    return computed(() => page.props.socialhq as SocialHqProps | undefined);
};

/**
 * Whether people may sign up without an invite: with a company email
 * (SignupRules) or by asking to start a company (shared by
 * SocialHqServiceProvider).
 */
export const useSignupOpen = (): ComputedRef<boolean> => {
    const props = socialHqProps();

    return computed(() => Boolean(props.value?.signup?.open));
};

/**
 * Sign-ups waiting for this admin's decision; 0 for people who can't decide.
 */
export const usePendingAccessRequests = (): ComputedRef<number> => {
    const props = socialHqProps();

    return computed(() => Number(props.value?.signup?.pendingRequests ?? 0));
};

/**
 * Whether the register page asks for a company name, and whether a work
 * email may leave it empty to join its team. The server decides on submit.
 */
export const useCompanySignup = (): ComputedRef<{
    asks: boolean;
    joinsByEmail: boolean;
}> => {
    const props = socialHqProps();

    return computed(() => ({
        asks: Boolean(props.value?.signup?.companyRequests),
        joinsByEmail: Boolean(props.value?.signup?.joinsByEmail),
    }));
};

/**
 * Whether the user is a platform admin, and the company requests waiting.
 */
export const usePlatformAdmin = (): ComputedRef<{
    admin: boolean;
    pendingCompanies: number;
}> => {
    const props = socialHqProps();

    return computed(() => ({
        admin: Boolean(props.value?.platform?.admin),
        pendingCompanies: Number(props.value?.platform?.pendingCompanies ?? 0),
    }));
};
