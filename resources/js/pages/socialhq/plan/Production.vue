<script setup lang="ts">
import { Head, Link, router, useHttp } from '@inertiajs/vue3';
import { IconLayoutKanban, IconUser } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import EmptyState from '@/components/EmptyState.vue';
import type {
    BriefStage,
    PlanAbilities,
} from '@/components/socialhq/plan/item/types';
import PlanHeader from '@/components/socialhq/plan/PlanHeader.vue';
import PlanTabs from '@/components/socialhq/plan/PlanTabs.vue';
import BoardCard from '@/components/socialhq/plan/production/BoardCard.vue';
import type {
    BoardCardData,
    BoardFilters,
} from '@/components/socialhq/plan/production/types';
import { useProductionBoard } from '@/components/socialhq/plan/production/useProductionBoard';
import { Button } from '@/components/ui/button';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import dayjs from '@/dayjs';
import AppLayout from '@/layouts/AppLayout.vue';
import { extractErrorMessage } from '@/lib/httpError';
import { show as planShow, production } from '@/routes/socialhq/plan';
import { move as moveBrief } from '@/routes/socialhq/plan/board';

const props = defineProps<{
    stages: BriefStage[];
    cards: BoardCardData[];
    hasBriefs: boolean;
    filters: BoardFilters;
    months: string[];
    approverNames: string[];
    highlight: number | null;
    abilities: PlanAbilities;
}>();

/** The board as shown: the server's cards with moves applied while they save. */
const board = ref<BoardCardData[]>([...props.cards]);

watch(
    () => props.cards,
    (cards) => {
        board.value = [...cards];
    },
);

const columnCards = (stage: BriefStage): BoardCardData[] =>
    board.value
        .filter((card) => card.stage === stage)
        .sort((a, b) => a.position - b.position || a.id - b.id);

const moveHttp = useHttp<
    { stage: BriefStage; ordered_ids: number[] },
    { stage: BriefStage }
>({ stage: 'todo', ordered_ids: [] });

onBeforeUnmount(() => {
    moveHttp.cancel();
});

const needsApprover = (from: BriefStage, to: BriefStage): boolean =>
    from !== to && (from === 'approved' || to === 'approved');

const applyMove = async (
    briefId: number,
    from: BriefStage,
    to: BriefStage,
    index: number,
): Promise<void> => {
    if (needsApprover(from, to) && !props.abilities.canApprove) {
        toast.error(
            trans('socialhq_production.board.errors.approvers_only', {
                names: props.approverNames.join(', '),
            }),
        );

        return;
    }

    const order = columnCards(to)
        .map((card) => card.id)
        .filter((id) => id !== briefId);
    order.splice(Math.min(index, order.length), 0, briefId);

    const unchanged =
        from === to &&
        columnCards(to).every((card, position) => card.id === order[position]);

    if (unchanged) {
        return;
    }

    const snapshot = board.value.map((card) => ({ ...card }));

    board.value = board.value.map((card) => {
        const position = order.indexOf(card.id);

        return position === -1 ? card : { ...card, stage: to, position };
    });

    moveHttp.stage = to;
    moveHttp.ordered_ids = order;

    try {
        const result = await moveHttp.put(moveBrief.url(briefId));

        if (!result) {
            board.value = snapshot;
            toast.error(trans('socialhq_production.board.move_failed'));

            return;
        }

        if (from !== to) {
            toast.success(
                trans('socialhq_production.board.moved', {
                    stage: trans(`socialhq_production.brief.stages.${to}`),
                }),
            );
        }
    } catch (exception) {
        board.value = snapshot;
        toast.error(
            extractErrorMessage(exception) ??
                trans('socialhq_production.board.move_failed'),
        );
    }
};

const { preview, registerCard, registerColumn } = useProductionBoard({
    onDrop: (briefId, from, to, index) => {
        void applyMove(briefId, from, to, index);
    },
});

