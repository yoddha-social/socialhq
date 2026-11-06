<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

import { Button } from '@/components/ui/button';
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
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    store as storeEntry,
    update as updateEntry,
} from '@/routes/socialhq/brand/entries';

export type KnowledgeFit = 'included' | 'no_room' | 'too_long' | 'off';

export interface KnowledgeEntryRow {
    id: number;
    kind: string;
    title: string;
    body: string;
    use_in_ai: boolean;
    pinned: boolean;
    fit: KnowledgeFit;
    updated_at: string | null;
}

type EntryField = 'kind' | 'title' | 'body' | 'use_in_ai' | 'pinned';

const props = defineProps<{
    entry: KnowledgeEntryRow | null;
    kinds: string[];
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    kind: 'product',
    title: '',
    body: '',
    use_in_ai: true,
    pinned: false,
});

/** An example post: the body is the post itself, read for its tone. */
const isExample = computed(() => form.kind === 'example_post');

/** Errors by key, including "limit", which belongs to no field. */
const errors = computed(
    () => form.errors as Partial<Record<EntryField | 'limit', string>>,
);

const errorId = (field: EntryField): string =>
    `socialhq-knowledge-${field}-error`;

/** aria-invalid and aria-describedby for a field, only while it has an error. */
const errorAttributes = (
    field: EntryField,
): Record<string, string | boolean | undefined> =>
    errors.value[field]
        ? { 'aria-invalid': true, 'aria-describedby': errorId(field) }
        : {};

const fill = (): void => {
    form.clearErrors();
    form.kind = props.entry?.kind ?? props.kinds[0] ?? 'product';
    form.title = props.entry?.title ?? '';
    form.body = props.entry?.body ?? '';
    form.use_in_ai = props.entry?.use_in_ai ?? true;
    form.pinned = props.entry?.pinned ?? false;
};

watch(open, (isOpen) => {
    if (isOpen) {
        fill();
    }
});

const close = (): void => {
    open.value = false;
};

const setUseInAi = (value: boolean): void => {
    form.use_in_ai = value;
};

const setPinned = (value: boolean): void => {
    form.pinned = value;
};

