<script setup lang="ts">
import { IconTag } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import type {
    KeywordPanel,
    PickedKeyword,
} from '@/components/socialhq/keywords/keywords';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { filterBySearch } from '@/lib/search';
import { formatFullNumber } from '@/lib/utils';

/**
 * "Keywords for this post": the few SEO keywords the app picked for one
 * post (KeywordPicker), the only ones its AI writer gets. "Change" lets a
 * person pick others from the brand's catalogue for this post, or go back
 * to the app's pick; the parent saves or sends what they leave.
 */
const props = defineProps<{
    panel: KeywordPanel;
    disabled?: boolean;
}>();

const emit = defineEmits<{
    /** The keywords chosen for this post, or null for the app's pick. */
    change: [texts: string[] | null];
}>();

const open = ref(false);
const search = ref('');

const selected = computed(() =>
    props.panel.picked.map((keyword) => keyword.text),
);
const isFull = computed(() => selected.value.length >= props.panel.max);

const options = computed(() =>
    filterBySearch(props.panel.catalogue, search.value, (keyword) => [
        keyword.text,
        ...keyword.tags.map((tag) => tag.label),
    ]),
);

/** Why the app picked it: the tags that fit, else main or general. */
const why = (keyword: PickedKeyword): string => {
    if (props.panel.chosen) {
        return trans('socialhq.keywords.for_post.why_chosen');
    }

    if (keyword.matched.length > 0) {
        return keyword.matched.map((tag) => tag.label).join(', ');
    }

    return keyword.main
        ? trans('socialhq.keywords.for_post.why_main')
        : trans('socialhq.keywords.for_post.why_general');
};

/** The keyword in test ids, without spaces. */
const slug = (text: string): string => text.replace(/\s+/g, '-');

const toggle = (text: string, on: boolean | 'indeterminate'): void => {
    const next =
        on === true
            ? isFull.value || selected.value.includes(text)
                ? selected.value
                : [...selected.value, text]
            : selected.value.filter((other) => other !== text);

    emit('change', next);
};

const reset = (): void => {
    emit('change', null);
};
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-1.5 text-[12.5px] text-muted-foreground"
        data-testid="socialhq-keywords-for-post"
    >
        <IconTag
            class="size-3.5 shrink-0 text-subtle-foreground"
            aria-hidden="true"
        />
        <span>{{ $t('socialhq.keywords.for_post.label') }}</span>
        <span
            v-for="keyword in panel.picked"
            :key="keyword.text"
            class="inline-flex items-center gap-1 rounded-full border border-border bg-card px-2 py-0.5 text-xs text-foreground"
            :title="why(keyword)"
            data-testid="socialhq-keywords-for-post-keyword"
        >
            <span
                v-if="keyword.main"
                class="text-[10.5px] font-semibold tracking-wide text-primary-text uppercase"
            >
                {{ $t('socialhq.keywords.preview.main') }}
            </span>
            {{ keyword.text }}
        </span>
        <span
            v-if="panel.picked.length === 0"
            data-testid="socialhq-keywords-for-post-none"
        >
            {{ $t('socialhq.keywords.for_post.none') }}
        </span>
        <span
            v-if="panel.chosen"
            class="text-xs text-subtle-foreground"
            data-testid="socialhq-keywords-for-post-chosen"
        >
            · {{ $t('socialhq.keywords.for_post.chosen') }}
        </span>
        <Popover v-model:open="open">
            <PopoverTrigger as-child>
                <button
                    type="button"
                    class="ms-auto font-medium whitespace-nowrap text-primary-text hover:underline disabled:opacity-50"
                    :disabled="disabled"
                    data-testid="socialhq-keywords-for-post-change"
                >
                    {{ $t('socialhq.keywords.for_post.change') }}
                </button>
            </PopoverTrigger>
            <PopoverContent
                align="end"
                class="w-80 p-3"
                data-testid="socialhq-keywords-for-post-popover"
            >
                <p class="text-sm font-medium text-foreground">
                    {{ $t('socialhq.keywords.for_post.popover_title') }}
                </p>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    {{
                        $t('socialhq.keywords.for_post.popover_hint', {
                            max: String(panel.max),
                        })
                    }}
                </p>
                <Input
                    v-model="search"
                    class="mt-2 h-8"
                    :aria-label="$t('socialhq.keywords.for_post.search')"
                    :placeholder="$t('socialhq.keywords.for_post.search')"
                    autocomplete="off"
                    data-testid="socialhq-keywords-for-post-search"
                />
                <ul class="mt-2 max-h-64 overflow-y-auto pe-1">
                    <li v-for="keyword in options" :key="keyword.text">
                        <label class="flex items-start gap-2 py-1 text-sm">
                            <Checkbox
                                class="mt-0.5"
                                :model-value="selected.includes(keyword.text)"
                                :disabled="
                                    disabled ||
                                    (isFull && !selected.includes(keyword.text))
                                "
                                :data-testid="`socialhq-keywords-for-post-option-${slug(keyword.text)}`"
                                @update:model-value="
                                    toggle(keyword.text, $event)
                                "
                            />
                            <span class="min-w-0">
                                <span class="break-words text-foreground">
                                    <span
                                        v-if="keyword.main"
                                        class="me-1 text-[10.5px] font-semibold tracking-wide text-primary-text uppercase"
                                    >
                                        {{
                                            $t('socialhq.keywords.preview.main')
                                        }}
                                    </span>
                                    {{ keyword.text }}
                                </span>
                                <span
                                    class="block truncate text-xs text-muted-foreground"
                                >
                                    {{
                                        keyword.tags.length > 0
                                            ? keyword.tags
                                                  .map((tag) => tag.label)
                                                  .join(' · ')
                                            : keyword.main
                                              ? $t(
                                                    'socialhq.keywords.for_post.why_main',
                                                )
                                              : $t(
                                                    'socialhq.keywords.for_post.why_general',
                                                )
                                    }}
                                </span>
                            </span>
                        </label>
                    </li>
                    <li
                        v-if="options.length === 0"
                        class="py-2 text-xs text-muted-foreground"
                    >
                        {{ $t('socialhq.keywords.for_post.empty_search') }}
                    </li>
                </ul>
                <div
                    class="mt-2 flex items-center justify-between gap-2 border-t border-border pt-2"
                >
                    <span class="text-xs text-muted-foreground tabular-nums">
                        {{
                            $t('socialhq.keywords.for_post.count', {
                                count: formatFullNumber(selected.length),
                                max: formatFullNumber(panel.max),
                            })
                        }}
                    </span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        :disabled="disabled || !panel.chosen"
                        data-testid="socialhq-keywords-for-post-reset"
                        @click="reset"
                    >
                        {{ $t('socialhq.keywords.for_post.reset') }}
                    </Button>
                </div>
            </PopoverContent>
        </Popover>
    </div>
</template>
