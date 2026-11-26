<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { IconDownload, IconExternalLink } from '@tabler/icons-vue';
import { onBeforeUnmount, ref, watch } from 'vue';

import type { SourcePreview } from '@/components/socialhq/brandSources';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { formatFullNumber } from '@/lib/utils';

/**
 * Preview on Brand knowledge: look inside a document, or a "Read our
 * website" suggestion, before deciding on it. Everything shown comes from
 * the app (DocumentPreview): a PDF in the browser's own viewer, framed from
 * our origin; text as text; Markdown rendered by the server without the
 * file's HTML, pictures or scripts; a Word or PowerPoint file as the text
 * read from it; a suggestion's pages as Accept keeps them. The preview is
 * loaded each time the dialog opens. The footer is the `actions` slot
 * (Accept and Skip for a suggestion, with `messages` for what they say
 * back), else Close and Download.
 */
const props = defineProps<{
    /** The preview endpoint. */
    url: string | null;
    title: string;
    description: string;
    /** Where the whole file downloads; none for a suggestion. */
    downloadUrl?: string | null;
}>();

const open = defineModel<boolean>('open', { required: true });

const http = useHttp<Record<string, never>, SourcePreview>({});
const preview = ref<SourcePreview | null>(null);
const failed = ref(false);

/** Counts loads, so an answer for a preview opened earlier is ignored. */
let latestLoad = 0;

const load = async (url: string): Promise<void> => {
    http.cancel();

    const current = ++latestLoad;

    preview.value = null;
    failed.value = false;

    try {
        const response = await http.get(url);

        if (current !== latestLoad) {
            return;
        }

        preview.value = response ?? null;
        failed.value = response === undefined;
    } catch {
        if (current === latestLoad) {
            failed.value = true;
        }
    }
};

watch(
    open,
    (isOpen) => {
        if (isOpen && props.url) {
            void load(props.url);
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    http.cancel();
});

const close = (): void => {
    open.value = false;
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="sm:max-w-3xl"
            data-testid="socialhq-preview-dialog"
        >
            <DialogHeader class="text-start">
                <DialogTitle class="break-words">{{ title }}</DialogTitle>
                <DialogDescription class="break-words">
                    {{ description }}
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="http.processing"
                class="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground"
                data-testid="socialhq-preview-loading"
            >
                <Spinner class="size-4" />
                {{ $t('socialhq_knowledge.preview.loading') }}
            </div>
            <p
                v-else-if="failed"
                role="alert"
                class="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive-text"
                data-testid="socialhq-preview-failed"
            >
                {{ $t('socialhq_knowledge.preview.failed') }}
            </p>
            <div
                v-else-if="preview"
                class="flex min-w-0 flex-col gap-2"
                :data-kind="preview.kind"
                data-testid="socialhq-preview"
            >
                <template v-if="preview.kind === 'pdf' && preview.file_url">
                    <iframe
                        :src="preview.file_url"
                        :title="title"
                        class="h-[65dvh] w-full rounded-lg border bg-muted"
                        data-testid="socialhq-preview-pdf"
                    />
                    <p class="text-sm text-muted-foreground">
                        {{ $t('socialhq_knowledge.preview.pdf_fallback') }}
                        <a
                            :href="preview.file_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 text-primary-text hover:underline"
                            data-testid="socialhq-preview-open-tab"
                        >
                            {{ $t('socialhq_knowledge.preview.open_tab') }}
                            <IconExternalLink class="size-3.5" />
                        </a>
                    </p>
                </template>

                <!-- Rendered by SafeMarkdown on the server: the file's own HTML is escaped, pictures and non-web links removed. -->
                <!-- eslint-disable vue/no-v-html -->
                <div
                    v-else-if="preview.kind === 'markdown'"
                    class="prose prose-sm max-h-[60dvh] max-w-none overflow-y-auto rounded-lg border p-4 break-words text-foreground dark:prose-invert prose-a:text-primary-text"
                    data-testid="socialhq-preview-markdown"
                    v-html="preview.html"
                />
                <!-- eslint-enable vue/no-v-html -->

                <template
                    v-else-if="
                        preview.kind === 'text' || preview.kind === 'extracted'
                    "
                >
                    <p
                        v-if="preview.kind === 'extracted'"
                        class="text-sm text-muted-foreground"
                    >
                        {{ $t('socialhq_knowledge.preview.extracted') }}
                    </p>
                    <pre
                        class="max-h-[60dvh] overflow-y-auto rounded-lg border bg-muted/40 p-4 font-sans text-sm break-words whitespace-pre-wrap text-foreground"
                        data-testid="socialhq-preview-text"
                        >{{ preview.text }}</pre
                    >
                </template>

                <template v-else-if="preview.kind === 'pages'">
                    <p
                        v-if="preview.pages.length === 0"
                        class="text-sm text-muted-foreground"
                        data-testid="socialhq-preview-no-pages"
                    >
                        {{ $t('socialhq_knowledge.website.errors.pages_gone') }}
                    </p>
                    <div
                        v-else
                        class="flex max-h-[60dvh] flex-col gap-5 overflow-y-auto rounded-lg border p-4"
                        data-testid="socialhq-preview-pages"
                    >
                        <section
                            v-for="(page, index) in preview.pages"
                            :key="index"
                            class="flex min-w-0 flex-col gap-1"
                            :data-testid="`socialhq-preview-page-${index}`"
                        >
                            <h3
                                class="text-sm font-semibold break-words text-foreground"
                            >
                                {{ page.title || page.url }}
                            </h3>
                            <p class="text-xs break-all text-muted-foreground">
                                {{ page.url }}
                            </p>
                            <p
                                class="text-sm break-words whitespace-pre-line text-foreground"
                            >
                                {{ page.text }}
                            </p>
                        </section>
                    </div>
                </template>

                <p
                    v-else
                    class="text-sm text-muted-foreground"
                    data-testid="socialhq-preview-none"
                >
                    {{ $t('socialhq_knowledge.preview.none') }}
                </p>

                <p
                    v-if="preview.truncated"
                    class="text-xs text-muted-foreground"
                    data-testid="socialhq-preview-truncated"
                >
                    {{
                        downloadUrl
                            ? $t('socialhq_knowledge.preview.truncated', {
                                  size: formatFullNumber(preview.kilobytes),
                              })
                            : $t('socialhq_knowledge.preview.truncated_pages', {
                                  size: formatFullNumber(preview.kilobytes),
                              })
                    }}
                </p>
            </div>

            <!-- What the actions say back (a refused Accept). -->
            <slot name="messages" />

            <DialogFooter>
                <slot name="actions">
                    <Button type="button" variant="ghost" @click="close">
                        {{ $t('socialhq_knowledge.preview.close') }}
                    </Button>
                    <Button
                        v-if="downloadUrl"
                        as="a"
                        :href="downloadUrl"
                        data-testid="socialhq-preview-download"
                    >
                        <IconDownload class="size-4" />
                        {{ $t('socialhq_knowledge.preview.download') }}
                    </Button>
                </slot>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
