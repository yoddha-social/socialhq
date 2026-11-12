<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { IconPhoto, IconPlus, IconTrash, IconUpload } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

import type {
    BrandColour,
    BrandLookProp,
} from '@/components/socialhq/brandSources';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { update as updateLook } from '@/routes/socialhq/brand/look';
import {
    destroy as destroyLogo,
    store as storeLogo,
} from '@/routes/socialhq/brand/look/logo';

/**
 * "Brand look" on Brand knowledge: the logo, brand colours, fonts and the
 * overall visual style. Briefs and AI graphics follow it. Admins change
 * it; members see it.
 */
const props = defineProps<{
    look: BrandLookProp;
    canEdit: boolean;
}>();

const HEX = /^#[0-9a-fA-F]{6}$/;
const LOGO_ACCEPT = 'image/png,image/jpeg,image/webp,image/svg+xml,.svg';

const form = useForm<{
    colours: BrandColour[];
    heading_font: string;
    body_font: string;
    style_notes: string;
}>({
    colours: props.look.colours.map((colour) => ({ ...colour })),
    heading_font: props.look.heading_font,
    body_font: props.look.body_font,
    style_notes: props.look.style_notes,
});

// A website suggestion accepted elsewhere on the page changes the look:
// show it, unless the admin is editing the form.
watch(
    () => props.look,
    (look) => {
        if (!form.isDirty) {
            form.defaults({
                colours: look.colours.map((colour) => ({ ...colour })),
                heading_font: look.heading_font,
                body_font: look.body_font,
                style_notes: look.style_notes,
            });
            form.reset();
        }
    },
);

const logoForm = useForm<{ logo: File | null }>({ logo: null });
const logoInput = ref<HTMLInputElement | null>(null);
const isRemoveOpen = ref(false);
const isRemoving = ref(false);

const errors = computed(() => form.errors as Record<string, string>);
const canAddColour = computed(
    () => props.canEdit && form.colours.length < props.look.maxColours,
);
const logoMegabytes = computed(() =>
    String(Math.max(1, Math.round(props.look.maxLogoKilobytes / 1024))),
);

const colourError = (index: number): string | undefined =>
    errors.value[`colours.${index}.hex`] ??
    errors.value[`colours.${index}.name`];

/** The swatch: the typed value while it is a full hex colour. */
const swatchOf = (colour: BrandColour): string =>
    HEX.test(colour.hex) ? colour.hex : '#ffffff';

const addColour = (): void => {
    if (canAddColour.value) {
        form.colours = [...form.colours, { name: '', hex: '#000000' }];
    }
};

const removeColour = (index: number): void => {
    form.colours = form.colours.filter((_, position) => position !== index);
};

const pickColour = (index: number, event: Event): void => {
    form.colours[index].hex = (event.target as HTMLInputElement).value;
};

const save = (): void => {
    form.put(updateLook.url(), {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults();
        },
    });
};

const chooseLogo = (): void => {
    logoInput.value?.click();
};

const uploadLogo = (event: Event): void => {
    const input = event.target as HTMLInputElement;
    logoForm.logo = input.files?.[0] ?? null;
    input.value = '';

    if (logoForm.logo === null) {
        return;
    }

    logoForm.post(storeLogo.url(), {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            logoForm.logo = null;
        },
    });
};

const openRemoveLogo = (): void => {
    isRemoveOpen.value = true;
};

const closeRemoveLogo = (): void => {
    isRemoveOpen.value = false;
};

const removeLogo = (): void => {
    router.delete(destroyLogo.url(), {
        preserveScroll: true,
        onStart: () => {
            isRemoving.value = true;
        },
        onFinish: () => {
            isRemoving.value = false;
        },
        onSuccess: closeRemoveLogo,
    });
};
</script>

