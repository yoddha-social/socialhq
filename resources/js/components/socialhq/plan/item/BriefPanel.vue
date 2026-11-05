<script setup lang="ts">
import { Link, router, useForm, useHttp } from '@inertiajs/vue3';
import {
    IconExternalLink,
    IconLayoutKanban,
    IconPalette,
    IconSparkles,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import type {
    BriefDetail,
    BriefStage,
    PlanAbilities,
    PlanPerson,
} from '@/components/socialhq/plan/item/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { extractErrorMessage } from '@/lib/httpError';
import { production } from '@/routes/socialhq/plan';
import {
    ai as draftWithAi,
    store as storeBrief,
    update as updateBrief,
} from '@/routes/socialhq/plan/items/brief';

const props = defineProps<{
    itemId: number;
    brief: BriefDetail | null;
    briefKind: 'graphic' | 'video' | null;
    team: PlanPerson[];
    abilities: PlanAbilities;
    aiAvailable: boolean;
}>();

type BriefFields = {
    kind: 'graphic' | 'video';
    assignee_id: string;
    due_on: string;
    format_notes: string;
    on_screen_text: string;
    direction: string;
    references: string;
};

type DraftField = 'on_screen_text' | 'direction' | 'references';

const DRAFT_FIELDS: DraftField[] = [
    'on_screen_text',
    'direction',
    'references',
];

const fieldsOf = (brief: BriefDetail | null): BriefFields => ({
    kind: brief?.kind ?? props.briefKind ?? 'graphic',
    assignee_id: brief?.assignee?.id ?? '',
    due_on: brief?.due_on ?? '',
    format_notes: brief?.format_notes ?? '',
    on_screen_text: brief?.on_screen_text ?? '',
    direction: brief?.direction ?? '',
    references: brief?.references ?? '',
});

const form = useForm<BriefFields>(fieldsOf(props.brief));

watch(
    () => props.brief,
    (brief) => {
        if (form.isDirty) {
            return;
        }

        form.defaults(fieldsOf(brief));
        form.reset();
    },
);

const stageVariant = (
    stage: BriefStage,
): 'secondary' | 'info' | 'warning' | 'success' | 'outline' => {
    switch (stage) {
        case 'in_progress':
            return 'secondary';
        case 'in_review':
            return 'info';
        case 'changes_requested':
            return 'warning';
        case 'approved':
            return 'success';
        default:
            return 'outline';
    }
};

const referenceLinks = computed(() =>
    form.references
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => /^https?:\/\/\S+$/i.test(line)),
);

const creating = ref(false);

const createBrief = (): void => {
    router.post(
        storeBrief.url(props.itemId),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                creating.value = true;
            },
            onFinish: () => {
                creating.value = false;
            },
        },
    );
};

const save = (): void => {
    form.transform((data) => ({
        ...data,
        assignee_id: data.assignee_id === '' ? null : data.assignee_id,
        due_on: data.due_on === '' ? null : data.due_on,
    })).put(updateBrief.url(props.itemId), {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults();
            toast.success(trans('socialhq_production.brief.saved'));
        },
    });
};

const setAssignee = (value: unknown): void => {
    form.assignee_id = typeof value === 'string' ? value : '';
};

const setKind = (value: unknown): void => {
    form.kind = value === 'video' ? 'video' : 'graphic';
};

const aiHttp = useHttp<Record<string, never>, Record<DraftField, string>>({});
const aiError = ref('');
const pending = ref<Partial<Record<DraftField, string>>>({});
const hasPending = computed(() => Object.keys(pending.value).length > 0);

onBeforeUnmount(() => {
    aiHttp.cancel();
});

const draftBrief = async (): Promise<void> => {
    if (aiHttp.processing) {
        return;
    }

    aiError.value = '';
    pending.value = {};

    try {
        const draft = await aiHttp.post(draftWithAi.url(props.itemId));

        if (!draft) {
            aiError.value = trans('socialhq_production.brief.ai_failed');

            return;
        }

        const conflicts: Partial<Record<DraftField, string>> = {};

        DRAFT_FIELDS.forEach((field) => {
            const suggestion = (draft[field] ?? '').trim();

            if (suggestion === '') {
                return;
            }

            if (form[field].trim() === '') {
                form[field] = suggestion;
            } else if (form[field].trim() !== suggestion) {
                conflicts[field] = suggestion;
            }
        });

        pending.value = conflicts;

        if (!hasPending.value) {
            toast.success(trans('socialhq_production.brief.ai_filled'));
        }
    } catch (exception) {
        aiError.value =
            extractErrorMessage(exception) ??
            trans('socialhq_production.brief.ai_failed');
    }
};

