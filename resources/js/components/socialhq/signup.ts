import { usePage } from '@inertiajs/vue3';
import { computed, type ComputedRef } from 'vue';

import type { SocialHqSharedProps } from '@/components/socialhq/types';

const socialHqProps = (): ComputedRef<SocialHqSharedProps> => {
    const page = usePage();

    return computed(() => page.props.socialhq);
};

/**
 * Whether people may sign up without an invite: with a company email
 * (SignupRules) or by asking to start a company (shared by
 * SocialHqServiceProvider).
 */
export const useSignupOpen = (): ComputedRef<boolean> => {
    const props = socialHqProps();

    return computed(() => props.value.signup.open);
};

/**
 * Sign-ups waiting for this admin's decision; 0 for people who can't decide.
 */
export const usePendingAccessRequests = (): ComputedRef<number> => {
    const props = socialHqProps();

    return computed(() => props.value.signup.pendingRequests);
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
        asks: props.value.signup.companyRequests,
        joinsByEmail: props.value.signup.joinsByEmail,
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
        admin: props.value.platform.admin,
        pendingCompanies: props.value.platform.pendingCompanies,
    }));
};
