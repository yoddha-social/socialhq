<script setup lang="ts">
import { router, useForm, useHttp, usePage } from '@inertiajs/vue3';
import {
    IconAlertTriangle,
    IconCheck,
    IconLoader2,
    IconLock,
    IconMessageReport,
    IconSend,
    IconSparkles,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import KeywordsMissingNote from '@/components/socialhq/keywords/KeywordsMissingNote.vue';
import ReviewDecisionDialog from '@/components/socialhq/plan/item/ReviewDecisionDialog.vue';
import type {
    CopyVersion,
    PlanAbilities,
    PlanItemDetail,
} from '@/components/socialhq/plan/item/types';
import UnsupportedDetailsNote from '@/components/socialhq/plan/UnsupportedDetailsNote.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { characterCount } from '@/lib/characters';
import { extractErrorMessage } from '@/lib/httpError';
import {
    approve as approveCopy,
    requestChanges as requestCopyChanges,
    submit as submitCopy,
    ai as writeWithAi,
} from '@/routes/socialhq/plan/items/copy';
import { store as storeVersion } from '@/routes/socialhq/plan/items/versions';

const props = defineProps<{
    item: PlanItemDetail;
    latest: CopyVersion | null;
    abilities: PlanAbilities;
    aiAvailable: boolean;
}>();

type CopyFields = {
    caption: string;
    hashtags: string;
    script: string;
    notes: string;
};

const fieldsOf = (version: CopyVersion | null): CopyFields => ({
    caption: version?.caption ?? '',
    hashtags: version?.hashtags ?? '',
    script: version?.script ?? '',
    notes: version?.notes ?? '',
});

const form = useForm<CopyFields>(fieldsOf(props.latest));
const page = usePage();

/** Approved copy stays read-only until someone starts a new version. */
const unlocked = ref(false);

const sameFields = (a: CopyFields, b: CopyFields): boolean =>
    a.caption === b.caption &&
    a.hashtags === b.hashtags &&
    a.script === b.script &&
    a.notes === b.notes;

/** A new latest version becomes the editor's baseline; what someone is typing stays (as BriefPanel does). */
watch(
    () => props.latest?.id,
    () => {
        const fields = fieldsOf(props.latest);
        const keepTyping = form.isDirty && !sameFields(form.data(), fields);

        form.defaults(fields);

        if (keepTyping) {
            return;
        }

        form.reset();
        form.clearErrors();
        unlocked.value = false;
    },
);

/**
 * Whether the editor holds copy that differs from the latest version. Only
 * text counts, not spaces around it (the server trims them), so "Save as new
 * version" stays off until something really changed.
 */
const hasChanges = computed(() => {
    const saved = fieldsOf(props.latest);

    return (Object.keys(saved) as (keyof CopyFields)[]).some(
        (field) => form[field].trim() !== saved[field].trim(),
    );
});

const isVideo = computed(() => props.item.creative === 'video');
const isLocked = computed(
    () => props.item.copy_status === 'approved' && !unlocked.value,
);
const isInReview = computed(() => props.item.copy_status === 'in_review');

/** Copy with gaps in square brackets can't be approved (the server refuses it too). */
const latestHasGaps = computed(
    () => (props.latest?.placeholders.length ?? 0) > 0,
);

const captionLength = computed(() => characterCount(form.caption));
const overBy = computed(() =>
    props.item.max_length === null
        ? 0
        : Math.max(0, captionLength.value - props.item.max_length),
);

/** Copy sent back with changes goes for review again only as a new version (saving one makes it a draft). */
const canSubmit = computed(
    () =>
        props.latest !== null &&
        !hasChanges.value &&
        props.item.copy_status === 'draft',
);

const copyError = computed(
    () => (page.props.errors as Record<string, string> | undefined)?.copy,
);

const startNewVersion = (): void => {
    unlocked.value = true;
};

const discard = (): void => {
    form.reset();
    form.clearErrors();
    unlocked.value = false;
};

const save = (): void => {
    if (aiHttp.processing) {
        return;
    }

    form.post(storeVersion.url(props.item.id), {
        preserveScroll: true,
    });
};

/** Load an earlier version into the editor, to save it again as the next one. */
const loadVersion = (version: CopyVersion): void => {
    const fields = fieldsOf(version);
    form.caption = fields.caption;
    form.hashtags = fields.hashtags;
    form.script = fields.script;
    form.notes = fields.notes;
    unlocked.value = true;
};

defineExpose({ loadVersion });

const aiHttp = useHttp<
    { instruction: string },
    { number: number; keywords_missing?: boolean; can_add_keywords?: boolean }
>({
    instruction: '',
});
const aiError = ref('');
/** The brand has no SEO keywords: a light note under the copy the AI just wrote. */
const keywordsMissingAfter = ref<number | null>(null);
const canAddKeywords = ref(false);

/** AI writes from the saved copy, so it waits for unsaved edits to be saved or discarded. */
const showAi = computed(
    () => props.aiAvailable && props.abilities.canWork && !isLocked.value,
);

onBeforeUnmount(() => {
    aiHttp.cancel();
});

/** Fields are read-only while approved copy is locked, and while AI writes the next version. */
const isReadonly = computed(() => isLocked.value || aiHttp.processing);

const writeCopyWithAi = async (): Promise<void> => {
    if (aiHttp.processing || hasChanges.value) {
        return;
    }

    // Only a rewrite takes the team's request.
    if (!props.latest) {
        aiHttp.instruction = '';
    }

    aiError.value = '';

    try {
        const result = await aiHttp.post(writeWithAi.url(props.item.id));

        if (!result) {
            aiError.value = trans('socialhq_production.item.copy.ai_failed');

            return;
        }

        aiHttp.instruction = '';
        keywordsMissingAfter.value =
            result.keywords_missing === true ? result.number : null;
        canAddKeywords.value = result.can_add_keywords === true;
        router.reload({
            only: ['item', 'versions', 'thread', 'postReady', 'postBlockers'],
        });
        toast.success(
            trans('socialhq_production.item.copy.ai_written', {
                number: String(result.number),
            }),
        );
    } catch (exception) {
        aiError.value =
            extractErrorMessage(exception) ??
            trans('socialhq_production.item.copy.ai_failed');
    }
};

const submitting = ref(false);

const sendForReview = (): void => {
    router.post(
        submitCopy.url(props.item.id),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                submitting.value = true;
            },
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
};

const decision = ref<'approve' | 'request_changes' | null>(null);
const decisionOpen = ref(false);
const deciding = ref(false);
const decisionError = ref('');

const openApprove = (): void => {
    decision.value = 'approve';
    decisionError.value = '';
    decisionOpen.value = true;
};

const openRequestChanges = (): void => {
    decision.value = 'request_changes';
    decisionError.value = '';
    decisionOpen.value = true;
};

const setDecisionOpen = (open: boolean): void => {
    decisionOpen.value = open;
};

const confirmDecision = (note: string): void => {
    const url =
        decision.value === 'approve'
            ? approveCopy.url(props.item.id)
            : requestCopyChanges.url(props.item.id);

    router.post(
        url,
        { note, version_id: props.latest?.id ?? null },
        {
            preserveScroll: true,
            onStart: () => {
                deciding.value = true;
            },
            onFinish: () => {
                deciding.value = false;
            },
            onSuccess: () => {
                decisionOpen.value = false;
            },
            onError: (errors) => {
                decisionError.value = errors.note ?? errors.copy ?? '';
            },
        },
    );
};
</script>

<template>
    <section
        class="rounded-xl border bg-card"
        :aria-labelledby="`socialhq-copy-title-${item.id}`"
        data-testid="item-copy"
    >
        <header
            class="flex flex-wrap items-center justify-between gap-3 px-5 pt-4"
        >
            <h2
                :id="`socialhq-copy-title-${item.id}`"
                class="text-base font-semibold"
            >
                {{ $t('socialhq_production.item.copy.title') }}
            </h2>
            <form
                v-if="showAi"
                class="flex min-w-0 flex-1 flex-wrap items-center justify-end gap-2"
                data-testid="item-copy-ai-form"
                @submit.prevent="writeCopyWithAi"
            >
                <template v-if="latest">
                    <Label
                        :for="`socialhq-copy-ai-instruction-${item.id}`"
                        class="sr-only"
                    >
                        {{
                            $t(
                                'socialhq_production.item.copy.ai_instruction_label',
                            )
                        }}
                    </Label>
                    <Input
                        :id="`socialhq-copy-ai-instruction-${item.id}`"
                        v-model="aiHttp.instruction"
                        class="h-8 min-w-0 flex-1 sm:max-w-72"
                        :placeholder="
                            $t(
                                'socialhq_production.item.copy.ai_instruction_placeholder',
                            )
                        "
                        :disabled="aiHttp.processing || hasChanges"
                        data-testid="item-copy-ai-instruction"
                    />
                </template>
                <!-- The label stays next to the spinner: a draft takes several seconds. -->
                <Button
                    type="submit"
                    variant="ghost"
                    class="min-w-36 bg-primary-subtle text-primary-text hover:bg-primary-subtle/80"
                    :disabled="aiHttp.processing || hasChanges"
                    :aria-busy="aiHttp.processing || undefined"
                    :title="
                        hasChanges
                            ? $t('socialhq_production.item.copy.ai_save_first')
                            : undefined
                    "
                    data-testid="item-copy-ai"
                >
                    <IconLoader2
                        v-if="aiHttp.processing"
                        class="animate-spin"
                        aria-hidden="true"
                    />
                    <IconSparkles v-else />
                    <template v-if="aiHttp.processing">
                        {{
                            latest
                                ? $t(
                                      'socialhq_production.item.copy.rewriting_ai',
                                  )
                                : $t('socialhq_production.item.copy.writing_ai')
                        }}
                    </template>
                    <template v-else>
                        {{
                            latest
                                ? $t('socialhq_production.item.copy.rewrite_ai')
                                : $t('socialhq_production.item.copy.write_ai')
                        }}
                    </template>
                </Button>
            </form>
        </header>

        <p
            v-if="latest === null && !hasChanges"
            class="px-5 pt-2 text-sm text-muted-foreground"
            data-testid="item-copy-empty"
        >
            {{
                showAi
                    ? $t('socialhq_production.item.copy.empty_ai')
                    : $t('socialhq_production.item.copy.empty')
            }}
        </p>

        <div
            v-if="isLocked"
            class="mx-5 mt-3 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-success-subtle px-3 py-2 text-sm text-success-text"
            data-testid="item-copy-locked"
        >
            <span class="flex items-center gap-2">
                <IconLock class="size-4 shrink-0" />
                {{ $t('socialhq_production.item.copy.locked') }}
            </span>
            <Button
                size="sm"
                variant="outline"
                data-testid="item-copy-new-version"
                @click="startNewVersion"
            >
                {{ $t('socialhq_production.item.copy.start_new_version') }}
            </Button>
        </div>

        <form
            class="grid gap-5 px-5 pt-4 pb-2"
            data-testid="item-copy-form"
            @submit.prevent="save"
        >
            <div class="grid gap-2">
                <div class="flex items-end justify-between gap-3">
                    <Label for="socialhq-copy-caption">
                        {{ $t('socialhq_production.item.copy.caption') }}
                    </Label>
                    <span
                        class="text-xs tabular-nums"
                        :class="
                            overBy > 0
                                ? 'font-medium text-destructive-text'
                                : 'text-muted-foreground'
                        "
                        data-testid="item-copy-count"
                    >
                        {{
                            item.max_length === null
                                ? $t(
                                      'socialhq_production.item.copy.count_plain',
                                      { count: String(captionLength) },
                                  )
                                : $t('socialhq_production.item.copy.count', {
                                      count: String(captionLength),
                                      max: String(item.max_length),
                                  })
                        }}
                    </span>
                </div>
                <Textarea
                    id="socialhq-copy-caption"
                    v-model="form.caption"
                    class="min-h-48 text-[15px] leading-relaxed"
                    :readonly="isReadonly"
                    :placeholder="
                        $t('socialhq_production.item.copy.caption_placeholder')
                    "
                    :aria-invalid="form.errors.caption ? true : undefined"
                    :aria-describedby="
                        form.errors.caption
                            ? 'socialhq-copy-caption-error'
                            : undefined
                    "
                    data-testid="item-copy-caption"
                />
                <p
                    v-if="overBy > 0"
                    class="text-xs text-destructive-text"
                    data-testid="item-copy-over"
                >
                    {{
                        $t('socialhq_production.item.copy.over_limit', {
                            count: String(overBy),
                        })
                    }}
                </p>
                <p
                    v-if="form.errors.caption"
                    id="socialhq-copy-caption-error"
                    class="text-sm text-destructive-text"
                >
                    {{ form.errors.caption }}
                </p>
            </div>

            <div class="grid gap-2">
                <Label for="socialhq-copy-hashtags">
                    {{ $t('socialhq_production.item.copy.hashtags') }}
                </Label>
                <Input
                    id="socialhq-copy-hashtags"
                    v-model="form.hashtags"
                    :readonly="isReadonly"
                    :placeholder="
                        $t('socialhq_production.item.copy.hashtags_placeholder')
                    "
                    :aria-invalid="form.errors.hashtags ? true : undefined"
                    data-testid="item-copy-hashtags"
                />
                <p
                    v-if="form.errors.hashtags"
                    class="text-sm text-destructive-text"
                >
                    {{ form.errors.hashtags }}
                </p>
            </div>

            <div v-if="isVideo" class="grid gap-2">
                <Label for="socialhq-copy-script">
                    {{ $t('socialhq_production.item.copy.script') }}
                </Label>
                <Textarea
                    id="socialhq-copy-script"
                    v-model="form.script"
                    class="min-h-56 font-mono text-[13px] leading-relaxed"
                    :readonly="isReadonly"
                    :placeholder="
                        $t('socialhq_production.item.copy.script_placeholder')
                    "
                    :aria-invalid="form.errors.script ? true : undefined"
                    data-testid="item-copy-script"
                />
                <p
                    v-if="form.errors.script"
                    class="text-sm text-destructive-text"
                >
                    {{ form.errors.script }}
                </p>
            </div>

            <div class="grid gap-2">
                <Label for="socialhq-copy-notes">
                    {{ $t('socialhq_production.item.copy.notes') }}
                </Label>
                <Textarea
                    id="socialhq-copy-notes"
                    v-model="form.notes"
                    class="min-h-20"
                    :readonly="isReadonly"
                    :placeholder="
                        $t('socialhq_production.item.copy.notes_placeholder')
                    "
                    data-testid="item-copy-notes"
                />
            </div>
        </form>

        <div
            v-if="latest && !hasChanges && latest.placeholders.length > 0"
            id="socialhq-copy-placeholders"
            class="mx-5 mb-4 flex items-start gap-2 rounded-lg border border-warning/30 bg-warning/10 px-3 py-2 text-sm text-foreground"
            role="status"
            data-testid="item-copy-placeholders"
        >
            <IconAlertTriangle
                class="mt-0.5 size-4 shrink-0 text-warning"
                aria-hidden="true"
            />
            <span>
                {{
                    $t('socialhq_production.item.copy.placeholders', {
                        gaps: latest.placeholders.join(', '),
                    })
                }}
            </span>
        </div>
        <UnsupportedDetailsNote
            v-if="
                latest &&
                !hasChanges &&
                latest.unsupported_details.length > 0 &&
                item.copy_status !== 'approved'
            "
            class="mx-5 mb-4"
            :details="latest.unsupported_details"
            :title="$t('socialhq_production.item.copy.unsupported_title')"
            :body="$t('socialhq_production.item.copy.unsupported_body')"
            data-testid="item-copy-unsupported"
        />
        <KeywordsMissingNote
            v-if="
                latest && !hasChanges && keywordsMissingAfter === latest.number
            "
            :can-add="canAddKeywords"
            class="mx-5 mb-4"
        />

        <div
            class="sticky bottom-0 z-10 flex flex-wrap items-center justify-between gap-2 rounded-b-xl border-t bg-card/95 px-5 py-3 backdrop-blur"
            data-testid="item-copy-actions"
        >
            <p class="min-w-0 text-xs text-muted-foreground">
                <span
                    v-if="aiError"
                    class="text-destructive-text"
                    role="alert"
                    data-testid="item-copy-ai-error"
                >
                    {{ aiError }}
                </span>
                <span v-else-if="copyError" class="text-destructive-text">
                    {{ copyError }}
                </span>
                <span v-else-if="hasChanges">
                    {{ $t('socialhq_production.item.copy.unsaved') }}
                </span>
                <span v-else-if="latest">
                    {{
                        $t('socialhq_production.item.copy.saved_as', {
                            number: String(latest.number),
                        })
                    }}
                </span>
            </p>
            <div class="flex flex-wrap items-center gap-2">
                <Button
                    v-if="
                        hasChanges ||
                        (unlocked && item.copy_status === 'approved')
                    "
                    variant="ghost"
                    data-testid="item-copy-discard"
                    @click="discard"
                >
                    {{ $t('socialhq_production.item.copy.discard') }}
                </Button>
                <template
                    v-if="isInReview && abilities.canApprove && !hasChanges"
                >
                    <Button
                        variant="outline"
                        data-testid="item-copy-request-changes"
                        @click="openRequestChanges"
                    >
                        <IconMessageReport />
                        {{
                            $t('socialhq_production.item.copy.request_changes')
                        }}
                    </Button>
                    <Button
                        :disabled="latestHasGaps"
                        :aria-describedby="
                            latestHasGaps
                                ? 'socialhq-copy-placeholders'
                                : undefined
                        "
                        data-testid="item-copy-approve"
                        @click="openApprove"
                    >
                        <IconCheck />
                        {{ $t('socialhq_production.item.copy.approve') }}
                    </Button>
                </template>
                <Button
                    v-if="canSubmit"
                    variant="outline"
                    :loading="submitting"
                    data-testid="item-copy-submit"
                    @click="sendForReview"
                >
                    <IconSend />
                    {{ $t('socialhq_production.item.copy.send_for_review') }}
                </Button>
                <Button
                    v-if="!isLocked"
                    :disabled="
                        !hasChanges ||
                        form.caption.trim() === '' ||
                        aiHttp.processing
                    "
                    :loading="form.processing"
                    data-testid="item-copy-save"
                    @click="save"
                >
                    {{ $t('socialhq_production.item.copy.save') }}
                </Button>
            </div>
        </div>
        <p
            v-if="isInReview && !abilities.canApprove"
            class="px-5 pb-3 text-xs text-muted-foreground"
            data-testid="item-copy-waiting"
        >
            {{ $t('socialhq_production.item.copy.in_review_member') }}
        </p>

        <ReviewDecisionDialog
            :open="decisionOpen"
            :mode="decision ?? 'approve'"
            :confirm-label="
                decision === 'approve'
                    ? $t('socialhq_production.item.copy.approve')
                    : $t('socialhq_production.item.copy.request_changes')
            "
            :processing="deciding"
            :error="decisionError"
            @update:open="setDecisionOpen"
            @confirm="confirmDecision"
        />
    </section>
</template>
