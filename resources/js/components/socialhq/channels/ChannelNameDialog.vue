<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

import InputError from '@/components/InputError.vue';
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
import { update } from '@/routes/socialhq/channels/name';

const props = defineProps<{
    channelId: string;
    customName: string | null;
    networkName: string;
    networkLabel: string;
}>();

const open = defineModel<boolean>('open', { required: true });
const form = useForm({ name: props.customName ?? '' });

watch(open, (isOpen) => {
    if (isOpen) {
        form.clearErrors();
        form.name = props.customName ?? '';
    }
});

const cancel = (): void => {
    open.value = false;
};

const close = (): void => {
    open.value = false;
};

const save = (): void => {
    form.put(update.url(props.channelId), {
        preserveScroll: true,
        onSuccess: close,
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            :show-close-button="false"
            class="sm:max-w-[460px]"
            data-testid="channel-name-dialog"
        >
            <form class="flex flex-col gap-4" @submit.prevent="save">
                <DialogHeader>
                    <DialogTitle>{{
                        $t('socialhq.channels.name.title')
                    }}</DialogTitle>
                    <DialogDescription>{{
                        $t('socialhq.channels.name.description')
                    }}</DialogDescription>
                </DialogHeader>
                <div class="flex flex-col gap-2">
                    <Label for="channel-name-input">{{
                        $t('socialhq.channels.name.label')
                    }}</Label>
                    <Input
                        id="channel-name-input"
                        v-model="form.name"
                        name="name"
                        autocomplete="off"
                        :placeholder="networkName"
                        data-testid="channel-name-input"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{
                            $t('socialhq.channels.name.hint', {
                                network: networkLabel,
                            })
                        }}
                    </p>
                    <InputError :message="form.errors.name" />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        data-testid="channel-name-cancel"
                        @click="cancel"
                    >
                        {{ $t('channels.settings_page.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :loading="form.processing"
                        data-testid="channel-name-save"
                    >
                        {{ $t('socialhq.channels.save') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