const moveWithMenu = (card: BoardCardData, stage: BriefStage): void => {
    void applyMove(card.id, card.stage, stage, columnCards(stage).length);
};

const columnEls = new Map<BriefStage, HTMLElement>();
const listEls = new Map<BriefStage, HTMLElement>();
const cleanups: (() => void)[] = [];

const setColumnEl = (stage: BriefStage, el: unknown): void => {
    if (el instanceof HTMLElement) {
        columnEls.set(stage, el);
    }
};

const setListEl = (stage: BriefStage, el: unknown): void => {
    if (el instanceof HTMLElement) {
        listEls.set(stage, el);
    }
};

onMounted(() => {
    props.stages.forEach((stage) => {
        const column = columnEls.get(stage);
        const list = listEls.get(stage);

        if (column && list) {
            cleanups.push(registerColumn(column, list, stage));
        }
    });
});

onBeforeUnmount(() => {
    cleanups.forEach((cleanup) => cleanup());
});

/** Where the drop line goes in a column, or null when it isn't the target. */
const placeholderIndex = (stage: BriefStage): number | null =>
    preview.value?.over && preview.value.stage === stage
        ? preview.value.index
        : null;

/** The column's cards with the slot for the dragged card, which itself is not counted. */
const rows = (
    stage: BriefStage,
): ({ type: 'card'; card: BoardCardData } | { type: 'slot' })[] => {
    const cards = columnCards(stage);
    const slot = placeholderIndex(stage);
    const draggedId = preview.value?.briefId;
    const result: ({ type: 'card'; card: BoardCardData } | { type: 'slot' })[] =
        [];
    let counted = 0;

    cards.forEach((card) => {
        if (card.id !== draggedId && slot === counted) {
            result.push({ type: 'slot' });
        }

        result.push({ type: 'card', card });

        if (card.id !== draggedId) {
            counted++;
        }
    });

    if (slot !== null && slot >= counted) {
        result.push({ type: 'slot' });
    }

    return result;
};

const isEmptyBoard = computed(() => !props.hasBriefs);

