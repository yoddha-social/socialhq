<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    IconChevronDown,
    IconExternalLink,
    IconShieldCheck,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';

import ProviderLogo from '@/components/socialhq/ai/ProviderLogo.vue';
import type {
    AiTaskName,
    CatalogueProvider,
    CatalogueTier,
    CompanyProvider,
    ProviderTier,
} from '@/components/socialhq/ai/types';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
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
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    settings as saveSettings,
    store,
    update,
} from '@/routes/socialhq/ai-settings/providers';

/**
 * Add a key, edit a key's settings (name, plan, tasks, models), or replace
 * a key. The key field is write-only: it is never filled from props or sent
 * back by the server, and it is cleared once the key is saved and whenever
 * the dialog opens. After a refused test it stays in the field (only in
 * this page's memory), so the owner can fix another field without pasting
 * it again.
 */
const props = defineProps<{
    open: boolean;
    catalogue: CatalogueProvider[];
    /** The company's keys, to suggest a name that isn't taken. */
    existing: CompanyProvider[];
    /** Set when editing that key's settings. */
    editing: CompanyProvider | null;
    /** Set when replacing that key. */
    replacing: CompanyProvider | null;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

/** An empty form. A successful save makes the saved values the form's defaults, so opening starts from this instead. */
const blank = () => ({
    provider: '',
    label: '',
    tier: '' as ProviderTier | '',
    tasks: [] as AiTaskName[],
    models: {} as Partial<Record<AiTaskName, string>>,
    provider_key: '',
    account_id: '',
    confirmed: false,
});

const form = useForm(blank());

const current = computed(() => props.editing ?? props.replacing);
const mode = computed<'add' | 'edit' | 'replace'>(() =>
    props.editing ? 'edit' : props.replacing ? 'replace' : 'add',
);
const showsSettings = computed(() => mode.value !== 'replace');
const showsKey = computed(() => mode.value !== 'edit');

const selected = computed<CatalogueProvider | null>(
    () =>
        props.catalogue.find(
            (provider) =>
                provider.id === (current.value?.provider ?? form.provider),
        ) ?? null,
);

const selectedTier = computed<CatalogueTier | null>(
    () => selected.value?.tiers.find((tier) => tier.tier === form.tier) ?? null,
);

/** The checkbox to tick: on adding or replacing, and on editing only when the plan changes. */
const confirmation = computed(() => {
    if (mode.value === 'edit' && form.tier === (props.editing?.tier ?? '')) {
        return null;
    }

    return selectedTier.value?.confirmation ?? null;
});

const steps = computed(() =>
    Array.from({ length: selected.value?.steps ?? 0 }, (_, index) => index + 1),
);

const modelsOpen = ref(false);
const hasCustomModels = computed(() =>
    Object.values(form.models).some((model) => Boolean(model)),
);

/** A free plan that may train is never picked for the owner. */
const defaultTier = (provider: CatalogueProvider): ProviderTier | '' =>
    (
        provider.tiers.find((tier) => tier.confirmation !== 'may_train') ??
        provider.tiers[0]
    )?.tier ?? '';

const allTasksOf = (tier: CatalogueTier | null): AiTaskName[] =>
    tier?.tasks.map((item) => item.task) ?? [];

/** "Groq", or "Groq 2" when a key is already called that. */
const suggestLabel = (provider: CatalogueProvider): string => {
    const name = trans(`socialhq_ai.providers.${provider.id}.name`);
    const taken = new Set(
        props.existing.map((item) => (item.label ?? '').toLowerCase()),
    );

    if (!taken.has(name.toLowerCase())) {
        return name;
    }

    let number = 2;

    while (taken.has(`${name} ${number}`.toLowerCase())) {
        number += 1;
    }

    return `${name} ${number}`;
};

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        form.defaults(blank());
        form.reset();
        form.clearErrors();
        modelsOpen.value = false;

        const item = current.value;

        if (item) {
            form.provider = item.provider;
            form.label =
                item.label ??
                trans(`socialhq_ai.providers.${item.provider}.name`);
            form.tier = item.tier;
            form.tasks = [...item.servedTasks];
            form.models = { ...item.models };
            form.account_id = item.options.accountId ?? '';
            modelsOpen.value = hasCustomModels.value;
        }
    },
);

