<script setup lang="ts">
import { IconChevronDown, IconRepeat } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

import PostRecurrenceSummary from '@/components/publish/PostRecurrenceSummary.vue';
import { isWithinLimit } from '@/components/socialhq/composer/repeat';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { RecurrenceFrequency, RecurrenceRule } from '@/lib/recurrence';
import {
    MAX_RECURRENCE_INTERVAL,
    MAX_RECURRENCE_TIMES,
    RECURRENCE_FREQUENCIES,
} from '@/lib/recurrence';

/**
 * Social HQ: "Repeat" in the composer's footer. The same rule as the post
 * card's "Make Recurring" (every N days, weeks, months or years, N more times),
 * picked before the post is saved. It needs a set date and time: in the queue
 * or "Now" the button is off and says why, and the rule waits until a time is
 * set again.
 */
const props = defineProps<{
    /** The post's time as a UTC instant; empty while no date and time is set. */
    scheduledAt: string;
    timezone: string;
    canHover: boolean;
}>();

const rule = defineModel<RecurrenceRule | null>({ required: true });

const DEFAULT_RULE: RecurrenceRule = {
    interval: 1,
    frequency: 'week',
    times: 1,
};

const open = ref(false);
const interval = ref(DEFAULT_RULE.interval);
const frequency = ref<RecurrenceFrequency>(DEFAULT_RULE.frequency);
const times = ref(DEFAULT_RULE.times);

const needsTime = computed(() => !props.scheduledAt);
/** What the post will be saved with: nothing while it has no set time. */
const shownRule = computed(() => (needsTime.value ? null : rule.value));

watch(open, (value) => {
    if (!value) return;

    const current = rule.value ?? DEFAULT_RULE;
    interval.value = current.interval;
    frequency.value = current.frequency;
    times.value = current.times;
});

const draft = computed<RecurrenceRule | null>(() =>
    isWithinLimit(interval.value, MAX_RECURRENCE_INTERVAL) &&
    isWithinLimit(times.value, MAX_RECURRENCE_TIMES)
        ? {
              interval: interval.value,
              frequency: frequency.value,
              times: times.value,
          }
        : null,
);

const applyRule = (): void => {
    if (!draft.value) return;
    rule.value = { ...draft.value };
    open.value = false;
};

const stopRepeating = (): void => {
    rule.value = null;
    open.value = false;
};
</script>