const visit = (filters: Partial<BoardFilters>): void => {
    const next = { ...props.filters, ...filters };
    const query: Record<string, string> = {};

    if (next.mine) {
        query.mine = '1';
    }

    if (next.kind) {
        query.kind = next.kind;
    }

    if (next.month) {
        query.month = next.month;
    }

    router.get(
        production.url({ query }),
        {},
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const toggleMine = (): void => {
    visit({ mine: !props.filters.mine });
};

const kindModel = computed({
    get: (): string => props.filters.kind ?? '',
    set: (value: unknown): void => {
        visit({
            kind: value === 'graphic' || value === 'video' ? value : null,
        });
    },
});

const monthModel = computed({
    get: (): string => props.filters.month ?? '',
    set: (value: unknown): void => {
        visit({
            month: typeof value === 'string' && value !== '' ? value : null,
        });
    },
});

const monthLabel = (month: string): string =>
    dayjs(`${month}-01`).format('MMMM YYYY');
</script>

<template>
    <Head :title="$t('socialhq_plan.tabs.production')" />
    <AppLayout full-width>
        <template #header>
            <PlanHeader />
        </template>
        <PlanTabs active="production">
            <template v-if="!isEmptyBoard" #filters>
                <Button
                    size="sm"
                    :variant="filters.mine ? 'secondary' : 'ghost'"
                    :aria-pressed="filters.mine"
                    data-testid="board-filter-mine"
                    @click="toggleMine"
                >
                    <IconUser />
                    {{ $t('socialhq_production.board.filters.mine') }}
                </Button>
                <NativeSelect
                    v-model="kindModel"
                    class="h-7 text-xs"
                    :aria-label="$t('socialhq_production.board.filters.kind')"
                    data-testid="board-filter-kind"
                >
                    <NativeSelectOption value="">
                        {{ $t('socialhq_production.board.filters.all_kinds') }}
                    </NativeSelectOption>
                    <NativeSelectOption value="graphic">
                        {{ $t('socialhq_production.brief.kinds.graphic') }}
                    </NativeSelectOption>
                    <NativeSelectOption value="video">
                        {{ $t('socialhq_production.brief.kinds.video') }}
                    </NativeSelectOption>
                </NativeSelect>
                <NativeSelect
                    v-model="monthModel"
                    class="h-7 text-xs"
                    :aria-label="$t('socialhq_production.board.filters.month')"
                    data-testid="board-filter-month"
                >
                    <NativeSelectOption value="">
                        {{ $t('socialhq_production.board.filters.all_months') }}
                    </NativeSelectOption>
                    <NativeSelectOption
                        v-for="month in months"
                        :key="month"
                        :value="month"
                    >
                        {{ monthLabel(month) }}
                    </NativeSelectOption>
                </NativeSelect>
            </template>
        </PlanTabs>

        <div
            v-if="isEmptyBoard"
            class="mx-auto flex max-w-lg flex-col items-center gap-4 px-4 py-16"
            data-testid="board-empty"
        >
            <EmptyState
                :icon="IconLayoutKanban"
                :title="$t('socialhq_production.board.empty_title')"
                :description="$t('socialhq_production.board.empty_description')"
            />
            <Button as-child>
                <Link :href="planShow.url()" data-testid="board-open-plan">
                    {{ $t('socialhq_production.board.open_plan') }}
                </Link>
            </Button>
        </div>

        <div v-else class="px-4 py-6 md:px-8" data-testid="production-board">
            <p
                v-if="cards.length === 0"
                class="mb-4 text-sm text-muted-foreground"
                data-testid="board-no-results"
            >
                {{ $t('socialhq_production.board.no_results') }}
            </p>
            <div
                class="flex snap-x gap-4 overflow-x-auto pb-4"
                :aria-label="$t('socialhq_production.board.label')"
                role="region"
            >
                <section
                    v-for="stage in stages"
                    :key="stage"
                    :ref="(el) => setColumnEl(stage, el)"
                    class="flex w-72 shrink-0 snap-start flex-col rounded-xl bg-muted/50 p-2 transition-colors"
                    :class="
                        placeholderIndex(stage) !== null
                            ? 'bg-primary-subtle/60'
                            : ''
                    "
                    :aria-labelledby="`board-column-${stage}`"
                    :data-testid="`board-column-${stage}`"
                >
                    <h2
                        :id="`board-column-${stage}`"
                        class="flex items-center justify-between px-2 py-1.5 text-sm font-semibold"
                    >
                        {{ $t(`socialhq_production.brief.stages.${stage}`) }}
                        <span
                            class="text-xs font-normal text-muted-foreground tabular-nums"
                        >
                            {{ columnCards(stage).length }}
                        </span>
                    </h2>
                    <ol
                        :ref="(el) => setListEl(stage, el)"
                        class="relative flex min-h-24 flex-col gap-2"
                    >
                        <template
                            v-for="(row, index) in rows(stage)"
                            :key="
                                row.type === 'card'
                                    ? row.card.id
                                    : `slot-${index}`
                            "
                        >
                            <li
                                v-if="row.type === 'slot'"
                                class="h-1 rounded-full bg-primary"
                                aria-hidden="true"
                            />
                            <BoardCard
                                v-else
                                :card="row.card"
                                :stages="stages"
                                :dragging="preview?.briefId === row.card.id"
                                :highlighted="highlight === row.card.id"
                                :register="registerCard"
                                @move="moveWithMenu(row.card, $event)"
                            />
                        </template>
                        <li
                            v-if="
                                columnCards(stage).length === 0 &&
                                placeholderIndex(stage) === null
                            "
                            class="px-2 py-6 text-center text-xs text-muted-foreground"
                        >
                            {{ $t('socialhq_production.board.column_empty') }}
                        </li>
                    </ol>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
