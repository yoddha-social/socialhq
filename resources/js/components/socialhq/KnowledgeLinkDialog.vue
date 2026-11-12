<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

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
import { store as storeLink } from '@/routes/socialhq/brand/links';

/**
 * Add a Google Docs, Sheets or Slides file by its link (shared with
 * "Anyone with the link"). Its text is read in the background.
 */
const open = defineModel<boolean>('open', { default: false });

const form = useForm<{ url: string; title: string }>({ url: '', title: '' });

watch(open, (isOpen) => {
    if (isOpen) {
        form.reset();
        form.clearErrors();
    }
});

const close = (): void => {
    open.value = false;
};

const submit = (): void => {
    form.post(storeLink.url(), {
        preserveScroll: true,
        onSuccess: close,
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg" data-testid="socialhq-link-dialog">
            <form class="flex flex-col gap-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>
                        {{ $t('socialhq_knowledge.links.dialog.title') }}
                    </DialogTitle>
                    <DialogDescription>
                        {{ $t('socialhq_knowledge.links.dialog.description') }}
                    </DialogDescription>
                </DialogHeader>

                <div class="flex flex-col gap-2">
                    <Label for="socialhq-link-url">
                        {{ $t('socialhq_knowledge.links.dialog.url') }}
                    </Label>
                    <Input
                        id="socialhq-link-url"
                        v-model="form.url"
                        inputmode="url"
                        :placeholder="
                            $t(
                                'socialhq_knowledge.links.dialog.url_placeholder',
                            )
                        "
                        :aria-invalid="form.errors.url ? true : undefined"
                        :aria-describedby="
                            form.errors.url
                                ? 'socialhq-link-url-error'
                                : undefined
                        "
                        data-testid="socialhq-link-url"
                    />
                    <p
                        v-if="form.errors.url"
                        id="socialhq-link-url-error"
                        role="alert"
                        class="text-sm text-destructive-text"
                        data-testid="socialhq-link-url-error"
                    >
                        {{ form.errors.url }}
                    </p>
                </div>

                <div class="flex flex-col gap-2">
                    <Label for="socialhq-link-title">
                        {{ $t('socialhq_knowledge.documents.dialog.title') }}
                    </Label>
                    <Input
                        id="socialhq-link-title"
                        v-model="form.title"
                        data-testid="socialhq-link-title"
                    />
                    <p class="text-sm text-muted-foreground">
                        {{ $t('socialhq_knowledge.links.dialog.title_hint') }}
                    </p>
                    <p
                        v-if="form.errors.title"
                        class="text-sm text-destructive-text"
                    >
                        {{ form.errors.title }}
                    </p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="close">
                        {{ $t('socialhq_knowledge.documents.dialog.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :loading="form.processing"
                        data-testid="socialhq-link-save"
                    >
                        {{ $t('socialhq_knowledge.links.dialog.add') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
