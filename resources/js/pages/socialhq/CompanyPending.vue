<script setup lang="ts">
import { Form, Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import Toast from '@/components/Toast.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import date from '@/date';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { logout } from '@/routes';
import { askAgain, update } from '@/routes/socialhq/company';
import { index as platformCompanies } from '@/routes/socialhq/platform/companies';
import { send } from '@/routes/verification';

interface WaitingCompany {
    name: string;
    status: 'pending' | 'declined';
    note: string | null;
    can_ask_again_at: string | null;
}

const props = defineProps<{
    company: WaitingCompany;
    email: string;
    verified: boolean;
    isOwner: boolean;
    isPlatformAdmin: boolean;
    status?: string | null;
}>();

const declined = computed(() => props.company.status === 'declined');

const nameForm = useForm({ name: props.company.name });

const saveName = (): void => {
    nameForm.put(update.url(), {
        preserveScroll: true,
        onSuccess: rememberName,
    });
};

const rememberName = (): void => {
    nameForm.defaults({ name: nameForm.name });
};
</script>

<template>
    <AuthLayout
        :title="
            declined
                ? $t('socialhq_companies.waiting.declined_title')
                : $t('socialhq_companies.waiting.pending_title')
        "
        :description="
            declined
                ? $t('socialhq_companies.waiting.declined_description', {
                      company: company.name,
                  })
                : $t('socialhq_companies.waiting.pending_description', {
                      company: company.name,
                      email,
                  })
        "
        :status="
            status === 'verification-link-sent'
                ? $t('socialhq_companies.waiting.link_sent')
                : undefined
        "
    >
        <Head :title="$t('socialhq_companies.waiting.page_title')" />
        <Toast />

        <div
            class="flex flex-col gap-6"
            data-testid="socialhq-company-waiting"
            :data-status="company.status"
        >
            <template v-if="!declined">
                <p
                    class="text-center text-sm text-muted-foreground"
                    data-testid="socialhq-company-waiting-state"
                >
                    {{
                        verified
                            ? $t('socialhq_companies.waiting.notified')
                            : $t('socialhq_companies.waiting.verify_first')
                    }}
                </p>

                <Form
                    v-if="!verified"
                    v-bind="send.form()"
                    v-slot="{ processing }"
                >
                    <Button
                        type="submit"
                        class="w-full"
                        :loading="processing"
                        data-testid="socialhq-company-resend"
                    >
                        {{ $t('socialhq_companies.waiting.resend') }}
                    </Button>
                </Form>

                <form
                    v-if="isOwner"
                    class="grid gap-2"
                    data-testid="socialhq-company-rename"
                    @submit.prevent="saveName"
                >
                    <Label for="socialhq-company-name">{{
                        $t('socialhq_companies.waiting.name_label')
                    }}</Label>
                    <div class="flex gap-2">
                        <Input
                            id="socialhq-company-name"
                            v-model="nameForm.name"
                            name="name"
                            data-testid="socialhq-company-name"
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            :loading="nameForm.processing"
                            :disabled="!nameForm.isDirty"
                            data-testid="socialhq-company-save-name"
                        >
                            {{ $t('socialhq_companies.waiting.save_name') }}
                        </Button>
                    </div>
                    <InputError :message="nameForm.errors.name" />
                </form>
            </template>

            <template v-else>
                <div
                    v-if="company.note"
                    class="rounded-lg border border-border bg-muted/40 p-3 text-sm"
                    data-testid="socialhq-company-note"
                >
                    <p class="font-medium text-foreground">
                        {{ $t('socialhq_companies.waiting.note_label') }}
                    </p>
                    <p class="mt-1 whitespace-pre-line text-muted-foreground">
                        {{ company.note }}
                    </p>
                </div>

                <template v-if="isOwner">
                    <p
                        v-if="company.can_ask_again_at"
                        class="text-center text-sm text-muted-foreground"
                        data-testid="socialhq-company-ask-again-later"
                    >
                        {{
                            $t('socialhq_companies.waiting.ask_again_after', {
                                time: date.diffForHumans(
                                    company.can_ask_again_at,
                                ),
                            })
                        }}
                    </p>
                    <template v-else>
                        <p class="text-center text-sm text-muted-foreground">
                            {{
                                $t('socialhq_companies.waiting.ask_again_hint')
                            }}
                        </p>
                        <Form v-bind="askAgain.form()" v-slot="{ processing }">
                            <Button
                                type="submit"
                                class="w-full"
                                :loading="processing"
                                data-testid="socialhq-company-ask-again"
                            >
                                {{ $t('socialhq_companies.waiting.ask_again') }}
                            </Button>
                        </Form>
                    </template>
                </template>
            </template>

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
                    {{ $t('socialhq_companies.waiting.log_out') }}
                </TextLink>
            </p>
        </div>
    </AuthLayout>
</template>
