<script setup lang="ts">
import { router, useHttp } from '@inertiajs/vue3';
import { IconCalendarStats, IconPlus } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

import EmptyState from '@/components/EmptyState.vue';
import SettingsListRow from '@/components/settings/SettingsListRow.vue';
import CalendarImportDialog from '@/components/socialhq/CalendarImportDialog.vue';
import CalendarRowsDialog from '@/components/socialhq/CalendarRowsDialog.vue';
import type { PastCalendar } from '@/components/socialhq/oldCalendars';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import date from '@/date';
import { formatFullNumber } from '@/lib/utils';
import { destroy } from '@/routes/socialhq/brand/calendars';

// Social HQ: "Old calendars" on Brand knowledge. The team's past content
// calendars, imported from spreadsheets, for the AI planner to learn from.

defineProps<{
    calendars: PastCalendar[];
    canEdit: boolean;
}>();

const isImportOpen = ref(false);
const isRowsOpen = ref(false);
const isSkippedOpen = ref(false);
const isDeleteOpen = ref(false);
const selected = ref<PastCalendar | null>(null);

const deleteHttp = useHttp<Record<string, never>>({});

const reloadCalendars = (): void => {
    router.reload({ only: ['pastCalendars'] });
};

const openImport = (): void => {
    isImportOpen.value = true;
};

const openRows = (calendar: PastCalendar): void => {
    selected.value = calendar;
    isRowsOpen.value = true;
};

const openSkipped = (calendar: PastCalendar): void => {
    selected.value = calendar;
    isSkippedOpen.value = true;
};

const closeSkipped = (): void => {
    isSkippedOpen.value = false;
};

const openDelete = (calendar: PastCalendar): void => {
    selected.value = calendar;
    isDeleteOpen.value = true;
};

const closeDelete = (): void => {
    isDeleteOpen.value = false;
};

const confirmDelete = async (): Promise<void> => {
    if (!selected.value || deleteHttp.processing) {
        return;
    }

    try {
        await deleteHttp.delete(destroy.url(selected.value.id));
    } catch {
        toast.error(trans('socialhq_calendars.errors.delete_failed'));

        return;
    }

    closeDelete();
    reloadCalendars();
};
</script>