<template>
    <section class="flex flex-col gap-4" data-testid="socialhq-brand-look">
        <div class="flex flex-col gap-1">
            <h2 class="text-base font-semibold text-foreground">
                {{ $t('socialhq_knowledge.look.title') }}
            </h2>
            <p class="text-sm text-muted-foreground">
                {{ $t('socialhq_knowledge.look.description') }}
            </p>
        </div>

        <div class="flex flex-col gap-2">
            <p class="text-sm font-medium text-foreground">
                {{ $t('socialhq_knowledge.look.logo') }}
            </p>
            <div class="flex flex-wrap items-center gap-4">
                <div
                    class="flex size-24 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-muted/40"
                    data-testid="socialhq-brand-logo"
                >
                    <img
                        v-if="look.logo"
                        :src="look.logo.url"
                        :alt="$t('socialhq_knowledge.look.logo_alt')"
                        class="max-h-full max-w-full object-contain p-2"
                    />
                    <IconPhoto
                        v-else
                        class="size-6 text-muted-foreground"
                        :aria-label="$t('socialhq_knowledge.look.no_logo')"
                        role="img"
                    />
                </div>
                <div v-if="canEdit" class="flex flex-col gap-2">
                    <div class="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            :loading="logoForm.processing"
                            data-testid="socialhq-brand-logo-upload"
                            @click="chooseLogo"
                        >
                            <IconUpload class="size-4" />
                            {{
                                look.logo
                                    ? $t('socialhq_knowledge.look.replace_logo')
                                    : $t('socialhq_knowledge.look.upload_logo')
                            }}
                        </Button>
                        <Button
                            v-if="look.logo"
                            type="button"
                            variant="ghost"
                            data-testid="socialhq-brand-logo-remove"
                            @click="openRemoveLogo"
                        >
                            {{ $t('socialhq_knowledge.look.remove_logo') }}
                        </Button>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        {{
                            $t('socialhq_knowledge.look.logo_hint', {
                                size: logoMegabytes,
                            })
                        }}
                    </p>
                    <input
                        ref="logoInput"
                        type="file"
                        class="sr-only"
                        :accept="LOGO_ACCEPT"
                        :aria-label="$t('socialhq_knowledge.look.upload_logo')"
                        data-testid="socialhq-brand-logo-input"
                        @change="uploadLogo"
                    />
                </div>
            </div>
            <p
                v-if="logoForm.errors.logo"
                role="alert"
                class="text-sm text-destructive-text"
                data-testid="socialhq-brand-logo-error"
            >
                {{ logoForm.errors.logo }}
            </p>
            <p
                v-if="canEdit && look.logo?.isVector"
                class="text-sm text-muted-foreground"
            >
                {{ $t('socialhq_knowledge.look.vector_note') }}
            </p>
        </div>

        <form class="flex flex-col gap-4" @submit.prevent="save">
            <fieldset class="flex flex-col gap-2">
                <legend class="text-sm font-medium text-foreground">
                    {{ $t('socialhq_knowledge.look.colours') }}
                </legend>
                <p class="text-sm text-muted-foreground">
                    {{
                        $t('socialhq_knowledge.look.colours_hint', {
                            count: String(look.maxColours),
                        })
                    }}
                </p>
                <p
                    v-if="form.colours.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    {{ $t('socialhq_knowledge.look.no_colours') }}
                </p>
                <ul v-else class="flex flex-col gap-2">
                    <li
                        v-for="(colour, index) in form.colours"
                        :key="index"
                        class="flex flex-col gap-1"
                        :data-testid="`socialhq-brand-colour-${index}`"
                    >
                        <div class="flex items-center gap-2">
                            <label
                                class="relative size-9 shrink-0 cursor-pointer overflow-hidden rounded-md border"
                                :style="{ backgroundColor: swatchOf(colour) }"
                            >
                                <span class="sr-only">
                                    {{
                                        $t(
                                            'socialhq_knowledge.look.colour_picker_label',
                                            { number: String(index + 1) },
                                        )
                                    }}
                                </span>
                                <input
                                    type="color"
                                    class="absolute inset-0 size-full cursor-pointer opacity-0"
                                    :value="swatchOf(colour)"
                                    :disabled="!canEdit"
                                    @input="pickColour(index, $event)"
                                />
                            </label>
                            <Input
                                v-model="colour.hex"
                                class="w-28 font-mono"
                                :aria-label="
                                    $t('socialhq_knowledge.look.colour_hex')
                                "
                                :aria-invalid="
                                    errors[`colours.${index}.hex`]
                                        ? true
                                        : undefined
                                "
                                :disabled="!canEdit"
                                :data-testid="`socialhq-brand-colour-hex-${index}`"
                            />
                            <Input
                                v-model="colour.name"
                                class="min-w-0 flex-1"
                                :aria-label="
                                    $t('socialhq_knowledge.look.colour_name')
                                "
                                :placeholder="
                                    $t(
                                        'socialhq_knowledge.look.colour_name_placeholder',
                                    )
                                "
                                :disabled="!canEdit"
                                :data-testid="`socialhq-brand-colour-name-${index}`"
                            />
                            <Button
                                v-if="canEdit"
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                class="shrink-0"
                                :aria-label="
                                    $t(
                                        'socialhq_knowledge.look.remove_colour_label',
                                        { number: String(index + 1) },
                                    )
                                "
                                @click="removeColour(index)"
                            >
                                <IconTrash class="size-4" />
                            </Button>
                        </div>
                        <p
                            v-if="colourError(index)"
                            class="text-sm text-destructive-text"
                        >
                            {{ colourError(index) }}
                        </p>
                    </li>
                </ul>
                <p v-if="errors.colours" class="text-sm text-destructive-text">
                    {{ errors.colours }}
                </p>
                <div v-if="canAddColour">
                    <Button
                        type="button"
                        variant="outline"
                        data-testid="socialhq-brand-colour-add"
                        @click="addColour"
                    >
                        <IconPlus class="size-4" />
                        {{ $t('socialhq_knowledge.look.add_colour') }}
                    </Button>
                </div>
            </fieldset>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="flex flex-col gap-2">
                    <Label for="socialhq-brand-heading-font">
                        {{ $t('socialhq_knowledge.look.heading_font') }}
                    </Label>
                    <Input
                        id="socialhq-brand-heading-font"
                        v-model="form.heading_font"
                        :placeholder="
                            $t('socialhq_knowledge.look.font_placeholder')
                        "
                        :disabled="!canEdit"
                        data-testid="socialhq-brand-heading-font"
                    />
                    <p
                        v-if="errors.heading_font"
                        class="text-sm text-destructive-text"
                    >
                        {{ errors.heading_font }}
                    </p>
                </div>
                <div class="flex flex-col gap-2">
                    <Label for="socialhq-brand-body-font">
                        {{ $t('socialhq_knowledge.look.body_font') }}
                    </Label>
                    <Input
                        id="socialhq-brand-body-font"
                        v-model="form.body_font"
                        :disabled="!canEdit"
                        data-testid="socialhq-brand-body-font"
                    />
                    <p
                        v-if="errors.body_font"
                        class="text-sm text-destructive-text"
                    >
                        {{ errors.body_font }}
                    </p>
                </div>
            </div>

            <div class="flex flex-col gap-2">
                <Label for="socialhq-brand-style-notes">
                    {{ $t('socialhq_knowledge.look.style_notes') }}
                </Label>
                <Textarea
                    id="socialhq-brand-style-notes"
                    v-model="form.style_notes"
                    :rows="3"
                    :placeholder="
                        $t('socialhq_knowledge.look.style_notes_placeholder')
                    "
                    :disabled="!canEdit"
                    data-testid="socialhq-brand-style-notes"
                />
                <p class="text-sm text-muted-foreground">
                    {{ $t('socialhq_knowledge.look.style_notes_hint') }}
                </p>
                <p
                    v-if="errors.style_notes"
                    class="text-sm text-destructive-text"
                >
                    {{ errors.style_notes }}
                </p>
            </div>

            <div v-if="canEdit">
                <Button
                    type="submit"
                    :disabled="!form.isDirty"
                    :loading="form.processing"
                    data-testid="socialhq-brand-look-save"
                >
                    {{ $t('socialhq_knowledge.look.save') }}
                </Button>
            </div>
        </form>

        <Dialog v-model:open="isRemoveOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {{ $t('socialhq_knowledge.look.remove_logo_title') }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            $t(
                                'socialhq_knowledge.look.remove_logo_description',
                            )
                        }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="closeRemoveLogo"
                    >
                        {{ $t('socialhq_knowledge.look.cancel') }}
                    </Button>
                    <Button
                        variant="destructive"
                        :loading="isRemoving"
                        data-testid="socialhq-brand-logo-remove-confirm"
                        @click="removeLogo"
                    >
                        {{ $t('socialhq_knowledge.look.remove_logo') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>
</template>
