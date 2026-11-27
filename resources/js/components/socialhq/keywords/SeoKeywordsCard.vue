<script setup lang="ts">
import { router, useHttp } from '@inertiajs/vue3';
import {
    IconClockPause,
    IconFileUpload,
    IconListCheck,
    IconTag,
} from '@tabler/icons-vue';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed, nextTick, ref } from 'vue';
import { toast } from 'vue-sonner';

import KeywordImportPanel from '@/components/socialhq/keywords/KeywordImportPanel.vue';
import KeywordRow from '@/components/socialhq/keywords/KeywordRow.vue';
import {
    clean,
    MAX_FILE_BYTES,
    MAX_LENGTH,
    MIN_LENGTH,
    parseKeywordFile,
    planImport,
    sameKey,
    SAMPLE_FILE,
    splitList,
    tagKey,
    VERDICTS,
    type ImportRow,
    type Keyword,
    type KeywordCheckProp,
    type KeywordCheckRow,
    type KeywordsProp,
    type KeywordTag,
    type KeywordVerdict,
} from '@/components/socialhq/keywords/keywords';
import KeywordTagChip from '@/components/socialhq/keywords/KeywordTagChip.vue';
import KeywordTagsDialog from '@/components/socialhq/keywords/KeywordTagsDialog.vue';
import { useDropZone } from '@/components/socialhq/useDropZone';
import TransSlots from '@/components/TransSlots.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { Skeleton } from '@/components/ui/skeleton';
import { formatFullNumber } from '@/lib/utils';
import {
    check as checkKeywords,
    update as updateKeywords,
} from '@/routes/socialhq/brand/keywords';

/**
 * The SEO keyword catalogue (Brand knowledge, Strategy tab): add keywords,
 * paste a list or import a CSV or text file, tag them with pillars,
 * products and occasions, filter by tag, and see the keyword check and
 * which keywords no post said lately. Every change saves the whole list at
 * once; the check comes back with it.
 */
const props = defineProps<{
    keywords: KeywordsProp;
    /** The keyword check; undefined while the deferred prop loads. */
    check: KeywordCheckProp | undefined;
    canEdit: boolean;
    /** The brand's content pillars, to tag keywords with. */
    pillars: string[];
    /** The brand's products and services (knowledge entries of that kind). */
    products: { id: number; title: string }[];
}>();

const emit = defineEmits<{
    /** "Add an entry" on a keyword nothing backs up: the dialog starts from it. */
    addEntry: [keyword: string];
}>();

/** The list shown: what is being saved, else what the server has. */
const pending = ref<Keyword[] | null>(null);
const items = computed<Keyword[]>(() => pending.value ?? props.keywords.items);
const limits = computed(() => props.keywords.limits);
const maxTotal = computed(() => limits.value.main + limits.value.supporting);
const mainCount = (list: Keyword[]): number =>
    list.filter((keyword) => keyword.main).length;
const mainItems = computed(() =>
    items.value.filter((keyword) => keyword.main && shows(keyword)),
);
const supportingItems = computed(() =>
    items.value.filter((keyword) => !keyword.main && shows(keyword)),
);
const allMainCount = computed(() => mainCount(items.value));
const hasKeywords = computed(() => items.value.length > 0);

/** The check of the saved list: a row per keyword, by its text. */
const rows = computed(
    () =>
        new Map<string, KeywordCheckRow>(
            (props.check?.keywords ?? []).map((row) => [row.text, row]),
        ),
);
const rowFor = (keyword: Keyword): KeywordCheckRow | null =>
    rows.value.get(keyword.text) ?? null;

const saving = ref(false);
/** Which control's change is being saved, for its button's spinner. */
const savingFrom = ref<'add' | 'other' | null>(null);

const save = (
    next: Keyword[],
    onSaved?: () => void,
    from: 'add' | 'other' = 'other',
): void => {
    pending.value = next;
    savingFrom.value = from;

    router.put(
        updateKeywords.url(),
        {
            keywords: next.map((keyword) => ({
                text: keyword.text,
                main: keyword.main,
                tags: keyword.tags.map((tag) => ({
                    kind: tag.kind,
                    value: tag.value,
                    entry_id: tag.entry_id,
                    from: tag.from,
                    to: tag.to,
                    label: tag.label,
                })),
            })),
        },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['keywords', 'keywordCheck'],
            // Tags are only in this card: other cards' state stays.
            onStart: () => {
                saving.value = true;
            },
            onSuccess: () => {
                onSaved?.();
            },
            onError: (errors) => {
                toast.error(
                    Object.values(errors)[0] ??
                        trans('socialhq.keywords.toasts.save_failed'),
                );
            },
            onFinish: () => {
                saving.value = false;
                savingFrom.value = null;
                pending.value = null;
            },
        },
    );
};

