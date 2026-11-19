<script setup lang="ts">
import { IconPlus, IconX } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import date from '@/date';
import { MAX_TIMES_PER_DAY } from '@/lib/postingSchedule';

const MINUTE_STEP = 5;
const DEFAULT_HOUR = '09';

const props = defineProps<{
    times: string[];
    daysLabel: string;
}>();

const emit = defineEmits<{
    save: [times: string[]];
    perDay: [];
}>();

const open = defineModel<boolean>('open', { required: true });
const draft = ref<string[]>([...props.times]);
const hour = ref(DEFAULT_HOUR);
const minute = ref('00');

const hours = Array.from({ length: 24 }, (_, value) =>
    String(value).padStart(2, '0'),
);
const minutes = Array.from({ length: 60 / MINUTE_STEP }, (_, index) =>
    String(index * MINUTE_STEP).padStart(2, '0'),
);

watch(open, (isOpen) => {
    if (isOpen) {
        draft.value = [...props.times];
        hour.value = DEFAULT_HOUR;
        minute.value = '00';
    }
});

const isFull = computed(() => draft.value.length >= MAX_TIMES_PER_DAY);

const add = (): void => {
    const time = `${hour.value}:${minute.value}`;

    if (!draft.value.includes(time) && !isFull.value) {
        draft.value = [...draft.value, time].sort();
    }
};

const remove = (time: string): void => {
    draft.value = draft.value.filter((entry) => entry !== time);
};

const testId = (time: string): string => time.replace(':', '');

const cancel = (): void => {
    open.value = false;
};

const save = (): void => {
    emit('save', draft.value);
    open.value = false;
};

const openPerDay = (): void => {
    open.value = false;
    emit('perDay');
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            :show-close-button="false"
            class="sm:max-w-[460px]"
            data-testid="channel-times-dialog"
        >
            <DialogHeader>
                <DialogTitle>{{
                    $t('socialhq.channels.times.title')
                }}</DialogTitle>
                <DialogDescription>{{
                    $t('socialhq.channels.times.dialog_description')
                }}</DialogDescription>
            </DialogHeader>

            <div class="flex flex-col gap-4">
                <div
                    class="flex items-center gap-2 rounded-lg bg-muted p-3 text-sm"
                >
                    <Select v-model="hour">
                        <SelectTrigger
                            class="bg-card tabular-nums"
                            :aria-label="$t('socialhq.channels.times.hour')"
                            data-testid="channel-times-hour"
                        >
                            <SelectValue>{{
                                date.formatHourOption(hour)
                            }}</SelectValue>
                        </SelectTrigger>
                        <SelectContent class="max-h-[320px]">
                            <SelectItem
                                v-for="option in hours"
                                :key="option"
                                :value="option"
                                :data-testid="`channel-times-hour-option-${option}`"
                            >
                                {{ date.formatHourOption(option) }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <span aria-hidden="true">:</span>
                    <Select v-model="minute">
                        <SelectTrigger
                            class="bg-card tabular-nums"
                            :aria-label="$t('socialhq.channels.times.minute')"
                            data-testid="channel-times-minute"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent class="max-h-[320px]">
                            <SelectItem
                                v-for="option in minutes"
                                :key="option"
                                :value="option"
                                :data-testid="`channel-times-minute-option-${option}`"
                            >
                                {{ option }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <Button
                        v-if="!isFull"
                        type="button"
                        class="ms-auto"
                        data-testid="channel-times-add"
                        @click="add"
                    >
                        <IconPlus class="size-4" />
                        {{ $t('socialhq.channels.times.add') }}
                    </Button>
                    <span
                        v-else
                        class="ms-auto text-end text-[13px] text-muted-foreground"
                        data-testid="channel-times-limit"
                    >
                        {{
                            $t('socialhq.channels.times.limit', {
                                count: String(MAX_TIMES_PER_DAY),
                            })
                        }}
                    </span>
                </div>

                <div class="flex flex-col gap-2">
                    <p class="text-xs text-subtle-foreground">
                        {{
                            $t('socialhq.channels.times.count', {
                                count: String(draft.length),
                            })
                        }}
                    </p>
                    <p
                        v-if="draft.length === 0"
                        class="rounded-lg border border-dashed border-border-strong px-3 py-4 text-center text-[13px] text-muted-foreground"
                        data-testid="channel-times-empty"
                    >
                        {{ $t('socialhq.channels.times.empty') }}
                    </p>
                    <ul v-else class="flex flex-col gap-1.5">
                        <li
                            v-for="time in draft"
                            :key="time"
                            class="flex items-center justify-between rounded-lg border border-border px-3 py-2 text-sm"
                            :data-testid="`channel-times-item-${testId(time)}`"
                        >
                            <span class="flex items-center gap-2 tabular-nums">
                                <span
                                    class="size-2 rounded-full bg-primary-strong"
                                    aria-hidden="true"
                                />
                                {{ date.formatClockTime(time) }}
                            </span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-xs"
                                class="text-subtle-foreground hover:text-destructive-text"
                                :aria-label="
                                    $t('socialhq.channels.times.remove', {
                                        time: date.formatClockTime(time),
                                    })
                                "
                                :data-testid="`channel-times-remove-${testId(time)}`"
                                @click="remove(time)"
                            >
                                <IconX class="size-4" />
                            </Button>
                        </li>
                    </ul>
                </div>

                <p class="text-xs text-muted-foreground">
                    {{ daysLabel }}.
                    <button
                        type="button"
                        class="font-medium text-primary-text underline-offset-4 hover:underline"
                        data-testid="channel-times-per-day"
                        @click="openPerDay"
                    >
                        {{ $t('socialhq.channels.times.per_day') }} →
                    </button>
                </p>
            </div>

            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    data-testid="channel-times-cancel"
                    @click="cancel"
                >
                    {{ $t('channels.settings_page.cancel') }}
                </Button>
                <Button
                    type="button"
                    data-testid="channel-times-save"
                    @click="save"
                >
                    {{ $t('socialhq.channels.save') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
