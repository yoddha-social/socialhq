<script setup lang="ts">
import { router, usePoll } from '@inertiajs/vue3';
import {
    IconBrandGoogleDrive,
    IconDots,
    IconDownload,
    IconExternalLink,
    IconFileText,
    IconFileTypeDocx,
    IconFileTypePdf,
    IconFileTypePpt,
    IconFileTypeTxt,
    IconMarkdown,
    IconUpload,
    IconWorld,
} from '@tabler/icons-vue';
import { computed, ref, watch, type Component } from 'vue';

import EmptyState from '@/components/EmptyState.vue';
import SettingsListRow from '@/components/settings/SettingsListRow.vue';
import KnowledgeDocumentDialog, {
    type KnowledgeDocumentRow,
} from '@/components/socialhq/KnowledgeDocumentDialog.vue';
import KnowledgeLinkDialog from '@/components/socialhq/KnowledgeLinkDialog.vue';
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
    refresh as refreshDocument,
} from '@/routes/socialhq/brand/documents';

export interface KnowledgeDocumentsProp {
    items: KnowledgeDocumentRow[];
    limit: number;
    /** Uploads and links: the website's text doesn't count toward the limit. */
    counted: number;
    maxMegabytes: number;
}

/**
 * The Documents section of Brand knowledge: brochures, price lists and decks
 * whose matching parts the AI reads, uploaded or linked from Google Docs,
 * Sheets and Slides (read again on request), and the website's text kept
 * from "Read our website". Admins add, edit and delete them; everyone who
 * reads brand knowledge downloads them. While a document is being read,
 * the list reloads every few seconds.
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
const atLimit = computed(
    () => props.documents.counted >= props.documents.limit,
);
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

const isGoogle = (document: KnowledgeDocumentRow): boolean =>
    document.source.startsWith('google_');

const iconOf = (document: KnowledgeDocumentRow): Component => {
    if (isGoogle(document)) {
        return IconBrandGoogleDrive;
    }

    if (document.source === 'website') {
        return IconWorld;
    }

    return (document.type && TYPE_ICONS[document.type]) || IconFileText;
};

const formatSize = (bytes: number): string =>
    bytes >= 1024 * 1024
        ? `${(bytes / (1024 * 1024)).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;

const isDialogOpen = ref(false);
const editingDocument = ref<KnowledgeDocumentRow | null>(null);
const deletingDocument = ref<KnowledgeDocumentRow | null>(null);
const isDeleteOpen = ref(false);
const isDeleting = ref(false);

const isLinkDialogOpen = ref(false);
const refreshing = ref<number | null>(null);

const openUpload = (): void => {
    editingDocument.value = null;
    isDialogOpen.value = true;
};

const openAddLink = (): void => {
    isLinkDialogOpen.value = true;
};

const readAgain = (document: KnowledgeDocumentRow): void => {
    router.post(
        refreshDocument.url(document.id),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                refreshing.value = document.id;
            },
            onFinish: () => {
                refreshing.value = null;
            },
        },
    );
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
            <div
                v-if="canEdit && !atLimit"
                class="flex shrink-0 flex-wrap gap-2 self-start"
            >
                <Button
                    variant="outline"
                    data-testid="socialhq-document-add-link"
                    @click="openAddLink"
                >
                    <IconBrandGoogleDrive class="size-4" />
                    {{ $t('socialhq_knowledge.documents.add_link') }}
                </Button>
                <Button
                    data-testid="socialhq-document-upload"
                    @click="openUpload"
                >
                    <IconUpload class="size-4" />
                    {{ $t('socialhq_knowledge.documents.upload') }}
                </Button>
            </div>
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
                        <span v-if="document.source !== 'upload'">
                            {{
                                $t(
                                    `socialhq_knowledge.documents.sources.${document.source}`,
                                )
                            }}
                            ·
                        </span>
                        <span v-else-if="document.type">
                            {{
                                $t(
                                    `socialhq_knowledge.documents.types.${document.type}`,
                                )
                            }}
                            ·
                        </span>
                        <template v-if="document.source === 'upload'">
                            {{ formatSize(document.size) }}
                        </template>
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
                        v-if="document.source === 'website' && canEdit"
                        class="text-sm text-muted-foreground"
                    >
                        {{ $t('socialhq_knowledge.documents.website_note') }}
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
                            v-if="isGoogle(document) && document.source_url"
                            variant="ghost"
                            size="icon-sm"
                            as="a"
                            :href="document.source_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            :aria-label="
                                $t(
                                    'socialhq_knowledge.documents.open_source_label',
                                    { title: document.title },
                                )
                            "
                            :data-testid="`socialhq-document-source-${document.id}`"
                        >
                            <IconExternalLink class="size-4" />
                        </Button>
                        <Button
                            v-if="
                                document.source === 'upload' ||
                                document.status === 'ready'
                            "
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
                                    v-if="isGoogle(document)"
                                    :disabled="
                                        document.status === 'processing' ||
                                        refreshing === document.id
                                    "
                                    :data-testid="`socialhq-document-refresh-${document.id}`"
                                    @select="readAgain(document)"
                                >
                                    {{
                                        $t(
                                            'socialhq_knowledge.documents.read_again',
                                        )
                                    }}
                                </DropdownMenuItem>
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

        <KnowledgeLinkDialog v-model:open="isLinkDialogOpen" />

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
