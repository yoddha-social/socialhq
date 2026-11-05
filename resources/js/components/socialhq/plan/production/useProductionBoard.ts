import {
    draggable,
    dropTargetForElements,
    monitorForElements,
} from '@atlaskit/pragmatic-drag-and-drop/element/adapter';
import { onBeforeUnmount, ref, type Ref } from 'vue';

import type { BriefStage } from '@/components/socialhq/plan/item/types';
import { cancelDragOnEscape, placeholderIndexAt } from '@/lib/dragPlaceholder';

/**
 * The card being dragged and where it would land: `index` among the cards of
 * `stage`, not counting the dragged card. `over` is false while the pointer
 * is outside every column; a drop there changes nothing.
 */
export interface BoardDragPreview {
    briefId: number;
    from: BriefStage;
    stage: BriefStage;
    index: number;
    over: boolean;
}

type DragData = Record<string | symbol, unknown>;

const CARD_KEY = 'productionBoardCard';
const COLUMN_KEY = 'productionBoardColumn';

const isCard = (
    data: DragData,
): data is DragData & { briefId: number; stage: BriefStage } =>
    data[CARD_KEY] === true && typeof data.briefId === 'number';

/**
 * Drag and drop for the production board (pragmatic-drag-and-drop, as on the
 * ideas board): cards move within and between stage columns, and the drop
 * hands the new column and position to `onDrop`.
 */
export const useProductionBoard = (options: {
    onDrop: (
        briefId: number,
        from: BriefStage,
        to: BriefStage,
        index: number,
    ) => void;
}): {
    preview: Ref<BoardDragPreview | null>;
    registerCard: (
        el: HTMLElement,
        card: () => { briefId: number; stage: BriefStage },
    ) => () => void;
    registerColumn: (
        el: HTMLElement,
        list: HTMLElement,
        stage: BriefStage,
    ) => () => void;
} => {
    const preview = ref<BoardDragPreview | null>(null);
    const lists = new Map<BriefStage, HTMLElement>();
    let stopEscape: (() => void) | null = null;

    const end = (): void => {
        preview.value = null;
        stopEscape?.();
        stopEscape = null;
    };

    const place = (
        targets: { data: DragData }[],
        pointer: { clientX: number; clientY: number },
    ): void => {
        const current = preview.value;

        if (!current) {
            return;
        }

        const column = targets.find(
            (target) => target.data[COLUMN_KEY] === true,
        );
        const stage = column?.data.stage as BriefStage | undefined;
        const list = stage ? lists.get(stage) : undefined;

        if (!stage || !list) {
            preview.value = { ...current, over: false };

            return;
        }

        preview.value = {
            ...current,
            stage,
            over: true,
            index: placeholderIndexAt(list, pointer, {
                attribute: 'sortableBrief',
                draggedId: String(current.briefId),
                axis: 'vertical',
            }),
        };
    };

    const stopMonitor = monitorForElements({
        canMonitor: ({ source }) => isCard(source.data),
        onDragStart: ({ source }) => {
            if (!isCard(source.data)) {
                return;
            }

            preview.value = {
                briefId: source.data.briefId,
                from: source.data.stage,
                stage: source.data.stage,
                index: 0,
                over: false,
            };
            stopEscape = cancelDragOnEscape(end);
        },
        onDrag: ({ location }) => {
            place(location.current.dropTargets, location.current.input);
        },
        onDrop: ({ location }) => {
            place(location.current.dropTargets, location.current.input);

            const final = preview.value;
            end();

            if (final?.over) {
                options.onDrop(
                    final.briefId,
                    final.from,
                    final.stage,
                    final.index,
                );
            }
        },
    });

    onBeforeUnmount(() => {
        stopMonitor();
        end();
    });

    const registerCard = (
        el: HTMLElement,
        card: () => { briefId: number; stage: BriefStage },
    ): (() => void) => {
        el.dataset.sortableBrief = String(card().briefId);

        const cleanup = draggable({
            element: el,
            getInitialData: () => ({ [CARD_KEY]: true, ...card() }),
        });

        return () => {
            delete el.dataset.sortableBrief;
            cleanup();
        };
    };

    const registerColumn = (
        el: HTMLElement,
        list: HTMLElement,
        stage: BriefStage,
    ): (() => void) => {
        lists.set(stage, list);

        const cleanup = dropTargetForElements({
            element: el,
            canDrop: ({ source }) => isCard(source.data),
            getData: () => ({ [COLUMN_KEY]: true, stage }),
        });

        return () => {
            lists.delete(stage);
            cleanup();
        };
    };

    return { preview, registerCard, registerColumn };
};
