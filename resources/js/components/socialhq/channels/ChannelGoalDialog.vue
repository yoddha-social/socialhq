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
import { Stepper } from '@/components/ui/stepper';
import { MAX_GOAL } from '@/lib/postingSchedule';

const props = defineProps<{
    goal: number;
}>();

const emit = defineEmits<{
    save: [goal: number];
}>();

const open = defineModel<boolean>('open', { required: true });
const draft = ref(props.goal);

watch(open, (isOpen) => {
    if (isOpen) {
        draft.value = props.goal;
    }
});

const cancel = (): void => {
    open.value = false;
};

const save = (): void => {
    emit('save', draft.value);
    open.value = false;
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            :show-close-button="false"
            class="sm:max-w-[420px]"
            data-testid="channel-goal-dialog"
        >
            <DialogHeader>
                <DialogTitle>{{
                    $t('socialhq.channels.goal.title')
                }}</DialogTitle>
                <DialogDescription>{{
                    $t('socialhq.channels.goal.dialog_description')
                }}</DialogDescription>
            </DialogHeader>
            <Stepper
                v-model="draft"
                :max="MAX_GOAL"
                :decrease-label="$t('channels.settings_page.goal_decrease')"
                :increase-label="$t('channels.settings_page.goal_increase')"
                decrease-test-id="channel-goal-decrease"
                increase-test-id="channel-goal-increase"
                value-test-id="channel-goal-value"
            />
            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    data-testid="channel-goal-cancel"
                    @click="cancel"
                >
                    {{ $t('channels.settings_page.cancel') }}
                </Button>
                <Button
                    type="button"
                    data-testid="channel-goal-save"
                    @click="save"
                >
                    {{ $t('socialhq.channels.save') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
