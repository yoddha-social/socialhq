<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { IconX } from '@tabler/icons-vue';
import { ref } from 'vue';

import { reject as rejectPostRoute } from '@/actions/App/Http/Controllers/App/PostApprovalController';
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
import { toastFirstError } from '@/composables/usePostCardActions';

/**
 * Social HQ's Reject for a pending post: asks for an optional reason, which
 * the server keeps as the approver's note and puts in the decision email.
 */
const props = defineProps<{
    postId: string;
    testKey: string;
}>();

const open = ref(false);
const reason = ref('');
const processing = ref(false);
const reasonError = ref<string | null>(null);

const openDialog = (): void => {
    reason.value = '';
    reasonError.value = null;
    open.value = true;
};

const closeDialog = (): void => {
    open.value = false;
};

const reject = (): void => {
    router.put(
        rejectPostRoute.url(props.postId),
        { reason: reason.value },
        {
            only: ['posts', 'counts', 'queue', 'hasData'],
            reset: ['posts'],
            preserveScroll: true,
            onStart: () => {
                processing.value = true;
            },
            onFinish: () => {
                processing.value = false;
            },
            onSuccess: () => {
                open.value = false;
            },
            onError: (errors: Record<string, string>) => {
                if (errors.reason) {
                    reasonError.value = errors.reason;
                    return;
                }
                open.value = false;
                toastFirstError(errors);
            },
        },
    );
};
</script>

<template>
    <div class="contents">
        <Button
            variant="outline"
            class="text-destructive-text"
            :data-testid="`post-reject-${testKey}`"
            @click="openDialog"
        >
            <IconX class="size-4" />
            {{ $t('posts.approvals.reject') }}
        </Button>
        <Dialog v-model:open="open">
            <DialogContent
                class="sm:max-w-lg"
                :data-testid="`post-reject-dialog-${testKey}`"
            >
                <DialogHeader>
                    <DialogTitle>
                        {{ $t('socialhq.approvals.reject.title') }}
                    </DialogTitle>
                    <DialogDescription>
                        {{ $t('socialhq.approvals.reject.description') }}
                    </DialogDescription>
                </DialogHeader>
                <form class="grid gap-2" @submit.prevent="reject">
                    <Label :for="`post-reject-reason-${testKey}`">
                        {{ $t('socialhq.approvals.reject.reason_label') }}
                    </Label>
                    <Textarea
                        :id="`post-reject-reason-${testKey}`"
                        v-model="reason"
                        rows="4"
                        :placeholder="
                            $t('socialhq.approvals.reject.reason_placeholder')
                        "
                        :aria-invalid="reasonError ? true : undefined"
                        :aria-describedby="`post-reject-reason-help-${testKey}`"
                        :data-testid="`post-reject-reason-${testKey}`"
                    />
                    <p
                        :id="`post-reject-reason-help-${testKey}`"
                        class="text-sm"
                        :class="
                            reasonError
                                ? 'text-destructive-text'
                                : 'text-muted-foreground'
                        "
                    >
                        {{
                            reasonError ??
                            $t('socialhq.approvals.reject.reason_hint')
                        }}
                    </p>
                    <DialogFooter class="mt-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="closeDialog"
                        >
                            {{ $t('socialhq.approvals.reject.cancel') }}
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            :loading="processing"
                            :data-testid="`post-reject-confirm-${testKey}`"
                        >
                            {{ $t('socialhq.approvals.reject.confirm') }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
