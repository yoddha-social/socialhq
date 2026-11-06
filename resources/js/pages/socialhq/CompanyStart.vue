<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';

import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import Toast from '@/components/Toast.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { logout } from '@/routes';
import { store } from '@/routes/socialhq/company';
import { index as platformCompanies } from '@/routes/socialhq/platform/companies';

defineProps<{
    canAskForCompany: boolean;
    isPlatformAdmin: boolean;
}>();

const form = useForm({ name: '' });

const submit = (): void => {
    form.post(store.url());
};
</script>

<template>
    <AuthLayout
        :title="$t('socialhq_companies.start.title')"
        :description="$t('socialhq_companies.start.description')"
    >
        <Head :title="$t('socialhq_companies.start.page_title')" />
        <Toast />

        <div class="flex flex-col gap-6" data-testid="socialhq-company-start">
            <form
                v-if="canAskForCompany"
                class="flex flex-col gap-4 rounded-lg border border-border p-4"
                data-testid="socialhq-company-start-form"
                @submit.prevent="submit"
            >
                <div class="flex flex-col gap-1">
                    <h2 class="text-sm font-medium text-foreground">
                        {{ $t('socialhq_companies.start.ask_title') }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{ $t('socialhq_companies.start.ask_description') }}
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="socialhq-start-company-name">{{
                        $t('socialhq_companies.start.name_label')
                    }}</Label>
                    <Input
                        id="socialhq-start-company-name"
                        v-model="form.name"
                        name="name"
                        autocomplete="organization"
                        :placeholder="
                            $t('socialhq_companies.signup.name_placeholder')
                        "
                        data-testid="socialhq-company-start-name"
                    />
                    <InputError :message="form.errors.name" />
                </div>
                <Button
                    type="submit"
                    class="w-full"
                    :loading="form.processing"
                    data-testid="socialhq-company-start-submit"
                >
                    {{ $t('socialhq_companies.start.submit') }}
                </Button>
            </form>

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
                    {{ $t('socialhq_companies.start.log_out') }}
                </TextLink>
            </p>
        </div>
    </AuthLayout>
</template>
