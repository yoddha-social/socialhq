<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    IconCheck,
    IconDownload,
    IconFileTypePdf,
    IconMessageReport,
    IconPhoto,
    IconSend,
    IconUpload,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

import ReviewDecisionDialog from '@/components/socialhq/plan/item/ReviewDecisionDialog.vue';
import type {
    BriefDetail,
    BriefFileRow,
    PlanAbilities,
} from '@/components/socialhq/plan/item/types';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import date from '@/date';
import { acceptAttribute } from '@/lib/mediaType';
import {
    approve as approveCreative,
    requestChanges as requestCreativeChanges,
    submit as submitCreative,
} from '@/routes/socialhq/plan/items/brief';
import { store as storeFile } from '@/routes/socialhq/plan/items/brief/files';

const props = defineProps<{
    itemId: number;
    brief: BriefDetail;
    abilities: PlanAbilities;
}>();

const rounds = computed(() => props.brief.rounds ?? []);
const currentRound = computed(() => props.brief.current_round ?? 0);
const acceptsUploads = computed(() => props.brief.accepts_uploads ?? false);
const nextRound = computed(() =>
    props.brief.stage === 'changes_requested'
        ? currentRound.value + 1
        : Math.max(1, currentRound.value),
);
const canSubmit = computed(
    () =>
        currentRound.value > 0 &&
        (props.brief.stage === 'todo' || props.brief.stage === 'in_progress'),
);

const fileInput = ref<HTMLInputElement | null>(null);
const note = ref('');
const uploadingName = ref<string | null>(null);
const accept = acceptAttribute();

