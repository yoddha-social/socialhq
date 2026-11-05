<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { IconSparkles } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, watch } from 'vue';
import { toast } from 'vue-sonner';

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
import { generate } from '@/routes/socialhq/plan';

const props = defineProps<{
    month: string;
    monthLabel: string;
    goals: string | null;
    hasSuggestions: boolean;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({ goals: '' });

/** Refusals that are not about a field (AI not set up, no access to AI). */
const generateError = computed(
    () => (form.errors as Record<string, string | undefined>).generate,
);

watch(open, (isOpen) => {
    if (isOpen) {
        form.clearErrors();
        form.goals = props.goals ?? '';
    }
});

const close = (): void => {
    open.value = false;
};

const refused = (response: { status: number }): false => {
    toast.error(
        trans(
            response.status === 429
                ? 'socialhq_plan.generate.too_many'
                : response.status === 409
                  ? 'socialhq_plan.generation.errors.already_running'
                  : 'socialhq_plan.errors.request_failed',
        ),
    );
    close();

    return false;
};

const start = (): void => {
    form.post(generate.url(props.month), {
        preserveScroll: true,
        onSuccess: close,
        onHttpException: refused,
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg" data-testid="plan-generate-dialog">
            <form class="flex flex-col gap-5" @submit.prevent="start">
                <DialogHeader>
                    <DialogTitle>{{
                        $t('socialhq_plan.generate.title', {
                            month: monthLabel,
                        })
                    }}</DialogTitle>
                    <DialogDescription>{{
                        $t('socialhq_plan.generate.description')
                    }}</DialogDescription>
                </DialogHeader>

                <div class="flex flex-col gap-2">
                    <Label for="plan-generate-goals">{{
                        $t('socialhq_plan.generate.goals')
                    }}</Label>
                    <Textarea
                        id="plan-generate-goals"
                        v-model="form.goals"
                        rows="4"
                        :placeholder="
                            $t('socialhq_plan.generate.goals_placeholder')
                        "
                        data-testid="plan-generate-goals"
                    />
                    <p
                        v-if="form.errors.goals"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.goals }}
                    </p>
                </div>

                <p
                    v-if="hasSuggestions"
                    class="rounded-lg bg-muted px-3 py-2 text-sm text-muted-foreground"
                >
                    {{ $t('socialhq_plan.generate.replaces') }}
                </p>

                <p
                    v-if="generateError"
                    class="text-sm text-destructive"
                    data-testid="plan-generate-error"
                >
                    {{ generateError }}
                </p>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="close">
                        {{ $t('socialhq_plan.sheet.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :loading="form.processing"
                        data-testid="plan-generate-start"
                    >
                        <IconSparkles class="size-4" />
                        {{ $t('socialhq_plan.generate.start') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
