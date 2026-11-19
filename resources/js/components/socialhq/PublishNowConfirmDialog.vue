<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { IconSend } from '@tabler/icons-vue';
import { onBeforeUnmount, onMounted, watchEffect } from 'vue';

import {
    answerPublishNow,
    publishNowConfirm,
} from '@/components/socialhq/publishNow';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';

const page = usePage();

watchEffect(() => {
    publishNowConfirm.enabled =
        page.props.socialhq?.publishNow?.confirm ?? true;
});

onMounted(() => {
    publishNowConfirm.mounted++;
});

onBeforeUnmount(() => {
    publishNowConfirm.mounted--;
    answerPublishNow(false);
});

const onOpenChange = (open: boolean): void => {
    if (!open) {
        answerPublishNow(false);
    }
};
</script>

<template>
    <!-- Rendered only while asking: its portal then mounts after any open dialog (the composer), so it stacks on top. -->
    <AlertDialog
        v-if="publishNowConfirm.open"
        :open="publishNowConfirm.open"
        @update:open="onOpenChange"
    >
        <AlertDialogContent
            class="gap-0 px-0 pt-6 pb-0 sm:max-w-[512px]"
            data-testid="publish-now-confirm"
        >
            <AlertDialogHeader class="gap-3 px-6 pb-4 text-left">
                <AlertDialogTitle class="font-sans text-base font-medium">
                    {{ $t('socialhq.publish_now.title') }}
                </AlertDialogTitle>
                <AlertDialogDescription class="text-sm text-foreground">
                    {{
                        publishNowConfirm.count > 1
                            ? $t('socialhq.publish_now.description_many', {
                                  count: String(publishNowConfirm.count),
                              })
                            : $t('socialhq.publish_now.description')
                    }}
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter class="mx-2 mb-2">
                <AlertDialogCancel data-testid="publish-now-cancel">
                    {{ $t('common.cancel') }}
                </AlertDialogCancel>
                <Button
                    size="lg"
                    data-testid="publish-now-confirm-button"
                    @click="answerPublishNow(true)"
                >
                    <IconSend class="size-4" />
                    {{ $t('socialhq.publish_now.confirm') }}
                </Button>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
