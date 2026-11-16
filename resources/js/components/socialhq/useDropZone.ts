import { computed, ref, type ComputedRef } from 'vue';

/**
 * A drop zone for files. The counter keeps the highlight while the pointer
 * moves over the zone's children (each child fires its own enter and leave).
 * Bind the handlers with `.prevent` on dragenter, dragover, dragleave and drop.
 */
export const useDropZone = (
    onFiles: (files: File[]) => void,
): {
    dragging: ComputedRef<boolean>;
    onDragEnter: () => void;
    onDragLeave: () => void;
    onDrop: (event: DragEvent) => void;
} => {
    const dragDepth = ref(0);
    const dragging = computed(() => dragDepth.value > 0);

    const onDragEnter = (): void => {
        dragDepth.value++;
    };

    const onDragLeave = (): void => {
        dragDepth.value = Math.max(0, dragDepth.value - 1);
    };

    const onDrop = (event: DragEvent): void => {
        dragDepth.value = 0;
        onFiles(Array.from(event.dataTransfer?.files ?? []));
    };

    return { dragging, onDragEnter, onDragLeave, onDrop };
};
