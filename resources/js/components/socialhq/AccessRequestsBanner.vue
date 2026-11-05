<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconUserPlus } from '@tabler/icons-vue';

import { usePendingAccessRequests } from '@/components/socialhq/signup';
import { Button } from '@/components/ui/button';
import { index as accessRequestsIndex } from '@/routes/socialhq/access-requests';

// 0 for anyone who can't let people in, so they never see it.
const pendingAccessRequests = usePendingAccessRequests();
</script>

<template>
    <div
        v-if="pendingAccessRequests > 0"
        class="mb-6 flex flex-col gap-3 rounded-lg border border-border bg-muted/40 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
        data-testid="socialhq-access-requests-banner"
    >
        <p class="flex items-start gap-2 text-sm text-foreground">
            <IconUserPlus class="mt-0.5 size-4 shrink-0" />
            <span>{{
                $tChoice(
                    'socialhq.signup.requests.banner',
                    pendingAccessRequests,
                    { count: String(pendingAccessRequests) },
                )
            }}</span>
        </p>
        <Button variant="outline" size="sm" class="shrink-0" as-child>
            <Link
                :href="accessRequestsIndex()"
                data-testid="socialhq-access-requests-banner-link"
            >
                {{ $t('socialhq.signup.requests.banner_button') }}
            </Link>
        </Button>
    </div>
</template>
