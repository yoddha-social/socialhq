<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { IconSearch } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, ref } from 'vue';

import { serverMessageOf } from '@/components/socialhq/requestErrors';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatFullNumber } from '@/lib/utils';
import { search as searchKnowledge } from '@/routes/socialhq/brand';

withDefaults(
    defineProps<{
        /** False inside the "Check AI context" dialog, which has its own title. */
        showHeading?: boolean;
    }>(),
    { showHeading: true },
);

interface CheckPart {
    type: 'entry' | 'excerpt';
    id: number;
    document_id: number | null;
    title: string;
    kind: string | null;
    text: string;
    size: number;
    matched: boolean;
    pinned: boolean;
}

interface CheckResult {
    used: number;
    budget: number;
    profile: { used: number; trimmed: boolean };
    parts: CheckPart[];
    /** The SEO keywords this request gets, outside the budget. */
    keywords: { text: string; main: boolean }[];
}

/**
 * "Check what the AI reads": type a topic, see the brand knowledge an AI
 * request about it gets (the profile, then entries and document excerpts in
 * order). Nothing is sent to an AI; it is how the team tests search.
 */
const http = useHttp<{ focus: string }, CheckResult>({ focus: '' });
const result = ref<CheckResult | null>(null);
const failed = ref('');

onBeforeUnmount(() => {
    http.cancel();
});

const hasMatch = computed(
    () => result.value?.parts.some((part) => part.matched) ?? false,
);

const isEmpty = computed(
    () =>
        result.value !== null &&
        result.value.parts.length === 0 &&
        result.value.profile.used === 0,
);

const reasonOf = (part: CheckPart): string => {
    if (part.pinned) {
        return 'pinned';
    }

    return part.matched ? 'matched' : 'newest';
};

const check = async (): Promise<void> => {
    if (http.processing) {
        return;
    }

    failed.value = '';

    try {
        const response = await http.post(searchKnowledge.url());

        // A 422 (an empty or too long topic) resolves with nothing and fills
        // http.errors instead of throwing.
        if (response === undefined) {
            result.value = null;
            failed.value =
                http.errors.focus ?? trans('socialhq_knowledge.check.failed');

            return;
        }

        result.value = response;
    } catch (exception) {
        result.value = null;
        failed.value =
            serverMessageOf(exception) ??
            trans('socialhq_knowledge.check.failed');
    }
};
</script>