const close = (): void => {
    emit('update:open', false);
};

const choose = (provider: CatalogueProvider): void => {
    const previousSuggestion = selected.value
        ? suggestLabel(selected.value)
        : '';

    form.provider = provider.id;
    form.tier = defaultTier(provider);
    form.tasks = allTasksOf(selectedTier.value);
    form.models = {};
    form.confirmed = false;
    form.clearErrors();

    if (form.label === '' || form.label === previousSuggestion) {
        form.label = suggestLabel(provider);
    }
};

/** Keeps the ticked tasks the new plan offers too; with none left, ticks all of them. */
const chooseTier = (value: unknown): void => {
    form.tier = value as ProviderTier;

    const offered = new Set(allTasksOf(selectedTier.value));
    const kept = form.tasks.filter((task) => offered.has(task));
    form.tasks = kept.length ? kept : [...offered];
    form.models = Object.fromEntries(
        Object.entries(form.models).filter(([task]) =>
            offered.has(task as AiTaskName),
        ),
    );
    form.confirmed = false;
};

const isTaskOn = (task: AiTaskName): boolean => form.tasks.includes(task);

/** The error on one task's model field ("models.write"). */
const modelError = (task: AiTaskName): string | undefined =>
    (form.errors as Record<string, string | undefined>)[`models.${task}`];

const toggleTask = (
    task: AiTaskName,
    value: boolean | 'indeterminate',
): void => {
    form.tasks =
        value === true
            ? [...new Set([...form.tasks, task])]
            : form.tasks.filter((item) => item !== task);
};

const toggleConfirmed = (value: boolean | 'indeterminate'): void => {
    form.confirmed = value === true;
};

interface SettingsData {
    label: string;
    tier: ProviderTier | '';
    tasks: AiTaskName[];
    models: Partial<Record<AiTaskName, string>>;
}

/** The settings sent, with models only for ticked tasks and never an empty one. */
const settingsPayload = (data: SettingsData): SettingsData => ({
    label: data.label,
    tier: data.tier,
    tasks: data.tasks,
    models: Object.fromEntries(
        Object.entries(data.models).filter(
            ([task, model]) =>
                Boolean(model) && data.tasks.includes(task as AiTaskName),
        ),
    ),
});