// ----- Add from the field (one keyword, or a pasted list)

const newText = ref('');

/**
 * The group new keywords go to: Main while a main slot is free, else
 * Supporting, following the list as it changes, until someone picks one.
 */
const pickedGroup = ref<'main' | 'supporting' | null>(null);
const group = computed<'main' | 'supporting'>({
    get: () =>
        pickedGroup.value ??
        (mainCount(items.value) < limits.value.main ? 'main' : 'supporting'),
    set: (value) => {
        pickedGroup.value = value;
    },
});

/**
 * An input drops line breaks from a pasted list: keep them as commas. The
 * list takes the place of any selected text, as a paste does.
 */
const pasteList = (event: ClipboardEvent): void => {
    const text = event.clipboardData?.getData('text') ?? '';

    if (!/\r?\n/.test(text.trim())) {
        return;
    }

    event.preventDefault();

    const input = event.target as HTMLInputElement;
    const list = text.trim().replace(/\s*\r?\n\s*/g, ', ');
    const start = input.selectionStart ?? newText.value.length;
    const end = input.selectionEnd ?? start;
    const before = newText.value.slice(0, start).replace(/[\s,]+$/, '');
    const after = newText.value.slice(end).replace(/^[\s,]+/, '');
    const caret = (before ? before.length + 2 : 0) + list.length;

    newText.value = [before, list, after].filter(Boolean).join(', ');
    void nextTick(() => {
        input.setSelectionRange(caret, caret);
    });
};

const addFromField = (): void => {
    const texts = splitList(newText.value);

    if (texts.length === 0 || saving.value) {
        return;
    }

    const next = [...items.value];
    const seen = new Set(next.map((keyword) => sameKey(keyword.text)));
    const added: string[] = [];
    /** Asked for as main, added as supporting because main was full. */
    let movedToSupporting = 0;

    for (const text of texts) {
        if (text.length < MIN_LENGTH) {
            toast.info(trans('socialhq.keywords.toasts.too_short'));
            continue;
        }

        if (text.length > MAX_LENGTH) {
            toast.info(trans('socialhq.keywords.toasts.too_long'));
            continue;
        }

        const key = sameKey(text);

        if (key === '' || seen.has(key)) {
            toast.info(
                trans('socialhq.keywords.toasts.duplicate', { keyword: text }),
            );
            continue;
        }

        let main = group.value === 'main';

        if (main && mainCount(next) >= limits.value.main) {
            main = false;
            movedToSupporting++;
        }

        if (!main && next.length - mainCount(next) >= limits.value.supporting) {
            toast.info(
                trans('socialhq.keywords.toasts.full', {
                    max: String(maxTotal.value),
                }),
            );
            break;
        }

        next.push({
            text,
            main,
            tags: filterTag.value ? [filterTag.value] : [],
        });
        seen.add(key);
        added.push(text);
    }

    if (added.length === 0) {
        return;
    }

    newText.value = '';

    save(
        next,
        () => {
            toast.success(addedMessage(added, movedToSupporting));
        },
        'add',
    );
};

/** One toast for what was added, saying how many went to supporting because main was full. */
const addedMessage = (added: string[], movedToSupporting: number): string => {
    const max = String(limits.value.main);

    if (added.length === 1) {
        return movedToSupporting > 0
            ? trans('socialhq.keywords.toasts.added_supporting', {
                  keyword: added[0],
                  max,
              })
            : trans('socialhq.keywords.toasts.added', { keyword: added[0] });
    }

    return movedToSupporting > 0
        ? trans('socialhq.keywords.toasts.added_many_supporting', {
              count: String(added.length),
              moved: String(movedToSupporting),
              max,
          })
        : trans('socialhq.keywords.toasts.added_many', {
              count: String(added.length),
          });
};

// ----- Row actions

const open = ref(new Set<string>());

const toggle = (keyword: Keyword): void => {
    const next = new Set(open.value);

    if (next.has(keyword.text)) {
        next.delete(keyword.text);
    } else {
        next.add(keyword.text);
    }

    open.value = next;
};

const setMain = (keyword: Keyword, main: boolean): void => {
    if (main && mainCount(items.value) >= limits.value.main) {
        toast.info(
            trans('socialhq.keywords.toasts.main_full', {
                max: String(limits.value.main),
            }),
        );

        return;
    }

    save(
        items.value.map((item) =>
            item.text === keyword.text ? { ...item, main } : item,
        ),
    );
};

