<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';

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
import { copyToClipboard } from '@/lib/utils';

const props = defineProps<{
    url: string;
}>();

const open = defineModel<boolean>('open', { required: true });

const copy = (): void => {
    void copyToClipboard(props.url, trans('socialhq.channels.invite.copied'));
};

const close = (): void => {
    open.value = false;
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            :show-close-button="false"
            class="sm:max-w-[520px]"
            data-testid="channel-invite-dialog"
        >
            <DialogHeader>
                <DialogTitle>{{
                    $t('socialhq.channels.invite.dialog_title')
                }}</DialogTitle>
                <DialogDescription>{{
                    $t('socialhq.channels.invite.dialog_description')
                }}</DialogDescription>
            </DialogHeader>
            <div class="flex items-center gap-2">
                <Input
                    :model-value="url"
                    readonly
                    class="font-mono text-xs"
                    :aria-label="$t('socialhq.channels.invite.dialog_title')"
                    data-testid="channel-invite-url"
                />
                <Button
                    type="button"
                    variant="outline"
                    class="shrink-0"
                    data-testid="channel-invite-copy-again"
                    @click="copy"
                >
                    {{ $t('socialhq.channels.invite.copy') }}
                </Button>
            </div>
            <DialogFooter>
                <Button
                    type="button"
                    data-testid="channel-invite-done"
                    @click="close"
                >
                    {{ $t('socialhq.channels.invite.done') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
