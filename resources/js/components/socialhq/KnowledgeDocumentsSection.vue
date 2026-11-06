<script setup lang="ts">
import { router, usePoll } from '@inertiajs/vue3';
import {
    IconDots,
    IconDownload,
    IconFileText,
    IconFileTypeDocx,
    IconFileTypePdf,
    IconFileTypePpt,
    IconFileTypeTxt,
    IconMarkdown,
    IconUpload,
} from '@tabler/icons-vue';
import { computed, ref, watch, type Component } from 'vue';

import EmptyState from '@/components/EmptyState.vue';
import SettingsListRow from '@/components/settings/SettingsListRow.vue';
import KnowledgeDocumentDialog, {
    type KnowledgeDocumentRow,
} from '@/components/socialhq/KnowledgeDocumentDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { formatFullNumber } from '@/lib/utils';
import {
    destroy as destroyDocument,
    show as downloadDocument,
} from '@/routes/socialhq/brand/documents';

export interface KnowledgeDocumentsProp {
    items: KnowledgeDocumentRow[];
    limit: number;
    maxMegabytes: number;
}

/**
 * The Documents section of Brand knowledge: brochures, price lists and decks
 * whose matching parts the AI reads. Admins upload, edit and delete them;
 * everyone who reads brand knowledge downloads them. While a document is
 * being read, the list reloads every few seconds.
 */
const props = defineProps<{
    documents: KnowledgeDocumentsProp;
    canEdit: boolean;
}>();

const TYPE_ICONS: Record<string, Component> = {
    pdf: IconFileTypePdf,
    docx: IconFileTypeDocx,
    pptx: IconFileTypePpt,
    txt: IconFileTypeTxt,
    md: IconMarkdown,
};

const items = computed(() => props.documents.items);
const atLimit = computed(() => items.value.length >= props.documents.limit);
const isReading = computed(() =>
    items.value.some((document) => document.status === 'processing'),
);

const { start: startPolling, stop: stopPolling } = usePoll(
    3000,
    { only: ['documents'] },
    { autoStart: false },
);

watch(
    isReading,
    (reading) => {
        if (reading) {
            startPolling();
        } else {
            stopPolling();
        }
    },
    { immediate: true },
);

const iconOf = (document: KnowledgeDocumentRow): Component =>
    (document.type && TYPE_ICONS[document.type]) || IconFileText;

