<script setup lang="ts">
import { IconFileSpreadsheet } from '@tabler/icons-vue';
import { computed } from 'vue';

import type {
    ImportRow,
    KeywordLimits,
    KeywordVerdict,
} from '@/components/socialhq/keywords/keywords';
import KeywordVerdictPill from '@/components/socialhq/keywords/KeywordVerdictPill.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { formatFullNumber } from '@/lib/utils';

/**
 * A file's keywords before they are imported: each row with its verdict,
 * or why it is skipped. Nothing is saved until "Import N keywords".
 */
const props = defineProps<{
    name: string;
    hasGroupColumn: boolean;
    rows: ImportRow[];
    /** The check's verdict per keyword; empty while it loads. */
    verdicts: Record<string, KeywordVerdict>;
    checking: boolean;
    limits: KeywordLimits;
    saving: boolean;
}>();

const emit = defineEmits<{
    cancel: [];
    confirm: [];
}>();

const adding = computed(() => props.rows.filter((row) => row.add).length);
const warned = computed(
    () =>
        props.rows.filter(
            (row) =>
                row.add &&
                props.verdicts[row.text] !== undefined &&
                props.verdicts[row.text] !== 'good',
        ).length,
);

const cancel = (): void => {
    emit('cancel');
};

const confirm = (): void => {
    emit('confirm');
};
</script>

<template>
    <div
        class="mt-3 rounded-lg border border-border bg-card"
        data-testid="socialhq-keywords-import"
    >
        <div
            class="flex flex-wrap items-center justify-between gap-2 border-b border-border px-4 py-2.5"
        >
            <p class="flex min-w-0 items-center gap-1.5 text-sm font-semibold">
                <IconFileSpreadsheet
                    class="size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <span class="truncate">{{ name }}</span>
            </p>
            <p
                class="text-xs text-muted-foreground"
                data-testid="socialhq-keywords-import-counts"
            >
                {{
                    $t('socialhq.keywords.import.to_add', {
                        count: formatFullNumber(adding),
                    })
                }}
                <template v-if="warned > 0">
                    ·
                    {{
                        $t('socialhq.keywords.import.warned', {
                            count: formatFullNumber(warned),
                        })
                    }}
                </template>
                ·
                {{
                    $t('socialhq.keywords.import.skipped', {
                        count: formatFullNumber(rows.length - adding),
                    })
                }}
            </p>
        </div>
        <p class="px-4 pt-2.5 text-xs text-muted-foreground">
            {{
                hasGroupColumn
                    ? $t('socialhq.keywords.import.group_column')
                    : $t('socialhq.keywords.import.no_group_column')
            }}
            {{ $t('socialhq.keywords.import.checked_now') }}
        </p>
        <ul class="max-h-[260px] overflow-auto px-2 py-1.5">
            <li
                v-for="(row, index) in rows"
                :key="index"
                class="flex flex-wrap items-center gap-2 rounded-md px-2 py-1.5 text-[13px]"
                :class="{ 'text-subtle-foreground': !row.add }"
                :data-testid="`socialhq-keywords-import-row-${index}`"
            >
                <span class="font-medium" :class="{ 'line-through': !row.add }">
                    {{ row.text || $t('socialhq.keywords.import.empty_row') }}
                </span>
                <template v-if="row.add">
                    <KeywordVerdictPill
                        v-if="verdicts[row.text]"
                        :verdict="verdicts[row.text]"
                    />
                    <Skeleton
                        v-else-if="checking"
                        class="h-[22px] w-16 rounded-full"
                    />
                </template>
                <span
                    class="ms-auto text-xs"
                    :class="{ 'text-muted-foreground': row.add }"
                >
                    {{
                        $t(`socialhq.keywords.import.notes.${row.note}`, {
                            max: formatFullNumber(
                                limits.main + limits.supporting,
                            ),
                        })
                    }}
                </span>
            </li>
        </ul>
        <div class="flex justify-end gap-2 border-t border-border px-4 py-2.5">
            <Button
                variant="ghost"
                size="sm"
                :disabled="saving"
                data-testid="socialhq-keywords-import-cancel"
                @click="cancel"
            >
                {{ $t('socialhq.keywords.import.cancel') }}
            </Button>
            <Button
                size="sm"
                :disabled="adding === 0"
                :loading="saving"
                data-testid="socialhq-keywords-import-confirm"
                @click="confirm"
            >
                {{
                    $tChoice('socialhq.keywords.import.confirm', adding, {
                        count: formatFullNumber(adding),
                    })
                }}
            </Button>
        </div>
    </div>
</template>
