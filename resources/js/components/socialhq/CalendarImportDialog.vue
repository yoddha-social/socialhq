<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { IconFileSpreadsheet, IconUpload } from '@tabler/icons-vue';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import {
    CALENDAR_FIELDS,
    type CalendarField,
    type CalendarMapping,
    type CalendarSheetPreview,
    type CalendarUpload,
    type DateOrder,
    type PastCalendar,
} from '@/components/socialhq/oldCalendars';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatFullNumber } from '@/lib/utils';
import { destroy, store, update } from '@/routes/socialhq/brand/calendars';

// Social HQ: "Import a calendar" on Brand knowledge. Step 1 uploads the file
// (kept on the server until confirmed); step 2 matches its columns to the
// fields and imports the rows.

const DATE_ORDERS: DateOrder[] = ['day_first', 'month_first'];

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    imported: [calendar: PastCalendar];
}>();

const emptyMapping = (): CalendarMapping =>
    Object.fromEntries(
        CALENDAR_FIELDS.map((field) => [field, null]),
    ) as CalendarMapping;

const uploadHttp = useHttp<{ file: File | null }, CalendarUpload>({
    file: null,
});
const confirmHttp = useHttp<
    { sheet: number; mapping: CalendarMapping; date_order: DateOrder },
    { calendar: PastCalendar }
>({
    sheet: 0,
    mapping: emptyMapping(),
    date_order: 'day_first',
});
const removeHttp = useHttp<Record<string, never>>({});

const upload = ref<CalendarUpload | null>(null);
const uploadError = ref<string | null>(null);
const confirmError = ref<string | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const sheetIndex = ref('0');
const dateOrder = ref<DateOrder>('day_first');
/** Field => column index as a select value ('' for "Not in this file"). */
const choices = reactive<Record<CalendarField, string>>(
    Object.fromEntries(CALENDAR_FIELDS.map((field) => [field, ''])) as Record<
        CalendarField,
        string
    >,
);

const sheet = computed<CalendarSheetPreview | null>(
    () =>
        upload.value?.sheets.find(
            (candidate) => String(candidate.index) === sheetIndex.value,
        ) ??
        upload.value?.sheets[0] ??
        null,
);

const mapping = computed<CalendarMapping>(
    () =>
        Object.fromEntries(
            CALENDAR_FIELDS.map((field) => [
                field,
                choices[field] === '' ? null : Number(choices[field]),
            ]),
        ) as CalendarMapping,
);

/** The mapped fields, in field order, for the preview table. */
const previewFields = computed(() =>
    CALENDAR_FIELDS.filter((field) => mapping.value[field] !== null),
);

const hasDateColumn = computed(() => mapping.value.date !== null);

const applySheet = (preview: CalendarSheetPreview | null): void => {
    CALENDAR_FIELDS.forEach((field) => {
        const column = preview?.mapping[field];
        choices[field] =
            column === null || column === undefined ? '' : String(column);
    });
    dateOrder.value = preview?.date_order ?? 'day_first';
};

watch(sheetIndex, () => {
    applySheet(sheet.value);
    confirmError.value = null;
});

/** Drop the upload on the server when the dialog closes without importing. */
const discardUpload = (): void => {
    const pending = upload.value;

    upload.value = null;

    if (pending) {
        void removeHttp.delete(destroy.url(pending.id)).catch(() => undefined);
    }
};

const reset = (): void => {
    uploadHttp.reset();
    uploadHttp.clearErrors();
    confirmHttp.clearErrors();
    uploadError.value = null;
    confirmError.value = null;
    sheetIndex.value = '0';
    applySheet(null);
};

watch(open, (isOpen) => {
    if (!isOpen) {
        discardUpload();
        reset();
    }
});

onBeforeUnmount(() => {
    uploadHttp.cancel();
    confirmHttp.cancel();
});

const firstError = (
    errors: Record<string, string | undefined>,
): string | null =>
    Object.values(errors).find((message) => Boolean(message)) ?? null;

const sendFile = async (file: File): Promise<void> => {
    if (uploadHttp.processing) {
        return;
    }

    uploadError.value = null;
    uploadHttp.clearErrors();
    uploadHttp.file = file;

    let response: CalendarUpload | undefined;

    try {
        response = await uploadHttp.post(store.url());
    } catch {
        uploadError.value = trans('socialhq_calendars.errors.request_failed');

        return;
    }

    if (!response) {
        uploadError.value = firstError(
            uploadHttp.errors as Record<string, string | undefined>,
        );

        return;
    }

    upload.value = response;
    sheetIndex.value = String(response.sheets[0]?.index ?? 0);
    applySheet(response.sheets[0] ?? null);
};

const onFilePicked = (event: Event): void => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';

    if (file) {
        void sendFile(file);
    }
};

const browse = (): void => {
    fileInput.value?.click();
};

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
    const file = event.dataTransfer?.files?.[0];

    if (file) {
        void sendFile(file);
    }
};

