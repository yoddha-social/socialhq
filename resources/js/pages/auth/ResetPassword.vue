<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

import type { PasswordRequirement } from '@/components/auth/PasswordRequirements.vue';
import PasswordRequirements from '@/components/auth/PasswordRequirements.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { clearFieldError } from '@/lib/formErrors';
import { request, email as sendLink, store } from '@/routes/password';

const props = defineProps<{
    token: string;
    email: string;
    tokenValid: boolean;
    passwordRequirements: PasswordRequirement[];
}>();

const inputEmail = ref(props.email);
const password = ref('');
</script>

<template>
    <AuthLayout
        :title="$t('auth.reset_password.title')"
        :description="
            tokenValid ? $t('auth.reset_password.description') : undefined
        "
    >
        <Head :title="$t('auth.reset_password.page_title')" />

        <!-- Social HQ: a used or expired link offers a new one instead of the form (PATCHES.md). -->
        <div
            v-if="!tokenValid"
            class="flex flex-col gap-4"
            data-testid="reset-password-link-invalid"
        >
            <p class="text-center text-sm text-muted-foreground">
                {{ $t('passwords.token') }}
            </p>

            <Form v-if="email" v-bind="sendLink.form()" v-slot="{ processing }">
                <input type="hidden" name="email" :value="email" />
                <Button
                    type="submit"
                    class="w-full"
                    :loading="processing"
                    data-testid="reset-password-send-new-link"
                >
                    {{ $t('auth.reset_password.send_new_link') }}
                </Button>
            </Form>

            <Button v-else as-child class="w-full">
                <Link :href="request()">
                    {{ $t('auth.reset_password.send_new_link') }}
                </Link>
            </Button>
        </div>

        <Form
            v-else
            v-bind="store.form()"
            :transform="(data) => ({ ...data, token, email })"
            :reset-on-success="['password', 'password_confirmation']"
            v-slot="{ errors, processing, clearErrors }"
            class="flex flex-col gap-4"
        >
            <div
                class="flex flex-col gap-4"
                @input="clearFieldError($event, clearErrors)"
            >
                <div class="grid gap-2">
                    <Label for="email">{{
                        $t('auth.reset_password.email')
                    }}</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        autocomplete="email"
                        v-model="inputEmail"
                        class="bg-muted text-muted-foreground"
                        readonly
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">{{
                        $t('auth.reset_password.password')
                    }}</Label>
                    <Input
                        id="password"
                        v-model="password"
                        type="password"
                        name="password"
                        autocomplete="new-password"
                        autofocus
                        :placeholder="$t('auth.reset_password.password')"
                        data-testid="reset-password-password"
                    />
                    <PasswordRequirements
                        :password="password"
                        :requirements="passwordRequirements"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">{{
                        $t('auth.reset_password.confirm_password')
                    }}</Label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        name="password_confirmation"
                        autocomplete="new-password"
                        :placeholder="
                            $t('auth.reset_password.confirm_placeholder')
                        "
                    />
                    <InputError :message="errors.password_confirmation" />
                </div>

                <Button
                    type="submit"
                    class="w-full"
                    :disabled="processing"
                    data-test="reset-password-button"
                >
                    <Spinner v-if="processing" />
                    {{ $t('auth.reset_password.submit') }}
                </Button>
            </div>
        </Form>
    </AuthLayout>
</template>
