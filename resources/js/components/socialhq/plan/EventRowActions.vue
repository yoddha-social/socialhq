<script setup lang="ts">
import { IconDots, IconFlag } from '@tabler/icons-vue';
import { computed } from 'vue';

import type { CalendarEventRow } from '@/components/socialhq/plan/eventStyles';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * What a person may do with one date: confirm it, and in its menu take the
 * confirmation back, edit, hide it for this brand or delete it; flag it
 * (someone who works on the plan but can't change it); or show it again
 * (in "Hidden for this brand"). Each country's date of an event has its own.
 */
const props = withDefaults(
    defineProps<{
        event: CalendarEventRow;
        /** May change this date (an approver; a shared one needs who manages shared dates). */
        canApprove: boolean;
        busy: boolean;
        canHide?: boolean;
        canFlag?: boolean;
        hidden?: boolean;
        /**
         * The country (or International, This brand only) when the date is
         * one row of an event in several countries: the buttons say which.
         */
        place?: string | null;
    }>(),
    { canHide: false, canFlag: false, hidden: false, place: null },
);

const emit = defineEmits<{
    confirm: [event: CalendarEventRow];
    unconfirm: [event: CalendarEventRow];
    edit: [event: CalendarEventRow];
    delete: [event: CalendarEventRow];
    hide: [event: CalendarEventRow];
    unhide: [event: CalendarEventRow];
    flag: [event: CalendarEventRow];
}>();

const hasMenu = computed(
    () => !props.hidden && (props.canApprove || props.canHide),
);

/** The date's name for the menu's label, with its country in a row. */
const menuName = computed((): string =>
    props.place ? `${props.event.name} · ${props.place}` : props.event.name,
);

const confirm = (): void => emit('confirm', props.event);
const unconfirm = (): void => emit('unconfirm', props.event);
const edit = (): void => emit('edit', props.event);
const remove = (): void => emit('delete', props.event);
const hide = (): void => emit('hide', props.event);
const unhide = (): void => emit('unhide', props.event);
const flag = (): void => emit('flag', props.event);
</script>

<template>
    <div class="flex shrink-0 items-center gap-1">
        <Button
            v-if="hidden"
            size="sm"
            variant="outline"
            :loading="busy"
            :data-testid="`socialhq-event-unhide-${event.id}`"
            @click="unhide"
        >
            {{ $t('socialhq_events.hide.show') }}
        </Button>
        <Button
            v-else-if="canFlag && !canApprove"
            size="sm"
            variant="ghost"
            :aria-label="
                place
                    ? $t('socialhq_events.group.flag_for', { country: place })
                    : undefined
            "
            :data-testid="`socialhq-event-flag-${event.id}`"
            @click="flag"
        >
            <IconFlag class="size-4" />
            {{ $t('socialhq_events.flag.action') }}
        </Button>
        <template v-if="hasMenu">
            <Button
                v-if="canApprove && !event.verified && !event.delisted"
                size="sm"
                variant="outline"
                :loading="busy"
                :aria-label="
                    place
                        ? $t('socialhq_events.group.confirm_for', {
                              country: place,
                          })
                        : undefined
                "
                :data-testid="`socialhq-event-confirm-${event.id}`"
                @click="confirm"
            >
                {{
                    place
                        ? $t('socialhq_events.group.confirm')
                        : $t('socialhq_events.actions.confirm')
                }}
            </Button>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        :aria-label="
                            $t('socialhq_events.actions.more', {
                                name: menuName,
                            })
                        "
                        :data-testid="`socialhq-event-menu-${event.id}`"
                    >
                        <IconDots class="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <template v-if="canApprove">
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
                    </template>
                    <DropdownMenuItem
                        v-if="canHide"
                        :data-testid="`socialhq-event-hide-${event.id}`"
                        @select="hide"
                    >
                        {{ $t('socialhq_events.hide.action') }}
                    </DropdownMenuItem>
                    <template v-if="canApprove">
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            :data-testid="`socialhq-event-delete-${event.id}`"
                            @select="remove"
                        >
                            {{ $t('socialhq_events.actions.delete') }}
                        </DropdownMenuItem>
                    </template>
                </DropdownMenuContent>
            </DropdownMenu>
        </template>
    </div>
</template>