const formatSize = (bytes: number): string =>
    bytes >= 1024 * 1024
        ? `${(bytes / (1024 * 1024)).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;

const pickFiles = (): void => {
    fileInput.value?.click();
};

const uploadOne = (file: File): Promise<boolean> =>
    new Promise((resolve) => {
        router.post(
            storeFile.url(props.itemId),
            { file, note: note.value },
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => resolve(true),
                onError: (errors) => {
                    toast.error(
                        errors.file ??
                            trans(
                                'socialhq_production.creative.upload_failed',
                                {
                                    name: file.name,
                                },
                            ),
                    );
                    resolve(false);
                },
                onCancel: () => resolve(false),
            },
        );
    });

const onFilesChosen = async (event: Event): Promise<void> => {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    input.value = '';

    let uploaded = 0;

    for (const file of files) {
        uploadingName.value = file.name;

        if (await uploadOne(file)) {
            uploaded++;
        }
    }

    uploadingName.value = null;

    if (uploaded > 0) {
        note.value = '';
    }
};

const submitting = ref(false);

const sendForReview = (): void => {
    router.post(
        submitCreative.url(props.itemId),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                submitting.value = true;
            },
            onFinish: () => {
                submitting.value = false;
            },
            onError: (errors) => {
                if (errors.creative) {
                    toast.error(errors.creative);
                }
            },
        },
    );
};

const decision = ref<'approve' | 'request_changes'>('approve');
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

const confirmDecision = (decisionNote: string): void => {
    router.post(
        decision.value === 'approve'
            ? approveCreative.url(props.itemId)
            : requestCreativeChanges.url(props.itemId),
        { note: decisionNote },
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
                decisionError.value = errors.note ?? errors.creative ?? '';
            },
        },
    );
};

const downloadUrl = (file: BriefFileRow): string => `${file.url}?download=1`;
</script>

<template>
    <section
        class="rounded-xl border bg-card"
        aria-labelledby="socialhq-creative-title"
        data-testid="item-creative"
    >
        <header
            class="flex flex-wrap items-center justify-between gap-3 px-5 pt-4"
        >
            <h2 id="socialhq-creative-title" class="text-base font-semibold">
                {{ $t('socialhq_production.creative.title') }}
            </h2>
            <div class="flex flex-wrap items-center gap-2">
                <template
                    v-if="brief.stage === 'in_review' && abilities.canApprove"
                >
                    <Button
                        variant="outline"
                        data-testid="item-creative-request-changes"
                        @click="openRequestChanges"
                    >
                        <IconMessageReport />
                        {{ $t('socialhq_production.creative.request_changes') }}
                    </Button>
                    <Button
                        data-testid="item-creative-approve"
                        @click="openApprove"
                    >
                        <IconCheck />
                        {{ $t('socialhq_production.creative.approve') }}
                    </Button>
                </template>
                <Button
                    v-if="canSubmit && abilities.canWork"
                    variant="outline"
                    :loading="submitting"
                    data-testid="item-creative-submit"
                    @click="sendForReview"
                >
                    <IconSend />
                    {{ $t('socialhq_production.creative.send_for_review') }}
                </Button>
            </div>
        </header>

        <p
            v-if="brief.stage === 'approved'"
            class="mx-5 mt-3 rounded-lg bg-success-subtle px-3 py-2 text-sm text-success-text"
            data-testid="item-creative-approved"
        >
            {{ $t('socialhq_production.creative.approved') }}
        </p>
        <p
            v-else-if="brief.stage === 'in_review'"
            class="mx-5 mt-3 rounded-lg bg-info-subtle px-3 py-2 text-sm text-info-text"
        >
            {{
                abilities.canApprove
                    ? $t('socialhq_production.creative.waiting_review')
                    : $t('socialhq_production.creative.in_review_member')
            }}
        </p>

        <div
            v-if="acceptsUploads && abilities.canWork"
            class="mx-5 mt-4 grid gap-3 rounded-lg border border-dashed p-4"
            data-testid="item-creative-upload"
        >
            <div class="grid gap-2">
                <Label for="socialhq-creative-note">
                    {{ $t('socialhq_production.creative.note_label') }}
                </Label>
                <Textarea
                    id="socialhq-creative-note"
                    v-model="note"
                    class="min-h-12"
                    :placeholder="
                        $t('socialhq_production.creative.note_placeholder')
                    "
                    data-testid="item-creative-note"
                />
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-muted-foreground">
                    {{ $t('socialhq_production.creative.accepted') }}
                    <template v-if="brief.stage === 'changes_requested'">
                        {{
                            $t('socialhq_production.creative.next_round', {
                                round: String(nextRound),
                            })
                        }}
                    </template>
                </p>
                <input
                    ref="fileInput"
                    type="file"
                    class="sr-only"
                    multiple
                    :accept="accept"
                    data-testid="item-creative-file"
                    @change="onFilesChosen"
                />
                <Button
                    class="min-w-36"
                    :loading="uploadingName !== null"
                    data-testid="item-creative-pick"
                    @click="pickFiles"
                >
                    <IconUpload />
                    {{ $t('socialhq_production.creative.upload') }}
                </Button>
            </div>
            <p
                v-if="uploadingName"
                class="text-xs text-muted-foreground"
                role="status"
            >
                {{
                    $t('socialhq_production.creative.uploading', {
                        name: uploadingName,
                    })
                }}
            </p>
        </div>

        <p
            v-if="rounds.length === 0"
            class="px-5 py-4 text-sm text-muted-foreground"
            data-testid="item-creative-empty"
        >
            {{ $t('socialhq_production.creative.empty') }}
        </p>

        <ol v-else class="grid gap-5 px-5 py-4">
            <li
                v-for="round in rounds"
                :key="round.round"
                :data-testid="`item-creative-round-${round.round}`"
            >
                <h3
                    class="mb-2 flex items-center gap-2 text-sm font-medium text-muted-foreground"
                >
                    {{
                        $t('socialhq_production.creative.round', {
                            round: String(round.round),
                        })
                    }}
                    <span
                        v-if="round.round === currentRound"
                        class="text-xs font-normal"
                    >
                        · {{ $t('socialhq_production.creative.round_current') }}
                    </span>
                </h3>
                <ul class="grid gap-3 sm:grid-cols-2">
                    <li
                        v-for="file in round.files"
                        :key="file.id"
                        class="overflow-hidden rounded-lg border bg-background"
                        :data-testid="`item-creative-file-${file.id}`"
                    >
                        <a
                            v-if="file.type === 'image'"
                            :href="file.url"
                            target="_blank"
                            rel="noopener"
                            class="block aspect-[4/3] bg-muted"
                            :aria-label="
                                $t('socialhq_production.creative.open_file', {
                                    name: file.name,
                                })
                            "
                        >
                            <img
                                :src="file.url"
                                :alt="file.name"
                                loading="lazy"
                                class="size-full object-contain"
                            />
                        </a>
                        <video
                            v-else-if="file.type === 'video'"
                            :src="file.url"
                            controls
                            preload="metadata"
                            class="aspect-video w-full bg-black"
                        />
                        <a
                            v-else
                            :href="file.url"
                            target="_blank"
                            rel="noopener"
                            class="flex aspect-[4/3] items-center justify-center bg-muted text-muted-foreground"
                            :aria-label="
                                $t('socialhq_production.creative.open_file', {
                                    name: file.name,
                                })
                            "
                        >
                            <IconFileTypePdf
                                v-if="file.type === 'document'"
                                class="size-10"
                            />
                            <IconPhoto v-else class="size-10" />
                        </a>
                        <div class="grid gap-1 px-3 py-2">
                            <div
                                class="flex items-center justify-between gap-2"
                            >
                                <p class="truncate text-sm font-medium">
                                    {{ file.name }}
                                </p>
                                <a
                                    :href="downloadUrl(file)"
                                    class="shrink-0 text-muted-foreground hover:text-foreground"
                                    :aria-label="
                                        $t(
                                            'socialhq_production.creative.download',
                                        )
                                    "
                                    :title="
                                        $t(
                                            'socialhq_production.creative.download',
                                        )
                                    "
                                >
                                    <IconDownload class="size-4" />
                                </a>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                {{ formatSize(file.size) }} ·
                                {{
                                    $t(
                                        'socialhq_production.creative.uploaded_by',
                                        {
                                            name: file.uploader?.name ?? '—',
                                            time: date.formatDateTime(
                                                file.created_at,
                                            ),
                                        },
                                    )
                                }}
                            </p>
                            <p
                                v-if="file.note"
                                class="text-sm whitespace-pre-wrap"
                            >
                                {{ file.note }}
                            </p>
                        </div>
                    </li>
                </ul>
            </li>
        </ol>

        <ReviewDecisionDialog
            :open="decisionOpen"
            :mode="decision"
            :confirm-label="
                decision === 'approve'
                    ? $t('socialhq_production.creative.approve')
                    : $t('socialhq_production.creative.request_changes')
            "
            :processing="deciding"
            :error="decisionError"
            @update:open="setDecisionOpen"
            @confirm="confirmDecision"
        />
    </section>
</template>
