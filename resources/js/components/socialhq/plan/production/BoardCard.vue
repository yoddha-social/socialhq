<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconDots, IconMovie, IconPhoto, IconStack2 } from '@tabler/icons-vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

import PlatformLogo from '@/components/PlatformLogo.vue';
import type { BriefStage } from '@/components/socialhq/plan/item/types';
import type { BoardCardData } from '@/components/socialhq/plan/production/types';
import type { BoardCardHandle } from '@/components/socialhq/plan/production/useProductionBoard';
import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import date from '@/date';
import dayjs from '@/dayjs';
import { userTimezone } from '@/preferences';
import { show as showItem } from '@/routes/socialhq/plan/items';

const props = defineProps<{
    card: BoardCardData;
    /** The stages this viewer may move the card to, its own excluded. */
    targets: BriefStage[];
    dragging: boolean;
    highlighted: boolean;
    register: (el: HTMLElement, card: () => BoardCardHandle) => () => void;
}>();

const emit = defineEmits<{
    move: [stage: BriefStage];
}>();

const root = ref<HTMLElement | null>(null);
let cleanup: (() => void) | null = null;

onMounted(() => {
    if (root.value) {
        cleanup = props.register(root.value, () => ({
            briefId: props.card.id,
            stage: props.card.stage,
            // Every card can be reordered within its column; the columns decide where it may go.
            movable: true,
        }));
    }

    if (props.highlighted) {
        root.value?.scrollIntoView({ block: 'center', inline: 'center' });
    }
});

onBeforeUnmount(() => {
    cleanup?.();
});

const isOverdue = computed(
    () =>
        props.card.due_on !== null &&
        props.card.stage !== 'approved' &&
        dayjs(props.card.due_on).isBefore(
            dayjs().tz(userTimezone.value).format('YYYY-MM-DD'),
            'day',
        ),
);

const moveTo = (stage: BriefStage): void => {
    emit('move', stage);
};
</script>

<template>
    <li
        ref="root"
        class="group relative rounded-lg border bg-card shadow-xs transition-opacity"
        :class="[
            targets.length > 0 ? 'cursor-grab active:cursor-grabbing' : '',
            dragging ? 'opacity-40' : '',
            highlighted ? 'ring-2 ring-primary' : '',
        ]"
        :data-testid="`board-card-${card.id}`"
    >
        <div
            v-if="card.thumbnail_url"
            class="aspect-[16/9] overflow-hidden rounded-t-lg bg-muted"
        >
            <img
                :src="card.thumbnail_url"
                alt=""
                loading="lazy"
                draggable="false"
                class="size-full object-cover"
            />
        </div>
        <div class="grid gap-2 p-3">
            <div class="flex items-start justify-between gap-2">
                <Link
                    :href="showItem.url(card.item_id)"
                    class="text-sm leading-snug font-medium after:absolute after:inset-0 after:content-[''] hover:underline focus-visible:outline-none"
                    draggable="false"
                    :data-testid="`board-card-link-${card.id}`"
                >
                    {{ card.title }}
                </Link>
                <DropdownMenu v-if="targets.length > 0">
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon-xs"
                            class="relative z-10 -me-1 shrink-0"
                            :aria-label="
                                $t('socialhq_production.board.move_card', {
                                    title: card.title,
                                })
                            "
                            :data-testid="`board-card-menu-${card.id}`"
                        >
                            <IconDots class="size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-52">
                        <DropdownMenuLabel>
                            {{ $t('socialhq_production.board.move_to') }}
                        </DropdownMenuLabel>
                        <DropdownMenuItem
                            v-for="stage in targets"
                            :key="stage"
                            :data-testid="`board-card-move-${card.id}-${stage}`"
                            @select="moveTo(stage)"
                        >
                            {{
                                $t(`socialhq_production.brief.stages.${stage}`)
                            }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
            <div
                class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground"
            >
                <span class="inline-flex items-center gap-1">
                    <IconMovie v-if="card.kind === 'video'" class="size-3.5" />
                    <IconPhoto v-else class="size-3.5" />
                    {{ $t(`socialhq_production.brief.kinds.${card.kind}`) }}
                </span>
                <span
                    v-if="card.platform || card.channel"
                    class="inline-flex min-w-0 items-center gap-1"
                >
                    <PlatformLogo
                        v-if="card.platform"
                        :platform="card.platform"
                        size="xs"
                    />
                    <span class="truncate">
                        {{ card.channel
                        }}<template v-if="card.format">
                            · {{ card.format }}</template
                        >
                    </span>
                </span>
            </div>
            <div class="flex items-center justify-between gap-2 text-xs">
                <span
                    v-if="card.due_on"
                    :class="
                        isOverdue
                            ? 'font-medium text-amber-700 dark:text-amber-400'
                            : 'text-muted-foreground'
                    "
                    :data-testid="`board-card-due-${card.id}`"
                >
                    {{
                        isOverdue
                            ? $t('socialhq_production.board.overdue', {
                                  date: date.formatDateOnly(card.due_on),
                              })
                            : $t('socialhq_production.board.due', {
                                  date: date.formatDateOnly(card.due_on),
                              })
                    }}
                </span>
                <span v-else />
                <span class="flex items-center gap-2">
                    <span
                        class="inline-flex items-center gap-1 text-muted-foreground"
                    >
                        <IconStack2 class="size-3.5" />
                        {{
                            $tChoice(
                                'socialhq_production.board.rounds',
                                card.rounds,
                                { count: String(card.rounds) },
                            )
                        }}
                    </span>
                    <Avatar
                        :src="card.assignee?.photo_url ?? null"
                        :name="
                            card.assignee?.name ??
                            $t('socialhq_production.board.unassigned')
                        "
                        :title="
                            card.assignee?.name ??
                            $t('socialhq_production.board.unassigned')
                        "
                        class="size-5"
                        fallback-class="text-[9px] bg-primary-subtle text-primary-text font-bold"
                    />
                </span>
            </div>
        </div>
    </li>
</template>