const chooseAnotherFile = (): void => {
    discardUpload();
    reset();
};

const close = (): void => {
    open.value = false;
};

const submit = async (): Promise<void> => {
    if (!upload.value || !sheet.value || confirmHttp.processing) {
        return;
    }

    confirmError.value = null;
    confirmHttp.clearErrors();
    confirmHttp.sheet = sheet.value.index;
    confirmHttp.mapping = mapping.value;
    confirmHttp.date_order = dateOrder.value;

    let response: { calendar: PastCalendar } | undefined;

    try {
        response = await confirmHttp.put(update.url(upload.value.id));
    } catch {
        confirmError.value = trans('socialhq_calendars.errors.request_failed');

        return;
    }

    if (!response) {
        confirmError.value = firstError(
            confirmHttp.errors as Record<string, string | undefined>,
        );

        return;
    }

    const { calendar } = response;

    toast.success(
        calendar.rows_skipped > 0
            ? trans('socialhq_calendars.dialog.imported_with_skipped', {
                  imported: formatFullNumber(calendar.rows_imported),
                  skipped: formatFullNumber(calendar.rows_skipped),
              })
            : transChoice(
                  'socialhq_calendars.dialog.imported',
                  calendar.rows_imported,
                  { count: formatFullNumber(calendar.rows_imported) },
              ),
    );

    // Imported: nothing to discard any more.
    upload.value = null;
    emit('imported', calendar);
    close();
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="sm:max-w-3xl"
            data-testid="socialhq-calendar-import-dialog"
        >
            <DialogHeader class="text-start">
                <DialogTitle>
                    {{
                        upload
                            ? $t('socialhq_calendars.dialog.mapping_title')
                            : $t('socialhq_calendars.dialog.title')
                    }}
                </DialogTitle>
                <DialogDescription>
                    {{
                        upload
                            ? $t(
                                  'socialhq_calendars.dialog.mapping_description',
                              )
                            : $t('socialhq_calendars.dialog.description')
                    }}
                </DialogDescription>
            </DialogHeader>

            <div v-if="!upload" class="flex flex-col gap-3">
                <div
                    class="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-border-strong px-4 py-10 text-center transition-colors"
                    :class="{ 'border-primary bg-primary/5': dragging }"
                    data-testid="socialhq-calendar-dropzone"
                    @dragenter.prevent="onDragEnter"
                    @dragover.prevent
                    @dragleave.prevent="onDragLeave"
                    @drop.prevent="onDrop"
                >
                    <span
                        class="inline-flex size-10 items-center justify-center rounded-full bg-muted text-muted-foreground"
                    >
                        <IconFileSpreadsheet class="size-5" />
                    </span>
                    <Button
                        type="button"
                        :loading="uploadHttp.processing"
                        data-testid="socialhq-calendar-choose"
                        @click="browse"
                    >
                        <IconUpload class="size-4" />
                        {{ $t('socialhq_calendars.dialog.choose') }}
                    </Button>
                    <p class="text-sm text-muted-foreground">
                        {{
                            uploadHttp.processing
                                ? $t('socialhq_calendars.dialog.uploading')
                                : $t('socialhq_calendars.dialog.drop')
                        }}
                    </p>
                    <input
                        ref="fileInput"
                        type="file"
                        class="sr-only"
                        tabindex="-1"
                        aria-hidden="true"
                        accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                        data-testid="socialhq-calendar-file"
                        @change="onFilePicked"
                    />
                </div>
                <p
                    v-if="uploadError"
                    role="alert"
                    class="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive-text"
                    data-testid="socialhq-calendar-upload-error"
                >
                    {{ uploadError }}
                </p>
            </div>

            <form
                v-else-if="sheet"
                id="socialhq-calendar-mapping"
                class="flex min-w-0 flex-col gap-5 [&_[data-slot=native-select-wrapper]]:w-full"
                @submit.prevent="submit"
            >
                <p class="text-sm text-muted-foreground">
                    {{
                        $t('socialhq_calendars.dialog.file', {
                            file: upload.file_name,
                            rows: $tChoice(
                                'socialhq_calendars.rows',
                                sheet.rows,
                                { count: formatFullNumber(sheet.rows) },
                            ),
                        })
                    }}
                </p>

                <div
                    v-if="upload.sheets.length > 1"
                    class="flex flex-col gap-2"
                >
                    <Label for="socialhq-calendar-sheet">
                        {{ $t('socialhq_calendars.dialog.sheet') }}
                    </Label>
                    <NativeSelect
                        id="socialhq-calendar-sheet"
                        v-model="sheetIndex"
                        data-testid="socialhq-calendar-sheet"
                    >
                        <NativeSelectOption
                            v-for="candidate in upload.sheets"
                            :key="candidate.index"
                            :value="String(candidate.index)"
                        >
                            {{
                                $t('socialhq_calendars.dialog.sheet_rows', {
                                    name: candidate.name,
                                    rows: $tChoice(
                                        'socialhq_calendars.rows',
                                        candidate.rows,
                                        {
                                            count: formatFullNumber(
                                                candidate.rows,
                                            ),
                                        },
                                    ),
                                })
                            }}
                        </NativeSelectOption>
                    </NativeSelect>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div
                        v-for="field in CALENDAR_FIELDS"
                        :key="field"
                        class="flex flex-col gap-2"
                    >
                        <Label :for="`socialhq-calendar-field-${field}`">
                            {{ $t(`socialhq_calendars.fields.${field}`) }}
                            <span
                                v-if="field === 'date'"
                                class="text-xs font-normal text-muted-foreground"
                            >
                                {{ $t('socialhq_calendars.dialog.required') }}
                            </span>
                        </Label>
                        <NativeSelect
                            :id="`socialhq-calendar-field-${field}`"
                            v-model="choices[field]"
                            :aria-invalid="
                                field === 'date' && !hasDateColumn
                                    ? true
                                    : undefined
                            "
                            :data-testid="`socialhq-calendar-field-${field}`"
                        >
                            <NativeSelectOption value="">
                                {{
                                    $t('socialhq_calendars.dialog.not_in_file')
                                }}
                            </NativeSelectOption>
                            <NativeSelectOption
                                v-for="column in sheet.columns"
                                :key="column.index"
                                :value="String(column.index)"
                            >
                                {{
                                    column.label
                                        ? $t(
                                              'socialhq_calendars.dialog.column_named',
                                              {
                                                  label: column.label,
                                                  letter: column.letter,
                                              },
                                          )
                                        : $t(
                                              'socialhq_calendars.dialog.column',
                                              { letter: column.letter },
                                          )
                                }}
                            </NativeSelectOption>
                        </NativeSelect>
                    </div>
                </div>

                <div v-if="hasDateColumn" class="flex flex-col gap-2">
                    <Label for="socialhq-calendar-date-order">
                        {{ $t('socialhq_calendars.dialog.date_order') }}
                    </Label>
                    <NativeSelect
                        id="socialhq-calendar-date-order"
                        v-model="dateOrder"
                        class="sm:w-72"
                        data-testid="socialhq-calendar-date-order"
                    >
                        <NativeSelectOption
                            v-for="order in DATE_ORDERS"
                            :key="order"
                            :value="order"
                        >
                            {{
                                $t(
                                    `socialhq_calendars.dialog.date_orders.${order}`,
                                )
                            }}
                        </NativeSelectOption>
                    </NativeSelect>
                    <p
                        v-if="!sheet.date_order_certain"
                        class="text-sm text-muted-foreground"
                    >
                        {{ $t('socialhq_calendars.dialog.date_order_check') }}
                    </p>
                </div>

                <section class="flex min-w-0 flex-col gap-2">
                    <h3 class="text-sm font-medium text-foreground">
                        {{ $t('socialhq_calendars.dialog.preview') }}
                    </h3>
                    <p
                        v-if="!hasDateColumn"
                        class="text-sm text-muted-foreground"
                    >
                        {{ $t('socialhq_calendars.dialog.preview_empty') }}
                    </p>
                    <div
                        v-else
                        class="overflow-x-auto rounded-lg border border-border"
                        data-testid="socialhq-calendar-preview"
                    >
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead
                                        v-for="field in previewFields"
                                        :key="field"
                                        class="whitespace-nowrap"
                                    >
                                        {{
                                            $t(
                                                `socialhq_calendars.fields.${field}`,
                                            )
                                        }}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow
                                    v-for="(sample, rowIndex) in sheet.samples"
                                    :key="rowIndex"
                                >
                                    <TableCell
                                        v-for="field in previewFields"
                                        :key="field"
                                        class="max-w-56 truncate"
                                    >
                                        {{ sample[mapping[field] ?? -1] ?? '' }}
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>
                </section>

                <p
                    v-if="confirmError"
                    role="alert"
                    class="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive-text"
                    data-testid="socialhq-calendar-confirm-error"
                >
                    {{ confirmError }}
                </p>
            </form>

            <DialogFooter>
                <Button type="button" variant="ghost" @click="close">
                    {{ $t('socialhq_calendars.dialog.cancel') }}
                </Button>
                <Button
                    v-if="upload"
                    type="button"
                    variant="outline"
                    data-testid="socialhq-calendar-back"
                    @click="chooseAnotherFile"
                >
                    {{ $t('socialhq_calendars.dialog.back') }}
                </Button>
                <Button
                    v-if="upload"
                    type="submit"
                    form="socialhq-calendar-mapping"
                    :disabled="!hasDateColumn"
                    :loading="confirmHttp.processing"
                    data-testid="socialhq-calendar-submit"
                >
                    {{ $t('socialhq_calendars.dialog.submit') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
