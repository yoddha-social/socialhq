<script setup lang="ts">
import { router, useHttp } from '@inertiajs/vue3';
import { IconFileUpload, IconListCheck, IconTag } from '@tabler/icons-vue';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
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
    VERDICTS,
    type ImportRow,
    type Keyword,
    type KeywordCheckProp,
    type KeywordCheckRow,
    type KeywordsProp,
    type KeywordVerdict,
} from '@/components/socialhq/keywords/keywords';
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
 * SEO keywords (Brand knowledge, Strategy tab): add them, paste a list or
 * import a CSV or text file, and see the keyword check. Every change saves
 * the whole list at once; the check comes back with it.
 */
const props = defineProps<{
    keywords: KeywordsProp;
    /** The keyword check; undefined while the deferred prop loads. */
    check: KeywordCheckProp | undefined;
    canEdit: boolean;
}>();

const emit = defineEmits<{
    addEntry: [];
}>();

/** The list shown: what is being saved, else what the server has. */
const pending = ref<Keyword[] | null>(null);
const items = computed<Keyword[]>(() => pending.value ?? props.keywords.items);
const limits = computed(() => props.keywords.limits);
const maxTotal = computed(() => limits.value.main + limits.value.supporting);
const mainCount = (list: Keyword[]): number =>
    list.filter((keyword) => keyword.main).length;
const mainItems = computed(() => items.value.filter((keyword) => keyword.main));
const supportingItems = computed(() =>
    items.value.filter((keyword) => !keyword.main),
);
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
            })),
        },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['keywords', 'keywordCheck'],
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
const group = ref<'main' | 'supporting'>(
    mainCount(props.keywords.items) < props.keywords.limits.main
        ? 'main'
        : 'supporting',
);

/** An input drops line breaks from a pasted list: keep them as commas. */
const pasteList = (event: ClipboardEvent): void => {
    const text = event.clipboardData?.getData('text') ?? '';

    if (!/\r?\n/.test(text.trim())) {
        return;
    }

    event.preventDefault();
    newText.value = [
        newText.value.trim(),
        text.trim().replace(/\s*\r?\n\s*/g, ', '),
    ]
        .filter(Boolean)
        .join(', ');
};

const addFromField = (): void => {
    const texts = splitList(newText.value);

    if (texts.length === 0 || saving.value) {
        return;
    }

    const next = [...items.value];
    const seen = new Set(next.map((keyword) => sameKey(keyword.text)));
    const added: string[] = [];

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
            toast.info(
                trans('socialhq.keywords.toasts.main_full', {
                    max: String(limits.value.main),
                }),
            );
        }

        if (!main && next.length - mainCount(next) >= limits.value.supporting) {
            toast.info(
                trans('socialhq.keywords.toasts.full', {
                    max: String(maxTotal.value),
                }),
            );
            break;
        }

        next.push({ text, main });
        seen.add(key);
        added.push(text);
    }

    if (added.length === 0) {
        return;
    }

    newText.value = '';

    if (mainCount(next) >= limits.value.main) {
        group.value = 'supporting';
    }

    save(
        next,
        () => {
            toast.success(
                added.length === 1
                    ? trans('socialhq.keywords.toasts.added', {
                          keyword: added[0],
                      })
                    : trans('socialhq.keywords.toasts.added_many', {
                          count: String(added.length),
                      }),
            );
        },
        'add',
    );
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

const remove = (keyword: Keyword): void => {
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
};

const addEntry = (): void => {
    emit('addEntry');
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

const cancelImport = (): void => {
    checkHttp.cancel();
    importing.value = null;
};

const confirmImport = (): void => {
    if (!importing.value) {
        return;
    }

    const added = importing.value.rows
        .filter((row) => row.add)
        .map((row) => ({ text: clean(row.text), main: row.main }));
    const warned = added.filter(
        (keyword) =>
            importVerdicts.value[keyword.text] !== undefined &&
            importVerdicts.value[keyword.text] !== 'good',
    ).length;

    save([...items.value, ...added], () => {
        importing.value = null;
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

const promptTexts = computed(
    () => new Set((props.check?.prompt ?? []).map((keyword) => keyword.text)),
);
const notThisTime = computed(() =>
    props.check && items.value.length > limits.value.prompt
        ? items.value
              .filter((keyword) => !promptTexts.value.has(keyword.text))
              .map((keyword) => keyword.text)
        : [],
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
                                        'socialhq.keywords.check.all_to_ai',
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
                                        (main ? mainItems : supportingItems)
                                            .length,
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
                                : $t('socialhq.keywords.groups.supporting_hint')
                        }}
                    </p>
                </div>
                <ul class="overflow-hidden rounded-lg border border-border">
                    <KeywordRow
                        v-for="keyword in main ? mainItems : supportingItems"
                        :key="keyword.text"
                        :keyword="keyword"
                        :row="rowFor(keyword)"
                        :checked="check?.checked ?? null"
                        :open="open.has(keyword.text)"
                        :can-edit="canEdit"
                        @toggle="toggle(keyword)"
                        @make-main="makeMain(keyword)"
                        @make-supporting="makeSupporting(keyword)"
                        @remove="remove(keyword)"
                        @add-entry="addEntry"
                    />
                    <li
                        v-if="(main ? mainItems : supportingItems).length === 0"
                        class="px-3 py-3 text-[13px] text-muted-foreground"
                    >
                        {{ $t('socialhq.keywords.groups.none') }}
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
                            max: formatFullNumber(limits.prompt),
                        })
                    }}
                </p>
                <div v-if="check" class="mt-3 flex flex-wrap gap-1.5">
                    <span
                        v-for="keyword in check.prompt"
                        :key="keyword.text"
                        class="inline-flex items-center gap-1 rounded-full border border-border bg-card px-2 py-0.5 text-xs text-foreground"
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
                <div v-else class="mt-3 flex gap-1.5">
                    <Skeleton class="h-6 w-28 rounded-full" />
                    <Skeleton class="h-6 w-24 rounded-full" />
                </div>
                <p
                    v-if="notThisTime.length > 0"
                    class="mt-1.5 text-xs text-muted-foreground"
                >
                    {{
                        $t('socialhq.keywords.preview.not_this_time', {
                            max: formatFullNumber(limits.prompt),
                            keywords: notThisTime.join(', '),
                        })
                    }}
                </p>
            </div>
        </template>
    </section>
</template>