const submit = (): void => {
    const options = { preserveScroll: true, onSuccess: close };

    if (props.entry) {
        form.put(updateEntry.url(props.entry.id), options);
    } else {
        form.post(storeEntry.url(), options);
    }
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="sm:max-w-lg"
            data-testid="socialhq-knowledge-dialog"
        >
            <form class="flex flex-col gap-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>
                        {{
                            entry
                                ? $t('socialhq.brand.entries.dialog.edit_title')
                                : $t('socialhq.brand.entries.dialog.add_title')
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{ $t('socialhq.brand.entries.description') }}
                    </DialogDescription>
                </DialogHeader>

                <p
                    v-if="errors.limit"
                    role="alert"
                    class="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive-text"
                    data-testid="socialhq-knowledge-limit-error"
                >
                    {{ errors.limit }}
                </p>

                <div class="flex flex-col gap-2">
                    <Label for="socialhq-knowledge-kind">
                        {{ $t('socialhq.brand.entries.dialog.kind') }}
                    </Label>
                    <NativeSelect
                        id="socialhq-knowledge-kind"
                        v-model="form.kind"
                        v-bind="errorAttributes('kind')"
                        data-testid="socialhq-knowledge-kind"
                    >
                        <NativeSelectOption
                            v-for="kind in kinds"
                            :key="kind"
                            :value="kind"
                        >
                            {{ $t(`socialhq.brand.entries.kinds.${kind}`) }}
                        </NativeSelectOption>
                    </NativeSelect>
                    <p
                        v-if="errors.kind"
                        :id="errorId('kind')"
                        class="text-sm text-destructive-text"
                    >
                        {{ errors.kind }}
                    </p>
                </div>

                <div class="flex flex-col gap-2">
                    <Label for="socialhq-knowledge-title">
                        {{ $t('socialhq.brand.entries.dialog.title') }}
                    </Label>
                    <Input
                        id="socialhq-knowledge-title"
                        v-model="form.title"
                        v-bind="errorAttributes('title')"
                        :placeholder="
                            isExample
                                ? $t(
                                      'socialhq.brand.entries.dialog.example_title_placeholder',
                                  )
                                : $t(
                                      'socialhq.brand.entries.dialog.title_placeholder',
                                  )
                        "
                        data-testid="socialhq-knowledge-title"
                    />
                    <p
                        v-if="errors.title"
                        :id="errorId('title')"
                        class="text-sm text-destructive-text"
                    >
                        {{ errors.title }}
                    </p>
                </div>

                <div class="flex flex-col gap-2">
                    <Label for="socialhq-knowledge-body">
                        {{
                            isExample
                                ? $t(
                                      'socialhq.brand.entries.dialog.example_body',
                                  )
                                : $t('socialhq.brand.entries.dialog.body')
                        }}
                    </Label>
                    <Textarea
                        id="socialhq-knowledge-body"
                        v-model="form.body"
                        v-bind="errorAttributes('body')"
                        rows="6"
                        :placeholder="
                            isExample
                                ? $t(
                                      'socialhq.brand.entries.dialog.example_body_placeholder',
                                  )
                                : $t(
                                      'socialhq.brand.entries.dialog.body_placeholder',
                                  )
                        "
                        data-testid="socialhq-knowledge-body"
                    />
                    <p
                        v-if="errors.body"
                        :id="errorId('body')"
                        class="text-sm text-destructive-text"
                    >
                        {{ errors.body }}
                    </p>
                </div>

                <div class="flex flex-col gap-2">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex flex-col gap-1">
                            <Label for="socialhq-knowledge-ai">
                                {{
                                    $t(
                                        'socialhq.brand.entries.dialog.use_in_ai',
                                    )
                                }}
                            </Label>
                            <p class="text-sm text-muted-foreground">
                                {{
                                    $t(
                                        'socialhq.brand.entries.dialog.use_in_ai_hint',
                                    )
                                }}
                            </p>
                        </div>
                        <Switch
                            id="socialhq-knowledge-ai"
                            class="shrink-0"
                            :model-value="form.use_in_ai"
                            v-bind="errorAttributes('use_in_ai')"
                            data-testid="socialhq-knowledge-ai"
                            @update:model-value="setUseInAi(Boolean($event))"
                        />
                    </div>
                    <p
                        v-if="errors.use_in_ai"
                        :id="errorId('use_in_ai')"
                        class="text-sm text-destructive-text"
                    >
                        {{ errors.use_in_ai }}
                    </p>
                </div>

                <div class="flex flex-col gap-2">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex flex-col gap-1">
                            <Label for="socialhq-knowledge-pinned">
                                {{ $t('socialhq.brand.entries.dialog.pinned') }}
                            </Label>
                            <p class="text-sm text-muted-foreground">
                                {{
                                    $t(
                                        'socialhq.brand.entries.dialog.pinned_hint',
                                    )
                                }}
                            </p>
                        </div>
                        <Switch
                            id="socialhq-knowledge-pinned"
                            class="shrink-0"
                            :model-value="form.pinned"
                            :disabled="!form.use_in_ai"
                            v-bind="errorAttributes('pinned')"
                            data-testid="socialhq-knowledge-pinned"
                            @update:model-value="setPinned(Boolean($event))"
                        />
                    </div>
                    <p
                        v-if="errors.pinned"
                        :id="errorId('pinned')"
                        class="text-sm text-destructive-text"
                    >
                        {{ errors.pinned }}
                    </p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="close">
                        {{ $t('socialhq.brand.entries.dialog.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :loading="form.processing"
                        data-testid="socialhq-knowledge-save"
                    >
                        {{ $t('socialhq.brand.entries.dialog.save') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
