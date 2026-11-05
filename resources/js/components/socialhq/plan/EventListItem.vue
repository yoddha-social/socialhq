<script setup lang="ts">
import { IconCircleCheck, IconDots, IconExternalLink } from '@tabler/icons-vue';
import { computed } from 'vue';

import {
    categoryBadgeClass,
    NEEDS_CHECK_CLASS,
    type CalendarEventRow,
} from '@/components/socialhq/plan/eventStyles';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * One event on the events page: names, kind, holiday, source and who
 * confirmed it. People who approve posts get the actions.
 */
const props = defineProps<{
    event: CalendarEventRow;
    canApprove: boolean;
    busy: boolean;
    /** "Until 12 Oct" text, already formatted, for a multi-day event. */
    until: string | null;
}>();

const emit = defineEmits<{
    confirm: [event: CalendarEventRow];
    unconfirm: [event: CalendarEventRow];
    edit: [event: CalendarEventRow];
    delete: [event: CalendarEventRow];
}>();

const sourceLabel = computed((): string | null => {
    if (props.event.source_name) {
        return props.event.source_name;
    }

    if (!props.event.source_url) {
        return null;
    }

    try {
        return new URL(props.event.source_url).hostname.replace(/^www\./, '');
    } catch {
        return props.event.source_url;
    }
});

const confirm = (): void => emit('confirm', props.event);
const unconfirm = (): void => emit('unconfirm', props.event);
const edit = (): void => emit('edit', props.event);
const remove = (): void => emit('delete', props.event);
</script>

<template>
    <li
        class="flex flex-col gap-3 rounded-lg border bg-card px-3 py-2.5 sm:flex-row sm:items-start sm:justify-between md:px-4 md:py-3"
        :class="
            event.verified
                ? 'border-border'
                : 'border-dashed border-border-strong'
        "
        :data-testid="`socialhq-event-${event.id}`"
        :data-verified="event.verified"
    >
        <div class="flex min-w-0 flex-col gap-1.5">
            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                <p class="text-sm font-medium text-foreground">
                    {{ event.name }}
                </p>
                <p
                    v-if="event.name_ne"
                    lang="ne"
                    class="text-sm text-muted-foreground"
                >
                    {{ event.name_ne }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-1.5">
                <Badge
                    class="px-1.5"
                    :class="categoryBadgeClass(event.category)"
                    :data-testid="`socialhq-event-category-${event.id}`"
                >
                    {{ $t(`socialhq_events.categories.${event.category}`) }}
                </Badge>
                <Badge
                    v-if="
                        event.public_holiday &&
                        event.category !== 'public_holiday'
                    "
                    variant="outline"
                    class="px-1.5"
                >
                    {{ $t('socialhq_events.badges.public_holiday') }}
                </Badge>
                <Badge v-if="event.brand_only" variant="outline" class="px-1.5">
                    {{ $t('socialhq_events.badges.brand_only') }}
                </Badge>
                <span v-else class="text-xs text-muted-foreground">
                    {{ $t(`socialhq_events.regions.${event.region}`) }}
                </span>
                <span v-if="until" class="text-xs text-muted-foreground">
                    · {{ until }}
                </span>
            </div>

            <p
                v-if="event.description"
                class="line-clamp-2 text-xs text-muted-foreground"
                :title="event.description"
            >
                {{ event.description }}
            </p>

            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                <span
                    v-if="event.verified"
                    class="inline-flex items-center gap-1 text-success-text"
                    :data-testid="`socialhq-event-confirmed-${event.id}`"
                >
                    <IconCircleCheck class="size-3.5" />
                    {{
                        event.verified_by
                            ? $t('socialhq_events.badges.confirmed_by', {
                                  name: event.verified_by,
                              })
                            : $t('socialhq_events.badges.confirmed')
                    }}
                </span>
                <Badge
                    v-else
                    class="px-1.5"
                    :class="NEEDS_CHECK_CLASS"
                    :data-testid="`socialhq-event-needs-check-${event.id}`"
                >
                    {{ $t('socialhq_events.badges.needs_check') }}
                </Badge>

                <a
                    v-if="event.source_url"
                    :href="event.source_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex max-w-full min-w-0 items-center gap-1 text-muted-foreground underline-offset-2 hover:text-foreground hover:underline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                    :title="event.source_name ?? event.source_url"
                    :data-testid="`socialhq-event-source-${event.id}`"
                >
                    <IconExternalLink class="size-3.5 shrink-0" />
                    <span class="truncate sm:max-w-80">{{ sourceLabel }}</span>
                </a>
                <span
                    v-else-if="sourceLabel"
                    class="truncate text-muted-foreground sm:max-w-80"
                    :title="sourceLabel"
                >
                    {{ sourceLabel }}
                </span>
            </div>
        </div>

        <div v-if="canApprove" class="flex shrink-0 items-center gap-1">
            <Button
                v-if="!event.verified"
                size="sm"
                variant="outline"
                :loading="busy"
                :data-testid="`socialhq-event-confirm-${event.id}`"
                @click="confirm"
            >
                {{ $t('socialhq_events.actions.confirm') }}
            </Button>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        :aria-label="
                            $t('socialhq_events.actions.more', {
                                name: event.name,
                            })
                        "
                        :data-testid="`socialhq-event-menu-${event.id}`"
                    >
                        <IconDots class="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem
                        v-if="event.verified"
                        :data-testid="`socialhq-event-unconfirm-${event.id}`"
                        @select="unconfirm"
                    >
                        {{ $t('socialhq_events.actions.unconfirm') }}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        :data-testid="`socialhq-event-edit-${event.id}`"
                        @select="edit"
                    >
                        {{ $t('socialhq_events.actions.edit') }}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        variant="destructive"
                        :data-testid="`socialhq-event-delete-${event.id}`"
                        @select="remove"
                    >
                        {{ $t('socialhq_events.actions.delete') }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </li>
</template>
