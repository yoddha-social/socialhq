<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { IconExternalLink, IconShieldCheck } from '@tabler/icons-vue';
import { computed, watch } from 'vue';

import ProviderLogo from '@/components/socialhq/ai/ProviderLogo.vue';
import type {
    CatalogueProvider,
    CompanyProvider,
} from '@/components/socialhq/ai/types';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { store, update } from '@/routes/socialhq/ai-settings/providers';

/**
 * Add a provider, or replace one's key. The key field is write-only: it
 * is never filled from props, cleared after every submit and never kept
 * by Inertia's remember.
 */
const props = defineProps<{
    open: boolean;
    catalogue: CatalogueProvider[];
    /** Provider ids the company already added (not offered again). */
    added: string[];
    /** Set when replacing that provider's key. */
    replacing: CompanyProvider | null;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const form = useForm({
    provider: '',
    provider_key: '',
    account_id: '',
    confirmed: false,
});

const available = computed(() =>
    props.catalogue.filter((provider) => !props.added.includes(provider.id)),
);

const selected = computed<CatalogueProvider | null>(
    () =>
        props.catalogue.find(
            (provider) =>
                provider.id === (props.replacing?.provider ?? form.provider),
        ) ?? null,
);

const steps = computed(() =>
    Array.from({ length: selected.value?.steps ?? 0 }, (_, index) => index + 1),
);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        form.reset();
        form.clearErrors();
        form.provider = props.replacing?.provider ?? '';
        form.account_id = props.replacing?.options.accountId ?? '';
    },
);

const close = (): void => {
    emit('update:open', false);
};

const choose = (id: string): void => {
    form.provider = id;
    form.confirmed = false;
    form.clearErrors();
};

const toggleConfirmed = (value: boolean | 'indeterminate'): void => {
    form.confirmed = value === true;
};