const formatSize = (bytes: number): string =>
    bytes >= 1024 * 1024
        ? `${(bytes / (1024 * 1024)).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;

const isDialogOpen = ref(false);
const editingDocument = ref<KnowledgeDocumentRow | null>(null);
const deletingDocument = ref<KnowledgeDocumentRow | null>(null);
const isDeleteOpen = ref(false);
const isDeleting = ref(false);

const openUpload = (): void => {
    editingDocument.value = null;
    isDialogOpen.value = true;
};

const openEdit = (document: KnowledgeDocumentRow): void => {
    editingDocument.value = document;
    isDialogOpen.value = true;
};

const openDelete = (document: KnowledgeDocumentRow): void => {
    deletingDocument.value = document;
    isDeleteOpen.value = true;
};

const closeDelete = (): void => {
    isDeleteOpen.value = false;
};

const confirmDelete = (): void => {
    if (deletingDocument.value === null) {
        return;
    }

    router.delete(destroyDocument.url(deletingDocument.value.id), {
        preserveScroll: true,
        onStart: () => {
            isDeleting.value = true;
        },
        onFinish: () => {
            isDeleting.value = false;
        },
        onSuccess: closeDelete,
    });
};
</script>

<template>
    <section class="flex flex-col gap-4" data-testid="socialhq-documents">
        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4"
        >
            <div class="flex flex-col gap-1">
                <h2 class="text-base font-semibold text-foreground">
                    {{ $t('socialhq_knowledge.documents.title') }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{ $t('socialhq_knowledge.documents.description') }}
                </p>
            </div>
            <Button
                v-if="canEdit && !atLimit"
                class="shrink-0 self-start"
                data-testid="socialhq-document-upload"
                @click="openUpload"
            >
                <IconUpload class="size-4" />
                {{ $t('socialhq_knowledge.documents.upload') }}
            </Button>
        </div>

        <p
            v-if="canEdit && atLimit"
            class="text-sm text-muted-foreground"
            data-testid="socialhq-documents-limit"
        >
            {{
                $t('socialhq_knowledge.documents.limit_reached', {
                    count: String(documents.limit),
                })
            }}
        </p>

        <EmptyState
            v-if="items.length === 0"
            :title="
                canEdit
                    ? $t('socialhq_knowledge.documents.empty')
                    : $t('socialhq_knowledge.documents.empty_member')
            "
            data-testid="socialhq-documents-empty"
        />

        <ul v-else class="flex flex-col gap-2">
            <SettingsListRow
                v-for="document in items"
                :key="document.id"
                :icon="iconOf(document)"
                :data-testid="`socialhq-document-${document.id}`"
            >
                <div class="flex min-w-0 flex-col gap-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="truncate text-sm font-medium text-foreground">
                            {{ document.title }}
                        </p>
                        <Badge
                            v-if="document.status === 'processing'"
                            variant="info"
                            :data-testid="`socialhq-document-status-${document.id}`"
                        >
                            <Spinner class="size-3" />
                            {{
                                $t(
                                    'socialhq_knowledge.documents.status.processing',
                                )
                            }}
                        </Badge>
                        <Badge
                            v-else-if="document.status === 'failed'"
                            variant="destructive-subtle"
                            :data-testid="`socialhq-document-status-${document.id}`"
                        >
                            {{
                                $t('socialhq_knowledge.documents.status.failed')
                            }}
                        </Badge>
                        <template v-else>
                            <Badge
                                variant="success"
                                :data-testid="`socialhq-document-status-${document.id}`"
                            >
                                {{
                                    $t(
                                        'socialhq_knowledge.documents.status.ready',
                                    )
                                }}
                            </Badge>
                            <Badge
                                :variant="
                                    document.use_in_ai ? 'secondary' : 'outline'
                                "
                                :data-testid="`socialhq-document-ai-${document.id}`"
                            >
                                {{
                                    document.use_in_ai
                                        ? $t(
                                              'socialhq_knowledge.documents.in_ai',
                                          )
                                        : $t(
                                              'socialhq_knowledge.documents.not_in_ai',
                                          )
                                }}
                            </Badge>
                        </template>
                    </div>
                    <p class="truncate text-sm text-muted-foreground">
                        <span v-if="document.type">
                            {{
                                $t(
                                    `socialhq_knowledge.documents.types.${document.type}`,
                                )
                            }}
                            ·
                        </span>
                        {{ formatSize(document.size) }}
                        <template
                            v-if="document.status === 'ready' && document.pages"
                        >
                            ·
                            {{
                                $tChoice(
                                    document.type === 'pptx'
                                        ? 'socialhq_knowledge.documents.slides'
                                        : 'socialhq_knowledge.documents.pages',
                                    document.pages,
                                    { count: formatFullNumber(document.pages) },
                                )
                            }}
                        </template>
                        <template v-if="document.status === 'ready'">
                            ·
                            {{
                                $t('socialhq_knowledge.documents.characters', {
                                    count: formatFullNumber(
                                        document.characters,
                                    ),
                                })
                            }}
                        </template>
                    </p>
                    <p
                        v-if="document.status === 'failed' && document.failure"
                        class="text-sm text-destructive-text"
                        :data-testid="`socialhq-document-failure-${document.id}`"
                    >
                        {{
                            $t(
                                `socialhq_knowledge.documents.failures.${document.failure}`,
                            )
                        }}
                    </p>
                    <p
                        v-if="document.status === 'ready' && document.truncated"
                        class="text-sm text-muted-foreground"
                    >
                        {{
                            $t('socialhq_knowledge.documents.truncated', {
                                count: formatFullNumber(document.characters),
                            })
                        }}
                    </p>
                </div>

                <template #actions>
                    <div class="flex shrink-0 items-center gap-1">
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            as="a"
                            :href="downloadDocument.url(document.id)"
                            :aria-label="
                                $t(
                                    'socialhq_knowledge.documents.download_label',
                                    {
                                        title: document.title,
                                    },
                                )
                            "
                            :data-testid="`socialhq-document-download-${document.id}`"
                        >
                            <IconDownload class="size-4" />
                        </Button>
                        <DropdownMenu v-if="canEdit">
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    :aria-label="
                                        $t(
                                            'socialhq_knowledge.documents.more_label',
                                            { title: document.title },
                                        )
                                    "
                                    :data-testid="`socialhq-document-menu-${document.id}`"
                                >
                                    <IconDots class="size-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    :data-testid="`socialhq-document-edit-${document.id}`"
                                    @select="openEdit(document)"
                                >
                                    {{
                                        $t('socialhq_knowledge.documents.edit')
                                    }}
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    variant="destructive"
                                    :data-testid="`socialhq-document-delete-${document.id}`"
                                    @select="openDelete(document)"
                                >
                                    {{
                                        $t(
                                            'socialhq_knowledge.documents.delete',
                                        )
                                    }}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </template>
            </SettingsListRow>
        </ul>

        <KnowledgeDocumentDialog
            v-model:open="isDialogOpen"
            :document="editingDocument"
            :max-megabytes="documents.maxMegabytes"
        />

        <Dialog v-model:open="isDeleteOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {{
                            $t('socialhq_knowledge.documents.delete_title', {
                                title: deletingDocument?.title ?? '',
                            })
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            $t(
                                'socialhq_knowledge.documents.delete_description',
                            )
                        }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button type="button" variant="ghost" @click="closeDelete">
                        {{ $t('socialhq_knowledge.documents.dialog.cancel') }}
                    </Button>
                    <Button
                        variant="destructive"
                        :loading="isDeleting"
                        data-testid="socialhq-document-delete-confirm"
                        @click="confirmDelete"
                    >
                        {{ $t('socialhq_knowledge.documents.confirm_delete') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>
</template>
