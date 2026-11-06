<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { IconUpload } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

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
import { Switch } from '@/components/ui/switch';
import {
    store as storeDocument,
    update as updateDocument,
} from '@/routes/socialhq/brand/documents';

export type KnowledgeDocumentStatus = 'processing' | 'ready' | 'failed';

export interface KnowledgeDocumentRow {
    id: number;
    title: string;
    file_name: string;
    type: 'pdf' | 'docx' | 'pptx' | 'txt' | 'md' | null;
    size: number;
    pages: number | null;
    characters: number;
    truncated: boolean;
    chunk_count: number;
    status: KnowledgeDocumentStatus;
    failure: string | null;
    use_in_ai: boolean;
    created_at: string | null;
}

/** What the file input accepts: a hint for the picker; the server checks the content. */
const ACCEPT =
    '.pdf,.docx,.pptx,.txt,.md,.markdown,application/pdf,text/plain,text/markdown,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.presentationml.presentation';

type DocumentField = 'file' | 'title' | 'use_in_ai';

/**
 * Upload a document (no `document`), or rename one and switch "Use in AI
 * writing" (with `document`). Its text changes only by uploading again.
 */
const props = defineProps<{
    document: KnowledgeDocumentRow | null;
    maxMegabytes: number;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm<{
    file: File | null;
    title: string;
    use_in_ai: boolean;
}>({
    file: null,
    title: '',
    use_in_ai: true,
});

const fileInput = ref<HTMLInputElement | null>(null);

const errors = computed(
    () => form.errors as Partial<Record<DocumentField, string>>,
);

const errorId = (field: DocumentField): string =>
    `socialhq-document-${field}-error`;

/** aria-invalid and aria-describedby for a field, only while it has an error. */
const errorAttributes = (
    field: DocumentField,
): Record<string, string | boolean | undefined> =>
    errors.value[field]
        ? { 'aria-invalid': true, 'aria-describedby': errorId(field) }
        : {};

const fill = (): void => {
    form.clearErrors();
    form.file = null;
    form.title = props.document?.title ?? '';
    form.use_in_ai = props.document?.use_in_ai ?? true;

    if (fileInput.value) {
        fileInput.value.value = '';
    }
};

watch(open, (isOpen) => {
    if (isOpen) {
        fill();
    }
});

const close = (): void => {
    open.value = false;
};

const pickFile = (): void => {
    fileInput.value?.click();
};

const onFileChosen = (event: Event): void => {
    const input = event.target as HTMLInputElement;
    form.file = input.files?.[0] ?? null;
    form.clearErrors('file');
};

const setUseInAi = (value: boolean): void => {
    form.use_in_ai = value;
};

const submit = (): void => {
    if (props.document) {
        form.transform((data) => ({
            title: data.title,
            use_in_ai: data.use_in_ai,
        })).put(updateDocument.url(props.document.id), {
            preserveScroll: true,
            onSuccess: close,
        });

        return;
    }

    form.transform((data) => ({ file: data.file, title: data.title })).post(
        storeDocument.url(),
        {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: close,
        },
    );
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="sm:max-w-lg"
            data-testid="socialhq-document-dialog"
        >
            <form class="flex flex-col gap-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>
                        {{
                            document
                                ? $t(
                                      'socialhq_knowledge.documents.dialog.edit_title',
                                  )
                                : $t(
                                      'socialhq_knowledge.documents.dialog.upload_title',
                                  )
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            document
                                ? $t(
                                      'socialhq_knowledge.documents.dialog.edit_description',
                                  )
                                : $t(
                                      'socialhq_knowledge.documents.dialog.upload_description',
                                      { size: String(maxMegabytes) },
                                  )
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div v-if="!document" class="flex flex-col gap-2">
                    <Label for="socialhq-document-file">
                        {{ $t('socialhq_knowledge.documents.dialog.file') }}
                    </Label>
                    <input
                        id="socialhq-document-file"
                        ref="fileInput"
                        type="file"
                        class="sr-only"
                        :accept="ACCEPT"
                        v-bind="errorAttributes('file')"
                        data-testid="socialhq-document-file"
                        @change="onFileChosen"
                    />
                    <div class="flex min-w-0 items-center gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            class="shrink-0"
                            data-testid="socialhq-document-choose"
                            @click="pickFile"
                        >
                            <IconUpload class="size-4" />
                            {{
                                $t('socialhq_knowledge.documents.dialog.choose')
                            }}
                        </Button>
                        <p
                            class="min-w-0 truncate text-sm text-muted-foreground"
                            aria-live="polite"
                        >
                            {{
                                form.file?.name ??
                                $t(
                                    'socialhq_knowledge.documents.dialog.no_file',
                                )
                            }}
                        </p>
                    </div>
                    <p
                        v-if="errors.file"
                        :id="errorId('file')"
                        role="alert"
                        class="text-sm text-destructive-text"
                        data-testid="socialhq-document-file-error"
                    >
                        {{ errors.file }}
                    </p>
                </div>

                <div class="flex flex-col gap-2">
                    <Label for="socialhq-document-title">
                        {{ $t('socialhq_knowledge.documents.dialog.title') }}
                    </Label>
                    <Input
                        id="socialhq-document-title"
                        v-model="form.title"
                        v-bind="errorAttributes('title')"
                        :placeholder="form.file?.name"
                        data-testid="socialhq-document-title"
                    />
                    <p v-if="!document" class="text-sm text-muted-foreground">
                        {{
                            $t('socialhq_knowledge.documents.dialog.title_hint')
                        }}
                    </p>
                    <p
                        v-if="errors.title"
                        :id="errorId('title')"
                        class="text-sm text-destructive-text"
                    >
                        {{ errors.title }}
                    </p>
                </div>

                <div v-if="document" class="flex flex-col gap-2">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex flex-col gap-1">
                            <Label for="socialhq-document-ai">
                                {{
                                    $t(
                                        'socialhq_knowledge.documents.dialog.use_in_ai',
                                    )
                                }}
                            </Label>
                            <p class="text-sm text-muted-foreground">
                                {{
                                    $t(
                                        'socialhq_knowledge.documents.dialog.use_in_ai_hint',
                                    )
                                }}
                            </p>
                        </div>
                        <Switch
                            id="socialhq-document-ai"
                            class="shrink-0"
                            :model-value="form.use_in_ai"
                            v-bind="errorAttributes('use_in_ai')"
                            data-testid="socialhq-document-ai"
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

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="close">
                        {{ $t('socialhq_knowledge.documents.dialog.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :loading="form.processing"
                        data-testid="socialhq-document-save"
                    >
                        {{
                            document
                                ? $t('socialhq_knowledge.documents.dialog.save')
                                : $t(
                                      'socialhq_knowledge.documents.dialog.upload',
                                  )
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
