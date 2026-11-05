import { usePage } from '@inertiajs/vue3';
import { computed, type ComputedRef } from 'vue';

interface SocialHqProps {
    signup?: { open?: boolean; pendingRequests?: number };
}

const signupProps = (): ComputedRef<SocialHqProps['signup']> => {
    const page = usePage();

    return computed(
        () => (page.props.socialhq as SocialHqProps | undefined)?.signup,
    );
};

/**
 * Whether people with a company email may sign up without an invite
 * (SignupRules, shared by SocialHqServiceProvider).
 */
export const useSignupOpen = (): ComputedRef<boolean> => {
    const signup = signupProps();

    return computed(() => Boolean(signup.value?.open));
};

/**
 * Sign-ups waiting for this admin's decision; 0 for people who can't decide.
 */
export const usePendingAccessRequests = (): ComputedRef<number> => {
    const signup = signupProps();

    return computed(() => Number(signup.value?.pendingRequests ?? 0));
};