<template>
    <section class="flex flex-col gap-4" data-testid="socialhq-old-calendars">
        <div class="flex items-start justify-between gap-4">
            <div class="flex flex-col gap-1">
                <h2 class="text-base font-semibold text-foreground">
                    {{ $t('socialhq_calendars.title') }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{ $t('socialhq_calendars.description') }}
                </p>
            </div>
            <Button
                v-if="canEdit"
                class="shrink-0"
                data-testid="socialhq-calendar-import"
                @click="openImport"
            >
                <IconPlus class="size-4" />
                {{ $t('socialhq_calendars.import') }}
            </Button>
        </div>

        <EmptyState
            v-if="calendars.length === 0"
            :title="
                canEdit
                    ? $t('socialhq_calendars.empty')
                    : $t('socialhq_calendars.empty_member')
            "
            data-testid="socialhq-old-calendars-empty"
        />

        <ul v-else class="flex flex-col gap-2">
            <SettingsListRow
                v-for="calendar in calendars"
                :key="calendar.id"
                :icon="IconCalendarStats"
                :data-testid="`socialhq-old-calendar-${calendar.id}`"
            >
                <p class="truncate text-sm font-medium text-foreground">
                    {{ calendar.file_name }}
                </p>
                <p class="text-sm text-muted-foreground">
                    <span>
                        {{
                            $tChoice(
                                'socialhq_calendars.rows',
                                calendar.rows_imported,
                                {
                                    count: formatFullNumber(
                                        calendar.rows_imported,
                                    ),
                                },
                            )
                        }}
                    </span>
                    <template v-if="calendar.first_date && calendar.last_date">
                        <span aria-hidden="true"> · </span>
                        <span>
                            {{
                                $t('socialhq_calendars.range', {
                                    from: date.formatDateOnly(
                                        calendar.first_date,
                                    ),
                                    to: date.formatDateOnly(calendar.last_date),
                                })
                            }}
                        </span>
                    </template>
                    <template v-if="calendar.rows_skipped > 0">
                        <span aria-hidden="true"> · </span>
                        <button
                            type="button"
                            class="rounded-sm text-foreground underline-offset-4 hover:underline focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                            :data-testid="`socialhq-old-calendar-skipped-${calendar.id}`"
                            @click="openSkipped(calendar)"
                        >
                            {{
                                $tChoice(
                                    'socialhq_calendars.skipped.count',
                                    calendar.rows_skipped,
                                    {
                                        count: formatFullNumber(
                                            calendar.rows_skipped,
                                        ),
                                    },
                                )
                            }}
                        </button>
                    </template>
                </p>
                <p
                    v-if="calendar.created_at"
                    class="text-xs text-muted-foreground"
                >
                    {{
                        calendar.created_by
                            ? $t('socialhq_calendars.imported_by', {
                                  name: calendar.created_by,
                                  when: date.diffForHumans(calendar.created_at),
                              })
                            : $t('socialhq_calendars.imported_when', {
                                  when: date.diffForHumans(calendar.created_at),
                              })
                    }}
                </p>

                <template #actions>
                    <div
                        class="flex shrink-0 flex-col gap-1 sm:flex-row sm:gap-2"
                    >
                        <Button
                            variant="ghost"
                            :aria-label="
                                $t('socialhq_calendars.view_rows_label', {
                                    file: calendar.file_name,
                                })
                            "
                            :data-testid="`socialhq-old-calendar-rows-${calendar.id}`"
                            @click="openRows(calendar)"
                        >
                            {{ $t('socialhq_calendars.view_rows') }}
                        </Button>
                        <Button
                            v-if="canEdit"
                            variant="ghost"
                            :aria-label="
                                $t('socialhq_calendars.delete_label', {
                                    file: calendar.file_name,
                                })
                            "
                            :data-testid="`socialhq-old-calendar-delete-${calendar.id}`"
                            @click="openDelete(calendar)"
                        >
                            {{ $t('socialhq_calendars.delete') }}
                        </Button>
                    </div>
                </template>
            </SettingsListRow>
        </ul>

        <CalendarImportDialog
            v-if="canEdit"
            v-model:open="isImportOpen"
            @imported="reloadCalendars"
        />

        <CalendarRowsDialog v-model:open="isRowsOpen" :calendar="selected" />

        <Dialog v-model:open="isSkippedOpen">
            <DialogContent
                class="sm:max-w-lg"
                data-testid="socialhq-old-calendar-skipped-dialog"
            >
                <DialogHeader class="text-start">
                    <DialogTitle>
                        {{
                            $t('socialhq_calendars.skipped.title', {
                                file: selected?.file_name ?? '',
                            })
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{ $t('socialhq_calendars.skipped.description') }}
                    </DialogDescription>
                </DialogHeader>
                <ul v-if="selected" class="flex flex-col gap-2 text-sm">
                    <li
                        v-for="skipped in selected.skipped_reasons"
                        :key="skipped.row"
                        class="flex flex-col gap-0.5 rounded-lg border border-border px-3 py-2"
                    >
                        <span class="font-medium text-foreground">
                            {{
                                $t('socialhq_calendars.skipped.row', {
                                    row: String(skipped.row),
                                })
                            }}
                            <span
                                v-if="skipped.value"
                                class="font-normal text-muted-foreground"
                            >
                                · {{ skipped.value }}
                            </span>
                        </span>
                        <span class="text-muted-foreground">
                            {{
                                $t(
                                    `socialhq_calendars.skipped.reasons.${skipped.reason}`,
                                )
                            }}
                        </span>
                    </li>
                </ul>
                <p
                    v-if="
                        selected &&
                        selected.rows_skipped > selected.skipped_reasons.length
                    "
                    class="text-sm text-muted-foreground"
                >
                    {{
                        $tChoice(
                            'socialhq_calendars.skipped.more',
                            selected.rows_skipped -
                                selected.skipped_reasons.length,
                            {
                                count: formatFullNumber(
                                    selected.rows_skipped -
                                        selected.skipped_reasons.length,
                                ),
                            },
                        )
                    }}
                </p>
                <DialogFooter>
                    <Button type="button" variant="ghost" @click="closeSkipped">
                        {{ $t('socialhq_calendars.close') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="isDeleteOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {{
                            $t('socialhq_calendars.delete_title', {
                                file: selected?.file_name ?? '',
                            })
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{ $t('socialhq_calendars.delete_description') }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button type="button" variant="ghost" @click="closeDelete">
                        {{ $t('socialhq_calendars.cancel') }}
                    </Button>
                    <Button
                        variant="destructive"
                        :loading="deleteHttp.processing"
                        data-testid="socialhq-old-calendar-delete-confirm"
                        @click="confirmDelete"
                    >
                        {{ $t('socialhq_calendars.confirm_delete') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>
</template>
