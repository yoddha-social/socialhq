<script setup lang="ts">
import { useHttp, usePage } from '@inertiajs/vue3';
import { IconPhotoPlus, IconShieldCheck, IconX } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { attach, index, show, store } from '@/routes/socialhq/ai-graphics';

// Social HQ: "Generate with AI" from the media menu of ideas and posts. The
// request is vision-checked while the dialog waits, then generated (and
// checked again) in the background; the finished graphic is handed to the
// composer as a media import.

const FORMATS = ['square', 'portrait', 'story', 'landscape'] as const;
const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const POLL_INTERVAL_MS = 2000;
// Up to 10 minutes: longer than the slowest generation the server allows.
const MAX_POLLS = 300;

type Phase = 'idle' | 'checking' | 'generating';

interface AiGraphicsProps {
    aiGraphics?: { maxReferences?: number; maxReferenceKilobytes?: number };
}

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    started: [payload: { importId: string; label: string }];
}>();

const page = usePage();
const maxImages = computed(
    () =>
        (page.props.socialhq as AiGraphicsProps | undefined)?.aiGraphics
            ?.maxReferences ?? 3,
);
const maxKilobytes = computed(
    () =>
        (page.props.socialhq as AiGraphicsProps | undefined)?.aiGraphics
            ?.maxReferenceKilobytes ?? 10240,
);

const http = useHttp<
    {
        prompt: string;
        format: (typeof FORMATS)[number];
        references: File[];
        use_brand_look: boolean;
        use_brand_logo: boolean;
    },
    { import_id: string }
>({
    prompt: '',
    format: 'square',
    references: [],
    use_brand_look: true,
    use_brand_logo: false,
});

// Brand look (Settings › Brand knowledge): what this brand can add.
interface BrandOffer {
    look: boolean;
    logo: string | null;
    vectorLogo: boolean;
}

const brand = ref<BrandOffer>({ look: false, logo: null, vectorLogo: false });

// The brand's logo, when added, takes one of the reference places.
const maxReferences = computed(
    () =>
        maxImages.value -
        (http.use_brand_logo && brand.value.logo !== null ? 1 : 0),
);

const phase = ref<Phase>('idle');
const message = ref<string | null>(null);
const previews = ref<string[]>([]);
const fileInput = ref<HTMLInputElement | null>(null);
let cancelled = false;

const busy = computed(() => phase.value !== 'idle');

const clearPreviews = (): void => {
    previews.value.forEach((url) => URL.revokeObjectURL(url));
    previews.value = [];
};

const reset = (): void => {
    http.reset();
    http.clearErrors();
    clearPreviews();
    phase.value = 'idle';
    message.value = null;
};

interface RecentGraphic {
    import_id: string;
    prompt: string;
    preview_url: string;
}

const recent = ref<RecentGraphic[]>([]);
const attaching = ref<string | null>(null);

// Graphics finished after their editor closed can be attached from here.
const loadRecent = async (): Promise<void> => {
    try {
        const response = await useHttp<
            Record<string, never>,
            { graphics: RecentGraphic[]; brand?: BrandOffer }
        >({}).get(index.url());
        recent.value = response?.graphics ?? [];
        brand.value = response?.brand ?? {
            look: false,
            logo: null,
            vectorLogo: false,
        };
    } catch {
        recent.value = [];
    }
};

const useRecent = async (graphic: RecentGraphic): Promise<void> => {
    if (attaching.value) {
        return;
    }

    attaching.value = graphic.import_id;
    message.value = null;

    try {
        const response = await useHttp<
            Record<string, never>,
            { import_id: string }
        >({}).post(attach.url(graphic.import_id));

        if (response?.import_id) {
            emit('started', {
                importId: response.import_id,
                label: trans('socialhq.ai_graphics.label'),
            });
            open.value = false;
        }
    } catch {
        message.value = trans('socialhq.ai_graphics.errors.attach_failed');
    } finally {
        attaching.value = null;
    }
};

// Closing the dialog while generating keeps waiting: the graphic is still
// attached when it is done, while the editor stays open; otherwise it shows
// up under "Your recent graphics".
watch(open, (isOpen) => {
    if (isOpen) {
        void loadRecent();
    } else if (phase.value === 'idle') {
        reset();
    }
});

onBeforeUnmount(() => {
    cancelled = true;
    clearPreviews();
});