const makeMain = (keyword: Keyword): void => {
    setMain(keyword, true);
};

const makeSupporting = (keyword: Keyword): void => {
    setMain(keyword, false);
};

/** Each shown row, to put the keyboard on it. */
const rowRefs = new Map<string, { focus: () => void }>();

const setRowRef = (text: string, row: unknown): void => {
    if (row) {
        rowRefs.set(text, row as { focus: () => void });
    } else {
        rowRefs.delete(text);
    }
};

const focusField = (): void => {
    document.getElementById('socialhq-keyword-input')?.focus();
};

const remove = (keyword: Keyword): void => {
    // The row goes at once, with the focus inside it: keep the keyboard in
    // the list, on the row after it (else the one before, else the field).
    const shown = [...mainItems.value, ...supportingItems.value];
    const index = shown.findIndex((item) => item.text === keyword.text);
    const neighbour = shown[index + 1] ?? shown[index - 1] ?? null;

    save(
        items.value.filter((item) => item.text !== keyword.text),
        () => {
            toast.success(
                trans('socialhq.keywords.toasts.removed', {
                    keyword: keyword.text,
                }),
            );
        },
    );

    void nextTick(() => {
        const row = neighbour ? rowRefs.get(neighbour.text) : undefined;

        if (row) {
            row.focus();
        } else {
            focusField();
        }
    });
};

const addEntry = (keyword: Keyword): void => {
    emit('addEntry', keyword.text);
};

// ----- Tags

const tagging = ref<Keyword | null>(null);
const tagsOpen = ref(false);

const editTags = (keyword: Keyword): void => {
    tagging.value = keyword;
    tagsOpen.value = true;
};

const saveTags = (tags: KeywordTag[]): void => {
    const keyword = tagging.value;

    if (!keyword || saving.value) {
        return;
    }

    save(
        items.value.map((item) =>
            item.text === keyword.text ? { ...item, tags } : item,
        ),
        () => {
            tagsOpen.value = false;
            toast.success(
                trans('socialhq.keywords.toasts.tags_saved', {
                    keyword: keyword.text,
                }),
            );
        },
    );
};

// ----- Filter by tag

/** 'all', 'general', 'stale' or a tag's key (tagKey()). */
const filter = ref('all');

/** Every tag in use, once, with how many keywords carry it. */
const tagFilters = computed(() => {
    const found = new Map<string, { tag: KeywordTag; count: number }>();

    for (const keyword of items.value) {
        for (const tag of keyword.tags) {
            const key = tagKey(tag);
            const known = found.get(key);

            found.set(key, { tag, count: (known?.count ?? 0) + 1 });
        }
    }

    return [...found.entries()].map(([key, { tag, count }]) => ({
        key,
        tag,
        count,
    }));
});

const staleTexts = computed(
    () =>
        new Set(
            (props.check?.keywords ?? [])
                .filter((row) => row.stale)
                .map((row) => row.text),
        ),
);

const generalCount = computed(
    () =>
        items.value.filter(
            (keyword) => !keyword.main && keyword.tags.length === 0,
        ).length,
);

/** The tag new keywords get: the one the filter shows. */
const filterTag = computed<KeywordTag | null>(
    () =>
        tagFilters.value.find((option) => option.key === filter.value)?.tag ??
        null,
);

const shows = (keyword: Keyword): boolean => {
    switch (filter.value) {
        case 'all':
            return true;
        case 'general':
            return !keyword.main && keyword.tags.length === 0;
        case 'stale':
            return staleTexts.value.has(keyword.text);
        default:
            return keyword.tags.some((tag) => tagKey(tag) === filter.value);
    }
};

const setFilter = (value: string): void => {
    filter.value = value;
};

const showStale = (): void => {
    setFilter('stale');
    void nextTick(() => {
        document
            .getElementById('socialhq-keywords-filters')
            ?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    });
};

// ----- Import a CSV or text file (read here, checked, then saved like any change)

const fileInput = ref<HTMLInputElement | null>(null);
const importing = ref<{
    name: string;
    hasGroupColumn: boolean;
    rows: ImportRow[];
} | null>(null);
const importVerdicts = ref<Record<string, KeywordVerdict>>({});
const checkHttp = useHttp<
    { keywords: string[] },
    { keywords: KeywordCheckRow[] }
>({ keywords: [] });

const pickFile = (): void => {
    fileInput.value?.click();
};

