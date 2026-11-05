<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { IconPlus, IconX } from '@tabler/icons-vue';
import { computed, watch } from 'vue';

import PlatformBrandIcon from '@/components/PlatformBrandIcon.vue';
import { pillarColorAt } from '@/components/socialhq/plan/pillarColors';
import type { PlanChannel } from '@/components/socialhq/plan/types';
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
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { update as updateBrandSettings } from '@/routes/socialhq/plan/brand-settings';

const MAX_PILLARS = 8;
const MAX_POSTS_PER_WEEK = 14;

const props = defineProps<{
    pillars: string[];
    cadence: Record<string, number | null>;
    channels: PlanChannel[];
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm<{ pillars: string[]; cadence: Record<string, string> }>({
    pillars: [],
    cadence: {},
});

const weeklyOptions = Array.from(
    { length: MAX_POSTS_PER_WEEK + 1 },
    (_, count) => String(count),
);

const canAddPillar = computed(() => form.pillars.length < MAX_PILLARS);

const pillarErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key.startsWith('pillars'))
        .map(([, message]) => message),
);

const cadenceErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key.startsWith('cadence'))
        .map(([, message]) => message),
);

const fill = (): void => {
    form.clearErrors();
    form.pillars = [...props.pillars];
    form.cadence = Object.fromEntries(
        props.channels.map((channel) => {
            const count = props.cadence[channel.id];

            return [
                channel.id,
                count === null || count === undefined ? '' : String(count),
            ];
        }),
    );
};

watch(open, (isOpen) => {
    if (isOpen) {
        fill();
    }
});

const addPillar = (): void => {
    if (canAddPillar.value) {
        form.pillars.push('');
    }
};

const removePillar = (index: number): void => {
    form.pillars.splice(index, 1);
};

const close = (): void => {
    open.value = false;
};

const save = (): void => {
    form.transform((data) => ({
        pillars: data.pillars,
        cadence: Object.fromEntries(
            Object.entries(data.cadence)
                .filter(([, count]) => count !== '')
                .map(([channelId, count]) => [channelId, Number(count)]),
        ),
    })).put(updateBrandSettings.url(), {
        preserveScroll: true,
        onSuccess: close,
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="sm:max-w-lg"
            data-testid="plan-brand-settings-dialog"
        >
            <form class="flex flex-col gap-6" @submit.prevent="save">
                <DialogHeader>
                    <DialogTitle>{{
                        $t('socialhq_plan.brand_settings.title')
                    }}</DialogTitle>
                    <DialogDescription>{{
                        $t('socialhq_plan.brand_settings.description')
                    }}</DialogDescription>
                </DialogHeader>

                <section class="flex flex-col gap-3">
                    <div>
                        <h3 class="text-sm font-medium text-foreground">
                            {{ $t('socialhq_plan.brand_settings.pillars') }}
                        </h3>
                        <p class="text-sm text-muted-foreground">
                            {{
                                $t(
                                    'socialhq_plan.brand_settings.pillars_help',
                                    {
                                        max: String(MAX_PILLARS),
                                    },
                                )
                            }}
                        </p>
                    </div>
                    <ul class="flex flex-col gap-2">
                        <li
                            v-for="(pillar, index) in form.pillars"
                            :key="index"
                            class="flex items-center gap-2"
                        >
                            <span
                                class="size-2.5 shrink-0 rounded-full"
                                :class="pillarColorAt(index).dot"
                                aria-hidden="true"
                            />
                            <Input
                                v-model="form.pillars[index]"
                                :aria-label="
                                    $t('socialhq_plan.brand_settings.pillar', {
                                        number: String(index + 1),
                                    })
                                "
                                :data-testid="`plan-pillar-${index}`"
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :aria-label="
                                    $t(
                                        'socialhq_plan.brand_settings.remove_pillar',
                                    )
                                "
                                :data-testid="`plan-pillar-remove-${index}`"
                                @click="removePillar(index)"
                            >
                                <IconX class="size-4" />
                            </Button>
                        </li>
                    </ul>
                    <Button
                        v-if="canAddPillar"
                        type="button"
                        variant="outline"
                        class="w-fit"
                        data-testid="plan-pillar-add"
                        @click="addPillar"
                    >
                        <IconPlus class="size-4" />
                        {{ $t('socialhq_plan.brand_settings.add_pillar') }}
                    </Button>
                    <p
                        v-for="message in pillarErrors"
                        :key="message"
                        class="text-sm text-destructive"
                    >
                        {{ message }}
                    </p>
                </section>

                <section v-if="channels.length" class="flex flex-col gap-3">
                    <div>
                        <h3 class="text-sm font-medium text-foreground">
                            {{ $t('socialhq_plan.brand_settings.cadence') }}
                        </h3>
                        <p class="text-sm text-muted-foreground">
                            {{
                                $t('socialhq_plan.brand_settings.cadence_help')
                            }}
                        </p>
                    </div>
                    <ul class="flex flex-col divide-y divide-border">
                        <li
                            v-for="channel in channels"
                            :key="channel.id"
                            class="flex items-center gap-3 py-2"
                        >
                            <PlatformBrandIcon
                                :platform="channel.platform"
                                colored
                                class="size-4 shrink-0"
                            />
                            <label
                                :for="`plan-cadence-${channel.id}`"
                                class="min-w-0 flex-1 truncate text-sm"
                                >{{ channel.name }}</label
                            >
                            <NativeSelect
                                :id="`plan-cadence-${channel.id}`"
                                v-model="form.cadence[channel.id]"
                                class="w-36"
                                :data-testid="`plan-cadence-${channel.id}`"
                            >
                                <NativeSelectOption value="">{{
                                    $t('socialhq_plan.brand_settings.not_set')
                                }}</NativeSelectOption>
                                <NativeSelectOption
                                    v-for="count in weeklyOptions"
                                    :key="count"
                                    :value="count"
                                >
                                    {{
                                        $t(
                                            'socialhq_plan.brand_settings.per_week',
                                            { count },
                                        )
                                    }}
                                </NativeSelectOption>
                            </NativeSelect>
                        </li>
                    </ul>
                    <p
                        v-for="message in cadenceErrors"
                        :key="message"
                        class="text-sm text-destructive"
                    >
                        {{ message }}
                    </p>
                </section>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="close">
                        {{ $t('socialhq_plan.sheet.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :loading="form.processing"
                        data-testid="plan-brand-settings-save"
                    >
                        {{ $t('socialhq_plan.brand_settings.save') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