<template>
    <Popover v-model:open="open">
        <!-- The anchor sits outside the tooltip: inside it, the trigger would anchor the tooltip's popper and the panel would never be placed. -->
        <PopoverAnchor as-child>
            <span class="inline-flex">
                <TooltipProvider :delay-duration="150" :disabled="!canHover">
                    <Tooltip :disabled="!canHover || !needsTime">
                        <TooltipTrigger as-child>
                            <span
                                class="inline-flex"
                                data-testid="composer-repeat"
                            >
                                <PopoverTrigger as-child>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="lg"
                                        class="max-w-64 rounded-xl px-3 max-sm:h-8 max-sm:px-2"
                                        :class="{
                                            'text-primary-text': shownRule,
                                        }"
                                        :disabled="needsTime"
                                        :aria-describedby="
                                            needsTime
                                                ? 'composer-repeat-needs-time'
                                                : undefined
                                        "
                                        data-testid="composer-repeat-trigger"
                                        :data-repeating="
                                            shownRule ? 'true' : 'false'
                                        "
                                    >
                                        <IconRepeat class="size-4" />
                                        <span
                                            class="truncate max-sm:sr-only"
                                            data-testid="composer-repeat-label"
                                            >{{
                                                shownRule
                                                    ? $tChoice(
                                                          'socialhq.composer.repeat.times',
                                                          shownRule.times,
                                                          {
                                                              rule: $tChoice(
                                                                  `socialhq.composer.repeat.rule.${shownRule.frequency}`,
                                                                  shownRule.interval,
                                                                  {
                                                                      count: String(
                                                                          shownRule.interval,
                                                                      ),
                                                                  },
                                                              ),
                                                              count: String(
                                                                  shownRule.times,
                                                              ),
                                                          },
                                                      )
                                                    : $t(
                                                          'socialhq.composer.repeat.none',
                                                      )
                                            }}</span
                                        >
                                        <IconChevronDown
                                            class="size-4 shrink-0 text-muted-foreground max-sm:hidden"
                                        />
                                    </Button>
                                </PopoverTrigger>
                            </span>
                        </TooltipTrigger>
                        <TooltipContent
                            side="top"
                            data-testid="composer-repeat-tooltip"
                        >
                            {{ $t('socialhq.composer.repeat.needs_time') }}
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            </span>
        </PopoverAnchor>
        <p v-if="needsTime" id="composer-repeat-needs-time" class="sr-only">
            {{ $t('socialhq.composer.repeat.needs_time') }}
        </p>
        <PopoverContent
            side="top"
            align="start"
            class="w-[min(27rem,calc(100vw-2rem))] space-y-4 p-4"
            data-testid="composer-repeat-panel"
        >
            <p class="text-sm font-semibold">
                {{ $t('socialhq.composer.repeat.title') }}
            </p>
            <form
                id="composer-repeat-form"
                class="flex flex-wrap items-center gap-2 text-sm"
                @submit.prevent="applyRule"
            >
                <label for="composer-repeat-interval">{{
                    $t('posts.recurrence.repeat_every')
                }}</label>
                <Input
                    id="composer-repeat-interval"
                    v-model.number="interval"
                    type="number"
                    inputmode="numeric"
                    class="w-14"
                    :aria-label="$t('posts.recurrence.interval_label')"
                    :aria-invalid="
                        !isWithinLimit(interval, MAX_RECURRENCE_INTERVAL)
                    "
                    data-testid="composer-repeat-interval"
                />
                <Select v-model="frequency">
                    <SelectTrigger
                        class="w-auto gap-1.5"
                        :aria-label="$t('posts.recurrence.frequency_label')"
                        data-testid="composer-repeat-frequency"
                        :data-value="frequency"
                    >
                        <SelectValue>{{
                            $tChoice(
                                `posts.recurrence.frequency.${frequency}`,
                                Number(interval) || 1,
                            )
                        }}</SelectValue>
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in RECURRENCE_FREQUENCIES"
                            :key="option"
                            :value="option"
                            :data-testid="`composer-repeat-frequency-option-${option}`"
                        >
                            {{
                                $tChoice(
                                    `posts.recurrence.frequency.${option}`,
                                    Number(interval) || 1,
                                )
                            }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <span>{{ $t('posts.recurrence.for') }}</span>
                <Input
                    v-model.number="times"
                    type="number"
                    inputmode="numeric"
                    class="w-14"
                    :aria-label="$t('posts.recurrence.times_label')"
                    :aria-invalid="!isWithinLimit(times, MAX_RECURRENCE_TIMES)"
                    data-testid="composer-repeat-times"
                />
                <span>{{
                    $tChoice('posts.recurrence.times', Number(times) || 1)
                }}</span>
            </form>
            <p
                v-if="draft && scheduledAt"
                class="rounded-lg bg-secondary p-3 text-sm text-foreground"
            >
                <PostRecurrenceSummary
                    :scheduled-at="scheduledAt"
                    :timezone="timezone"
                    :rule="draft"
                    data-testid="composer-repeat-summary"
                />
            </p>
            <div class="flex justify-end gap-2">
                <Button
                    v-if="rule"
                    type="button"
                    variant="ghost"
                    data-testid="composer-repeat-clear"
                    @click="stopRepeating"
                    >{{ $t('socialhq.composer.repeat.clear') }}</Button
                >
                <Button
                    type="submit"
                    form="composer-repeat-form"
                    :disabled="!draft"
                    data-testid="composer-repeat-apply"
                    >{{ $t('socialhq.composer.repeat.apply') }}</Button
                >
            </div>
        </PopoverContent>
    </Popover>
</template>