const keepMine = (): void => {
    pending.value = {};
};

const replaceWithDraft = (): void => {
    (Object.keys(pending.value) as DraftField[]).forEach((field) => {
        form[field] = pending.value[field] ?? form[field];
    });
    pending.value = {};
};
</script>

<template>
    <section
        class="rounded-xl border bg-card"
        aria-labelledby="socialhq-brief-title"
        data-testid="item-brief"
    >
        <header
            class="flex flex-wrap items-center justify-between gap-3 px-5 pt-4"
        >
            <h2
                id="socialhq-brief-title"
                class="flex items-center gap-2 text-base font-semibold"
            >
                <IconPalette class="size-4 text-muted-foreground" />
                {{ $t('socialhq_production.brief.title') }}
                <Badge
                    v-if="brief"
                    :variant="stageVariant(brief.stage)"
                    data-testid="item-brief-stage"
                >
                    {{ $t(`socialhq_production.brief.stages.${brief.stage}`) }}
                </Badge>
            </h2>
            <div v-if="brief" class="flex flex-wrap items-center gap-2">
                <Button
                    v-if="aiAvailable && abilities.canWork"
                    variant="ghost"
                    class="min-w-44 bg-primary-subtle text-primary-text hover:bg-primary-subtle/80"
                    :loading="aiHttp.processing"
                    data-testid="item-brief-ai"
                    @click="draftBrief"
                >
                    <IconSparkles />
                    {{ $t('socialhq_production.brief.draft_ai') }}
                </Button>
                <Button variant="outline" as-child>
                    <Link
                        :href="production.url({ query: { brief: brief.id } })"
                        data-testid="item-brief-board"
                    >
                        <IconLayoutKanban />
                        {{ $t('socialhq_production.brief.open_board') }}
                    </Link>
                </Button>
            </div>
        </header>

        <div
            v-if="!brief"
            class="flex flex-wrap items-center justify-between gap-3 px-5 py-4"
            data-testid="item-brief-needed"
        >
            <p class="text-sm text-muted-foreground">
                {{ $t(`socialhq_production.brief.needed.${briefKind}`) }}
            </p>
            <Button
                v-if="abilities.canWork"
                :loading="creating"
                data-testid="item-brief-create"
                @click="createBrief"
            >
                {{ $t('socialhq_production.brief.create') }}
            </Button>
        </div>

        <form
            v-else
            class="grid gap-5 px-5 pt-4 pb-5"
            data-testid="item-brief-form"
            @submit.prevent="save"
        >
            <p
                v-if="aiError"
                class="text-sm text-destructive-text"
                role="alert"
                data-testid="item-brief-ai-error"
            >
                {{ aiError }}
            </p>
            <div
                v-if="hasPending"
                class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-info-subtle px-3 py-2 text-sm text-info-text"
                role="status"
                data-testid="item-brief-ai-conflict"
            >
                <span>{{ $t('socialhq_production.brief.ai_conflict') }}</span>
                <span class="flex gap-2">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        data-testid="item-brief-ai-keep"
                        @click="keepMine"
                    >
                        {{ $t('socialhq_production.brief.ai_keep') }}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        data-testid="item-brief-ai-replace"
                        @click="replaceWithDraft"
                    >
                        {{ $t('socialhq_production.brief.ai_replace') }}
                    </Button>
                </span>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="socialhq-brief-kind">
                        {{ $t('socialhq_production.brief.kind') }}
                    </Label>
                    <NativeSelect
                        id="socialhq-brief-kind"
                        :model-value="form.kind"
                        class="w-full"
                        data-testid="item-brief-kind"
                        @update:model-value="setKind"
                    >
                        <NativeSelectOption value="graphic">
                            {{ $t('socialhq_production.brief.kinds.graphic') }}
                        </NativeSelectOption>
                        <NativeSelectOption value="video">
                            {{ $t('socialhq_production.brief.kinds.video') }}
                        </NativeSelectOption>
                    </NativeSelect>
                </div>
                <div class="grid gap-2">
                    <Label for="socialhq-brief-assignee">
                        {{ $t('socialhq_production.brief.assignee') }}
                    </Label>
                    <NativeSelect
                        id="socialhq-brief-assignee"
                        :model-value="form.assignee_id"
                        class="w-full"
                        :aria-invalid="
                            form.errors.assignee_id ? true : undefined
                        "
                        data-testid="item-brief-assignee"
                        @update:model-value="setAssignee"
                    >
                        <NativeSelectOption value="">
                            {{ $t('socialhq_production.brief.unassigned') }}
                        </NativeSelectOption>
                        <NativeSelectOption
                            v-for="person in team"
                            :key="person.id"
                            :value="person.id"
                        >
                            {{ person.name }}
                        </NativeSelectOption>
                    </NativeSelect>
                    <p
                        v-if="form.errors.assignee_id"
                        class="text-sm text-destructive-text"
                    >
                        {{ form.errors.assignee_id }}
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="socialhq-brief-due">
                        {{ $t('socialhq_production.brief.due_on') }}
                    </Label>
                    <Input
                        id="socialhq-brief-due"
                        v-model="form.due_on"
                        type="date"
                        :aria-invalid="form.errors.due_on ? true : undefined"
                        data-testid="item-brief-due"
                    />
                    <p
                        v-if="form.errors.due_on"
                        class="text-sm text-destructive-text"
                    >
                        {{ form.errors.due_on }}
                    </p>
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="socialhq-brief-format">
                    {{ $t('socialhq_production.brief.format_notes') }}
                </Label>
                <Textarea
                    id="socialhq-brief-format"
                    v-model="form.format_notes"
                    class="min-h-16"
                    data-testid="item-brief-format"
                />
            </div>
            <div class="grid gap-2">
                <Label for="socialhq-brief-text">
                    {{ $t('socialhq_production.brief.on_screen_text') }}
                </Label>
                <Textarea
                    id="socialhq-brief-text"
                    v-model="form.on_screen_text"
                    class="min-h-24"
                    :placeholder="
                        $t(
                            'socialhq_production.brief.on_screen_text_placeholder',
                        )
                    "
                    data-testid="item-brief-text"
                />
            </div>
            <div class="grid gap-2">
                <Label for="socialhq-brief-direction">
                    {{ $t('socialhq_production.brief.direction') }}
                </Label>
                <Textarea
                    id="socialhq-brief-direction"
                    v-model="form.direction"
                    class="min-h-32"
                    :placeholder="
                        $t('socialhq_production.brief.direction_placeholder')
                    "
                    data-testid="item-brief-direction"
                />
            </div>
            <div class="grid gap-2">
                <Label for="socialhq-brief-references">
                    {{ $t('socialhq_production.brief.references') }}
                </Label>
                <Textarea
                    id="socialhq-brief-references"
                    v-model="form.references"
                    class="min-h-20"
                    :placeholder="
                        $t('socialhq_production.brief.references_placeholder')
                    "
                    data-testid="item-brief-references"
                />
                <ul
                    v-if="referenceLinks.length"
                    class="grid gap-1 text-sm"
                    data-testid="item-brief-reference-links"
                >
                    <li v-for="link in referenceLinks" :key="link">
                        <a
                            :href="link"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex max-w-full items-center gap-1 text-primary-text hover:underline"
                        >
                            <IconExternalLink class="size-3.5 shrink-0" />
                            <span class="truncate">{{ link }}</span>
                        </a>
                    </li>
                </ul>
            </div>

            <div v-if="abilities.canWork" class="flex justify-end">
                <Button
                    type="submit"
                    :disabled="!form.isDirty"
                    :loading="form.processing"
                    data-testid="item-brief-save"
                >
                    {{ $t('socialhq_production.brief.save') }}
                </Button>
            </div>
        </form>

        <slot :brief="brief" />
    </section>
</template>