const addFiles = (picked: File[]): void => {
    if (busy.value || picked.length === 0) {
        return;
    }

    message.value = null;

    if (picked.some((file) => !ACCEPTED_TYPES.includes(file.type))) {
        message.value = trans('socialhq.ai_graphics.errors.reference_type');

        return;
    }

    if (picked.some((file) => file.size > maxKilobytes.value * 1024)) {
        message.value = trans('socialhq.ai_graphics.errors.reference_size', {
            size: String(Math.round(maxKilobytes.value / 1024)),
        });

        return;
    }

    if (http.references.length + picked.length > maxReferences.value) {
        message.value = trans(
            'socialhq.ai_graphics.errors.too_many_references',
            { count: String(maxReferences.value) },
        );

        return;
    }

    http.references = [...http.references, ...picked];
    previews.value = [
        ...previews.value,
        ...picked.map((file) => URL.createObjectURL(file)),
    ];
};

const addReferences = (event: Event): void => {
    const input = event.target as HTMLInputElement;
    const picked = Array.from(input.files ?? []);
    input.value = '';
    addFiles(picked);
};

const canAddMore = computed(
    () => !busy.value && http.references.length < maxReferences.value,
);

// Drag and drop: the counter keeps the highlight while the pointer moves
// over the zone's children.
const dragDepth = ref(0);
const dragging = computed(() => dragDepth.value > 0);

const onDragEnter = (): void => {
    dragDepth.value++;
};

const onDragLeave = (): void => {
    dragDepth.value = Math.max(0, dragDepth.value - 1);
};

const onDrop = (event: DragEvent): void => {
    dragDepth.value = 0;

    if (!canAddMore.value) {
        return;
    }

    addFiles(Array.from(event.dataTransfer?.files ?? []));
};

const browse = (): void => {
    if (canAddMore.value) {
        fileInput.value?.click();
    }
};

// Pasting an image anywhere in the dialog adds it too.
const onPaste = (event: ClipboardEvent): void => {
    const images = Array.from(event.clipboardData?.files ?? []).filter((file) =>
        file.type.startsWith('image/'),
    );

    if (images.length > 0) {
        event.preventDefault();
        addFiles(images);
    }
};

const setUseBrandLook = (value: boolean): void => {
    http.use_brand_look = value;
};

const setUseBrandLogo = (value: boolean): void => {
    http.use_brand_logo = value;
};

const removeReference = (index: number): void => {
    URL.revokeObjectURL(previews.value[index]);
    previews.value = previews.value.filter((_, i) => i !== index);
    http.references = http.references.filter((_, i) => i !== index);
};

const wait = (milliseconds: number): Promise<void> =>
    new Promise((resolve) => window.setTimeout(resolve, milliseconds));

const poll = async (importId: string): Promise<void> => {
    const status = useHttp<
        Record<string, never>,
        { status: string; reason: string | null }
    >({});

    for (let attempt = 0; attempt < MAX_POLLS && !cancelled; attempt++) {
        await wait(POLL_INTERVAL_MS);

        let result: { status: string; reason: string | null };

        try {
            result = await status.get(show.url(importId));
        } catch {
            continue;
        }

        if (result.status === 'generated') {
            emit('started', {
                importId,
                label: trans('socialhq.ai_graphics.label'),
            });
            reset();
            open.value = false;

            return;
        }

        if (result.status !== 'pending') {
            phase.value = 'idle';
            message.value =
                result.reason ??
                trans('socialhq.ai_graphics.errors.generation_failed');

            return;
        }
    }

    phase.value = 'idle';
};