<template>
    <section class="flex flex-col gap-4" data-testid="socialhq-knowledge-check">
        <div v-if="showHeading" class="flex flex-col gap-1">
            <h2 class="text-base font-semibold text-foreground">
                {{ $t('socialhq_knowledge.check.title') }}
            </h2>
            <p class="text-sm text-muted-foreground">
                {{ $t('socialhq_knowledge.check.description') }}
            </p>
        </div>

        <form
            class="flex flex-col gap-2 sm:flex-row sm:items-end"
            @submit.prevent="check"
        >
            <div class="flex min-w-0 flex-1 flex-col gap-2">
                <Label for="socialhq-knowledge-check-focus">
                    {{ $t('socialhq_knowledge.check.label') }}
                </Label>
                <Input
                    id="socialhq-knowledge-check-focus"
                    v-model="http.focus"
                    :placeholder="$t('socialhq_knowledge.check.placeholder')"
                    :aria-invalid="failed !== '' || undefined"
                    :aria-describedby="
                        failed ? 'socialhq-knowledge-check-error' : undefined
                    "
                    data-testid="socialhq-knowledge-check-focus"
                />
            </div>
            <Button
                type="submit"
                class="shrink-0 self-start sm:self-auto"
                :loading="http.processing"
                data-testid="socialhq-knowledge-check-submit"
            >
                <IconSearch class="size-4" />
                {{ $t('socialhq_knowledge.check.submit') }}
            </Button>
        </form>

        <p
            v-if="failed"
            id="socialhq-knowledge-check-error"
            role="alert"
            class="text-sm text-destructive-text"
        >
            {{ failed }}
        </p>

        <div
            v-if="result"
            class="flex flex-col gap-3"
            aria-live="polite"
            data-testid="socialhq-knowledge-check-result"
        >
            <p class="text-sm text-muted-foreground tabular-nums">
                {{
                    $t('socialhq_knowledge.check.usage', {
                        used: formatFullNumber(result.used),
                        budget: formatFullNumber(result.budget),
                    })
                }}
            </p>

            <p v-if="isEmpty" class="text-sm text-muted-foreground">
                {{ $t('socialhq_knowledge.check.nothing') }}
            </p>
            <p
                v-else-if="!hasMatch"
                class="text-sm text-muted-foreground"
                data-testid="socialhq-knowledge-check-no-match"
            >
                {{ $t('socialhq_knowledge.check.no_match') }}
            </p>

            <ol class="flex flex-col gap-2">
                <li
                    v-if="result.profile.used > 0"
                    class="flex flex-col gap-1 rounded-xl border border-border bg-card p-3"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-sm font-medium text-foreground">
                            {{ $t('socialhq_knowledge.check.profile') }}
                        </p>
                        <Badge v-if="result.profile.trimmed" variant="warning">
                            {{ $t('socialhq_knowledge.check.profile_trimmed') }}
                        </Badge>
                        <span
                            class="ms-auto text-xs text-muted-foreground tabular-nums"
                        >
                            {{
                                $t('socialhq_knowledge.check.size', {
                                    count: formatFullNumber(
                                        result.profile.used,
                                    ),
                                })
                            }}
                        </span>
                    </div>
                </li>
                <li
                    v-for="part in result.parts"
                    :key="`${part.type}-${part.id}`"
                    class="flex flex-col gap-1 rounded-xl border border-border bg-card p-3"
                    :data-testid="`socialhq-knowledge-check-${part.type}-${part.id}`"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <p
                            class="min-w-0 truncate text-sm font-medium text-foreground"
                        >
                            {{
                                part.type === 'excerpt'
                                    ? $t(
                                          'socialhq_knowledge.check.from_document',
                                          { title: part.title },
                                      )
                                    : part.title
                            }}
                        </p>
                        <Badge v-if="part.kind" variant="secondary">
                            {{
                                $t(`socialhq.brand.entries.kinds.${part.kind}`)
                            }}
                        </Badge>
                        <Badge :variant="part.matched ? 'success' : 'outline'">
                            {{
                                $t(
                                    `socialhq_knowledge.check.reasons.${reasonOf(part)}`,
                                )
                            }}
                        </Badge>
                        <span
                            class="ms-auto text-xs text-muted-foreground tabular-nums"
                        >
                            {{
                                $t('socialhq_knowledge.check.size', {
                                    count: formatFullNumber(part.size),
                                })
                            }}
                        </span>
                    </div>
                    <p
                        class="line-clamp-3 text-sm whitespace-pre-line text-muted-foreground"
                    >
                        {{ part.text }}
                    </p>
                </li>
            </ol>

            <div
                class="flex flex-col gap-1.5 rounded-xl border border-dashed border-border p-3"
                data-testid="socialhq-knowledge-check-keywords"
            >
                <p class="text-sm font-medium text-foreground">
                    {{ $t('socialhq.keywords.ai_reads.title') }}
                </p>
                <template v-if="result.keywords.length > 0">
                    <p class="text-xs text-muted-foreground">
                        {{ $t('socialhq.keywords.ai_reads.hint') }}
                    </p>
                    <div class="flex flex-wrap gap-1.5">
                        <span
                            v-for="keyword in result.keywords"
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
                </template>
                <p v-else class="text-xs text-muted-foreground">
                    {{ $t('socialhq.keywords.ai_reads.none') }}
                </p>
            </div>
        </div>
    </section>
</template>
