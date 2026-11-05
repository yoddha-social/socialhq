import { usePage } from '@inertiajs/vue3';
import { computed, type ComputedRef } from 'vue';

interface SocialHqProps {
    approvals?: { membersAlwaysReviewed?: boolean };
}

/**
 * Social HQ approval rules shared by SocialHqServiceProvider: when every
 * member who is not an admin needs approval, the per-member choice is gone.
 */
export const useMembersAlwaysReviewed = (): ComputedRef<boolean> => {
    const page = usePage();

    return computed(() =>
        Boolean(
            (page.props.socialhq as SocialHqProps | undefined)?.approvals
                ?.membersAlwaysReviewed,
        ),
    );
};
