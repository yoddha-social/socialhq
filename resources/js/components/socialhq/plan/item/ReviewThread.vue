<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    IconCircleCheck,
    IconMessage,
    IconMessageReport,
    IconPencil,
    IconSend,
} from '@tabler/icons-vue';

import type { ReviewEntry } from '@/components/socialhq/plan/item/types';
import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import date from '@/date';
import { store as storeComment } from '@/routes/socialhq/plan/items/comments';

const props = defineProps<{
    itemId: number;
    entries: ReviewEntry[];
    canComment: boolean;
    hasCopy: boolean;
    hasBrief: boolean;
}>();

const form = useForm<{ body: string; about: string }>({
    body: '',
    about: '',
});

const icons = {
    comment: IconMessage,
    submitted: IconSend,
    changes_requested: IconMessageReport,
    approved: IconCircleCheck,
    reopened: IconPencil,
} as const;

/** The lang key of what happened; reopening a creative is worded apart from a new copy version. */
const kindLabel = (entry: ReviewEntry): string =>
    entry.kind === 'reopened' && entry.about === 'creative'
        ? 'socialhq_production.review.kinds.reopened_creative'
        : `socialhq_production.review.kinds.${entry.kind}`;

const entryClass = (entry: ReviewEntry): string => {
    switch (entry.kind) {
        case 'changes_requested':
            return 'border-amber-300 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/40';
        case 'approved':
            return 'border-success-text/30 bg-success-subtle';
        default:
            return 'border-border bg-card';
    }
};

const addComment = (): void => {
    form.transform((data) => ({
        body: data.body,
        about: data.about === '' ? null : data.about,
    })).post(storeComment.url(props.itemId), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('body');
        },
    });
};
</script>

<template>
    <section
        class="flex flex-col rounded-xl border bg-card"
        aria-labelledby="socialhq-review-title"
        data-testid="item-review"
    >
        <h2
            id="socialhq-review-title"
            class="px-5 pt-4 text-base font-semibold"
        >
            {{ $t('socialhq_production.review.title') }}
        </h2>

        <p
            v-if="entries.length === 0"
            class="px-5 pt-2 text-sm text-muted-foreground"
        >
            {{ $t('socialhq_production.review.empty') }}
        </p>

        <ol class="grid gap-3 px-5 pt-3" data-testid="item-review-entries">
            <li
                v-for="entry in entries"
                :key="entry.id"
                class="rounded-lg border px-3 py-2.5"
                :class="entryClass(entry)"
                :data-testid="`item-review-entry-${entry.kind}`"
            >
                <div class="flex items-start gap-2">
                    <Avatar
                        :src="entry.user?.photo_url ?? null"
                        :name="entry.user?.name ?? ''"
                        class="mt-0.5 size-6 shrink-0"
                        fallback-class="text-[10px] bg-primary-subtle text-primary-text font-bold"
                    />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm">
                            <span class="font-medium">{{
                                entry.user?.name ?? '—'
                            }}</span>
                            <span
                                v-if="entry.kind !== 'comment'"
                                class="text-muted-foreground"
                            >
                                <component
                                    :is="icons[entry.kind]"
                                    class="mx-1 inline size-3.5 align-[-2px]"
                                />{{ $t(kindLabel(entry)) }}</span
                            >
                        </p>
                        <p class="text-xs text-muted-foreground">
                            <time :datetime="entry.created_at ?? undefined">{{
                                date.formatDateTime(entry.created_at)
                            }}</time>
                            <template
                                v-if="
                                    entry.about === 'copy' &&
                                    entry.version_number
                                "
                            >
                                ·
                                {{
                                    $t(
                                        'socialhq_production.review.about_version',
                                        {
                                            number: String(
                                                entry.version_number,
                                            ),
                                        },
                                    )
                                }}
                            </template>
                            <template
                                v-else-if="
                                    entry.about === 'creative' && entry.round
                                "
                            >
                                ·
                                {{
                                    $t(
                                        'socialhq_production.review.about_round',
                                        {
                                            round: String(entry.round),
                                        },
                                    )
                                }}
                            </template>
                        </p>
                        <p
                            v-if="entry.body"
                            class="mt-1.5 text-sm leading-relaxed whitespace-pre-wrap"
                        >
                            {{ entry.body }}
                        </p>
                    </div>
                </div>
            </li>
        </ol>

        <form
            v-if="canComment"
            class="mt-4 grid gap-2 border-t px-5 py-4"
            data-testid="item-review-comment-form"
            @submit.prevent="addComment"
        >
            <Label for="socialhq-review-comment" class="sr-only">
                {{ $t('socialhq_production.review.comment_label') }}
            </Label>
            <Textarea
                id="socialhq-review-comment"
                v-model="form.body"
                class="min-h-20"
                :placeholder="
                    $t('socialhq_production.review.comment_placeholder')
                "
                :aria-invalid="form.errors.body ? true : undefined"
                :aria-describedby="
                    form.errors.body
                        ? 'socialhq-review-comment-error'
                        : undefined
                "
                data-testid="item-review-comment"
            />
            <p
                v-if="form.errors.body"
                id="socialhq-review-comment-error"
                class="text-sm text-destructive-text"
            >
                {{ form.errors.body }}
            </p>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div
                    v-if="hasCopy || hasBrief"
                    class="flex items-center gap-2 text-sm"
                >
                    <Label for="socialhq-review-about" class="text-xs">
                        {{ $t('socialhq_production.review.about') }}
                    </Label>
                    <NativeSelect
                        id="socialhq-review-about"
                        v-model="form.about"
                        class="h-7 w-auto min-w-28 py-0 pr-8 pl-2.5 text-xs"
                        data-testid="item-review-about"
                    >
                        <NativeSelectOption value="">
                            {{ $t('socialhq_production.review.about_general') }}
                        </NativeSelectOption>
                        <NativeSelectOption v-if="hasCopy" value="copy">
                            {{ $t('socialhq_production.review.about_copy') }}
                        </NativeSelectOption>
                        <NativeSelectOption v-if="hasBrief" value="creative">
                            {{
                                $t('socialhq_production.review.about_creative')
                            }}
                        </NativeSelectOption>
                    </NativeSelect>
                </div>
                <Button
                    type="submit"
                    size="sm"
                    class="ms-auto"
                    :disabled="form.body.trim() === ''"
                    :loading="form.processing"
                    data-testid="item-review-comment-submit"
                >
                    {{ $t('socialhq_production.review.add_comment') }}
                </Button>
            </div>
        </form>
    </section>
</template>