const submit = (): void => {
    const options = {
        preserveScroll: true,
        onSuccess: close,
        onFinish: () => {
            form.reset('provider_key');
        },
    };

    form.transform((data) => ({
        provider_key: data.provider_key,
        confirmed: data.confirmed,
        ...(selected.value?.needsAccountId
            ? { account_id: data.account_id }
            : {}),
        ...(props.replacing ? {} : { provider: data.provider }),
    }));

    if (props.replacing) {
        form.put(update.url(props.replacing.id), options);

        return;
    }

    form.post(store.url(), options);
};
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-lg" data-testid="socialhq-ai-key-dialog">
            <DialogHeader>
                <DialogTitle>
                    {{
                        replacing
                            ? $t('socialhq_ai.replace.title', {
                                  provider: $t(
                                      `socialhq_ai.providers.${replacing.provider}.name`,
                                  ),
                              })
                            : $t('socialhq_ai.add.title')
                    }}
                </DialogTitle>
                <DialogDescription>
                    {{
                        replacing
                            ? $t('socialhq_ai.replace.description')
                            : $t('socialhq_ai.add.description')
                    }}
                </DialogDescription>
            </DialogHeader>

            <form
                class="flex flex-col gap-5"
                data-testid="socialhq-ai-key-form"
                @submit.prevent="submit"
            >
                <fieldset v-if="!replacing" class="flex flex-col gap-2">
                    <legend class="mb-2 text-sm font-medium">
                        {{ $t('socialhq_ai.add.provider') }}
                    </legend>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        <button
                            v-for="provider in available"
                            :key="provider.id"
                            type="button"
                            class="flex items-center gap-2 rounded-lg border p-2 text-start text-sm transition-control hover:bg-muted"
                            :class="
                                form.provider === provider.id
                                    ? 'border-primary ring-1 ring-primary'
                                    : 'border-border'
                            "
                            :aria-pressed="form.provider === provider.id"
                            :data-testid="`socialhq-ai-choose-${provider.id}`"
                            @click="choose(provider.id)"
                        >
                            <ProviderLogo :logo="provider.logo" />
                            <span class="min-w-0 font-medium">
                                {{
                                    $t(
                                        `socialhq_ai.providers.${provider.id}.name`,
                                    )
                                }}
                            </span>
                        </button>
                    </div>
                    <p
                        v-if="form.errors.provider"
                        class="text-sm text-destructive"
                        data-testid="socialhq-ai-provider-error"
                    >
                        {{ form.errors.provider }}
                    </p>
                </fieldset>

                <template v-if="selected">
                    <div class="flex flex-col gap-2 rounded-lg bg-muted p-3">
                        <ol
                            class="list-decimal space-y-1 ps-5 text-sm text-foreground"
                        >
                            <li v-for="step in steps" :key="step">
                                {{
                                    $t(
                                        `socialhq_ai.providers.${selected.id}.step_${step}`,
                                    )
                                }}
                            </li>
                        </ol>
                        <a
                            v-if="selected.consoleUrl"
                            :href="selected.consoleUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex w-fit items-center gap-1 text-sm text-primary-text underline underline-offset-2 hover:text-primary-text-hover"
                            data-testid="socialhq-ai-console-link"
                        >
                            {{
                                $t('socialhq_ai.add.open_console', {
                                    provider: $t(
                                        `socialhq_ai.providers.${selected.id}.name`,
                                    ),
                                })
                            }}
                            <IconExternalLink class="size-3.5" />
                        </a>
                        <p
                            class="flex items-start gap-1.5 text-xs text-muted-foreground"
                        >
                            <IconShieldCheck class="mt-px size-4 shrink-0" />
                            {{
                                $t(`socialhq_ai.providers.${selected.id}.data`)
                            }}
                        </p>
                    </div>

                    <div
                        v-if="selected.needsAccountId"
                        class="flex flex-col gap-2"
                    >
                        <Label for="socialhq-ai-account-id">
                            {{ $t('socialhq_ai.add.account_id') }}
                        </Label>
                        <Input
                            id="socialhq-ai-account-id"
                            v-model="form.account_id"
                            autocomplete="off"
                            spellcheck="false"
                            data-testid="socialhq-ai-account-id"
                        />
                        <p
                            v-if="form.errors.account_id"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.account_id }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-2">
                        <Label for="socialhq-ai-key">
                            {{ $t('socialhq_ai.add.key') }}
                        </Label>
                        <Input
                            id="socialhq-ai-key"
                            v-model="form.provider_key"
                            type="password"
                            autocomplete="off"
                            spellcheck="false"
                            :placeholder="$t('socialhq_ai.add.key_placeholder')"
                            :aria-invalid="Boolean(form.errors.provider_key)"
                            data-testid="socialhq-ai-key"
                        />
                        <p
                            v-if="form.errors.provider_key"
                            class="text-sm text-destructive"
                            data-testid="socialhq-ai-key-error"
                        >
                            {{ form.errors.provider_key }}
                        </p>
                    </div>

                    <div
                        v-if="selected.confirmation"
                        class="flex flex-col gap-1"
                    >
                        <label
                            class="flex cursor-pointer items-start gap-3 text-sm"
                        >
                            <Checkbox
                                :model-value="form.confirmed"
                                class="mt-0.5"
                                data-testid="socialhq-ai-confirm"
                                @update:model-value="toggleConfirmed"
                            />
                            <span>
                                {{
                                    $t(
                                        `socialhq_ai.confirm.${selected.confirmation}`,
                                    )
                                }}
                            </span>
                        </label>
                        <p
                            v-if="form.errors.confirmed"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.confirmed }}
                        </p>
                    </div>

                    <p class="text-xs text-muted-foreground">
                        {{ $t('socialhq_ai.add.stored_note') }}
                    </p>
                </template>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="close">
                        {{ $t('common.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :loading="form.processing"
                        :disabled="!selected"
                        data-testid="socialhq-ai-key-submit"
                    >
                        {{
                            replacing
                                ? $t('socialhq_ai.replace.submit')
                                : $t('socialhq_ai.add.submit')
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