const submit = (): void => {
    const options = {
        preserveScroll: true,
        onSuccess: (): void => {
            form.reset('provider_key');
            close();
        },
    };

    form.transform((data) => ({
        ...(showsSettings.value ? settingsPayload(data) : {}),
        ...(showsKey.value ? { provider_key: data.provider_key } : {}),
        ...(showsKey.value && selected.value?.needsAccountId
            ? { account_id: data.account_id }
            : {}),
        ...(mode.value === 'add' ? { provider: data.provider } : {}),
        confirmed: data.confirmed,
    }));

    if (props.editing) {
        form.put(saveSettings.url(props.editing.id), options);

        return;
    }

    if (props.replacing) {
        form.put(update.url(props.replacing.id), options);

        return;
    }

    form.post(store.url(), options);
};
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-xl" data-testid="socialhq-ai-key-dialog">
            <DialogHeader>
                <DialogTitle>
                    <template v-if="mode === 'edit'">
                        {{
                            $t('socialhq_ai.edit.title', {
                                provider:
                                    editing?.label ??
                                    $t(
                                        `socialhq_ai.providers.${editing?.provider}.name`,
                                    ),
                            })
                        }}
                    </template>
                    <template v-else-if="mode === 'replace'">
                        {{
                            $t('socialhq_ai.replace.title', {
                                provider:
                                    replacing?.label ??
                                    $t(
                                        `socialhq_ai.providers.${replacing?.provider}.name`,
                                    ),
                            })
                        }}
                    </template>
                    <template v-else>
                        {{ $t('socialhq_ai.add.title') }}
                    </template>
                </DialogTitle>
                <DialogDescription>
                    {{ $t(`socialhq_ai.${mode}.description`) }}
                </DialogDescription>
            </DialogHeader>

            <form
                class="flex min-w-0 flex-col gap-5"
                data-testid="socialhq-ai-key-form"
                @submit.prevent="submit"
            >
                <fieldset v-if="mode === 'add'" class="flex flex-col gap-2">
                    <legend class="mb-2 text-sm font-medium">
                        {{ $t('socialhq_ai.add.provider') }}
                    </legend>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        <button
                            v-for="provider in catalogue"
                            :key="provider.id"
                            type="button"
                            class="flex min-w-0 items-center gap-2 rounded-lg border p-2 text-start text-sm transition-control hover:bg-muted"
                            :class="
                                form.provider === provider.id
                                    ? 'border-primary ring-1 ring-primary'
                                    : 'border-border'
                            "
                            :aria-pressed="form.provider === provider.id"
                            :data-testid="`socialhq-ai-choose-${provider.id}`"
                            @click="choose(provider)"
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
                    <template v-if="showsSettings">
                        <div class="flex flex-col gap-2">
                            <Label for="socialhq-ai-label">
                                {{ $t('socialhq_ai.add.label') }}
                            </Label>
                            <Input
                                id="socialhq-ai-label"
                                v-model="form.label"
                                autocomplete="off"
                                :aria-invalid="Boolean(form.errors.label)"
                                data-testid="socialhq-ai-label"
                            />
                            <p class="text-xs text-muted-foreground">
                                {{ $t('socialhq_ai.add.label_hint') }}
                            </p>
                            <p
                                v-if="form.errors.label"
                                class="text-sm text-destructive"
                                data-testid="socialhq-ai-label-error"
                            >
                                {{ form.errors.label }}
                            </p>
                        </div>

                        <div
                            v-if="selected.tiers.length > 1"
                            class="flex flex-col gap-2"
                        >
                            <span class="text-sm font-medium">
                                {{ $t('socialhq_ai.add.tier') }}
                            </span>
                            <RadioGroup
                                :model-value="form.tier"
                                class="gap-3"
                                :aria-label="$t('socialhq_ai.add.tier')"
                                @update:model-value="chooseTier"
                            >
                                <label
                                    v-for="tier in selected.tiers"
                                    :key="tier.tier"
                                    class="flex cursor-pointer items-start gap-3"
                                >
                                    <RadioGroupItem
                                        :value="tier.tier"
                                        class="mt-0.5"
                                        :data-testid="`socialhq-ai-tier-${tier.tier}`"
                                    />
                                    <span class="min-w-0">
                                        <span
                                            class="block text-sm leading-5 font-medium"
                                        >
                                            {{
                                                $t(
                                                    `socialhq_ai.tiers.${tier.tier}`,
                                                )
                                            }}
                                        </span>
                                        <span
                                            class="block text-sm text-muted-foreground"
                                        >
                                            {{
                                                $t(
                                                    `socialhq_ai.tier_notes.${tier.tier}`,
                                                )
                                            }}
                                        </span>
                                    </span>
                                </label>
                            </RadioGroup>
                            <p
                                v-if="form.errors.tier"
                                class="text-sm text-destructive"
                            >
                                {{ form.errors.tier }}
                            </p>
                        </div>

                        <fieldset
                            v-if="selectedTier"
                            class="flex flex-col gap-2"
                        >
                            <legend class="mb-2 text-sm font-medium">
                                {{ $t('socialhq_ai.add.tasks') }}
                            </legend>
                            <label
                                v-for="item in selectedTier.tasks"
                                :key="item.task"
                                class="flex cursor-pointer items-center gap-3 text-sm"
                            >
                                <Checkbox
                                    :model-value="isTaskOn(item.task)"
                                    :data-testid="`socialhq-ai-task-${item.task}`"
                                    @update:model-value="
                                        toggleTask(item.task, $event)
                                    "
                                />
                                {{
                                    $t(`socialhq_ai.status.tasks.${item.task}`)
                                }}
                            </label>
                            <p
                                v-if="form.errors.tasks"
                                class="text-sm text-destructive"
                                data-testid="socialhq-ai-tasks-error"
                            >
                                {{ form.errors.tasks }}
                            </p>
                        </fieldset>

                        <Collapsible
                            v-if="selectedTier && form.tasks.length"
                            v-model:open="modelsOpen"
                            class="flex flex-col gap-3"
                        >
                            <CollapsibleTrigger as-child>
                                <button
                                    type="button"
                                    class="inline-flex w-fit items-center gap-1 text-sm font-medium"
                                    data-testid="socialhq-ai-models-toggle"
                                >
                                    {{ $t('socialhq_ai.add.models') }}
                                    <IconChevronDown
                                        class="size-4 transition-transform"
                                        :class="{ 'rotate-180': modelsOpen }"
                                    />
                                </button>
                            </CollapsibleTrigger>
                            <CollapsibleContent class="flex flex-col gap-3">
                                <p class="text-xs text-muted-foreground">
                                    {{ $t('socialhq_ai.add.models_hint') }}
                                </p>
                                <template
                                    v-for="item in selectedTier.tasks"
                                    :key="item.task"
                                >
                                    <div
                                        v-if="isTaskOn(item.task)"
                                        class="flex flex-col gap-1.5"
                                    >
                                        <Label
                                            :for="`socialhq-ai-model-${item.task}`"
                                            class="text-xs"
                                        >
                                            {{
                                                $t(
                                                    `socialhq_ai.status.tasks.${item.task}`,
                                                )
                                            }}
                                        </Label>
                                        <Input
                                            :id="`socialhq-ai-model-${item.task}`"
                                            v-model="form.models[item.task]"
                                            :placeholder="item.model"
                                            autocomplete="off"
                                            spellcheck="false"
                                            class="font-mono text-xs"
                                            :data-testid="`socialhq-ai-model-${item.task}`"
                                        />
                                        <p
                                            v-if="modelError(item.task)"
                                            class="text-sm text-destructive"
                                        >
                                            {{ modelError(item.task) }}
                                        </p>
                                    </div>
                                </template>
                            </CollapsibleContent>
                        </Collapsible>
                    </template>

                    <template v-if="showsKey">
                        <div
                            class="flex flex-col gap-2 rounded-lg bg-muted p-3"
                        >
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
                                <IconShieldCheck
                                    class="mt-px size-4 shrink-0"
                                />
                                {{
                                    $t(
                                        `socialhq_ai.providers.${selected.id}.data`,
                                    )
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
                                :placeholder="
                                    $t('socialhq_ai.add.key_placeholder')
                                "
                                :aria-invalid="
                                    Boolean(form.errors.provider_key)
                                "
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
                    </template>

                    <div v-if="confirmation" class="flex flex-col gap-1">
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
                                {{ $t(`socialhq_ai.confirm.${confirmation}`) }}
                            </span>
                        </label>
                        <p
                            v-if="form.errors.confirmed"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.confirmed }}
                        </p>
                    </div>

                    <p v-if="showsKey" class="text-xs text-muted-foreground">
                        {{ $t('socialhq_ai.add.stored_note') }}
                    </p>
                </template>

                <DialogFooter
                    class="sticky -bottom-4 -mb-4 bg-background pt-3 pb-4"
                >
                    <Button type="button" variant="outline" @click="close">
                        {{ $t('common.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :loading="form.processing"
                        :disabled="!selected"
                        data-testid="socialhq-ai-key-submit"
                    >
                        {{ $t(`socialhq_ai.${mode}.submit`) }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