const submit = async (): Promise<void> => {
    if (busy.value) {
        return;
    }

    message.value = null;
    phase.value = 'checking';

    let started: { import_id: string } | undefined;

    try {
        // A validation error (including a blocked request) resolves with
        // nothing and fills http.errors.
        started = await http.post(store.url());
    } catch {
        message.value = trans('socialhq.ai_graphics.errors.generation_failed');
    }

    if (!started) {
        phase.value = 'idle';

        return;
    }

    phase.value = 'generating';
    await poll(started.import_id);
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="sm:max-w-lg"
            data-testid="socialhq-ai-graphic-dialog"
        >
            <DialogHeader class="text-start">
                <DialogTitle>{{
                    $t('socialhq.ai_graphics.title')
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t('socialhq.ai_graphics.description', {
                            count: String(maxReferences),
                        })
                    }}
                </DialogDescription>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="submit" @paste="onPaste">
                <div class="grid gap-2">
                    <Label for="socialhq-ai-graphic-prompt">{{
                        $t('socialhq.ai_graphics.prompt_label')
                    }}</Label>
                    <Textarea
                        id="socialhq-ai-graphic-prompt"
                        v-model="http.prompt"
                        rows="4"
                        maxlength="2000"
                        :disabled="busy"
                        :placeholder="
                            $t('socialhq.ai_graphics.prompt_placeholder')
                        "
                        data-testid="socialhq-ai-graphic-prompt"
                    />
                    <p
                        v-if="http.errors.prompt"
                        class="text-sm text-destructive"
                        data-testid="socialhq-ai-graphic-error"
                    >
                        {{ http.errors.prompt }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label>{{
                        $t('socialhq.ai_graphics.references_label')
                    }}</Label>
                    <div
                        role="button"
                        :tabindex="canAddMore ? 0 : -1"
                        :aria-disabled="!canAddMore"
                        :class="[
                            'flex flex-col items-center justify-center gap-1.5 rounded-xl border-2 border-dashed px-4 py-6 text-center transition-control',
                            dragging && canAddMore
                                ? 'border-primary bg-primary-subtle text-primary-text'
                                : 'border-border text-muted-foreground',
                            canAddMore
                                ? 'cursor-pointer hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring'
                                : 'cursor-not-allowed opacity-60',
                        ]"
                        data-testid="socialhq-ai-graphic-drop-zone"
                        @click="browse"
                        @keydown.enter.prevent="browse"
                        @keydown.space.prevent="browse"
                        @dragenter.prevent.stop="onDragEnter"
                        @dragover.prevent.stop
                        @dragleave.prevent.stop="onDragLeave"
                        @drop.prevent.stop="onDrop"
                    >
                        <IconPhotoPlus class="size-6" />
                        <span class="text-sm font-medium text-foreground">
                            {{
                                !canAddMore && !busy
                                    ? $t('socialhq.ai_graphics.drop_full', {
                                          count: String(maxReferences),
                                      })
                                    : dragging
                                      ? $t('socialhq.ai_graphics.drop_active')
                                      : $t('socialhq.ai_graphics.drop_title')
                            }}
                        </span>
                        <span class="text-xs">
                            {{
                                $t('socialhq.ai_graphics.drop_hint', {
                                    count: String(maxReferences),
                                    size: String(
                                        Math.round(maxKilobytes / 1024),
                                    ),
                                })
                            }}
                        </span>
                    </div>
                    <div
                        v-if="previews.length > 0"
                        class="flex flex-wrap gap-2"
                        data-testid="socialhq-ai-graphic-references"
                    >
                        <div
                            v-for="(preview, index) in previews"
                            :key="preview"
                            class="relative size-16 overflow-hidden rounded-lg border border-border"
                        >
                            <img
                                :src="preview"
                                alt=""
                                class="size-full object-cover"
                            />
                            <button
                                type="button"
                                class="absolute end-0.5 top-0.5 flex size-5 items-center justify-center rounded-full bg-background/90 text-foreground"
                                :disabled="busy"
                                :aria-label="
                                    $t(
                                        'socialhq.ai_graphics.remove_reference',
                                        {
                                            name:
                                                http.references[index]?.name ??
                                                '',
                                        },
                                    )
                                "
                                @click="removeReference(index)"
                            >
                                <IconX class="size-3" />
                            </button>
                        </div>
                    </div>
                    <input
                        ref="fileInput"
                        type="file"
                        class="hidden"
                        accept="image/jpeg,image/png,image/webp"
                        multiple
                        data-testid="socialhq-ai-graphic-reference-input"
                        @change="addReferences"
                    />
                    <p
                        v-if="
                            http.errors['references.0'] ||
                            http.errors.references
                        "
                        class="text-sm text-destructive"
                    >
                        {{
                            http.errors['references.0'] ??
                            http.errors.references
                        }}
                    </p>
                </div>

                <div
                    v-if="brand.look || brand.logo || brand.vectorLogo"
                    class="grid gap-3"
                    data-testid="socialhq-ai-graphic-brand"
                >
                    <div
                        v-if="brand.look"
                        class="flex items-center justify-between gap-4"
                    >
                        <Label for="socialhq-ai-graphic-brand-look">
                            {{
                                $t('socialhq_knowledge.look.graphics.use_look')
                            }}
                        </Label>
                        <Switch
                            id="socialhq-ai-graphic-brand-look"
                            :model-value="http.use_brand_look"
                            :disabled="busy"
                            data-testid="socialhq-ai-graphic-brand-look"
                            @update:model-value="
                                setUseBrandLook(Boolean($event))
                            "
                        />
                    </div>
                    <div
                        v-if="brand.logo"
                        class="flex items-center justify-between gap-4"
                    >
                        <div class="flex items-center gap-2">
                            <img
                                :src="brand.logo"
                                alt=""
                                class="size-8 rounded border border-border object-contain"
                            />
                            <Label for="socialhq-ai-graphic-brand-logo">
                                {{
                                    $t(
                                        'socialhq_knowledge.look.graphics.add_logo',
                                    )
                                }}
                            </Label>
                        </div>
                        <Switch
                            id="socialhq-ai-graphic-brand-logo"
                            :model-value="http.use_brand_logo"
                            :disabled="
                                busy ||
                                (!http.use_brand_logo &&
                                    http.references.length >= maxImages)
                            "
                            data-testid="socialhq-ai-graphic-brand-logo"
                            @update:model-value="
                                setUseBrandLogo(Boolean($event))
                            "
                        />
                    </div>
                    <p
                        v-else-if="brand.vectorLogo"
                        class="text-xs text-muted-foreground"
                    >
                        {{ $t('socialhq_knowledge.look.graphics.vector_logo') }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label>{{ $t('socialhq.ai_graphics.format_label') }}</Label>
                    <div
                        class="grid grid-cols-4 gap-2"
                        role="radiogroup"
                        :aria-label="$t('socialhq.ai_graphics.format_label')"
                    >
                        <button
                            v-for="format in FORMATS"
                            :key="format"
                            type="button"
                            role="radio"
                            :aria-checked="http.format === format"
                            :disabled="busy"
                            :class="[
                                'rounded-lg border px-2 py-1.5 text-sm transition-control',
                                http.format === format
                                    ? 'border-primary bg-primary-subtle text-primary-text'
                                    : 'border-border hover:bg-accent',
                            ]"
                            :data-testid="`socialhq-ai-graphic-format-${format}`"
                            @click="http.format = format"
                        >
                            {{ $t(`socialhq.ai_graphics.formats.${format}`) }}
                        </button>
                    </div>
                </div>

                <p
                    v-if="message"
                    class="rounded-lg border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive"
                    data-testid="socialhq-ai-graphic-message"
                >
                    {{ message }}
                </p>

                <p
                    v-if="busy"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                    data-testid="socialhq-ai-graphic-progress"
                >
                    <Spinner />
                    {{
                        phase === 'checking'
                            ? $t('socialhq.ai_graphics.checking')
                            : $t('socialhq.ai_graphics.generating')
                    }}
                </p>

                <div
                    v-if="recent.length > 0"
                    class="grid gap-2"
                    data-testid="socialhq-ai-graphic-recent"
                >
                    <Label>{{ $t('socialhq.ai_graphics.recent_label') }}</Label>
                    <div class="flex gap-2 overflow-x-auto pb-1">
                        <button
                            v-for="graphic in recent"
                            :key="graphic.import_id"
                            type="button"
                            class="relative size-16 shrink-0 overflow-hidden rounded-lg border border-border hover:ring-2 hover:ring-ring disabled:opacity-50"
                            :title="graphic.prompt"
                            :aria-label="
                                $t('socialhq.ai_graphics.use_recent', {
                                    prompt: graphic.prompt,
                                })
                            "
                            :disabled="attaching !== null"
                            :data-testid="`socialhq-ai-graphic-recent-${graphic.import_id}`"
                            @click="useRecent(graphic)"
                        >
                            <img
                                :src="graphic.preview_url"
                                alt=""
                                loading="lazy"
                                class="size-full object-cover"
                            />
                            <span
                                v-if="attaching === graphic.import_id"
                                class="absolute inset-0 flex items-center justify-center bg-background/70"
                            >
                                <Spinner />
                            </span>
                        </button>
                    </div>
                </div>

                <p class="flex items-start gap-2 text-xs text-muted-foreground">
                    <IconShieldCheck class="mt-px size-3.5 shrink-0" />
                    {{ $t('socialhq.ai_graphics.review_note') }}
                </p>

                <DialogFooter>
                    <Button
                        type="submit"
                        :disabled="busy || http.prompt.trim().length < 3"
                        data-testid="socialhq-ai-graphic-submit"
                    >
                        <Spinner v-if="busy" />
                        {{ $t('socialhq.ai_graphics.submit') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
