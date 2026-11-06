<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';

defineProps<{
    email?: string | null;
}>();
</script>

<template>
    <AuthLayout
        :title="$t('socialhq_companies.requested.title')"
        :description="
            email
                ? $t('socialhq_companies.requested.description', { email })
                : $t('socialhq_companies.requested.description_no_email')
        "
    >
        <Head :title="$t('socialhq_companies.requested.page_title')" />

        <div
            class="flex flex-col gap-6"
            data-testid="socialhq-company-requested"
        >
            <ol
                class="flex list-decimal flex-col gap-2 ps-5 text-sm text-muted-foreground"
            >
                <li>{{ $t('socialhq_companies.requested.step_confirm') }}</li>
                <li>{{ $t('socialhq_companies.requested.step_review') }}</li>
                <li>{{ $t('socialhq_companies.requested.step_answer') }}</li>
            </ol>

            <Button as-child class="w-full">
                <Link
                    :href="login()"
                    data-testid="socialhq-company-requested-login"
                >
                    {{ $t('socialhq_companies.requested.log_in') }}
                </Link>
            </Button>
        </div>
    </AuthLayout>
</template>
