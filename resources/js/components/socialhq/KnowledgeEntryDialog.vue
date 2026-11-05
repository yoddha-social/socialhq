<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

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

export interface KnowledgeEntryRow {
    id: number;
    kind: string;
    title: string;
    body: string;
    use_in_ai: boolean;
    updated_at: string | null;
}

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
});

const fill = (): void => {
    form.clearErrors();
    form.kind = props.entry?.kind ?? props.kinds[0] ?? 'product';
    form.title = props.entry?.title ?? '';
    form.body = props.entry?.body ?? '';
    form.use_in_ai = props.entry?.use_in_ai ?? true;
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

                <div class="flex flex-col gap-2">
                    <Label for="socialhq-knowledge-kind">
                        {{ $t('socialhq.brand.entries.dialog.kind') }}
                    </Label>
                    <NativeSelect
                        id="socialhq-knowledge-kind"
                        v-model="form.kind"
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
                </div>

                <div class="flex flex-col gap-2">
                    <Label for="socialhq-knowledge-title">
                        {{ $t('socialhq.brand.entries.dialog.title') }}
                    </Label>
                    <Input
                        id="socialhq-knowledge-title"
                        v-model="form.title"
                        :placeholder="
                            $t(
                                'socialhq.brand.entries.dialog.title_placeholder',
                            )
                        "
                        data-testid="socialhq-knowledge-title"
                    />
                    <p
                        v-if="form.errors.title"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.title }}
                    </p>
                </div>

                <div class="flex flex-col gap-2">
                    <Label for="socialhq-knowledge-body">
                        {{ $t('socialhq.brand.entries.dialog.body') }}
                    </Label>
                    <Textarea
                        id="socialhq-knowledge-body"
                        v-model="form.body"
                        rows="6"
                        :placeholder="
                            $t('socialhq.brand.entries.dialog.body_placeholder')
                        "
                        data-testid="socialhq-knowledge-body"
                    />
                    <p v-if="form.errors.body" class="text-sm text-destructive">
                        {{ form.errors.body }}
                    </p>
                </div>

                <div class="flex items-start justify-between gap-4">
                    <div class="flex flex-col gap-1">
                        <Label for="socialhq-knowledge-ai">
                            {{ $t('socialhq.brand.entries.dialog.use_in_ai') }}
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
                        data-testid="socialhq-knowledge-ai"
                        @update:model-value="setUseInAi(Boolean($event))"
                    />
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
