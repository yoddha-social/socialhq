<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';

import TextLink from '@/components/TextLink.vue';
import Toast from '@/components/Toast.vue';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

defineProps<{
    email: string;
    verified: boolean;
    status?: string | null;
}>();
</script>

<template>
    <AuthLayout
        :title="$t('socialhq.signup.pending.title')"
        :description="$t('socialhq.signup.pending.description', { email })"
        :status="
            status === 'verification-link-sent'
                ? $t('socialhq.signup.pending.link_sent')
                : undefined
        "
    >
        <Head :title="$t('socialhq.signup.pending.page_title')" />
        <!-- Flash messages land here too (an invite declined while waiting). -->
        <Toast />

        <div class="flex flex-col gap-6" data-testid="socialhq-access-pending">
            <p
                class="text-center text-sm text-muted-foreground"
                data-testid="socialhq-access-pending-state"
            >
                {{
                    verified
                        ? $t('socialhq.signup.pending.notified')
                        : $t('socialhq.signup.pending.verify_first')
                }}
            </p>

            <Form v-if="!verified" v-bind="send.form()" v-slot="{ processing }">
                <Button
                    type="submit"
                    class="w-full"
                    :loading="processing"
                    data-testid="socialhq-access-pending-resend"
                >
                    {{ $t('socialhq.signup.pending.resend') }}
                </Button>
            </Form>

            <p class="text-center text-sm text-muted-foreground">
                <TextLink :href="logout()" as="button">
                    {{ $t('socialhq.signup.pending.log_out') }}
                </TextLink>
            </p>
        </div>
    </AuthLayout>
</template>
