<script setup lang="ts">
import {
    IconChevronDown,
    IconChevronLeft,
    IconChevronRight,
} from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import dayjs from '@/dayjs';
import { activeLocale } from '@/language';

const props = defineProps<{
    month: string;
    today: string;
}>();

const emit = defineEmits<{
    go: [month: string];
}>();

const localized = (value: dayjs.ConfigType): dayjs.Dayjs =>
    dayjs(value).locale(activeLocale.value.toLowerCase());

const current = computed(() => localized(`${props.month}-01`));
const todayMonth = computed(() => props.today.slice(0, 7));

const title = computed(() => current.value.format('MMMM YYYY'));

const pickerOpen = ref(false);
const pickerYear = ref(current.value.year());

watch(pickerOpen, (isOpen) => {
    if (isOpen) {
        pickerYear.value = current.value.year();
    }
});

const monthsOfPickerYear = computed(() =>
    Array.from({ length: 12 }, (_, index) => {
        const month = localized(`${pickerYear.value}-01-01`).add(
            index,
            'month',
        );

        return { key: month.format('YYYY-MM'), label: month.format('MMM') };
    }),
);

const go = (month: string): void => {
    pickerOpen.value = false;

    if (month !== props.month) {
        emit('go', month);
    }
};

const previous = (): void =>
    go(current.value.subtract(1, 'month').format('YYYY-MM'));

const next = (): void => go(current.value.add(1, 'month').format('YYYY-MM'));

const goToToday = (): void => go(todayMonth.value);

const previousYear = (): void => {
    pickerYear.value -= 1;
};

const nextYear = (): void => {
    pickerYear.value += 1;
};
</script>

<template>
    <div
        class="flex min-w-0 items-center gap-1"
        data-testid="plan-month-switcher"
    >
        <Button
            variant="ghost"
            size="icon"
            class="shrink-0"
            :aria-label="$t('socialhq_plan.month.previous')"
            data-testid="plan-month-previous"
            @click="previous"
        >
            <IconChevronLeft class="size-4" />
        </Button>
        <Button
            variant="ghost"
            size="icon"
            class="shrink-0"
            :aria-label="$t('socialhq_plan.month.next')"
            data-testid="plan-month-next"
            @click="next"
        >
            <IconChevronRight class="size-4" />
        </Button>

        <Popover v-model:open="pickerOpen">
            <PopoverTrigger as-child>
                <button
                    type="button"
                    class="inline-flex h-9 min-w-0 items-center gap-1.5 rounded-lg px-2 font-heading text-base font-medium text-foreground capitalize transition-control hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                    :aria-label="$t('socialhq_plan.month.pick')"
                    data-testid="plan-month-title"
                >
                    <span class="truncate">{{ title }}</span>
                    <IconChevronDown
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                </button>
            </PopoverTrigger>
            <PopoverContent align="start" class="w-72 p-3">
                <div class="mb-2 flex items-center justify-between">
                    <Button
                        variant="ghost"
                        size="icon"
                        :aria-label="$t('socialhq_plan.month.previous_year')"
                        @click="previousYear"
                    >
                        <IconChevronLeft class="size-4" />
                    </Button>
                    <span class="text-sm font-medium tabular-nums">{{
                        pickerYear
                    }}</span>
                    <Button
                        variant="ghost"
                        size="icon"
                        :aria-label="$t('socialhq_plan.month.next_year')"
                        @click="nextYear"
                    >
                        <IconChevronRight class="size-4" />
                    </Button>
                </div>
                <div class="grid grid-cols-3 gap-1">
                    <button
                        v-for="option in monthsOfPickerYear"
                        :key="option.key"
                        type="button"
                        class="h-9 rounded-md text-sm font-medium capitalize transition-control focus-visible:outline-2 focus-visible:outline-ring"
                        :class="
                            option.key === month
                                ? 'bg-primary-selected text-primary-text'
                                : option.key === todayMonth
                                  ? 'text-primary-text hover:bg-accent'
                                  : 'text-foreground hover:bg-accent'
                        "
                        :aria-current="
                            option.key === month ? 'date' : undefined
                        "
                        :data-testid="`plan-month-option-${option.key}`"
                        @click="go(option.key)"
                    >
                        {{ option.label }}
                    </button>
                </div>
            </PopoverContent>
        </Popover>

        <Button
            :disabled="month === todayMonth"
            variant="outline"
            size="sm"
            class="ms-1 shrink-0"
            data-testid="plan-month-today"
            @click="goToToday"
        >
            {{ $t('socialhq_plan.month.today') }}
        </Button>
    </div>
</template>
