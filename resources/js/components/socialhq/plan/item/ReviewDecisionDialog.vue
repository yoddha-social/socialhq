<script setup lang="ts">
import { ref, watch } from 'vue';

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
import { Textarea } from '@/components/ui/textarea';

const props = defineProps<{
    open: boolean;
    mode: 'approve' | 'request_changes';
    confirmLabel: string;
    processing: boolean;
    error?: string;
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
    confirm: [note: string];
}>();

const note = ref('');

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            note.value = '';
        }
    },
);

const close = (): void => {
    emit('update:open', false);
};

const onOpenChange = (isOpen: boolean): void => {
    emit('update:open', isOpen);
};

const confirm = (): void => {
    emit('confirm', note.value);
};
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent class="sm:max-w-lg" data-testid="review-decision-dialog">
            <DialogHeader>
                <DialogTitle>
                    {{
                        mode === 'approve'
                            ? $t('socialhq_production.review.approve_title')
                            : $t(
                                  'socialhq_production.review.request_changes_title',
                              )
                    }}
                </DialogTitle>
                <DialogDescription>
                    {{
                        mode === 'approve'
                            ? $t(
                                  'socialhq_production.review.approve_description',
                              )
                            : $t(
                                  'socialhq_production.review.request_changes_description',
                              )
                    }}
                </DialogDescription>
            </DialogHeader>
            <form
                class="grid gap-2"
                data-testid="review-decision-form"
                @submit.prevent="confirm"
            >
                <Label for="socialhq-review-note">
                    {{
                        mode === 'approve'
                            ? $t(
                                  'socialhq_production.review.approve_note_label',
                              )
                            : $t('socialhq_production.review.note_label')
                    }}
                </Label>
                <Textarea
                    id="socialhq-review-note"
                    v-model="note"
                    rows="4"
                    :placeholder="
                        mode === 'approve'
                            ? ''
                            : $t('socialhq_production.review.note_placeholder')
                    "
                    :aria-invalid="error ? true : undefined"
                    :aria-describedby="
                        error ? 'socialhq-review-note-error' : undefined
                    "
                    data-testid="review-decision-note"
                />
                <p
                    v-if="error"
                    id="socialhq-review-note-error"
                    class="text-sm text-destructive-text"
                >
                    {{ error }}
                </p>
                <DialogFooter class="mt-2">
                    <Button type="button" variant="outline" @click="close">
                        {{ $t('socialhq_production.review.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :loading="processing"
                        data-testid="review-decision-confirm"
                    >
                        {{ confirmLabel }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