const readFile = async (file: File): Promise<void> => {
    if (!/\.(csv|txt)$/i.test(file.name)) {
        toast.error(
            trans('socialhq.keywords.import.errors.type', { name: file.name }),
        );

        return;
    }

    if (file.size > MAX_FILE_BYTES) {
        toast.error(
            trans('socialhq.keywords.import.errors.size', { name: file.name }),
        );

        return;
    }

    let text: string;

    try {
        text = await file.text();
    } catch {
        toast.error(
            trans('socialhq.keywords.import.errors.read', { name: file.name }),
        );

        return;
    }

    const parsed = parseKeywordFile(file.name, text);

    if (parsed.rows.every((row) => row.text === '')) {
        toast.error(
            trans('socialhq.keywords.import.errors.empty', { name: file.name }),
        );

        return;
    }

    const planned = planImport(parsed, items.value, limits.value);

    importing.value = {
        name: file.name,
        hasGroupColumn: parsed.hasGroupColumn,
        rows: planned,
    };
    importVerdicts.value = {};

    const toCheck = planned.filter((row) => row.add).map((row) => row.text);

    if (toCheck.length === 0) {
        return;
    }

    checkHttp.keywords = toCheck;

    try {
        const result = await checkHttp.post(checkKeywords.url());

        if (result && importing.value?.name === file.name) {
            importVerdicts.value = Object.fromEntries(
                result.keywords.map((row) => [row.text, row.verdict]),
            );
        }
    } catch {
        toast.error(
            trans('socialhq.keywords.import.errors.check', { name: file.name }),
        );
    }
};

const chooseFile = (event: Event): void => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    input.value = '';

    if (file) {
        void readFile(file);
    }
};

/** The preview closes with the focus inside it: back to the field. */
const closeImport = (): void => {
    importing.value = null;
    void nextTick(focusField);
};

const cancelImport = (): void => {
    checkHttp.cancel();
    closeImport();
};

const confirmImport = (): void => {
    if (!importing.value) {
        return;
    }

    const added = importing.value.rows
        .filter((row) => row.add)
        .map((row) => ({ text: clean(row.text), main: row.main, tags: [] }));
    const warned = added.filter(
        (keyword) =>
            importVerdicts.value[keyword.text] !== undefined &&
            importVerdicts.value[keyword.text] !== 'good',
    ).length;

    save([...items.value, ...added], () => {
        closeImport();
        toast.success(
            warned > 0
                ? trans('socialhq.keywords.toasts.imported_warned', {
                      count: String(added.length),
                      warned: String(warned),
                  })
                : transChoice(
                      'socialhq.keywords.toasts.imported',
                      added.length,
                      {
                          count: String(added.length),
                      },
                  ),
        );
    });
};

const { dragging, onDragEnter, onDragLeave, onDrop } = useDropZone((files) => {
    if (props.canEdit && files[0]) {
        void readFile(files[0]);
    }
});

const downloadSample = (): void => {
    const url = URL.createObjectURL(
        new Blob([SAMPLE_FILE], { type: 'text/csv' }),
    );
    const link = document.createElement('a');

    link.href = url;
    link.download = 'seo-keywords-sample.csv';
    link.click();
    URL.revokeObjectURL(url);
};

// ----- The check's summary and "What a post gets"

const BAR_COLOURS: Record<KeywordVerdict, string> = {
    good: 'bg-emerald-500',
    check: 'bg-amber-400',
    weak: 'bg-stone-300 dark:bg-stone-600',
    conflict: 'bg-red-500',
};

const total = computed(() =>
    VERDICTS.reduce(
        (sum, verdict) => sum + (props.check?.counts[verdict] ?? 0),
        0,
    ),
);

/** "1 conflict · 2 weak · 2 to check", as lang keys and counts. */
const summaryParts = computed(() =>
    (['conflict', 'weak', 'check'] as const)
        .map((verdict) => ({
            verdict,
            count: props.check?.counts[verdict] ?? 0,
        }))
        .filter((part) => part.count > 0),
);
</script>

