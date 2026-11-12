<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

import TextLink from '@/components/TextLink.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { logout } from '@/routes';
import { index as platformCompanies } from '@/routes/socialhq/platform/companies';

defineProps<{
    company: string;
    isOwner: boolean;
    note: string | null;
    contact: string | null;
    ownerName: string | null;
    isPlatformAdmin: boolean;
}>();
</script>

<template>
    <AuthLayout
        :title="$t('socialhq_companies.paused.title')"
        :description="$t('socialhq_companies.paused.description', { company })"
    >
        <Head :title="$t('socialhq_companies.paused.page_title')" />

        <div class="flex flex-col gap-6" data-testid="socialhq-company-paused">
            <div
                v-if="isOwner && note"
                class="rounded-lg border border-border bg-muted/40 p-3 text-sm"
                data-testid="socialhq-company-paused-note"
            >
                <p class="font-medium text-foreground">
                    {{ $t('socialhq_companies.paused.note_label') }}
                </p>
                <p class="mt-1 whitespace-pre-line text-muted-foreground">
                    {{ note }}
                </p>
            </div>

            <p
                class="text-center text-sm text-muted-foreground"
                data-testid="socialhq-company-paused-contact"
            >
                <template v-if="isOwner">
                    {{
                        contact
                            ? $t('socialhq_companies.paused.owner_contact', {
                                  email: contact,
                              })
                            : $t('socialhq_companies.paused.owner_no_contact')
                    }}
                </template>
                <template v-else>
                    {{
                        $t('socialhq_companies.paused.member_contact', {
                            name: ownerName ?? '',
                            email: contact ?? '',
                        })
                    }}
                </template>
            </p>

            <p
                class="flex flex-col items-center gap-2 text-center text-sm text-muted-foreground"
            >
                <Link
                    v-if="isPlatformAdmin"
                    :href="platformCompanies()"
                    class="text-primary-text underline underline-offset-2"
                    data-testid="socialhq-company-platform-link"
                >
                    {{ $t('socialhq_companies.waiting.platform_link') }}
                </Link>
                <TextLink :href="logout()" as="button">
                    {{ $t('socialhq_companies.paused.log_out') }}
                </TextLink>
            </p>
        </div>
    </AuthLayout>
</template>
