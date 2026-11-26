<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import {
    IconArrowLeft,
    IconLoader2,
    IconRefresh,
    IconSparkles,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

import CharacterCounter from '@/components/CharacterCounter.vue';
import type {
    KeywordAnswer,
    KeywordPanel,
} from '@/components/socialhq/keywords/keywords';
import KeywordsAfterAnswer from '@/components/socialhq/keywords/KeywordsAfterAnswer.vue';
import KeywordsForPost from '@/components/socialhq/keywords/KeywordsForPost.vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { extractErrorMessage } from '@/lib/httpError';
import {
    countPromptWords,
    PROMPT_MAX_LENGTH,
    PROMPT_MIN_WORDS,
} from '@/lib/promptWords';
import { formatNumber } from '@/lib/utils';
import { assist } from '@/routes/app/posts/ai';
import { forPost as keywordsForPost } from '@/routes/socialhq/brand/keywords';

export type AssistantChannel = {
    accountId: string;
    platform: string;
    label: string;
    limit: number | null;
};

type RewriteMode =
    | 'rephrase'
    | 'shorten'
    | 'expand'
    | 'more_casual'
    | 'more_formal';

const REWRITE_MODES: RewriteMode[] = [
    'rephrase',
    'shorten',
    'expand',
    'more_casual',
    'more_formal',
];

const props = defineProps<{
    content: string;
    channel: AssistantChannel | null;
    /**
     * Social HQ: what the post is (the plan item it is made from, else the
     * day it goes out), for "Keywords for this post". Without it the panel
     * shows none and the server picks.
     */
    seo?: { planItemId: number | null; date: string | null } | null;
}>();

const emit = defineEmits<{
    insert: [text: string];
    replace: [text: string];
}>();

const screen = ref<'actions' | 'prompt' | 'suggestion'>('actions');
const prompt = ref('');
const lastPrompt = ref('');
const suggestion = ref('');
const suggestionAction = ref<'insert' | 'replace'>('insert');
/** Social HQ: the SEO keywords note, or the keywords the caption says. */
const keywordAnswer = ref<KeywordAnswer | null>(null);
const busy = ref(false);
const error = ref('');
const pendingMode = ref('generate');

const http = useHttp({
    mode: '',
    prompt: '',
    current_content: '',
    previous_content: '',
    platform: null as string | null,
    social_account_id: null as string | null,
    seo_keywords: null as string[] | null,
});

/**
 * Social HQ: "Keywords for this post", as the server picked them for the
 * post; a person's change stays in this panel and goes with each request.
 */
const keywordPanel = ref<KeywordPanel | null>(null);
const keywordsHttp = useHttp({});

const loadKeywords = async (): Promise<void> => {
    if (!props.seo) {
        keywordPanel.value = null;
        return;
    }

    const query: Record<string, string | number> = {};

    if (props.seo.planItemId !== null) {
        query.plan_item_id = props.seo.planItemId;
    } else if (props.seo.date) {
        query.date = props.seo.date;
    }

    try {
        const result = (await keywordsHttp.get(
            keywordsForPost.url({ query }),
        )) as { keywords: KeywordPanel | null } | undefined;
        keywordPanel.value = result?.keywords ?? null;
    } catch {
        keywordPanel.value = null;
    }
};

watch(
    () => [props.seo?.planItemId ?? null, props.seo?.date ?? null],
    () => void loadKeywords(),
    { immediate: true },
);

/** A person's change: their list, or null for the app's pick again. */
const changeKeywords = (texts: string[] | null): void => {
    const panel = keywordPanel.value;

    if (!panel) {
        return;
    }

    const byText = new Map(
        panel.catalogue.map((keyword) => [keyword.text, keyword]),
    );

    keywordPanel.value = {
        ...panel,
        chosen: texts !== null,
        picked:
            texts === null
                ? panel.app_pick
                : texts.flatMap((text) => {
                      const keyword = byText.get(text);

                      return keyword ? [{ ...keyword, matched: [] }] : [];
                  }),
    };
};

onBeforeUnmount(() => {
    http.cancel();
    keywordsHttp.cancel();
});

const spinnerLabel = computed(() =>
    REWRITE_MODES.includes(pendingMode.value as RewriteMode)
        ? trans(`posts.composer.assistant_modes.${pendingMode.value}`)
        : trans('posts.composer.assistant_generate'),
);

const hasContent = computed(() => props.content.trim().length > 0);
const promptWords = computed(() => countPromptWords(prompt.value));
const promptTooShort = computed(() => promptWords.value < PROMPT_MIN_WORDS);
const promptTooLong = computed(() => prompt.value.length > PROMPT_MAX_LENGTH);
const canGenerate = computed(
    () => !busy.value && !promptTooShort.value && !promptTooLong.value,
);

const request = async (
    payload: {
        mode: string;
        prompt?: string;
        current_content?: string;
        previous_content?: string;
    },
    action: 'insert' | 'replace',
): Promise<void> => {
    busy.value = true;
    error.value = '';
    pendingMode.value = payload.mode;
    http.mode = payload.mode;
    http.prompt = payload.prompt ?? '';
    http.current_content = payload.current_content ?? '';
    http.previous_content = payload.previous_content ?? '';
    http.platform = props.channel?.platform ?? null;
    http.social_account_id = props.channel?.accountId ?? null;
    http.seo_keywords = keywordPanel.value
        ? keywordPanel.value.picked.map((keyword) => keyword.text)
        : null;
    try {
        const result = (await http.post(assist.url())) as
            | ({ content: string } & KeywordAnswer)
            | undefined;
        if (!result) {
            error.value =
                Object.values(http.errors)[0] ??
                trans('posts.composer.assistant_error');
            return;
        }
        suggestion.value = result.content;
        keywordAnswer.value = result;
        suggestionAction.value = action;
        screen.value = 'suggestion';
    } catch (exception) {
        error.value =
            extractErrorMessage(exception) ??
            trans('posts.composer.assistant_error');
    } finally {
        busy.value = false;
    }
};

const openPrompt = (): void => {
    error.value = '';
    screen.value = 'prompt';
};

const generate = (): void => {
    if (!canGenerate.value) return;
    lastPrompt.value = prompt.value.trim();
    void request({ mode: 'generate', prompt: lastPrompt.value }, 'insert');
};

const regenerate = (): void => {
    void request(
        {
            mode: 'regenerate',
            prompt: lastPrompt.value,
            previous_content: suggestion.value,
        },
        'insert',
    );
};

const rewrite = (mode: RewriteMode): void => {
    void request({ mode, current_content: props.content }, 'replace');
};

const back = (): void => {
    error.value = '';
    screen.value = 'actions';
};

const accept = (): void => {
    if (suggestionAction.value === 'insert') {
        emit('insert', suggestion.value);
    } else {
        emit('replace', suggestion.value);
    }
    suggestion.value = '';
    screen.value = 'actions';
};
</script>

<template>
    <div
        class="flex min-h-full flex-col gap-4"
        data-testid="writing-assistant-panel"
    >
        <template v-if="screen === 'actions'">
            <p class="text-sm">
                {{ $t('posts.composer.assistant_question') }}
            </p>
            <KeywordsForPost
                v-if="keywordPanel"
                :panel="keywordPanel"
                :disabled="busy"
                @change="changeKeywords"
            />
            <div class="space-y-2">
                <Button
                    type="button"
                    variant="outline"
                    class="w-full justify-start"
                    data-testid="writing-assistant-mode-generate"
                    :disabled="busy"
                    @click="openPrompt"
                >
                    <IconSparkles class="size-4" />
                    {{ $t('posts.composer.assistant_modes.generate') }}
                </Button>
                <Button
                    v-for="mode in REWRITE_MODES"
                    :key="mode"
                    type="button"
                    variant="outline"
                    class="w-full justify-start"
                    :data-testid="`writing-assistant-mode-${mode}`"
                    :disabled="busy || !hasContent"
                    @click="rewrite(mode)"
                >
                    {{ $t(`posts.composer.assistant_modes.${mode}`) }}
                </Button>
            </div>
            <IconLoader2
                v-if="busy"
                class="size-5 animate-spin"
                role="status"
                :aria-label="spinnerLabel"
            />
        </template>

        <template v-else-if="screen === 'prompt'">
            <Button
                type="button"
                variant="ghost"
                size="icon"
                data-testid="writing-assistant-back"
                :aria-label="$t('posts.composer.assistant_back')"
                :disabled="busy"
                @click="back"
            >
                <IconArrowLeft class="size-4" />
            </Button>
            <div class="space-y-2">
                <label
                    for="writing-assistant-prompt"
                    class="block text-sm font-medium"
                >
                    {{ $t('posts.composer.assistant_prompt_question') }}
                </label>
                <Textarea
                    id="writing-assistant-prompt"
                    v-model="prompt"
                    data-testid="writing-assistant-prompt"
                    class="min-h-40 bg-background p-3"
                    :placeholder="
                        $t('posts.composer.assistant_prompt_placeholder')
                    "
                />
                <div
                    class="flex items-start justify-between gap-3 text-xs text-muted-foreground"
                >
                    <p>{{ $t('posts.composer.assistant_tip') }}</p>
                    <CharacterCounter
                        class="shrink-0"
                        :exceeded="promptTooLong"
                        data-testid="writing-assistant-prompt-count"
                    >
                        {{
                            $t('posts.composer.assistant_prompt_count', {
                                count: formatNumber(prompt.length),
                                max: formatNumber(PROMPT_MAX_LENGTH),
                            })
                        }}
                    </CharacterCounter>
                </div>
                <p
                    v-if="promptTooShort"
                    class="text-xs text-muted-foreground"
                    data-testid="writing-assistant-prompt-hint"
                >
                    {{
                        $t('posts.composer.assistant_prompt_min_words', {
                            count: String(PROMPT_MIN_WORDS),
                        })
                    }}
                </p>
            </div>
            <KeywordsForPost
                v-if="keywordPanel"
                :panel="keywordPanel"
                :disabled="busy"
                @change="changeKeywords"
            />
            <Button
                type="button"
                class="ml-auto"
                data-testid="writing-assistant-generate"
                :disabled="!canGenerate"
                @click="generate"
            >
                <IconLoader2 v-if="busy" class="size-4 animate-spin" />
                <IconSparkles v-else class="size-4" />
                {{ $t('posts.composer.assistant_generate') }}
            </Button>
        </template>

        <template v-else>
            <div class="space-y-3">
                <h4 class="text-sm font-medium">
                    {{ $t('posts.composer.assistant_suggestion') }}
                </h4>
                <p
                    class="rounded-md border bg-background p-3 text-sm whitespace-pre-wrap"
                    data-testid="writing-assistant-suggestion"
                >
                    {{ suggestion }}
                </p>
                <KeywordsAfterAnswer :answer="keywordAnswer" />
            </div>
            <div class="flex flex-wrap items-center justify-end gap-2">
                <Button
                    type="button"
                    variant="ghost"
                    class="mr-auto"
                    data-testid="writing-assistant-back"
                    :disabled="busy"
                    @click="back"
                >
                    <IconArrowLeft class="size-4" />
                    {{ $t('posts.composer.assistant_back') }}
                </Button>
                <Button
                    v-if="suggestionAction === 'insert'"
                    type="button"
                    variant="outline"
                    data-testid="writing-assistant-regenerate"
                    :disabled="busy"
                    @click="regenerate"
                >
                    <IconLoader2 v-if="busy" class="size-4 animate-spin" />
                    <IconRefresh v-else class="size-4" />
                    {{ $t('posts.composer.assistant_regenerate') }}
                </Button>
                <Button
                    v-if="suggestionAction === 'insert'"
                    type="button"
                    data-testid="writing-assistant-insert"
                    :disabled="busy"
                    @click="accept"
                >
                    {{ $t('posts.composer.assistant_insert') }}
                </Button>
                <Button
                    v-else
                    type="button"
                    data-testid="writing-assistant-replace"
                    :disabled="busy"
                    @click="accept"
                >
                    {{ $t('posts.composer.assistant_replace') }}
                </Button>
            </div>
        </template>

        <p
            v-if="error"
            role="alert"
            class="text-sm text-destructive"
            data-testid="writing-assistant-error"
        >
            {{ error }}
        </p>

        <div
            class="mt-auto space-y-2 border-t pt-4 text-xs text-muted-foreground"
        >
            <p
                v-if="channel && channel.limit !== null"
                data-testid="writing-assistant-channel"
            >
                {{
                    $t('posts.composer.assistant_channel', {
                        platform: channel.label,
                        limit: formatNumber(channel.limit),
                    })
                }}
            </p>
            <p data-testid="writing-assistant-disclaimer">
                {{ $t('posts.composer.assistant_disclaimer') }}
            </p>
        </div>
    </div>
</template>