<template>
    <section
        id="socialhq-seo-keywords"
        class="scroll-mt-4 rounded-xl border border-border-strong bg-card p-5"
        :class="{
            'outline-2 outline-offset-2 outline-primary-strong outline-dashed':
                canEdit && dragging,
        }"
        data-testid="socialhq-seo-keywords"
        @dragenter.prevent="onDragEnter"
        @dragover.prevent
        @dragleave.prevent="onDragLeave"
        @drop.prevent="onDrop"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="max-w-2xl">
                <h2 class="text-base font-semibold text-foreground">
                    {{ $t('socialhq.keywords.title') }}
                </h2>
                <p class="mt-0.5 text-sm text-muted-foreground">
                    {{ $t('socialhq.keywords.description') }}
                </p>
            </div>
            <span
                v-if="hasKeywords"
                class="text-xs text-muted-foreground tabular-nums"
                data-testid="socialhq-keywords-count"
            >
                {{
                    $t('socialhq.keywords.count', {
                        count: formatFullNumber(items.length),
                        max: formatFullNumber(maxTotal),
                    })
                }}
            </span>
        </div>

        <div v-if="canEdit" class="mt-4">
            <form
                class="flex flex-wrap items-center gap-2"
                data-testid="socialhq-keywords-form"
                @submit.prevent="addFromField"
            >
                <div class="relative min-w-[240px] flex-1">
                    <IconTag
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-subtle-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        id="socialhq-keyword-input"
                        v-model="newText"
                        class="h-9 ps-9"
                        :aria-label="$t('socialhq.keywords.field.label')"
                        :placeholder="$t('socialhq.keywords.field.placeholder')"
                        autocomplete="off"
                        data-testid="socialhq-keywords-input"
                        @paste="pasteList"
                    />
                </div>
                <NativeSelect
                    v-model="group"
                    class="font-normal"
                    :aria-label="$t('socialhq.keywords.field.group')"
                    data-testid="socialhq-keywords-group"
                >
                    <NativeSelectOption value="main">
                        {{ $t('socialhq.keywords.field.main') }}
                    </NativeSelectOption>
                    <NativeSelectOption value="supporting">
                        {{ $t('socialhq.keywords.field.supporting') }}
                    </NativeSelectOption>
                </NativeSelect>
                <Button
                    type="submit"
                    class="h-9 px-4"
                    :loading="savingFrom === 'add'"
                    data-testid="socialhq-keywords-add"
                >
                    {{ $t('socialhq.keywords.field.add') }}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    class="h-9"
                    data-testid="socialhq-keywords-import-open"
                    @click="pickFile"
                >
                    <IconFileUpload class="size-4" />
                    {{ $t('socialhq.keywords.field.import') }}
                </Button>
                <input
                    ref="fileInput"
                    type="file"
                    accept=".csv,.txt,text/csv,text/plain"
                    class="hidden"
                    data-testid="socialhq-keywords-file"
                    @change="chooseFile"
                />
            </form>
            <p class="mt-1.5 text-xs text-muted-foreground">
                {{
                    hasKeywords
                        ? $t('socialhq.keywords.field.hint_drop')
                        : $t('socialhq.keywords.field.hint')
                }}
                <span
                    v-if="filterTag"
                    class="font-medium text-foreground"
                    data-testid="socialhq-keywords-hint-tag"
                >
                    {{
                        $t('socialhq.keywords.field.hint_tag', {
                            tag: filterTag.label,
                        })
                    }}
                </span>
            </p>
            <KeywordImportPanel
                v-if="importing"
                :name="importing.name"
                :has-group-column="importing.hasGroupColumn"
                :rows="importing.rows"
                :verdicts="importVerdicts"
                :checking="checkHttp.processing"
                :limits="limits"
                :saving="saving"
                @cancel="cancelImport"
                @confirm="confirmImport"
            />
        </div>

        <template v-if="!hasKeywords">
            <div
                v-if="canEdit"
                class="mt-4 rounded-lg border border-dashed px-4 py-6 text-center transition-colors"
                :class="
                    dragging
                        ? 'border-primary-strong bg-accent'
                        : 'border-border-strong'
                "
                data-testid="socialhq-keywords-drop"
            >
                <span
                    class="mx-auto inline-flex size-9 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                >
                    <IconFileUpload class="size-[18px]" aria-hidden="true" />
                </span>
                <p class="mt-2 text-sm font-medium text-foreground">
                    {{ $t('socialhq.keywords.empty.title') }}
                </p>
                <p
                    class="mx-auto mt-1 max-w-md text-[13px] text-muted-foreground"
                >
                    <TransSlots :message="$t('socialhq.keywords.empty.body')">
                        <template #choose>
                            <button
                                type="button"
                                class="font-medium text-primary-text hover:underline"
                                data-testid="socialhq-keywords-choose-file"
                                @click="pickFile"
                            >
                                {{ $t('socialhq.keywords.empty.choose') }}
                            </button>
                        </template>
                    </TransSlots>
                </p>
                <p class="mx-auto mt-2 max-w-md text-xs text-subtle-foreground">
                    {{ $t('socialhq.keywords.empty.format') }}
                    <button
                        type="button"
                        class="text-primary-text hover:underline"
                        data-testid="socialhq-keywords-sample"
                        @click="downloadSample"
                    >
                        {{ $t('socialhq.keywords.empty.sample') }}
                    </button>
                </p>
            </div>
            <div
                v-else
                class="mt-4 rounded-lg border border-dashed border-border-strong px-4 py-6 text-center"
                data-testid="socialhq-keywords-empty-member"
            >
                <span
                    class="mx-auto inline-flex size-9 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                >
                    <IconTag class="size-[18px]" aria-hidden="true" />
                </span>
                <p class="mt-2 text-sm font-medium text-foreground">
                    {{ $t('socialhq.keywords.empty.member_title') }}
                </p>
                <p
                    class="mx-auto mt-1 max-w-md text-[13px] text-muted-foreground"
                >
                    {{ $t('socialhq.keywords.empty.member_body') }}
                </p>
            </div>
        </template>

        <template v-else>
            <div
                class="mt-5 rounded-lg bg-muted/60 p-4"
                data-testid="socialhq-keywords-check"
            >
                <template v-if="check && check.verdict">
                    <div
                        class="grid gap-4 md:grid-cols-[1fr_260px] md:items-start"
                    >
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <p
                                    class="text-[13px] font-semibold text-foreground"
                                >
                                    {{ $t('socialhq.keywords.check.title') }}
                                </p>
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="
                                        check.verdict === 'on_brand'
                                            ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400'
                                            : 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-400'
                                    "
                                    data-testid="socialhq-keywords-brand-verdict"
                                >
                                    <span
                                        class="size-1.5 rounded-full bg-current"
                                        aria-hidden="true"
                                    />
                                    {{
                                        $t(
                                            `socialhq.keywords.check.brand.${check.verdict}`,
                                        )
                                    }}
                                </span>
                            </div>
                            <p
                                class="mt-2 text-[15px] font-semibold text-foreground"
                            >
                                {{
                                    $tChoice(
                                        'socialhq.keywords.check.fit',
                                        total,
                                        {
                                            good: formatFullNumber(
                                                check.counts.good,
                                            ),
                                            total: formatFullNumber(total),
                                        },
                                    )
                                }}
                            </p>
                            <p class="mt-0.5 text-sm text-muted-foreground">
                                <template
                                    v-for="(part, index) in summaryParts"
                                    :key="part.verdict"
                                >
                                    {{ index > 0 ? ' · ' : ''
                                    }}{{
                                        $tChoice(
                                            `socialhq.keywords.summary.${part.verdict}`,
                                            part.count,
                                            {
                                                count: formatFullNumber(
                                                    part.count,
                                                ),
                                            },
                                        )
                                    }}{{
                                        index === summaryParts.length - 1
                                            ? '. '
                                            : ''
                                    }}
                                </template>
                                {{
                                    $tChoice(
                                        canEdit
                                            ? 'socialhq.keywords.check.all_to_ai'
                                            : 'socialhq.keywords.check.all_to_ai_member',
                                        total,
                                        {
                                            count: formatFullNumber(total),
                                        },
                                    )
                                }}
                            </p>
                        </div>
                        <div class="md:pt-1">
                            <div
                                class="flex h-2 overflow-hidden rounded-full bg-accent"
                                aria-hidden="true"
                            >
                                <template
                                    v-for="verdict in VERDICTS"
                                    :key="verdict"
                                >
                                    <span
                                        v-if="check.counts[verdict] > 0"
                                        class="h-full"
                                        :class="BAR_COLOURS[verdict]"
                                        :style="{
                                            width: `${(check.counts[verdict] / Math.max(1, total)) * 100}%`,
                                        }"
                                    />
                                </template>
                            </div>
                            <dl
                                class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs"
                            >
                                <div
                                    v-for="verdict in VERDICTS"
                                    :key="verdict"
                                    class="flex items-center gap-1.5 whitespace-nowrap"
                                >
                                    <span
                                        class="size-2 rounded-sm"
                                        :class="BAR_COLOURS[verdict]"
                                        aria-hidden="true"
                                    />
                                    <dt class="text-muted-foreground">
                                        {{
                                            $t(
                                                `socialhq.keywords.verdicts.${verdict}`,
                                            )
                                        }}
                                    </dt>
                                    <dd
                                        class="font-medium text-foreground tabular-nums"
                                    >
                                        {{
                                            formatFullNumber(
                                                check.counts[verdict],
                                            )
                                        }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                    <p
                        class="mt-3 max-w-2xl border-t border-border pt-3 text-xs text-muted-foreground"
                    >
                        <IconListCheck
                            class="inline size-3.5 align-[-2px]"
                            aria-hidden="true"
                        />
                        {{
                            $t('socialhq.keywords.check.checked', {
                                entries: $tChoice(
                                    'socialhq.keywords.check.entries',
                                    check.checked.entries,
                                    {
                                        count: formatFullNumber(
                                            check.checked.entries,
                                        ),
                                    },
                                ),
                                documents: $tChoice(
                                    'socialhq.keywords.check.documents',
                                    check.checked.documents,
                                    {
                                        count: formatFullNumber(
                                            check.checked.documents,
                                        ),
                                    },
                                ),
                                posts: $tChoice(
                                    'socialhq.keywords.check.posts',
                                    check.checked.posts,
                                    {
                                        count: formatFullNumber(
                                            check.checked.posts,
                                        ),
                                    },
                                ),
                                days: String(check.checked.days),
                            })
                        }}
                    </p>
                    <p
                        v-if="check.stale.count > 0"
                        class="mt-2 text-xs text-amber-700 dark:text-amber-400"
                        data-testid="socialhq-keywords-stale-summary"
                    >
                        <IconClockPause
                            class="inline size-3.5 align-[-2px]"
                            aria-hidden="true"
                        />
                        {{
                            $tChoice(
                                'socialhq.keywords.check.stale',
                                check.stale.count,
                                {
                                    count: formatFullNumber(check.stale.count),
                                    days: String(check.stale.days),
                                },
                            )
                        }}
                        <button
                            type="button"
                            class="ms-1 font-medium text-primary-text hover:underline"
                            data-testid="socialhq-keywords-stale-show"
                            @click="showStale"
                        >
                            {{ $t('socialhq.keywords.check.stale_show') }}
                        </button>
                    </p>
                </template>
                <div
                    v-else
                    class="flex flex-col gap-2"
                    role="status"
                    :aria-label="$t('socialhq.keywords.check.loading')"
                >
                    <Skeleton class="h-4 w-40" />
                    <Skeleton class="h-5 w-64" />
                    <Skeleton class="h-4 w-full max-w-md" />
                </div>
            </div>

            <div
                id="socialhq-keywords-filters"
                class="mt-5 flex scroll-mt-4 flex-wrap items-center gap-1.5"
                role="group"
                :aria-label="$t('socialhq.keywords.filter.label')"
                data-testid="socialhq-keywords-filters"
            >
                <span class="me-1 text-xs font-medium text-muted-foreground">
                    {{ $t('socialhq.keywords.filter.label') }}
                </span>
                <button
                    v-for="option in [
                        {
                            key: 'all',
                            label: $t('socialhq.keywords.filter.all'),
                            count: items.length,
                        },
                        {
                            key: 'general',
                            label: $t('socialhq.keywords.filter.general'),
                            count: generalCount,
                        },
                    ]"
                    :key="option.key"
                    type="button"
                    class="inline-flex h-7 items-center gap-1 rounded-full border px-2.5 text-xs font-medium transition-colors"
                    :class="
                        filter === option.key
                            ? 'border-primary-strong bg-primary-subtle text-primary-text'
                            : 'border-border bg-card text-muted-foreground hover:bg-accent'
                    "
                    :aria-pressed="filter === option.key"
                    :data-testid="`socialhq-keywords-filter-${option.key}`"
                    @click="setFilter(option.key)"
                >
                    {{ option.label }}
                    <span class="tabular-nums opacity-70">{{
                        formatFullNumber(option.count)
                    }}</span>
                </button>
                <button
                    v-for="(option, index) in tagFilters"
                    :key="option.key"
                    type="button"
                    class="rounded-full ring-offset-1 transition-shadow"
                    :class="
                        filter === option.key
                            ? 'ring-2 ring-primary-strong'
                            : 'hover:ring-1 hover:ring-border-strong'
                    "
                    :aria-pressed="filter === option.key"
                    :data-testid="`socialhq-keywords-filter-tag-${index}`"
                    @click="setFilter(option.key)"
                >
                    <KeywordTagChip
                        :kind="option.tag.kind"
                        :label="`${option.tag.label} · ${formatFullNumber(option.count)}`"
                    />
                </button>
                <button
                    v-if="(check?.stale.count ?? 0) > 0"
                    type="button"
                    class="inline-flex h-7 items-center gap-1 rounded-full border px-2.5 text-xs font-medium transition-colors"
                    :class="
                        filter === 'stale'
                            ? 'border-amber-500 bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-400'
                            : 'border-border bg-card text-muted-foreground hover:bg-accent'
                    "
                    :aria-pressed="filter === 'stale'"
                    data-testid="socialhq-keywords-filter-stale"
                    @click="setFilter('stale')"
                >
                    <IconClockPause class="size-3.5" aria-hidden="true" />
                    {{ $t('socialhq.keywords.filter.stale') }}
                    <span class="tabular-nums opacity-70">{{
                        formatFullNumber(check?.stale.count ?? 0)
                    }}</span>
                </button>
            </div>

            <div
                v-for="main in [true, false]"
                :key="String(main)"
                class="mt-5"
                :data-testid="`socialhq-keywords-group-${main ? 'main' : 'supporting'}`"
            >
                <div
                    class="mb-2 flex flex-wrap items-baseline justify-between gap-x-3"
                >
                    <p class="text-[13px] font-semibold text-foreground">
                        {{
                            main
                                ? $t('socialhq.keywords.groups.main')
                                : $t('socialhq.keywords.groups.supporting')
                        }}
                        <span
                            class="font-normal text-muted-foreground tabular-nums"
                        >
                            ·
                            {{
                                $t('socialhq.keywords.count', {
                                    count: formatFullNumber(
                                        main
                                            ? allMainCount
                                            : items.length - allMainCount,
                                    ),
                                    max: formatFullNumber(
                                        main ? limits.main : limits.supporting,
                                    ),
                                })
                            }}
                        </span>
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{
                            main
                                ? $t('socialhq.keywords.groups.main_hint')
                                : $t(
                                      'socialhq.keywords.groups.supporting_hint',
                                      { count: String(limits.per_post) },
                                  )
                        }}
                    </p>
                </div>
                <ul class="overflow-hidden rounded-lg border border-border">
                    <KeywordRow
                        v-for="keyword in main ? mainItems : supportingItems"
                        :key="keyword.text"
                        :ref="(row) => setRowRef(keyword.text, row)"
                        :keyword="keyword"
                        :row="rowFor(keyword)"
                        :checked="check?.checked ?? null"
                        :stale-days="check?.stale.days ?? null"
                        :open="open.has(keyword.text)"
                        :can-edit="canEdit"
                        @toggle="toggle(keyword)"
                        @edit-tags="editTags(keyword)"
                        @make-main="makeMain(keyword)"
                        @make-supporting="makeSupporting(keyword)"
                        @remove="remove(keyword)"
                        @add-entry="addEntry(keyword)"
                    />
                    <li
                        v-if="(main ? mainItems : supportingItems).length === 0"
                        class="px-3 py-3 text-[13px] text-muted-foreground"
                    >
                        {{
                            filter === 'all'
                                ? $t('socialhq.keywords.groups.none')
                                : $t('socialhq.keywords.groups.none_filtered')
                        }}
                    </li>
                </ul>
            </div>

            <div
                class="mt-5 rounded-lg border border-border p-4"
                data-testid="socialhq-keywords-preview"
            >
                <p class="text-[13px] font-semibold text-foreground">
                    {{ $t('socialhq.keywords.preview.title') }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{
                        $t('socialhq.keywords.preview.description', {
                            count: String(limits.per_post),
                        })
                    }}
                </p>
                <div
                    v-if="check && check.prompt.length > 0"
                    class="mt-3 flex flex-wrap gap-1.5"
                >
                    <span
                        v-for="keyword in check.prompt"
                        :key="keyword.text"
                        class="inline-flex items-center gap-1 rounded-full border border-border bg-card px-2 py-0.5 text-xs text-foreground"
                        :title="
                            keyword.matched
                                .map((tag) => tag.label)
                                .join(', ') || undefined
                        "
                        data-testid="socialhq-keywords-preview-keyword"
                    >
                        <span
                            v-if="keyword.main"
                            class="text-[10.5px] font-semibold tracking-wide text-primary-text uppercase"
                        >
                            {{ $t('socialhq.keywords.preview.main') }}
                        </span>
                        {{ keyword.text }}
                    </span>
                </div>
                <p v-else-if="check" class="mt-3 text-xs text-muted-foreground">
                    {{ $t('socialhq.keywords.preview.none') }}
                </p>
                <div v-else class="mt-3 flex gap-1.5">
                    <Skeleton class="h-6 w-28 rounded-full" />
                    <Skeleton class="h-6 w-24 rounded-full" />
                </div>
            </div>
        </template>

        <KeywordTagsDialog
            v-if="canEdit"
            v-model:open="tagsOpen"
            :keyword="tagging"
            :pillars="pillars"
            :products="products"
            :occasions="keywords.occasions"
            :occasion-days="keywords.occasion_days"
            :max-tags="limits.tags"
            :saving="saving"
            @save="saveTags"
        />
    </section>
</template>
